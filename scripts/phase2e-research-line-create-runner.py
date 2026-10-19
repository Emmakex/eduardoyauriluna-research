#!/usr/bin/env python3
"""Execute one bounded Research Line create command through the production MCP.

This Phase 2E slice is intentionally narrow: create one first-class Research Line
in draft state with only title/language/status supplied and explicit evidence
confirmation. No arbitrary line fields, publication transition, MCP tool name or
backend URL are accepted. The operation uses the existing Plan -> Apply -> Verify
-> Rollback lifecycle and persists only sanitized evidence for ChatGPT readback.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import re
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE_RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
REGISTRY_PATH = ROOT / "config" / "phase2e-research-line-command-registry.json"
SCHEMA_PATH = ROOT / "schemas" / "phase2e-research-line-create-v1.schema.json"
ACTION = "research.line.create"
ALLOWED_DATA_FIELDS = {"title", "language", "status"}
LINE_PLAN_ID_RE = re.compile(r"^erm-line-plan-[a-f0-9]{20}-[a-f0-9]{8}$")
LINE_OP_ID_RE = re.compile(r"^erm-line-op-[a-f0-9]{32}$")

spec = importlib.util.spec_from_file_location("phase2d_command_runner_line_create_base", BASE_RUNNER)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

CommandError = base.CommandError
TENANT_RE = base.TENANT_RE
load_json = base.load_json
sanitized_message = base.sanitized_message
write_result = base.write_result
subprocess = base.subprocess


def validate_payload(payload: dict[str, Any]) -> None:
    if set(payload) != {"data", "evidence_confirmed", "evidence_reference"}:
        raise CommandError("research.line.create payload keys do not match the bounded contract")
    if payload.get("evidence_confirmed") is not True:
        raise CommandError("research.line.create requires explicit evidence confirmation")
    evidence_reference = payload.get("evidence_reference")
    if (
        not isinstance(evidence_reference, str)
        or not evidence_reference.strip()
        or len(evidence_reference) > 512
        or "\n" in evidence_reference
        or "\r" in evidence_reference
    ):
        raise CommandError("research.line.create evidence_reference is invalid")

    data = payload.get("data")
    if not isinstance(data, dict):
        raise CommandError("payload.data must be an object")
    if set(data) != ALLOWED_DATA_FIELDS:
        raise CommandError("research.line.create data must contain exactly title, language and status")
    title = data.get("title")
    if not isinstance(title, str) or not title.strip() or len(title) > 200:
        raise CommandError("payload.data.title is invalid")
    if data.get("language") not in {"es", "en"}:
        raise CommandError("payload.data.language must be es or en")
    if data.get("status") != "draft":
        raise CommandError("research.line.create is draft-only")


def validate_registry(registry: dict[str, Any]) -> None:
    if registry.get("version") != "1":
        raise CommandError("unsupported Research Line registry version")
    if registry.get("tenants") != {"eduardo-research": {"enabled": True}}:
        raise CommandError("Research Line tenant registry changed unexpectedly")
    actions = registry.get("actions")
    if not isinstance(actions, dict) or set(actions) != {ACTION}:
        raise CommandError("Research Line registry must expose exactly one bounded action")
    action = actions[ACTION]
    if action.get("required_capabilities") != ["research.write", "operations.rollback"]:
        raise CommandError("Research Line capability contract changed unexpectedly")
    if action.get("tools") != {
        "plan": "site_research_line_create_plan",
        "apply": "site_research_line_apply",
        "verify": "site_research_line_verify",
        "rollback": "site_research_line_rollback",
    }:
        raise CommandError("Research Line tool mapping changed unexpectedly")
    if action.get("verify_rendered") is not False:
        raise CommandError("draft Research Line must use semantic verification")
    if action.get("allowed_statuses") != ["draft"]:
        raise CommandError("Research Line create must remain draft-only")
    fields = action.get("allowed_data_fields")
    if not isinstance(fields, list) or set(fields) != ALLOWED_DATA_FIELDS:
        raise CommandError("Research Line create field allowlist changed unexpectedly")
    if action.get("evidence_required") is not True:
        raise CommandError("Research Line evidence gate must remain mandatory")


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
        raise CommandError("Research Line command tenant is not registered")
    if command.get("action") != ACTION:
        raise CommandError("unsupported Research Line command action")
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

    import os

    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise CommandError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", base.DEFAULT_BASE_URL).strip().rstrip("/")
    if not base_url.startswith("https://"):
        raise CommandError("PHASE2D_MCP_BASE_URL must use HTTPS")

    tenant = str(command["tenant"])
    tools = registry["actions"][ACTION]["tools"]
    client = base.McpClient(f"{base_url}/{tenant}", token)
    plan_id: str | None = None
    operation_id: str | None = None
    rollback_ok: bool | None = None
    try:
        client.initialize()
        payload = command["payload"]
        data = dict(payload["data"])
        plan = client.call(
            tools["plan"],
            {
                "data": data,
                "evidenceConfirmed": True,
                "evidenceReference": payload["evidence_reference"],
            },
        )
        plan_id = base.regex_string(plan, LINE_PLAN_ID_RE)
        applied = client.call(tools["apply"], {"planId": plan_id, "confirm": True})
        operation_id = base.regex_string(applied, LINE_OP_ID_RE)
        verification = client.call(
            tools["verify"],
            {"operationId": operation_id, "rendered": False},
        )
        if not base.verified(verification):
            raise CommandError("Research Line verification did not report verified=true")
        post_id = base.first_post_id(verification, applied, plan)
        if post_id is None:
            raise CommandError("verified Research Line post_id was not returned")

        result = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "verified": True,
            "resource": {
                "kind": "line",
                "post_id": post_id,
                "title": data["title"],
                "state": "draft",
                "language": data["language"],
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
                "message": "The bounded Research Line lifecycle did not complete successfully.",
            },
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
    props = schema.get("properties", {})
    if props.get("action", {}).get("const") != ACTION:
        raise CommandError("Research Line schema action changed unexpectedly")
    payload_props = props.get("payload", {}).get("properties", {})
    if payload_props.get("evidence_confirmed", {}).get("const") is not True:
        raise CommandError("Research Line schema evidence gate changed unexpectedly")
    data_props = payload_props.get("data", {}).get("properties", {})
    if set(data_props) != ALLOWED_DATA_FIELDS:
        raise CommandError("Research Line schema fields do not match runner allowlist")
    if data_props.get("status", {}).get("const") != "draft":
        raise CommandError("Research Line schema must remain draft-only")

    good = {
        "version": "1",
        "command_id": "cmd-selftest-research-line-0001",
        "tenant": "eduardo-research",
        "action": ACTION,
        "requested_by": "chatgpt",
        "payload": {
            "data": {
                "title": "Phase 2E Research Line acceptance fixture",
                "language": "en",
                "status": "draft",
            },
            "evidence_confirmed": True,
            "evidence_reference": "acceptance:phase2e:research-line:create",
        },
    }
    validate_command(good, registry)

    variants = ["publish", "extra-field", "missing-evidence", "unknown-tenant"]
    for case in variants:
        bad = json.loads(json.dumps(good))
        if case == "publish":
            bad["payload"]["data"]["status"] = "publish"
        elif case == "extra-field":
            bad["payload"]["data"]["topics"] = ["blocked"]
        elif case == "missing-evidence":
            bad["payload"]["evidence_confirmed"] = False
        else:
            bad["tenant"] = "other-tenant"
        try:
            validate_command(bad, registry)
        except CommandError:
            pass
        else:
            raise CommandError(f"self-test: unsafe Research Line case {case} did not fail closed")

    print("phase2e-research-line-create-runner self-test: OK")
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
