#!/usr/bin/env python3
"""Rollback only the three known Slice 13 acceptance operations.

Acceptance-only. Git carries no operation ID or arbitrary target. Exact command IDs
select exact operation IDs captured from successful sanitized acceptance evidence.
The MCP adapter performs its own operation-type preflight before rollback.
"""
from __future__ import annotations

import argparse
import importlib.util
import os
import re
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(__file__).with_name("phase2d-command-runner.py")
ACTION = "slice13.acceptance.rollback"
COMMANDS: dict[str, dict[str, Any]] = {
    "cmd-phase2e-slice13-relation-rollback-0001": {
        "tool": "site_insight_rollback",
        "operation_id": "erm-operation-30c4c80fdb2c4b64a9260edbd5803118",
        "post_id": 107,
        "target": "insight-research-line-relation",
    },
    "cmd-phase2e-slice13-insight-rollback-0001": {
        "tool": "site_insight_rollback",
        "operation_id": "erm-operation-882f69d4e63344bfbda9b80fe79cdea9",
        "post_id": 107,
        "target": "temporary-insight-fixture",
    },
    "cmd-phase2e-slice13-line-rollback-0001": {
        "tool": "site_research_line_rollback",
        "operation_id": "erm-line-op-8916fbfc086c43a79ce2528f5fbef192",
        "post_id": 106,
        "target": "temporary-research-line-fixture",
    },
}
INSIGHT_OP_RE = re.compile(r"^erm-operation-[a-f0-9]{32}$")
LINE_OP_RE = re.compile(r"^erm-line-op-[a-f0-9]{32}$")

spec = importlib.util.spec_from_file_location("phase2d_slice13_acceptance_rollback_base", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
CommandError = base.CommandError
write_result = base.write_result
sanitized_message = base.sanitized_message


def expected_command(command_id: str) -> dict[str, Any]:
    if command_id not in COMMANDS:
        raise CommandError("Slice 13 rollback command ID is not registered")
    return {
        "version": "1",
        "command_id": command_id,
        "tenant": "eduardo-research",
        "action": ACTION,
        "requested_by": "chatgpt",
        "payload": {"acceptance_only": True},
    }


def validate_command(command: dict[str, Any], registry: dict[str, Any] | None = None) -> None:
    command_id = command.get("command_id")
    if not isinstance(command_id, str) or command != expected_command(command_id):
        raise CommandError("Slice 13 rollback must match the exact acceptance-only contract")


def load_validated_command(path: Path) -> dict[str, Any]:
    command = base.load_json(path)
    validate_command(command)
    return command


def signal_true(value: Any, key: str) -> bool:
    return any(obj.get(key) is True for obj in base.objects(value))


def rolled_status(value: Any) -> str | None:
    for obj in base.objects(value):
        status = obj.get("status")
        if status in {"rolled-back", "rolled_back"}:
            return str(status)
    return None


def execute(command_path: Path, result_dir: Path) -> int:
    command = base.load_json(command_path)
    validate_command(command)
    command_id = command["command_id"]
    existing = result_dir / f"{command_id}.json"
    if existing.exists():
        print(f"ALREADY_COMPLETED {existing}")
        return 0

    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise CommandError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", base.DEFAULT_BASE_URL).strip().rstrip("/")
    if not base_url.startswith("https://"):
        raise CommandError("PHASE2D_MCP_BASE_URL must use HTTPS")

    config = COMMANDS[command_id]
    client = base.McpClient(f"{base_url}/eduardo-research", token)
    try:
        client.initialize()
        rolled = client.call(
            str(config["tool"]),
            {"operationId": str(config["operation_id"]), "confirm": True},
        )
        status = rolled_status(rolled)
        if not base.rollback_verified(rolled) or status is None:
            raise CommandError("Slice 13 rollback did not prove a rolled-back operation")
        observed_post_id = base.first_post_id(rolled)
        if observed_post_id is not None and observed_post_id != config["post_id"]:
            raise CommandError("Slice 13 rollback response referenced an unexpected post_id")
        result = {
            "version": "1",
            "status": "success",
            "tenant": "eduardo-research",
            "action": ACTION,
            "command_id": command_id,
            "verified": True,
            "resource": {
                "target": config["target"],
                "post_id": config["post_id"],
                "temporary": config["target"] != "insight-research-line-relation",
            },
            "rollback": {
                "attempted": True,
                "verified": True,
                "status": status,
                "restored_baseline": signal_true(rolled, "rollback_restored_baseline"),
                "deleted": signal_true(rolled, "deleted"),
            },
            "correlation": {"operation_id": config["operation_id"]},
        }
        path = write_result(result_dir, command_id, result)
        print(f"SUCCESS {path}")
        return 0
    except Exception as exc:  # noqa: BLE001
        result = {
            "version": "1",
            "status": "failed",
            "tenant": "eduardo-research",
            "action": ACTION,
            "command_id": command_id,
            "verified": False,
            "error": {"code": "rollback_failed", "message": "Exact Slice 13 acceptance rollback did not complete successfully."},
            "rollback": {"attempted": True, "verified": False},
            "correlation": {"operation_id": config["operation_id"]},
        }
        path = write_result(result_dir, command_id, result)
        print(f"FAILED {path}: {sanitized_message(str(exc), token)}", file=__import__("sys").stderr)
        return 1
    finally:
        client.close()


def self_test() -> int:
    for command_id, config in COMMANDS.items():
        validate_command(expected_command(command_id))
        operation_id = str(config["operation_id"])
        if config["tool"] == "site_research_line_rollback":
            if not LINE_OP_RE.fullmatch(operation_id):
                raise CommandError("registered Slice 13 Line rollback operation ID is invalid")
        elif not INSIGHT_OP_RE.fullmatch(operation_id):
            raise CommandError("registered Slice 13 Insight rollback operation ID is invalid")
    for unsafe in (
        "cmd-phase2e-slice13-rollback-unsafe",
        "cmd-phase2e-slice13-relation-primary-0001",
    ):
        try:
            expected_command(unsafe)
        except CommandError:
            continue
        raise CommandError("Slice 13 rollback helper accepted an unregistered command ID")
    mutated = expected_command("cmd-phase2e-slice13-relation-rollback-0001")
    mutated["payload"]["operation_id"] = COMMANDS["cmd-phase2e-slice13-relation-rollback-0001"]["operation_id"]
    try:
        validate_command(mutated)
    except CommandError:
        pass
    else:
        raise CommandError("Slice 13 rollback helper accepted an operation ID from Git")
    print("phase2e-slice13-acceptance-rollback-runner self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", nargs="?", type=Path)
    parser.add_argument("--result-dir", type=Path, default=ROOT / "commands" / "phase2d" / "results")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            return self_test()
        if args.command is None:
            parser.error("command is required unless --self-test is used")
        return execute(args.command, args.result_dir)
    except CommandError as exc:
        print(f"ERROR: {exc}", file=__import__("sys").stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
