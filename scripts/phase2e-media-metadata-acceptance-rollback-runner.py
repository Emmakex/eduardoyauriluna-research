#!/usr/bin/env python3
"""Rollback only the two known Media metadata acceptance operations.

Acceptance-only. Operation IDs are hard-coded and selected by exact command ID;
Git never carries an arbitrary rollback target.
"""
from __future__ import annotations

import argparse
import importlib.util
import os
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(__file__).with_name("phase2d-command-runner.py")
ACTION = "media.metadata.acceptance.rollback"
COMMANDS = {
    "cmd-phase2e-media-metadata-rollback-replay-0001": "erm-media-op-488aac7d947e4d10915fdf4e117ac3a1",
    "cmd-phase2e-media-metadata-rollback-primary-0001": "erm-media-op-181c23a90fbb45d8bc9389e1be329dd2",
}

spec = importlib.util.spec_from_file_location("phase2d_media_metadata_acceptance_rollback_base", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
CommandError = base.CommandError
write_result = base.write_result


def expected_command(command_id: str) -> dict[str, Any]:
    if command_id not in COMMANDS:
        raise CommandError("Media metadata acceptance rollback command ID is not registered")
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
        raise CommandError("Media metadata rollback must match the exact acceptance-only contract")


def load_validated_command(path: Path) -> dict[str, Any]:
    command = base.load_json(path)
    validate_command(command)
    return command


def attachment_id(value: Any) -> int | None:
    for obj in base.objects(value):
        candidate = obj.get("attachment_id")
        if isinstance(candidate, int) and not isinstance(candidate, bool) and candidate > 0:
            return candidate
        if isinstance(candidate, str) and candidate.isdigit() and int(candidate) > 0:
            return int(candidate)
    return None


def signal_true(value: Any, key: str) -> bool:
    return any(obj.get(key) is True for obj in base.objects(value))


def status_is(value: Any, expected: str) -> bool:
    return any(obj.get("status") == expected for obj in base.objects(value))


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
    operation_id = COMMANDS[command_id]
    try:
        client.initialize()
        rolled = client.call("site_media_metadata_rollback", {"operationId": operation_id, "confirm": True})
        if not (
            base.rollback_verified(rolled)
            and status_is(rolled, "rolled-back")
            and signal_true(rolled, "rollback_restored_source_revision")
        ):
            raise CommandError("Media metadata rollback did not prove exact source revision restoration")
        aid = attachment_id(rolled)
        if aid != 105:
            raise CommandError("Media metadata rollback target is not the acceptance attachment")
        result = {
            "version": "1",
            "status": "success",
            "tenant": "eduardo-research",
            "action": ACTION,
            "command_id": command_id,
            "verified": True,
            "resource": {"attachment_id": aid, "changed_fields": ["alt_text"]},
            "rollback": {"attempted": True, "verified": True, "restored_source_revision": True},
            "correlation": {"operation_id": operation_id},
        }
        path = write_result(result_dir, command_id, result)
        print(f"SUCCESS {path}")
        return 0
    finally:
        client.close()


def self_test() -> int:
    for command_id, operation_id in COMMANDS.items():
        validate_command(expected_command(command_id))
        if not operation_id.startswith("erm-media-op-") or len(operation_id) != len("erm-media-op-") + 32:
            raise CommandError("registered Media metadata rollback operation ID is invalid")
    try:
        expected_command("cmd-phase2e-media-metadata-rollback-unsafe")
    except CommandError:
        pass
    else:
        raise CommandError("rollback helper accepted an unregistered command ID")
    print("phase2e-media-metadata-acceptance-rollback-runner self-test: OK")
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
