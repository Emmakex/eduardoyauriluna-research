#!/usr/bin/env python3
"""Execute one bounded managed Media ingest through the production MCP.

The command accepts only inline canonical base64 for JPEG/PNG/WebP/PDF, never
URLs or filesystem paths. It validates MIME signatures, enforces the effective
5 MiB Manager limit and image alt text, then runs Plan -> Apply -> checksum /
provenance Verify. A staged plan is cancelled on pre-Apply failure; an applied
operation is rolled back on any later failure.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import importlib.util
import json
import os
import re
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE_RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
REGISTRY_PATH = ROOT / "config" / "phase2e-media-ingest-command-registry.json"
SCHEMA_PATH = ROOT / "schemas" / "phase2e-media-ingest-v1.schema.json"
ACTION = "media.ingest"
MAX_BYTES = 5 * 1024 * 1024
MAX_BASE64 = 6_990_508
MIME_TYPES = ("image/jpeg", "image/png", "image/webp", "application/pdf")
PLAN_ID_RE = re.compile(r"^erm-media-ingest-plan-[a-f0-9]{20}-[a-f0-9]{8}$")
OP_ID_RE = re.compile(r"^erm-media-ingest-op-[a-f0-9]{32}$")
BASE64_RE = re.compile(r"^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$")

spec = importlib.util.spec_from_file_location("phase2d_command_runner_media_ingest_base", BASE_RUNNER)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

CommandError = base.CommandError
load_json = base.load_json
write_result = base.write_result
sanitized_message = base.sanitized_message


def decode_content(value: Any) -> bytes:
    if not isinstance(value, str) or not value or len(value) > MAX_BASE64 or not BASE64_RE.fullmatch(value):
        raise CommandError("payload.content_base64 must be canonical bounded base64")
    try:
        decoded = base64.b64decode(value, validate=True)
    except Exception as exc:  # noqa: BLE001
        raise CommandError("payload.content_base64 is invalid") from exc
    if not decoded or len(decoded) > MAX_BYTES:
        raise CommandError("Media ingest file must be between 1 byte and 5 MiB")
    if base64.b64encode(decoded).decode("ascii") != value:
        raise CommandError("payload.content_base64 must use canonical base64 encoding")
    return decoded


def validate_signature(mime_type: str, data: bytes) -> None:
    valid = False
    if mime_type == "image/png":
        valid = data.startswith(b"\x89PNG\r\n\x1a\n")
    elif mime_type == "image/jpeg":
        valid = len(data) >= 3 and data[:3] == b"\xff\xd8\xff"
    elif mime_type == "image/webp":
        valid = len(data) >= 12 and data[:4] == b"RIFF" and data[8:12] == b"WEBP"
    elif mime_type == "application/pdf":
        valid = data.startswith(b"%PDF-")
    if not valid:
        raise CommandError("payload MIME type does not match the bounded file signature")


def validate_optional_text(payload: dict[str, Any], field: str, maximum: int, non_empty: bool = False) -> None:
    if field not in payload:
        return
    value = payload[field]
    if not isinstance(value, str) or len(value) > maximum or (non_empty and not value.strip()):
        raise CommandError(f"payload.{field} is invalid")


def validate_payload(payload: dict[str, Any]) -> bytes:
    allowed = {"file_name", "mime_type", "content_base64", "title", "alt_text", "caption", "description"}
    if not set(payload).issubset(allowed) or not {"file_name", "mime_type", "content_base64"}.issubset(payload):
        raise CommandError("media.ingest payload contains unsupported fields or misses required fields")
    name = payload.get("file_name")
    if (
        not isinstance(name, str)
        or not name.strip()
        or len(name) > 200
        or name in {".", ".."}
        or "/" in name
        or "\\" in name
        or "\x00" in name
    ):
        raise CommandError("payload.file_name must be a bounded basename")
    mime_type = payload.get("mime_type")
    if mime_type not in MIME_TYPES:
        raise CommandError("payload.mime_type is outside the accepted Media contract")
    validate_optional_text(payload, "title", 200, True)
    validate_optional_text(payload, "alt_text", 500)
    validate_optional_text(payload, "caption", 2000)
    validate_optional_text(payload, "description", 12000)
    if str(mime_type).startswith("image/") and not str(payload.get("alt_text", "")).strip():
        raise CommandError("image Media ingest requires non-empty payload.alt_text")
    decoded = decode_content(payload.get("content_base64"))
    validate_signature(str(mime_type), decoded)
    return decoded


def validate_registry(registry: dict[str, Any]) -> None:
    if registry.get("version") != "1":
        raise CommandError("unsupported Media ingest registry version")
    if registry.get("tenants") != {"eduardo-research": {"enabled": True}}:
        raise CommandError("Media ingest tenant registry changed unexpectedly")
    actions = registry.get("actions")
    if not isinstance(actions, dict) or set(actions) != {ACTION}:
        raise CommandError("Media ingest registry must expose exactly one bounded action")
    action = actions[ACTION]
    if action.get("required_capabilities") != ["media.write", "operations.rollback"]:
        raise CommandError("Media ingest capability contract changed unexpectedly")
    if action.get("tools") != {
        "plan": "site_media_ingest_plan",
        "cancel": "site_media_ingest_cancel",
        "apply": "site_media_ingest_apply",
        "verify": "site_media_ingest_verify",
        "rollback": "site_media_ingest_rollback",
    }:
        raise CommandError("Media ingest tool mapping changed unexpectedly")
    if action.get("allowed_mime_types") != list(MIME_TYPES) or action.get("max_bytes") != MAX_BYTES:
        raise CommandError("Media ingest file policy changed unexpectedly")
    for key, expected in {
        "max_filename": 200,
        "max_title": 200,
        "max_alt_text": 500,
        "max_caption": 2000,
        "max_description": 12000,
    }.items():
        if action.get(key) != expected:
            raise CommandError(f"Media ingest {key} changed unexpectedly")
    if action.get("arbitrary_urls") is not False or action.get("filesystem_paths") is not False:
        raise CommandError("Media ingest source boundary changed unexpectedly")
    if action.get("image_alt_text_required") is not True:
        raise CommandError("Media ingest image alt-text requirement changed unexpectedly")


def validate_command(command: dict[str, Any], registry: dict[str, Any] | None = None) -> bytes:
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
        raise CommandError("Media ingest command tenant is not registered")
    if command.get("action") != ACTION or command.get("requested_by") != "chatgpt":
        raise CommandError("unsupported Media ingest command envelope")
    payload = command.get("payload")
    if not isinstance(payload, dict):
        raise CommandError("payload must be an object")
    return validate_payload(payload)


def load_validated_command(path: Path) -> dict[str, Any]:
    registry = load_json(REGISTRY_PATH)
    command = load_json(path)
    validate_command(command, registry)
    return command


def signal_true(value: Any, key: str) -> bool:
    for obj in base.objects(value):
        if obj.get(key) is True:
            return True
    return False


def execute(command_path: Path, result_dir: Path) -> int:
    registry = load_json(REGISTRY_PATH)
    command = load_json(command_path)
    decoded = validate_command(command, registry)
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
    tools = registry["actions"][ACTION]["tools"]
    client = base.McpClient(f"{base_url}/{tenant}", token)
    plan_id: str | None = None
    operation_id: str | None = None
    cleanup_mode: str | None = None
    cleanup_verified: bool | None = None
    try:
        client.initialize()
        plan_args: dict[str, Any] = {
            "fileName": payload["file_name"],
            "mimeType": payload["mime_type"],
            "contentBase64": payload["content_base64"],
        }
        for source, target in (
            ("title", "title"),
            ("alt_text", "altText"),
            ("caption", "caption"),
            ("description", "description"),
        ):
            if source in payload:
                plan_args[target] = payload[source]
        planned = client.call(tools["plan"], plan_args)
        plan_id = base.regex_string(planned, PLAN_ID_RE)
        applied = client.call(tools["apply"], {"planId": plan_id, "confirm": True})
        operation_id = base.regex_string(applied, OP_ID_RE)
        verification = client.call(tools["verify"], {"operationId": operation_id})
        if not base.verified(verification):
            raise CommandError("Media ingest verification did not report verified=true")
        if not signal_true(verification, "checksum_matches"):
            raise CommandError("Media ingest verification did not prove checksum_matches=true")
        if not signal_true(verification, "managed_by_manager"):
            raise CommandError("Media ingest verification did not prove managed_by_manager=true")

        result = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "verified": True,
            "resource": {
                "file_name": payload["file_name"],
                "mime_type": payload["mime_type"],
                "byte_size": len(decoded),
                "sha256": hashlib.sha256(decoded).hexdigest(),
                "image_alt_text_present": bool(str(payload.get("alt_text", "")).strip()) if str(payload["mime_type"]).startswith("image/") else None,
                "inline_content_persisted": False,
            },
            "verification": {
                "checksum_matches": True,
                "managed_by_manager": True,
            },
            "correlation": {"plan_id": plan_id, "operation_id": operation_id},
        }
        path = write_result(result_dir, command_id, result)
        print(f"SUCCESS {path}")
        return 0
    except Exception as exc:  # noqa: BLE001
        if operation_id is not None:
            cleanup_mode = "rollback"
            try:
                rolled = client.call(tools["rollback"], {"operationId": operation_id, "confirm": True})
                cleanup_verified = base.rollback_verified(rolled)
            except Exception:  # noqa: BLE001
                cleanup_verified = False
        elif plan_id is not None:
            cleanup_mode = "cancel-staging"
            try:
                client.call(tools["cancel"], {"planId": plan_id})
                cleanup_verified = True
            except Exception:  # noqa: BLE001
                cleanup_verified = False
        result = {
            "version": "1",
            "status": "failed",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "verified": False,
            "error": {"code": "execution_failed", "message": "The bounded Media ingest lifecycle did not complete successfully."},
            "correlation": {"plan_id": plan_id, "operation_id": operation_id},
            "cleanup": {"mode": cleanup_mode, "attempted": cleanup_mode is not None, "verified": cleanup_verified},
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
        raise CommandError("Media ingest schema action changed unexpectedly")

    png = base64.b64encode(b"\x89PNG\r\n\x1a\n" + b"bounded-test").decode("ascii")
    good = {
        "version": "1",
        "command_id": "cmd-selftest-media-ingest-0001",
        "tenant": "eduardo-research",
        "action": ACTION,
        "requested_by": "chatgpt",
        "payload": {
            "file_name": "bounded-test.png",
            "mime_type": "image/png",
            "content_base64": png,
            "alt_text": "Bounded Media self-test image",
        },
    }
    validate_command(good, registry)
    cases = ("url-name", "path-name", "missing-alt", "mime-mismatch", "extra-field", "unknown-tenant")
    for case in cases:
        bad = json.loads(json.dumps(good))
        if case == "url-name":
            bad["payload"]["file_name"] = "https://example.com/a.png"
        elif case == "path-name":
            bad["payload"]["file_name"] = "../a.png"
        elif case == "missing-alt":
            bad["payload"]["alt_text"] = ""
        elif case == "mime-mismatch":
            bad["payload"]["mime_type"] = "application/pdf"
        elif case == "extra-field":
            bad["payload"]["source_url"] = "https://example.com/a.png"
        else:
            bad["tenant"] = "other-tenant"
        try:
            validate_command(bad, registry)
        except CommandError:
            pass
        else:
            raise CommandError(f"self-test: unsafe Media case {case} did not fail closed")
    print("phase2e-media-ingest-runner self-test: OK")
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
