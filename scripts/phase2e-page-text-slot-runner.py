#!/usr/bin/env python3
"""Execute bounded Phase 2E text-slot updates on Theme-owned Pages.

Interior Pages remain limited to scalar eyebrow/lead copy. Home is permitted only
for an explicit reviewed set of scalar editorial slots exposed by the active Theme
contract. Researcher identity fields, navigation targets, arrays, page state, slugs
and arbitrary MCP tools remain outside this command surface.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE_RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
REGISTRY_PATH = ROOT / "config" / "phase2e-page-text-slot-registry.json"
SCHEMA_PATH = ROOT / "schemas" / "phase2e-page-text-slot-v1.schema.json"
ACTION = "page.slot.update"
INTERIOR_KEYS = (
    "about",
    "research",
    "publications",
    "projects",
    "software",
    "datasets",
    "cv",
    "insights",
    "contact",
    "privacy-policy",
    "legal-notice",
)
ALLOWED_KEYS = ("home",) + INTERIOR_KEYS
ALLOWED_LANGUAGES = ("en", "es")
INTERIOR_SLOTS = ("eyebrow", "lead")
HOME_SLOTS = (
    "hero-eyebrow",
    "hero-lead",
    "research-lines-heading",
    "research-lines-intro",
    "selected-outputs-heading",
    "selected-outputs-intro",
    "academic-identifiers-heading",
    "latest-insights-heading",
    "final-cta-heading",
    "final-cta-body",
    "final-cta-button",
)
ALL_SLOTS = INTERIOR_SLOTS + HOME_SLOTS
VALUE_MAX = 200

spec = importlib.util.spec_from_file_location("phase2d_command_runner_page_text_base", BASE_RUNNER)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

CommandError = base.CommandError
load_json = base.load_json
sanitized_message = base.sanitized_message
write_result = base.write_result
subprocess = base.subprocess


def allowed_slots_for_key(key: str) -> tuple[str, ...]:
    return HOME_SLOTS if key == "home" else INTERIOR_SLOTS


def validate_registry(registry: dict[str, Any]) -> None:
    if registry.get("version") != "1":
        raise CommandError("unsupported Page text-slot registry version")
    if registry.get("tenants") != {"eduardo-research": {"enabled": True}}:
        raise CommandError("Page text-slot tenant registry changed unexpectedly")
    actions = registry.get("actions")
    if not isinstance(actions, dict) or set(actions) != {ACTION}:
        raise CommandError("Page text-slot registry must expose exactly one action")
    action = actions[ACTION]
    if action.get("required_capabilities") != ["pages.write", "operations.rollback"]:
        raise CommandError("Page text-slot capability contract changed unexpectedly")
    if action.get("tools") != {
        "capabilities": "research_capabilities",
        "plan": "site_page_slots_plan",
        "apply": "site_page_slots_apply",
        "verify": "site_page_slots_verify",
        "rollback": "site_page_slots_rollback",
    }:
        raise CommandError("Page text-slot tool mapping changed unexpectedly")
    if action.get("verify_rendered") is not True:
        raise CommandError("Page text-slot updates must require rendered verification")
    if action.get("allowed_keys") != list(ALLOWED_KEYS):
        raise CommandError("Page text-slot key allowlist changed unexpectedly")
    if action.get("allowed_languages") != list(ALLOWED_LANGUAGES):
        raise CommandError("Page text-slot language allowlist changed unexpectedly")
    if action.get("allowed_slots_by_key") != {
        "home": list(HOME_SLOTS),
        "interior": list(INTERIOR_SLOTS),
    }:
        raise CommandError("Page text-slot per-page slot allowlist changed unexpectedly")
    if action.get("value_max") != VALUE_MAX:
        raise CommandError("Page text-slot value bound changed unexpectedly")


def validate_payload(payload: dict[str, Any]) -> None:
    if set(payload) != {"key", "language", "slot", "value"}:
        raise CommandError("page.slot.update payload must contain only key, language, slot and value")
    key = payload.get("key")
    language = payload.get("language")
    slot = payload.get("slot")
    value = payload.get("value")
    if key not in ALLOWED_KEYS:
        raise CommandError("page.slot.update key is outside the reviewed Page allowlist")
    if language not in ALLOWED_LANGUAGES:
        raise CommandError("page.slot.update language is outside the reviewed EN/ES allowlist")
    if slot not in allowed_slots_for_key(str(key)):
        raise CommandError("page.slot.update slot is outside the reviewed allowlist for this Page")
    if (
        not isinstance(value, str)
        or not value.strip()
        or len(value) > VALUE_MAX
        or "<" in value
        or ">" in value
        or "\n" in value
        or "\r" in value
        or "\x00" in value
    ):
        raise CommandError("page.slot.update value must be bounded single-line plain text without markup")


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
        raise CommandError("Page text-slot tenant is not registered")
    if command.get("action") != ACTION:
        raise CommandError("unsupported Page text-slot action")
    if command.get("requested_by") != "chatgpt":
        raise CommandError("requested_by must be chatgpt")
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
    if base_url != base.DEFAULT_BASE_URL:
        raise CommandError("Page text-slot execution only permits the production MCP base URL")

    tenant = str(command["tenant"])
    action = registry["actions"][ACTION]
    tools = action["tools"]
    client = base.McpClient(f"{base_url}/{tenant}", token)
    plan_id: str | None = None
    operation_id: str | None = None
    rollback_ok: bool | None = None
    try:
        client.initialize()
        client.call(tools["capabilities"])
        payload = command["payload"]
        reason = f"Kairoseth command {command_id}: bounded Page text slot"
        plan = client.call(
            tools["plan"],
            {
                "key": payload["key"],
                "language": payload["language"],
                "slots": {payload["slot"]: payload["value"]},
                "reason": reason,
            },
        )
        plan_id = base.regex_string(plan, base.PLAN_ID_RE)
        applied = client.call(tools["apply"], {"planId": plan_id, "confirm": True})
        operation_id = base.regex_string(applied, base.OP_ID_RE)
        verification = client.call(
            tools["verify"],
            {"operationId": operation_id, "rendered": True},
        )
        if not base.verified(verification):
            raise CommandError("Page text-slot rendered verification did not report verified=true")

        result = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "verified": True,
            "resource": {
                "key": payload["key"],
                "language": payload["language"],
                "slot": payload["slot"],
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
            "error": {
                "code": "execution_failed",
                "message": "The bounded Page text-slot lifecycle did not complete successfully.",
            },
            "correlation": {"plan_id": plan_id, "operation_id": operation_id},
            "rollback": {"attempted": operation_id is not None, "verified": rollback_ok},
        }
        path = write_result(result_dir, command_id, result)
        print(f"FAILED {path}: {sanitized_message(str(exc), token)}", file=sys.stderr)
        return 1
    finally:
        client.close()


def self_test() -> int:
    registry = load_json(REGISTRY_PATH)
    validate_registry(registry)
    schema = load_json(SCHEMA_PATH)
    props = schema.get("properties", {})
    if props.get("action", {}).get("const") != ACTION:
        raise CommandError("Page text-slot schema action changed unexpectedly")
    payload_props = props.get("payload", {}).get("properties", {})
    if payload_props.get("key", {}).get("enum") != list(ALLOWED_KEYS):
        raise CommandError("Page text-slot schema key allowlist does not match the runner")
    if payload_props.get("language", {}).get("enum") != list(ALLOWED_LANGUAGES):
        raise CommandError("Page text-slot schema language allowlist does not match the runner")
    if payload_props.get("slot", {}).get("enum") != list(ALL_SLOTS):
        raise CommandError("Page text-slot schema slot allowlist does not match the runner")
    value_schema = payload_props.get("value", {})
    if value_schema.get("maxLength") != VALUE_MAX or value_schema.get("pattern") != "^[^<>\\r\\n]+$":
        raise CommandError("Page text-slot schema value bound does not match the runner")

    interior = {
        "version": "1",
        "command_id": "cmd-selftest-page-text-0001",
        "tenant": "eduardo-research",
        "action": ACTION,
        "requested_by": "chatgpt",
        "payload": {"key": "about", "language": "es", "slot": "lead", "value": "Perfil del investigador."},
    }
    validate_command(interior, registry)
    home = json.loads(json.dumps(interior))
    home["command_id"] = "cmd-selftest-home-text-0001"
    home["payload"] = {"key": "home", "language": "en", "slot": "hero-lead", "value": "Research profile."}
    validate_command(home, registry)

    variants = []
    bad = json.loads(json.dumps(interior)); bad["payload"]["key"] = "unknown"; variants.append(bad)
    bad = json.loads(json.dumps(interior)); bad["payload"]["language"] = "fr"; variants.append(bad)
    bad = json.loads(json.dumps(interior)); bad["payload"]["slot"] = "hero-lead"; variants.append(bad)
    bad = json.loads(json.dumps(home)); bad["payload"]["slot"] = "lead"; variants.append(bad)
    bad = json.loads(json.dumps(interior)); bad["payload"]["value"] = "<strong>unsafe</strong>"; variants.append(bad)
    bad = json.loads(json.dumps(interior)); bad["payload"]["value"] = "line one\nline two"; variants.append(bad)
    for bad in variants:
        try:
            validate_command(bad, registry)
        except CommandError:
            pass
        else:
            raise CommandError("self-test: unsafe Page text-slot command did not fail closed")

    print("phase2e-page-text-slot-runner self-test: OK")
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
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
