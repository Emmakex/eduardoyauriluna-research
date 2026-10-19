#!/usr/bin/env python3
"""Rollback only the two known Slice 12 Design acceptance operations.

Acceptance-only: Git never carries an arbitrary rollback target. Operation IDs are
hard-coded from the verified primary acceptance results and selected by exact
command ID. Remove this helper after both rollback proofs are persisted.
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
ACTION = "design.acceptance.rollback"
COMMANDS = {
    "cmd-phase2e-design-preset-rollback-0001": {
        "operation_id": "erm-design-op-ce24526154c64b0e9adb956505d6e81a",
        "tool": "site_design_preset_rollback",
        "changed_fields": ["density", "palette", "typography"],
    },
    "cmd-phase2e-design-variant-rollback-0001": {
        "operation_id": "erm-design-variant-op-289fd25763e84de7ace9b89144db4b6c",
        "tool": "site_design_variant_rollback",
        "changed_fields": ["cards", "collections", "hero", "sections"],
    },
}
OP_RE = re.compile(r"^erm-design(?:-variant)?-op-[a-f0-9]{32}$")

spec = importlib.util.spec_from_file_location("phase2d_design_acceptance_rollback_base", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
CommandError = base.CommandError
write_result = base.write_result


def expected_command(command_id: str) -> dict[str, Any]:
    if command_id not in COMMANDS:
        raise CommandError("Design acceptance rollback command ID is not registered")
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
        raise CommandError("Design rollback must match the exact acceptance-only contract")


def load_validated_command(path: Path) -> dict[str, Any]:
    command = base.load_json(path)
    validate_command(command)
    return command


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
    client = base.McpClient(f"{base_url}/eduardo-research", token)
    spec = COMMANDS[command_id]
    operation_id = str(spec["operation_id"])
    try:
        client.initialize()
        rolled = client.call(str(spec["tool"]), {"operationId": operation_id, "confirm": True})
        if not base.rollback_verified(rolled):
            raise CommandError("Design rollback did not prove baseline restoration")
        result = {
            "version": "1",
            "status": "success",
            "tenant": "eduardo-research",
            "action": ACTION,
            "command_id": command_id,
            "verified": True,
            "resource": {"changed_fields": spec["changed_fields"]},
            "rollback": {"attempted": True, "verified": True, "restored_baseline": True},
            "correlation": {"operation_id": operation_id},
        }
        path = write_result(result_dir, command_id, result)
        print(f"SUCCESS {path}")
        return 0
    finally:
        client.close()


def self_test() -> int:
    for command_id, item in COMMANDS.items():
        validate_command(expected_command(command_id))
        if not OP_RE.fullmatch(str(item["operation_id"])):
            raise CommandError("registered Design rollback operation ID is invalid")
    for unsafe in ("cmd-phase2e-design-rollback-unsafe", "cmd-phase2e-design-preset-primary-0001"):
        try:
            expected_command(unsafe)
        except CommandError:
            pass
        else:
            raise CommandError("rollback helper accepted an unregistered command ID")
    print("phase2e-design-acceptance-rollback-runner self-test: OK")
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
