#!/usr/bin/env python3
"""Bounded live capability check for Research Gateway v2.

The caller may ask only about a closed set of registered Gateway capabilities. The
result exposes booleans for the requested scopes and never returns the principal's full
scope inventory.
"""
from __future__ import annotations

import argparse
import importlib.util
import os
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE_RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
CONTRACT_PATH = Path(__file__).with_name("research-gateway-v2-contract.py")
ACTION = "manager.capability.check"
CHECKABLE_CAPABILITIES = {
    "design.write",
    "evidence.read",
    "insights.read",
    "insights.write",
    "media.write",
    "navigation.read",
    "navigation.write",
    "operations.rollback",
    "pages.read",
    "pages.write",
    "research.read",
    "research.write",
    "site.read",
    "translations.write",
}


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"unable to load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


base = load_module("phase2d_command_runner_capability_check_base", BASE_RUNNER)
contract = load_module("research_gateway_v2_contract_capability_check", CONTRACT_PATH)
CommandError = base.CommandError
load_json = base.load_json
write_result = base.write_result
sanitized_message = base.sanitized_message


def validate_command(command: dict[str, Any]) -> None:
    if set(command) != {"version", "command_id", "tenant", "action", "requested_by", "payload"}:
        raise CommandError("command keys do not match command-v1 contract")
    if command.get("version") != "1":
        raise CommandError("unsupported command version")
    command_id = command.get("command_id")
    if not isinstance(command_id, str) or base.COMMAND_ID_RE.fullmatch(command_id) is None:
        raise CommandError("invalid command_id")
    if command.get("tenant") != "eduardo-research":
        raise CommandError("capability check tenant is not registered")
    if command.get("action") != ACTION:
        raise CommandError("unsupported capability check action")
    if command.get("requested_by") != "chatgpt":
        raise CommandError("requested_by must be chatgpt")

    payload = command.get("payload")
    if not isinstance(payload, dict) or set(payload) != {"capabilities"}:
        raise CommandError("capability check payload must contain only capabilities")
    requested = payload.get("capabilities")
    if (
        not isinstance(requested, list)
        or not 1 <= len(requested) <= 8
        or any(not isinstance(item, str) for item in requested)
        or len(set(requested)) != len(requested)
        or any(item not in CHECKABLE_CAPABILITIES for item in requested)
    ):
        raise CommandError("capability check contains an unregistered or invalid capability")


def load_validated_command(path: Path) -> dict[str, Any]:
    command = load_json(path)
    validate_command(command)
    return command


def execute(command_path: Path, result_dir: Path) -> int:
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
        raise CommandError("capability check only permits the production MCP base URL")

    tenant = str(command["tenant"])
    requested = list(command["payload"]["capabilities"])
    client = base.McpClient(f"{base_url}/{tenant}", token)
    try:
        client.initialize()
        capabilities = client.call("research_capabilities")
        granted = set(contract.granted_scopes(capabilities))
        checks = {scope: scope in granted for scope in requested}
        result = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "read_only": True,
            "checks": checks,
            "all_granted": all(checks.values()),
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
            "read_only": True,
            "error": {
                "code": "execution_failed",
                "message": "The bounded capability check did not complete successfully.",
            },
        }
        path = write_result(result_dir, command_id, result)
        print(f"FAILED {path}: {sanitized_message(str(exc), token)}", file=sys.stderr)
        return 1
    finally:
        client.close()


def self_test() -> int:
    good = {
        "version": "1",
        "command_id": "cmd-selftest-capability-check-0001",
        "tenant": "eduardo-research",
        "action": ACTION,
        "requested_by": "chatgpt",
        "payload": {"capabilities": ["navigation.write", "operations.rollback"]},
    }
    validate_command(good)

    bad = dict(good)
    bad["payload"] = {"capabilities": ["arbitrary.admin"]}
    try:
        validate_command(bad)
    except CommandError:
        pass
    else:
        raise CommandError("capability check self-test accepted an unregistered scope")

    print("research-gateway-v2 capability check self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", nargs="?", type=Path)
    parser.add_argument("--result-dir", type=Path, default=ROOT / "commands" / "gateway-v2" / "results")
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
