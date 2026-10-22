#!/usr/bin/env python3
"""Bounded source-command rollback for Research Gateway v2.

The public command exposes only a previous successful Gateway v2 command id. It never
accepts an operation id, MCP tool, URL or backend payload. The trusted rollback registry
maps the original business action to its accepted rollback tool. This first slice is
intentionally limited to navigation.update.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
import re
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE_RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
GUARD_PATH = Path(__file__).with_name("phase2e-command-guard.py")
REGISTRY_PATH = ROOT / "config" / "research-gateway-v2-rollback-registry.json"
REQUEST_DIR = ROOT / "commands" / "gateway-v2" / "requests"
RESULT_DIR = ROOT / "commands" / "gateway-v2" / "results"
ACTION = "operation.rollback"
TOOL_RE = re.compile(r"^site_[a-z0-9_]{3,96}$")


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"unable to load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


base = load_module("phase2d_command_runner_operation_rollback_base", BASE_RUNNER)
guard_module = load_module("phase2e_command_guard_operation_rollback", GUARD_PATH)
CommandError = base.CommandError
load_json = base.load_json
write_result = base.write_result
sanitized_message = base.sanitized_message


def validate_registry(registry: dict[str, Any]) -> None:
    if set(registry) != {"version", "tenant", "actions"}:
        raise CommandError("rollback registry top-level keys changed unexpectedly")
    if registry.get("version") != "1" or registry.get("tenant") != "eduardo-research":
        raise CommandError("unsupported rollback registry identity")
    actions = registry.get("actions")
    if not isinstance(actions, dict) or set(actions) != {"navigation.update"}:
        raise CommandError("rollback registry first slice must expose navigation.update only")
    cfg = actions["navigation.update"]
    if set(cfg) != {"rollback_tool", "operation_id_pattern", "required_capabilities"}:
        raise CommandError("rollback registry navigation contract changed unexpectedly")
    if cfg.get("rollback_tool") != "site_navigation_rollback":
        raise CommandError("rollback registry tool changed unexpectedly")
    if not isinstance(cfg.get("rollback_tool"), str) or TOOL_RE.fullmatch(cfg["rollback_tool"]) is None:
        raise CommandError("rollback registry tool is invalid")
    try:
        re.compile(str(cfg.get("operation_id_pattern")))
    except re.error as exc:
        raise CommandError("rollback registry operation id pattern is invalid") from exc
    if cfg.get("required_capabilities") != ["navigation.write", "operations.rollback"]:
        raise CommandError("rollback registry capability contract changed unexpectedly")


def validate_command(command: dict[str, Any]) -> None:
    if set(command) != {"version", "command_id", "tenant", "action", "requested_by", "payload"}:
        raise CommandError("command keys do not match command-v1 contract")
    if command.get("version") != "1":
        raise CommandError("unsupported command version")
    command_id = command.get("command_id")
    if not isinstance(command_id, str) or base.COMMAND_ID_RE.fullmatch(command_id) is None:
        raise CommandError("invalid command_id")
    if command.get("tenant") != "eduardo-research":
        raise CommandError("rollback command tenant is not registered")
    if command.get("action") != ACTION or command.get("requested_by") != "chatgpt":
        raise CommandError("unsupported rollback command envelope")
    payload = command.get("payload")
    if not isinstance(payload, dict) or set(payload) != {"source_command_id"}:
        raise CommandError("operation.rollback payload must contain only source_command_id")
    source_id = payload.get("source_command_id")
    if not isinstance(source_id, str) or base.COMMAND_ID_RE.fullmatch(source_id) is None:
        raise CommandError("operation.rollback source_command_id is invalid")
    if source_id == command_id:
        raise CommandError("operation.rollback cannot reference itself")


def load_validated_command(path: Path) -> dict[str, Any]:
    command = load_json(path)
    validate_command(command)
    return command


def validated_source(command: dict[str, Any], registry: dict[str, Any]) -> tuple[dict[str, Any], dict[str, Any], dict[str, Any], str]:
    source_id = str(command["payload"]["source_command_id"])
    request_path = REQUEST_DIR / f"{source_id}.json"
    result_path = RESULT_DIR / f"{source_id}.json"
    if not request_path.is_file() or not result_path.is_file():
        raise CommandError("rollback source command is not a persisted Gateway v2 success")

    try:
        source_command = guard_module.guard.load_validated_command(request_path)
    except Exception as exc:  # noqa: BLE001
        raise CommandError("rollback source request no longer validates") from exc
    source_result = load_json(result_path)
    if source_command.get("tenant") != command.get("tenant"):
        raise CommandError("rollback source tenant mismatch")
    source_action = source_command.get("action")
    actions = registry["actions"]
    if source_action not in actions:
        raise CommandError("rollback source action is not registered for rollback")
    if source_result.get("status") != "success" or source_result.get("verified") is not True:
        raise CommandError("rollback source result is not a verified success")
    if (
        source_result.get("command_id") != source_id
        or source_result.get("tenant") != command.get("tenant")
        or source_result.get("action") != source_action
    ):
        raise CommandError("rollback source result identity mismatch")

    expected_sha = guard_module.guard.command_sha256(source_command)
    if source_result.get("command_sha256") != expected_sha:
        raise CommandError("rollback source result fingerprint mismatch")

    correlation = source_result.get("correlation")
    if not isinstance(correlation, dict):
        raise CommandError("rollback source result contains no correlation object")
    operation_id = correlation.get("operation_id")
    cfg = actions[source_action]
    pattern = re.compile(str(cfg["operation_id_pattern"]))
    if not isinstance(operation_id, str) or pattern.fullmatch(operation_id) is None:
        raise CommandError("rollback source result contains an invalid operation id")
    return source_command, source_result, cfg, operation_id


def rollback_verified(value: Any) -> bool:
    return base.rollback_verified(value)


def execute(command_path: Path, result_dir: Path) -> int:
    registry = load_json(REGISTRY_PATH)
    validate_registry(registry)
    command = load_validated_command(command_path)
    command_id = str(command["command_id"])
    existing = result_dir / f"{command_id}.json"
    if existing.exists():
        print(f"ALREADY_COMPLETED {existing}")
        return 0

    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise CommandError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", base.DEFAULT_BASE_URL).strip().rstrip("/")
    if base_url != base.DEFAULT_BASE_URL:
        raise CommandError("operation.rollback only permits the production MCP base URL")

    source_command, _source_result, cfg, operation_id = validated_source(command, registry)
    tenant = str(command["tenant"])
    client = base.McpClient(f"{base_url}/{tenant}", token)
    try:
        client.initialize()
        rolled = client.call(
            str(cfg["rollback_tool"]),
            {"operationId": operation_id, "confirm": True},
        )
        if not rollback_verified(rolled):
            raise CommandError("rollback did not report a verified restoration")
        result = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "verified": True,
            "source": {
                "command_id": source_command["command_id"],
                "action": source_command["action"],
            },
            "rollback": {"attempted": True, "verified": True},
        }
        path = write_result(result_dir, command_id, result)
        print(f"SUCCESS {path}")
        return 0
    except Exception as exc:  # noqa: BLE001
        result = {
            "version": "1",
            "status": "failed",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "verified": False,
            "source": {"command_id": command["payload"]["source_command_id"]},
            "error": {
                "code": "execution_failed",
                "message": "The bounded rollback did not complete successfully.",
            },
        }
        path = write_result(result_dir, command_id, result)
        print(f"FAILED {path}: {sanitized_message(str(exc), token)}", file=sys.stderr)
        return 1
    finally:
        client.close()


def self_test() -> int:
    registry = load_json(REGISTRY_PATH)
    validate_registry(registry)
    good = {
        "version": "1",
        "command_id": "cmd-selftest-operation-rollback-0002",
        "tenant": "eduardo-research",
        "action": ACTION,
        "requested_by": "chatgpt",
        "payload": {"source_command_id": "cmd-selftest-navigation-source-0001"},
    }
    validate_command(good)
    for payload in (
        {"source_command_id": "bad"},
        {"source_command_id": good["command_id"]},
        {"source_command_id": "cmd-selftest-navigation-source-0001", "operation_id": "erm-nav-op-deadbeef"},
    ):
        bad = json.loads(json.dumps(good))
        bad["payload"] = payload
        try:
            validate_command(bad)
        except CommandError:
            pass
        else:
            raise CommandError("operation.rollback self-test accepted an unsafe payload")
    print("research-gateway-v2 operation rollback self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", nargs="?", type=Path)
    parser.add_argument("--result-dir", type=Path, default=RESULT_DIR)
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            return self_test()
        if args.command is None:
            parser.error("command is required unless --self-test is used")
        return execute(args.command, args.result_dir)
    except CommandError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
