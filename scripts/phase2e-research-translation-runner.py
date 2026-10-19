#!/usr/bin/env python3
"""Execute bounded structured Research translation pair/unpair through production MCP.

This surface covers Research Lines, Outputs, Projects, Software and Datasets through
the already-accepted structured translation lifecycle. Insight translations remain on
their separate dedicated commands. Publication state and arbitrary MCP passthrough are
not exposed here.
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
REGISTRY_PATH = ROOT / "config" / "phase2e-research-translation-command-registry.json"
SCHEMA_PATH = ROOT / "schemas" / "phase2e-research-translation-v1.schema.json"
PAIR_ACTION = "research.translation.pair"
UNPAIR_ACTION = "research.translation.unpair"
ACTIONS = {PAIR_ACTION, UNPAIR_ACTION}
PLAN_ID_RE = re.compile(r"^erm-translation-plan-[a-f0-9]{18}-[a-f0-9]{8}$")
OP_ID_RE = re.compile(r"^erm-translation-op-[a-f0-9]{32}$")

spec = importlib.util.spec_from_file_location("phase2d_command_runner_research_translation_base", BASE_RUNNER)
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


def validate_evidence(payload: dict[str, Any]) -> None:
    if "evidence_confirmed" in payload and not isinstance(payload["evidence_confirmed"], bool):
        raise CommandError("payload.evidence_confirmed must be boolean")
    if "evidence_reference" in payload:
        ref = payload["evidence_reference"]
        if not isinstance(ref, str) or len(ref) > 512:
            raise CommandError("payload.evidence_reference is invalid")
    if payload.get("evidence_confirmed") is True and not str(payload.get("evidence_reference", "")).strip():
        raise CommandError("evidence_confirmed=true requires a non-empty evidence_reference")


def validate_payload(action: str, payload: dict[str, Any]) -> None:
    optional = {"evidence_confirmed", "evidence_reference"}
    if action == PAIR_ACTION:
        required = {"first_id", "second_id"}
        if set(payload) - (required | optional) or not required.issubset(payload):
            raise CommandError("Research translation pair payload must contain first_id, second_id and optional evidence context only")
        if not valid_id(payload.get("first_id")) or not valid_id(payload.get("second_id")):
            raise CommandError("translation pair IDs must be positive safe integers")
        if payload["first_id"] == payload["second_id"]:
            raise CommandError("translation pair requires two distinct IDs")
    elif action == UNPAIR_ACTION:
        required = {"post_id"}
        if set(payload) - (required | optional) or not required.issubset(payload):
            raise CommandError("Research translation unpair payload must contain post_id and optional evidence context only")
        if not valid_id(payload.get("post_id")):
            raise CommandError("translation unpair post_id must be a positive safe integer")
    else:
        raise CommandError("unsupported Research translation action")
    validate_evidence(payload)


def validate_registry(registry: dict[str, Any]) -> None:
    if registry.get("version") != "1":
        raise CommandError("unsupported Research translation registry version")
    if registry.get("tenants") != {"eduardo-research": {"enabled": True}}:
        raise CommandError("Research translation tenant registry changed unexpectedly")
    actions = registry.get("actions")
    if not isinstance(actions, dict) or set(actions) != ACTIONS:
        raise CommandError("Research translation registry action set changed unexpectedly")
    for action, plan in ((PAIR_ACTION, "site_translation_pair_plan"), (UNPAIR_ACTION, "site_translation_unpair_plan")):
        cfg = actions[action]
        if cfg.get("required_capabilities") != ["translations.write", "operations.rollback"]:
            raise CommandError("Research translation capability contract changed unexpectedly")
        if cfg.get("tools") != {
            "plan": plan,
            "apply": "site_translation_apply",
            "verify": "site_translation_verify",
            "rollback": "site_translation_rollback",
        }:
            raise CommandError("Research translation tool mapping changed unexpectedly")
        if cfg.get("verify_rendered") is not True:
            raise CommandError("Research translation must require rendered verification")


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
        raise CommandError("Research translation command tenant is not registered")
    action = command.get("action")
    if action not in ACTIONS:
        raise CommandError("unsupported Research translation action")
    if command.get("requested_by") != "chatgpt":
        raise CommandError("requested_by must be chatgpt")
    payload = command.get("payload")
    if not isinstance(payload, dict):
        raise CommandError("payload must be an object")
    validate_payload(str(action), payload)


def load_validated_command(path: Path) -> dict[str, Any]:
    command = load_json(path)
    validate_command(command)
    return command


def evidence_args(payload: dict[str, Any]) -> dict[str, Any]:
    args: dict[str, Any] = {}
    if "evidence_confirmed" in payload:
        args["evidenceConfirmed"] = payload["evidence_confirmed"]
    if "evidence_reference" in payload:
        args["evidenceReference"] = payload["evidence_reference"]
    return args


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
    action = str(command["action"])
    payload = command["payload"]
    tools = registry["actions"][action]["tools"]
    client = base.McpClient(f"{base_url}/{tenant}", token)
    plan_id: str | None = None
    operation_id: str | None = None
    rollback_ok: bool | None = None
    try:
        client.initialize()
        if action == PAIR_ACTION:
            plan_args = {"firstId": int(payload["first_id"]), "secondId": int(payload["second_id"]), **evidence_args(payload)}
            resource = {"first_id": int(payload["first_id"]), "second_id": int(payload["second_id"])}
        else:
            plan_args = {"postId": int(payload["post_id"]), **evidence_args(payload)}
            resource = {"post_id": int(payload["post_id"])}
        plan = client.call(tools["plan"], plan_args)
        plan_id = base.regex_string(plan, PLAN_ID_RE)
        applied = client.call(tools["apply"], {"planId": plan_id, "confirm": True})
        operation_id = base.regex_string(applied, OP_ID_RE)
        verification = client.call(tools["verify"], {"operationId": operation_id, "rendered": True})
        if not base.verified(verification):
            raise CommandError("Research translation verification did not report verified=true")
        result = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": action,
            "command_id": command_id,
            "verified": True,
            "resource": resource,
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
            "action": action,
            "command_id": command_id,
            "verified": False,
            "error": {"code": "execution_failed", "message": "The bounded Research translation lifecycle did not complete successfully."},
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
    if schema.get("properties", {}).get("action", {}).get("enum") != [PAIR_ACTION, UNPAIR_ACTION]:
        raise CommandError("Research translation schema actions changed unexpectedly")
    pair = {"version":"1","command_id":"cmd-selftest-research-pair-0001","tenant":"eduardo-research","action":PAIR_ACTION,"requested_by":"chatgpt","payload":{"first_id":101,"second_id":102}}
    unpair = {"version":"1","command_id":"cmd-selftest-research-unpair-0001","tenant":"eduardo-research","action":UNPAIR_ACTION,"requested_by":"chatgpt","payload":{"post_id":101}}
    validate_command(pair, registry); validate_command(unpair, registry)
    evidence = json.loads(json.dumps(pair)); evidence["payload"].update({"evidence_confirmed":True,"evidence_reference":"acceptance fixture"}); validate_command(evidence, registry)
    bad_cases: list[dict[str, Any]] = []
    same = json.loads(json.dumps(pair)); same["payload"]["second_id"] = 101; bad_cases.append(same)
    zero = json.loads(json.dumps(unpair)); zero["payload"]["post_id"] = 0; bad_cases.append(zero)
    status = json.loads(json.dumps(pair)); status["payload"]["status"] = "publish"; bad_cases.append(status)
    tool = json.loads(json.dumps(pair)); tool["payload"]["tool"] = "site_translation_apply"; bad_cases.append(tool)
    backend = json.loads(json.dumps(pair)); backend["payload"]["backend_url"] = "https://example.invalid"; bad_cases.append(backend)
    missing_ref = json.loads(json.dumps(pair)); missing_ref["payload"]["evidence_confirmed"] = True; bad_cases.append(missing_ref)
    insight_hint = json.loads(json.dumps(pair)); insight_hint["payload"]["post_type"] = "insight"; bad_cases.append(insight_hint)
    tenant = json.loads(json.dumps(pair)); tenant["tenant"] = "other-tenant"; bad_cases.append(tenant)
    for index, bad in enumerate(bad_cases):
        try:
            validate_command(bad, registry)
        except CommandError:
            continue
        raise CommandError(f"self-test: unsafe Research translation case {index} did not fail closed")
    print("phase2e-research-translation-runner self-test: OK")
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
