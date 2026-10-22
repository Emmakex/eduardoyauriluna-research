#!/usr/bin/env python3
"""Bounded read-only Theme Design inspection for Research Gateway v2."""
from __future__ import annotations

import argparse
import importlib.util
import os
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE_RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
ACTION = "manager.design.inspect"


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"unable to load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


base = load_module("research_gateway_v2_design_inspect_base", BASE_RUNNER)
CommandError = base.CommandError
load_json = base.load_json
write_result = base.write_result
sanitized_message = base.sanitized_message

SENSITIVE_KEY_PARTS = ("token", "secret", "password", "credential", "authorization", "cookie", "nonce")


def sanitize(value: Any, depth: int = 0) -> Any:
    if depth > 10:
        return "[depth-limited]"
    if isinstance(value, dict):
        safe: dict[str, Any] = {}
        for key, child in value.items():
            name = str(key)
            if any(part in name.lower() for part in SENSITIVE_KEY_PARTS):
                continue
            safe[name] = sanitize(child, depth + 1)
        return safe
    if isinstance(value, list):
        return [sanitize(item, depth + 1) for item in value[:200]]
    if isinstance(value, str):
        return value[:40000]
    if value is None or isinstance(value, (bool, int, float)):
        return value
    return str(value)[:40000]


def validate_command(command: dict[str, Any]) -> None:
    expected = {"version", "command_id", "tenant", "action", "requested_by", "payload"}
    if set(command) != expected:
        raise CommandError("command keys do not match command-v1 contract")
    if command.get("version") != "1":
        raise CommandError("unsupported command version")
    command_id = command.get("command_id")
    if not isinstance(command_id, str) or base.COMMAND_ID_RE.fullmatch(command_id) is None:
        raise CommandError("invalid command_id")
    if command.get("tenant") != "eduardo-research":
        raise CommandError("Design inspect tenant is not registered")
    if command.get("action") != ACTION or command.get("requested_by") != "chatgpt":
        raise CommandError("unsupported Design inspect command envelope")
    if command.get("payload") != {}:
        raise CommandError("manager.design.inspect payload must be empty")


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
        raise CommandError("Design inspect only permits the production MCP base URL")

    tenant = str(command["tenant"])
    client = base.McpClient(f"{base_url}/{tenant}", token)
    try:
        client.initialize()
        data = client.call("site_design_inspect", {})
        if not isinstance(data, dict):
            raise CommandError("site_design_inspect returned a non-object result")
        result = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "read_only": True,
            "resource": {"type": "design"},
            "data": sanitize(data),
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
                "message": "The bounded Design read did not complete successfully.",
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
        "command_id": "cmd-selftest-design-inspect-0001",
        "tenant": "eduardo-research",
        "action": ACTION,
        "requested_by": "chatgpt",
        "payload": {},
    }
    validate_command(good)
    bad = dict(good)
    bad["payload"] = {"tool": "site_design_inspect"}
    try:
        validate_command(bad)
    except CommandError:
        pass
    else:
        raise CommandError("Design inspect self-test accepted caller-selected parameters")
    print("research-gateway-v2 Design inspect self-test: OK")
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
