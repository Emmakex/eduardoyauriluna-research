#!/usr/bin/env python3
"""Manage the exact temporary Media fixture used for metadata gateway acceptance.

Acceptance-only. No user bytes, URL, path or arbitrary metadata enter through Git.
The create command uses one fixed public 1x1 PNG embedded in this runner. A second
exact command is permitted only to prove cleanup of the known failed fixture-0001
operation before any new acceptance mutation is attempted.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import importlib.util
import os
import re
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(__file__).with_name("phase2d-command-runner.py")
ACTION = "media.acceptance.fixture.create"
CREATE_COMMAND_ID = "cmd-phase2e-media-fixture-0002"
CLEANUP_COMMAND_ID = "cmd-phase2e-media-fixture-cleanup-0002"
FAILED_FIXTURE_OPERATION_ID = "erm-media-ingest-op-58cfd560af614e6bade2db635a8840b0"
PNG_BASE64 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4//8/AAX+Av4N70a4AAAAAElFTkSuQmCC"
PNG_BYTES = base64.b64decode(PNG_BASE64)
PNG_SHA256 = hashlib.sha256(PNG_BYTES).hexdigest()
PLAN_RE = re.compile(r"^erm-media-ingest-plan-[a-f0-9]{20}-[a-f0-9]{8}$")
OP_RE = re.compile(r"^erm-media-ingest-op-[a-f0-9]{32}$")

spec = importlib.util.spec_from_file_location("phase2d_command_runner_media_fixture_cmd", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
CommandError = base.CommandError
write_result = base.write_result
sanitized_message = base.sanitized_message


def expected_command(command_id: str) -> dict[str, Any]:
    if command_id not in {CREATE_COMMAND_ID, CLEANUP_COMMAND_ID}:
        raise CommandError("Media fixture command ID is outside the exact acceptance-only contract")
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
        raise CommandError("Media fixture command must match the exact acceptance-only contract")


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


def rollback_proven(value: Any) -> bool:
    return base.rollback_verified(value) and status_is(value, "rolled-back") and signal_true(value, "deleted")


def execute_cleanup(command: dict[str, Any], client: Any, result_dir: Path) -> int:
    command_id = command["command_id"]
    rolled = client.call(
        "site_media_ingest_rollback",
        {"operationId": FAILED_FIXTURE_OPERATION_ID, "confirm": True},
    )
    if not rollback_proven(rolled):
        raise CommandError("Known failed Media fixture operation is not proven rolled-back and deleted")
    aid = attachment_id(rolled)
    result = {
        "version": "1",
        "status": "success",
        "tenant": "eduardo-research",
        "action": ACTION,
        "command_id": command_id,
        "verified": True,
        "resource": {"attachment_id": aid, "temporary": True, "deleted": True},
        "rollback": {"attempted": True, "verified": True, "status": "rolled-back"},
        "correlation": {"operation_id": FAILED_FIXTURE_OPERATION_ID},
    }
    path = write_result(result_dir, command_id, result)
    print(f"SUCCESS {path}")
    return 0


def execute_create(command: dict[str, Any], client: Any, token: str, result_dir: Path) -> int:
    command_id = command["command_id"]
    plan_id = None
    operation_id = None
    rollback_attempted = False
    rollback_ok: bool | None = None
    cancel_attempted = False
    cancel_ok: bool | None = None
    try:
        plan = client.call(
            "site_media_ingest_plan",
            {
                "fileName": "phase2e-media-metadata-acceptance.png",
                "mimeType": "image/png",
                "contentBase64": PNG_BASE64,
                "title": "Phase 2E temporary Media acceptance fixture",
                "altText": "Temporary one-pixel Media acceptance fixture; not site content.",
                "caption": "Temporary reversible fixture for Media metadata gateway acceptance.",
                "description": "Acceptance-only attachment generated from fixed public test bytes and removed after verification.",
            },
        )
        plan_id = base.regex_string(plan, PLAN_RE)
        applied = client.call("site_media_ingest_apply", {"planId": plan_id, "confirm": True})
        operation_id = base.regex_string(applied, OP_RE)
        verified = client.call("site_media_ingest_verify", {"operationId": operation_id})
        if not (
            base.verified(verified)
            and signal_true(verified, "checksum_matches")
            and signal_true(verified, "provenance_matches")
            and signal_true(verified, "mime_matches")
            and signal_true(verified, "alt_matches")
        ):
            raise CommandError("Media fixture did not pass canonical checksum/provenance/MIME/alt verification")
        aid = attachment_id(applied) or attachment_id(verified)
        if aid is None:
            raise CommandError("Media fixture did not return attachment_id")
        result = {
            "version": "1",
            "status": "success",
            "tenant": "eduardo-research",
            "action": ACTION,
            "command_id": command_id,
            "verified": True,
            "resource": {
                "attachment_id": aid,
                "file_name": "phase2e-media-metadata-acceptance.png",
                "mime_type": "image/png",
                "byte_size": len(PNG_BYTES),
                "sha256": PNG_SHA256,
                "temporary": True,
            },
            "verification": {
                "checksum_matches": True,
                "provenance_matches": True,
                "mime_matches": True,
                "alt_matches": True,
            },
            "rollback": {"attempted": False, "verified": None},
            "correlation": {"plan_id": plan_id, "operation_id": operation_id},
        }
        path = write_result(result_dir, command_id, result)
        print(f"SUCCESS {path}")
        return 0
    except Exception as exc:
        if operation_id:
            rollback_attempted = True
            try:
                rolled = client.call("site_media_ingest_rollback", {"operationId": operation_id, "confirm": True})
                rollback_ok = rollback_proven(rolled)
            except Exception:
                rollback_ok = False
        elif plan_id:
            cancel_attempted = True
            try:
                cancelled = client.call("site_media_ingest_cancel", {"planId": plan_id})
                cancel_ok = status_is(cancelled, "cancelled")
            except Exception:
                cancel_ok = False
        result = {
            "version": "1",
            "status": "failed",
            "tenant": "eduardo-research",
            "action": ACTION,
            "command_id": command_id,
            "verified": False,
            "error": {"code": "fixture_failed", "message": "Acceptance-only Media fixture creation failed."},
            "rollback": {"attempted": rollback_attempted, "verified": rollback_ok},
            "cancel": {"attempted": cancel_attempted, "verified": cancel_ok},
            "correlation": {"plan_id": plan_id, "operation_id": operation_id},
        }
        path = write_result(result_dir, command_id, result)
        print(f"FAILED {path}: {sanitized_message(str(exc), token)}", file=__import__("sys").stderr)
        return 1


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
    try:
        client.initialize()
        if command_id == CLEANUP_COMMAND_ID:
            return execute_cleanup(command, client, result_dir)
        return execute_create(command, client, token, result_dir)
    finally:
        client.close()


def self_test() -> int:
    for command_id in (CREATE_COMMAND_ID, CLEANUP_COMMAND_ID):
        validate_command(expected_command(command_id))
    try:
        expected_command("cmd-phase2e-media-fixture-unsafe")
    except CommandError:
        pass
    else:
        raise CommandError("fixture validator accepted an unregistered command ID")
    if len(PNG_BYTES) != 69 or PNG_SHA256 != "e878950f8091ec010cf5cc723bdea027a8539cf7147cfea199c2f666232dcd4e":
        raise CommandError("fixed PNG fixture changed unexpectedly")
    if not OP_RE.fullmatch(FAILED_FIXTURE_OPERATION_ID):
        raise CommandError("known failed fixture operation ID has invalid format")
    print("phase2e-media-fixture-command-runner self-test: OK")
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
