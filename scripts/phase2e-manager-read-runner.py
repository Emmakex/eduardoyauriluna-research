#!/usr/bin/env python3
"""Execute bounded read-only Manager inspection commands through production MCP.

This runner exposes authenticated discovery/inspection only. It never invokes a mutation
tool. Persisted output is deliberately limited to tool names or the public MCP schema of
an explicitly allowlisted read tool.
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
LIST_ACTION = "manager.tools.list"
DESCRIBE_ACTION = "manager.tool.describe"
ACTION = LIST_ACTION
ACTIONS = {LIST_ACTION, DESCRIBE_ACTION}
DESCRIBABLE_TOOLS = {"site_page_inspect"}

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
    action = command.get("action")
    if action not in ACTIONS:
        raise CommandError("unsupported Manager read action")
    if command.get("requested_by") != "chatgpt":
        raise CommandError("requested_by must be chatgpt")
    payload = command.get("payload")
    if not isinstance(payload, dict):
        raise CommandError("payload must be an object")
    if action == LIST_ACTION:
        if payload != {}:
            raise CommandError("manager.tools.list payload must be empty")
    elif action == DESCRIBE_ACTION:
        if set(payload) != {"tool"} or payload.get("tool") not in DESCRIBABLE_TOOLS:
            raise CommandError("manager.tool.describe is limited to the reviewed read-tool allowlist")


def load_validated_command(path: Path) -> dict[str, Any]:
    command = load_json(path)
    validate_command(command)
    return command


def tool_inventory(client: Any) -> list[dict[str, Any]]:
    payload = client.run(["--method", "tools/list"])
    result_obj = payload.get("result") if isinstance(payload, dict) else None
    tools = result_obj.get("tools") if isinstance(result_obj, dict) else None
    if not isinstance(tools, list):
        raise CommandError("MCP tools/list returned no tool inventory")
    return [item for item in tools if isinstance(item, dict)]


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
    action = str(command["action"])
    client = base.McpClient(f"{base_url}/{tenant}", token)
    try:
        client.initialize()
        client.call("research_capabilities")
        tools = tool_inventory(client)
        result: dict[str, Any] = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": action,
            "command_id": command_id,
            "read_only": True,
        }
        if action == LIST_ACTION:
            names = sorted({item.get("name") for item in tools if isinstance(item.get("name"), str)})
            if not names:
                raise CommandError("MCP tools/list returned an empty tool inventory")
            result["tools"] = names
        else:
            requested = str(command["payload"]["tool"])
            matches = [item for item in tools if item.get("name") == requested]
            if len(matches) != 1:
                raise CommandError("requested MCP tool was not uniquely present")
            item = matches[0]
            safe: dict[str, Any] = {"name": requested}
            description = item.get("description")
            if isinstance(description, str):
                safe["description"] = description
            input_schema = item.get("inputSchema")
            if not isinstance(input_schema, dict):
                raise CommandError("requested MCP tool exposes no input schema")
            safe["inputSchema"] = input_schema
            output_schema = item.get("outputSchema")
            if isinstance(output_schema, dict):
                safe["outputSchema"] = output_schema
            result["tool"] = safe
        path = write_result(result_dir, command_id, result)
        print(f"SUCCESS {path}")
        return 0
    except Exception as exc:  # noqa: BLE001
        result = {
            "version": "1",
            "status": "failed",
            "tenant": tenant,
            "action": action,
            "command_id": command_id,
            "read_only": True,
            "error": {
                "code": "execution_failed",
                "message": "The bounded Manager read operation did not complete successfully.",
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
