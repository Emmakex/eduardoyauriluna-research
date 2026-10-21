#!/usr/bin/env python3
"""Execute bounded read-only Manager inspection commands through production MCP.

This runner intentionally exposes no mutation lifecycle. It is limited to authenticated
MCP tool discovery so ChatGPT can resolve the real read surface before inspecting live
WordPress resources. Only sanitized tool names are persisted.
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
ACTION = "manager.tools.list"

spec = importlib.util.spec_from_file_location("phase2d_command_runner_manager_read_base", BASE_RUNNER)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

CommandError = base.CommandError
load_json = base.load_json
sanitized_message = base.sanitized_message
write_result = base.write_result


def validate_command(command: dict[str, Any]) -> None:
    if set(command) != {"version", "command_id", "tenant", "action", "requested_by", "payload"}:
        raise CommandError("command keys do not match command-v1 contract")
    if command.get("version") != "1":
        raise CommandError("unsupported command version")
    command_id = command.get("command_id")
    if not isinstance(command_id, str) or not base.COMMAND_ID_RE.fullmatch(command_id):
        raise CommandError("invalid command_id")
    if command.get("tenant") != "eduardo-research":
        raise CommandError("Manager read tenant is not registered")
    if command.get("action") != ACTION:
        raise CommandError("unsupported Manager read action")
    if command.get("requested_by") != "chatgpt":
        raise CommandError("requested_by must be chatgpt")
    if command.get("payload") != {}:
        raise CommandError("manager.tools.list payload must be empty")


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
        raise CommandError("Manager read execution only permits the production MCP base URL")

    tenant = str(command["tenant"])
    client = base.McpClient(f"{base_url}/{tenant}", token)
    try:
        client.initialize()
        client.call("research_capabilities")
        payload = client.run(["--method", "tools/list"])
        result_obj = payload.get("result") if isinstance(payload, dict) else None
        tools = result_obj.get("tools") if isinstance(result_obj, dict) else None
        if not isinstance(tools, list):
            raise CommandError("MCP tools/list returned no tool inventory")
        names = sorted({item.get("name") for item in tools if isinstance(item, dict) and isinstance(item.get("name"), str)})
        if not names:
            raise CommandError("MCP tools/list returned an empty tool inventory")
        result = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "read_only": True,
            "tools": names,
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
                "message": "The bounded Manager read discovery did not complete successfully.",
            },
        }
        path = write_result(result_dir, command_id, result)
        print(f"FAILED {path}: {sanitized_message(str(exc), token)}", file=sys.stderr)
        return 1
    finally:
        client.close()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", type=Path)
    parser.add_argument("--result-dir", type=Path, default=ROOT / "commands" / "phase2d" / "results")
    args = parser.parse_args()
    try:
        return execute(args.command, args.result_dir)
    except CommandError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
