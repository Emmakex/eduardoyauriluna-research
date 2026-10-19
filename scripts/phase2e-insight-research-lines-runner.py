#!/usr/bin/env python3
"""Execute one bounded Insight -> Research Line relation update through production MCP.

Only the accepted M4 relation field is exposed: an exact Insight post ID plus a
bounded unique list of Research Line IDs. Publication state, scheduling, editorial
text, arbitrary MCP tools and backend routes are intentionally unavailable.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE_RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
REGISTRY_PATH = ROOT / "config" / "phase2e-insight-research-lines-command-registry.json"
SCHEMA_PATH = ROOT / "schemas" / "phase2e-insight-research-lines-v1.schema.json"
ACTION = "insight.research-lines.update"
MAX_LINE_IDS = 32

spec = importlib.util.spec_from_file_location("phase2d_command_runner_insight_relations_base", BASE_RUNNER)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

CommandError = base.CommandError
load_json = base.load_json
sanitized_message = base.sanitized_message
write_result = base.write_result
subprocess = base.subprocess


def valid_id(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool) and 0 < value <= base.MAX_SAFE_INTEGER


def validate_payload(payload: dict[str, Any]) -> None:
    if set(payload) != {"post_id", "line_ids"}:
        raise CommandError("Insight Research Line payload must contain only post_id and line_ids")
    if not valid_id(payload.get("post_id")):
        raise CommandError("payload.post_id must be a positive safe integer")
    line_ids = payload.get("line_ids")
    if not isinstance(line_ids, list) or len(line_ids) > MAX_LINE_IDS:
        raise CommandError("payload.line_ids must be an array of at most 32 IDs")
    if any(not valid_id(value) for value in line_ids):
        raise CommandError("payload.line_ids must contain only positive safe integers")
    if len(set(line_ids)) != len(line_ids):
        raise CommandError("payload.line_ids must not contain duplicates")


def validate_registry(registry: dict[str, Any]) -> None:
    if registry.get("version") != "1":
        raise CommandError("unsupported Insight Research Line registry version")
    if registry.get("tenants") != {"eduardo-research": {"enabled": True}}:
        raise CommandError("Insight Research Line tenant registry changed unexpectedly")
    actions = registry.get("actions")
    if not isinstance(actions, dict) or set(actions) != {ACTION}:
        raise CommandError("Insight Research Line registry must expose exactly one bounded action")
    action = actions[ACTION]
    if action.get("required_capabilities") != ["insights.write", "operations.rollback"]:
        raise CommandError("Insight Research Line capability contract changed unexpectedly")
    if action.get("tools") != {
        "plan": "site_insight_update_plan",
        "apply": "site_insight_apply",
        "verify": "site_insight_verify",
        "rollback": "site_insight_rollback",
    }:
        raise CommandError("Insight Research Line tool mapping changed unexpectedly")
    if action.get("verify_rendered") is not True:
        raise CommandError("Insight Research Line relation must require rendered verification")
    if action.get("max_line_ids") != MAX_LINE_IDS or action.get("relation_field") != "line_ids":
        raise CommandError("Insight Research Line relation bounds changed unexpectedly")


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
        raise CommandError("Insight Research Line command tenant is not registered")
    if command.get("action") != ACTION:
        raise CommandError("unsupported Insight Research Line action")
    if command.get("requested_by") != "chatgpt":
        raise CommandError("requested_by must be chatgpt")
    payload = command.get("payload")
    if not isinstance(payload, dict):
        raise CommandError("payload must be an object")
    validate_payload(payload)


def load_validated_command(path: Path) -> dict[str, Any]:
    command = load_json(path)
    validate_command(command)
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
    payload = command["payload"]
    post_id = int(payload["post_id"])
    line_ids = [int(value) for value in payload["line_ids"]]
    tools = registry["actions"][ACTION]["tools"]
    client = base.McpClient(f"{base_url}/{tenant}", token)
    plan_id: str | None = None
    operation_id: str | None = None
    rollback_ok: bool | None = None
    try:
        client.initialize()
        plan = client.call(
            tools["plan"],
            {
                "postId": post_id,
                "changes": {"line_ids": line_ids},
                "reason": f"Kairoseth command {command_id}: bounded Insight Research Line relation update",
            },
        )
        plan_id = base.regex_string(plan, base.PLAN_ID_RE)
        applied = client.call(tools["apply"], {"planId": plan_id, "confirm": True})
        operation_id = base.regex_string(applied, base.OP_ID_RE)
        verification = client.call(tools["verify"], {"operationId": operation_id, "rendered": True})
        if not base.verified(verification):
            raise CommandError("Insight Research Line verification did not report verified=true")
        observed_post_id = base.first_post_id(verification, applied, plan)
        if observed_post_id is not None and observed_post_id != post_id:
            raise CommandError("verified Insight post_id does not match requested target")

        result = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "verified": True,
            "resource": {"post_id": post_id, "line_ids": line_ids, "changed_fields": ["line_ids"]},
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
            "error": {"code": "execution_failed", "message": "The bounded Insight Research Line lifecycle did not complete successfully."},
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
        raise CommandError("Insight Research Line schema action changed unexpectedly")
    line_schema = props.get("payload", {}).get("properties", {}).get("line_ids", {})
    if line_schema.get("maxItems") != MAX_LINE_IDS or line_schema.get("uniqueItems") is not True:
        raise CommandError("Insight Research Line schema bounds changed unexpectedly")

    good = {
        "version": "1",
        "command_id": "cmd-selftest-insight-lines-0001",
        "tenant": "eduardo-research",
        "action": ACTION,
        "requested_by": "chatgpt",
        "payload": {"post_id": 101, "line_ids": [201, 202]},
    }
    validate_command(good, registry)
    unlink = json.loads(json.dumps(good)); unlink["payload"]["line_ids"] = []; validate_command(unlink, registry)

    bad_cases: list[dict[str, Any]] = []
    duplicate = json.loads(json.dumps(good)); duplicate["payload"]["line_ids"] = [201, 201]; bad_cases.append(duplicate)
    invalid = json.loads(json.dumps(good)); invalid["payload"]["line_ids"] = [0]; bad_cases.append(invalid)
    too_many = json.loads(json.dumps(good)); too_many["payload"]["line_ids"] = list(range(1, 34)); bad_cases.append(too_many)
    status = json.loads(json.dumps(good)); status["payload"]["status"] = "publish"; bad_cases.append(status)
    schedule = json.loads(json.dumps(good)); schedule["payload"]["scheduled_at"] = "2030-01-01T10:00:00Z"; bad_cases.append(schedule)
    text = json.loads(json.dumps(good)); text["payload"]["content"] = "blocked"; bad_cases.append(text)
    tenant = json.loads(json.dumps(good)); tenant["tenant"] = "other-tenant"; bad_cases.append(tenant)
    for index, bad in enumerate(bad_cases):
        try:
            validate_command(bad, registry)
        except CommandError:
            continue
        raise CommandError(f"self-test: unsafe Insight Research Line case {index} did not fail closed")
    print("phase2e-insight-research-lines-runner self-test: OK")
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
