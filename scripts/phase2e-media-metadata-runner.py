#!/usr/bin/env python3
"""Execute one bounded Media metadata update through the production MCP.

Only title, alt text, caption and description are mutable. No file content,
source URL, filesystem path, attachment replacement or deletion is accepted.
Lifecycle: Plan -> Apply -> stored Verify -> exact rollback on post-Apply failure.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
import re
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE_RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
REGISTRY_PATH = ROOT / "config" / "phase2e-media-metadata-command-registry.json"
SCHEMA_PATH = ROOT / "schemas" / "phase2e-media-metadata-v1.schema.json"
ACTION = "media.metadata.update"
FIELDS = ("title", "alt_text", "caption", "description")
LIMITS = {"title": 200, "alt_text": 500, "caption": 2000, "description": 12000}
PLAN_ID_RE = re.compile(r"^erm-media-plan-[a-f0-9]{20}-[a-f0-9]{8}$")
OP_ID_RE = re.compile(r"^erm-media-op-[a-f0-9]{32}$")
MAX_SAFE_INTEGER = 9007199254740991

spec = importlib.util.spec_from_file_location("phase2d_command_runner_media_metadata_base", BASE_RUNNER)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

CommandError = base.CommandError
load_json = base.load_json
write_result = base.write_result
sanitized_message = base.sanitized_message


def validate_payload(payload: dict[str, Any]) -> None:
    if set(payload) != {"attachment_id", "changes"}:
        raise CommandError("media.metadata.update payload must contain only attachment_id and changes")
    attachment_id = payload.get("attachment_id")
    if not isinstance(attachment_id, int) or isinstance(attachment_id, bool) or not 0 < attachment_id <= MAX_SAFE_INTEGER:
        raise CommandError("payload.attachment_id must be a positive safe integer")
    changes = payload.get("changes")
    if not isinstance(changes, dict) or not changes or not set(changes).issubset(FIELDS):
        raise CommandError("payload.changes must contain at least one bounded Media metadata field")
    for field, value in changes.items():
        if not isinstance(value, str) or len(value) > LIMITS[field]:
            raise CommandError(f"payload.changes.{field} is invalid")
        if field == "title" and not value.strip():
            raise CommandError("payload.changes.title cannot be empty")


def validate_registry(registry: dict[str, Any]) -> None:
    if registry.get("version") != "1":
        raise CommandError("unsupported Media metadata registry version")
    if registry.get("tenants") != {"eduardo-research": {"enabled": True}}:
        raise CommandError("Media metadata tenant registry changed unexpectedly")
    actions = registry.get("actions")
    if not isinstance(actions, dict) or set(actions) != {ACTION}:
        raise CommandError("Media metadata registry must expose exactly one bounded action")
    action = actions[ACTION]
    if action.get("required_capabilities") != ["media.write", "operations.rollback"]:
        raise CommandError("Media metadata capability contract changed unexpectedly")
    if action.get("tools") != {
        "plan": "site_media_metadata_update_plan",
        "apply": "site_media_metadata_apply",
        "verify": "site_media_metadata_verify",
        "rollback": "site_media_metadata_rollback",
    }:
        raise CommandError("Media metadata tool mapping changed unexpectedly")
    if action.get("verify_rendered") is not False:
        raise CommandError("Media metadata first slice must use stored verification")
    if action.get("allowed_change_fields") != list(FIELDS):
        raise CommandError("Media metadata field allowlist changed unexpectedly")
    for field, limit in LIMITS.items():
        if action.get(f"max_{field}") != limit:
            raise CommandError(f"Media metadata {field} limit changed unexpectedly")


def validate_command(command: dict[str, Any], registry: dict[str, Any] | None = None) -> None:
    if registry is None:
        registry = load_json(REGISTRY_PATH)
    validate_registry(registry)
    if set(command) != {"version", "command_id", "tenant", "action", "requested_by", "payload"}:
        raise CommandError("command keys do not match command-v1 contract")
    if command.get("version") != "1":
        raise CommandError("unsupported command version")
    command_id = command.get("command_id")
    if not isinstance(command_id, str) or not base.COMMAND_ID_RE.fullmatch(command_id):
        raise CommandError("invalid command_id")
    if command.get("tenant") != "eduardo-research":
        raise CommandError("Media metadata command tenant is not registered")
    if command.get("action") != ACTION or command.get("requested_by") != "chatgpt":
        raise CommandError("unsupported Media metadata command envelope")
    payload = command.get("payload")
    if not isinstance(payload, dict):
        raise CommandError("payload must be an object")
    validate_payload(payload)


def load_validated_command(path: Path) -> dict[str, Any]:
    registry = load_json(REGISTRY_PATH)
    command = load_json(path)
    validate_command(command, registry)
    return command


def execute(command_path: Path, result_dir: Path) -> int:
    registry = load_json(REGISTRY_PATH)
    command = load_json(command_path)
    validate_command(command, registry)
    command_id = str(command["command_id"])
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

    tenant = str(command["tenant"])
    payload = command["payload"]
    changes = payload["changes"]
    tools = registry["actions"][ACTION]["tools"]
    client = base.McpClient(f"{base_url}/{tenant}", token)
    plan_id: str | None = None
    operation_id: str | None = None
    rollback_ok: bool | None = None
    try:
        client.initialize()
        mapped: dict[str, str] = {}
        for source, target in (("title", "title"), ("alt_text", "altText"), ("caption", "caption"), ("description", "description")):
            if source in changes:
                mapped[target] = changes[source]
        plan = client.call(tools["plan"], {"attachmentId": payload["attachment_id"], "changes": mapped})
        plan_id = base.regex_string(plan, PLAN_ID_RE)
        applied = client.call(tools["apply"], {"planId": plan_id, "confirm": True})
        operation_id = base.regex_string(applied, OP_ID_RE)
        verification = client.call(tools["verify"], {"operationId": operation_id, "rendered": False})
        if not base.verified(verification):
            raise CommandError("Media metadata verification did not report verified=true")

        result = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "verified": True,
            "resource": {
                "attachment_id": payload["attachment_id"],
                "changed_fields": sorted(changes.keys()),
            },
            "correlation": {"plan_id": plan_id, "operation_id": operation_id},
        }
        path = write_result(result_dir, command_id, result)
        print(f"SUCCESS {path}")
        return 0
    except Exception as exc:  # noqa: BLE001
        if operation_id is not None:
            try:
                rolled = client.call(tools["rollback"], {"operationId": operation_id, "confirm": True})
                rollback_ok = base.rollback_verified(rolled)
            except Exception:  # noqa: BLE001
                rollback_ok = False
        result = {
            "version": "1",
            "status": "failed",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "verified": False,
            "error": {"code": "execution_failed", "message": "The bounded Media metadata lifecycle did not complete successfully."},
            "correlation": {"plan_id": plan_id, "operation_id": operation_id},
            "rollback": {"attempted": operation_id is not None, "verified": rollback_ok},
        }
        path = write_result(result_dir, command_id, result)
        print(f"FAILED {path}: {sanitized_message(str(exc), token)}", file=__import__("sys").stderr)
        return 1
    finally:
        client.close()


def self_test() -> int:
    registry = load_json(REGISTRY_PATH)
    validate_registry(registry)
    schema = load_json(SCHEMA_PATH)
    if schema.get("properties", {}).get("action", {}).get("const") != ACTION:
        raise CommandError("Media metadata schema action changed unexpectedly")
    good = {
        "version": "1",
        "command_id": "cmd-selftest-media-metadata-0001",
        "tenant": "eduardo-research",
        "action": ACTION,
        "requested_by": "chatgpt",
        "payload": {"attachment_id": 123, "changes": {"alt_text": "Bounded metadata self-test"}},
    }
    validate_command(good, registry)
    for case in ("extra-payload", "unsupported-change", "empty-title", "bad-id", "unknown-tenant"):
        bad = json.loads(json.dumps(good))
        if case == "extra-payload":
            bad["payload"]["source_url"] = "https://example.com/a.png"
        elif case == "unsupported-change":
            bad["payload"]["changes"] = {"file": "replacement.png"}
        elif case == "empty-title":
            bad["payload"]["changes"] = {"title": ""}
        elif case == "bad-id":
            bad["payload"]["attachment_id"] = 0
        else:
            bad["tenant"] = "other-tenant"
        try:
            validate_command(bad, registry)
        except CommandError:
            pass
        else:
            raise CommandError(f"self-test: unsafe Media metadata case {case} did not fail closed")
    print("phase2e-media-metadata-runner self-test: OK")
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
