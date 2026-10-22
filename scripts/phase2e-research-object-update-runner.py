#!/usr/bin/env python3
"""Execute the bounded Phase 2E Research Software title-update command.

The first update slice is intentionally narrow: one existing Research Software
post, one title change, mandatory evidence confirmation, and the accepted
Research Object Plan -> Apply -> Verify -> Rollback lifecycle. No status, slug,
kind passthrough or arbitrary MCP tool selection is exposed.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
import re
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE_RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
LIFECYCLE_PATH = Path(__file__).with_name("research-gateway-v2-lifecycle.py")
REGISTRY_PATH = ROOT / "config" / "phase2e-research-object-update-registry.json"
SCHEMA_PATH = ROOT / "schemas" / "phase2e-research-object-update-v1.schema.json"
ACTION = "research.object.update"
KIND = "software"
OBJECT_PLAN_ID_RE = re.compile(r"^erm-object-plan-[a-f0-9]{18}-[a-f0-9]{8}$")
OBJECT_OP_ID_RE = re.compile(r"^erm-object-op-[a-f0-9]{32}$")


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"unable to load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


base = load_module("phase2d_command_runner_update_base", BASE_RUNNER)
lifecycle = load_module("research_gateway_v2_research_update_lifecycle", LIFECYCLE_PATH)

CommandError = base.CommandError
TENANT_RE = base.TENANT_RE
load_json = base.load_json
sanitized_message = base.sanitized_message
write_result = base.write_result
subprocess = base.subprocess


def validate_registry(registry: dict[str, Any]) -> None:
    if registry.get("version") != "1":
        raise CommandError("unsupported Research update registry version")
    if registry.get("tenants") != {"eduardo-research": {"enabled": True}}:
        raise CommandError("Research update tenant registry changed unexpectedly")
    actions = registry.get("actions")
    if not isinstance(actions, dict) or set(actions) != {ACTION}:
        raise CommandError("Research update registry must expose exactly one action")
    action = actions[ACTION]
    if action.get("required_capabilities") != ["research.write", "operations.rollback"]:
        raise CommandError("Research update capability contract changed unexpectedly")
    if action.get("tools") != {
        "plan": "site_research_object_update_plan",
        "apply": "site_research_object_apply",
        "verify": "site_research_object_verify",
        "rollback": "site_research_object_rollback",
    }:
        raise CommandError("Research update tool mapping changed unexpectedly")
    lifecycle.validate_tools(action["tools"])
    if action.get("verify_rendered") is not False:
        raise CommandError("Research Software title update must use semantic verification")
    if action.get("allowed_kinds") != [KIND]:
        raise CommandError("Research update kind allowlist changed unexpectedly")
    if action.get("allowed_change_fields") != ["title"]:
        raise CommandError("Research update field allowlist changed unexpectedly")
    if action.get("evidence_required") is not True:
        raise CommandError("Research update evidence gate must remain mandatory")


def validate_payload(payload: dict[str, Any]) -> None:
    if set(payload) != {"kind", "post_id", "changes", "evidence_confirmed", "evidence_reference"}:
        raise CommandError("research.object.update payload keys do not match the bounded contract")
    if payload.get("kind") != KIND:
        raise CommandError("research.object.update only permits kind=software in this slice")
    post_id = payload.get("post_id")
    if not isinstance(post_id, int) or isinstance(post_id, bool) or post_id <= 0:
        raise CommandError("research.object.update post_id must be a positive integer")
    changes = payload.get("changes")
    if not isinstance(changes, dict) or set(changes) != {"title"}:
        raise CommandError("research.object.update permits exactly one title change")
    title = changes.get("title")
    if not isinstance(title, str) or not title.strip() or len(title) > 200:
        raise CommandError("research.object.update title is invalid")
    if payload.get("evidence_confirmed") is not True:
        raise CommandError("research.object.update requires explicit evidence confirmation")
    evidence_reference = payload.get("evidence_reference")
    if (
        not isinstance(evidence_reference, str)
        or not evidence_reference.strip()
        or len(evidence_reference) > 512
        or "\n" in evidence_reference
        or "\r" in evidence_reference
    ):
        raise CommandError("research.object.update evidence_reference is invalid")


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
        raise CommandError("Research update tenant is not registered")
    if command.get("action") != ACTION:
        raise CommandError("unsupported Research update action")
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
    if not base_url.startswith("https://"):
        raise CommandError("PHASE2D_MCP_BASE_URL must use HTTPS")

    tenant = str(command["tenant"])
    action = registry["actions"][ACTION]
    tools = action["tools"]
    client = base.McpClient(f"{base_url}/{tenant}", token)
    state = lifecycle.LifecycleState()
    try:
        client.initialize()
        payload = command["payload"]
        lifecycle.run(
            client=client,
            tools=tools,
            plan_args={
                "kind": KIND,
                "postId": payload["post_id"],
                "changes": dict(payload["changes"]),
                "evidenceConfirmed": True,
                "evidenceReference": payload["evidence_reference"],
            },
            parse_plan_id=lambda value: base.regex_string(value, OBJECT_PLAN_ID_RE),
            parse_operation_id=lambda value: base.regex_string(value, OBJECT_OP_ID_RE),
            is_verified=base.verified,
            state=state,
            verify_args={"rendered": False},
            verification_error="Research Software update verification did not report verified=true",
        )
        verified_post_id = base.first_post_id(state.verification, state.applied, state.plan)
        if verified_post_id is None:
            verified_post_id = int(payload["post_id"])
        if verified_post_id != int(payload["post_id"]):
            raise CommandError("Research Software update verification returned the wrong post_id")

        result = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "verified": True,
            "resource": {
                "kind": KIND,
                "post_id": verified_post_id,
                "changed_fields": ["title"],
            },
            "correlation": state.correlation(),
        }
        path = write_result(result_dir, command_id, result)
        print(f"SUCCESS {path}")
        return 0
    except Exception as exc:  # noqa: BLE001
        lifecycle.rollback(
            client=client,
            tools=tools,
            state=state,
            is_rollback_verified=base.rollback_verified,
        )
        result = {
            "version": "1",
            "status": "failed",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "verified": False,
            "error": {
                "code": "execution_failed",
                "message": "The bounded Research Software update lifecycle did not complete successfully.",
            },
            "correlation": state.correlation(),
            "rollback": state.rollback_result(),
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
        raise CommandError("Research update schema action changed unexpectedly")
    payload_props = props.get("payload", {}).get("properties", {})
    if payload_props.get("kind", {}).get("const") != KIND:
        raise CommandError("Research update schema kind changed unexpectedly")
    changes = payload_props.get("changes", {})
    if set(changes.get("properties", {})) != {"title"} or changes.get("required") != ["title"]:
        raise CommandError("Research update schema must remain title-only")
    if payload_props.get("evidence_confirmed", {}).get("const") is not True:
        raise CommandError("Research update schema evidence gate changed unexpectedly")

    good = {
        "version": "1",
        "command_id": "cmd-selftest-research-update-0001",
        "tenant": "eduardo-research",
        "action": ACTION,
        "requested_by": "chatgpt",
        "payload": {
            "kind": KIND,
            "post_id": 123,
            "changes": {"title": "Updated bounded Research Software title"},
            "evidence_confirmed": True,
            "evidence_reference": "acceptance:phase2e:research-software:update",
        },
    }
    validate_command(good, registry)

    variants = []
    bad = json.loads(json.dumps(good)); bad["payload"]["kind"] = "project"; variants.append(bad)
    bad = json.loads(json.dumps(good)); bad["payload"]["post_id"] = 0; variants.append(bad)
    bad = json.loads(json.dumps(good)); bad["payload"]["changes"]["status"] = "publish"; variants.append(bad)
    bad = json.loads(json.dumps(good)); bad["payload"]["evidence_confirmed"] = False; variants.append(bad)
    for bad in variants:
        try:
            validate_command(bad, registry)
        except CommandError:
            pass
        else:
            raise CommandError("self-test: unsafe Research Software update did not fail closed")

    print("phase2e-research-object-update-runner self-test: OK")
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
    except (CommandError, lifecycle.LifecycleError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
