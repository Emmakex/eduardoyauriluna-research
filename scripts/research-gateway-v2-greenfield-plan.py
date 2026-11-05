#!/usr/bin/env python3
"""Bounded Site Architect Greenfield canonical Plan/Preview; no public Apply.

The canonical Blueprint and Plan authority live in WordPress. The MCP exposes
only the stored Plan summary. Gateway commands never accept a new Blueprint,
WordPress route, MCP tool name, plan ID to Apply, or arbitrary mutation payload.
"""
from __future__ import annotations

import argparse
import importlib.util
import os
import re
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE_RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
ACTION = "site.blueprint.preview"
TOOL = "site_greenfield_canonical_plan"
PLAN_ID_RE = re.compile(r"^erm-remote-plan-[a-f0-9]{20}-[a-f0-9]{8}$")
SHA_RE = re.compile(r"^[a-f0-9]{64}$")
EXPIRES_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\+00:00|Z)$")
PHASES = ("bootstrap", "pairing", "hydration", "relations")

spec = importlib.util.spec_from_file_location("site_architect_phase2d_base", BASE_RUNNER)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load the existing Gateway transport")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
CommandError = base.CommandError
load_json = base.load_json
write_result = base.write_result


def validate_command(command: dict[str, Any]) -> None:
    if set(command) != {"version", "command_id", "tenant", "action", "requested_by", "payload"}:
        raise CommandError("Greenfield Plan command has unsupported fields")
    if (
        command.get("version") != "1"
        or command.get("tenant") != "eduardo-research"
        or command.get("requested_by") != "chatgpt"
        or command.get("action") != ACTION
        or not isinstance(command.get("command_id"), str)
        or base.COMMAND_ID_RE.fullmatch(command["command_id"]) is None
        or command.get("payload") != {}
    ):
        raise CommandError("Greenfield Plan request must target the closed canonical Blueprint with empty payload")


def load_validated_command(path: Path) -> dict[str, Any]:
    command = load_json(path)
    validate_command(command)
    return command


def safe_plan(value: Any) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise CommandError("Greenfield Plan tool returned no object")
    if (
        value.get("product") != "Site Architect"
        or value.get("track") != "Greenfield"
        or value.get("operation") != "greenfield-canonical"
        or value.get("status") != "planned"
        or type(value.get("confirmation_required")) is not bool
        or value["confirmation_required"] is not True
        or type(value.get("apply_allowed")) is not bool
        or value.get("public_site_apply") != "not_executed"
        or value.get("stored_plan_created") is not True
        or not isinstance(value.get("plan_id"), str)
        or PLAN_ID_RE.fullmatch(value["plan_id"]) is None
        or not isinstance(value.get("expires_at"), str)
        or EXPIRES_RE.fullmatch(value["expires_at"]) is None
        or any(not isinstance(value.get(field), str) or SHA_RE.fullmatch(value[field]) is None
               for field in ("source_revision", "blueprint_sha256", "contract_fingerprint"))
    ):
        raise CommandError("Greenfield Plan summary is not the expected stored Plan authority")
    phases = value.get("phases")
    if not isinstance(phases, dict) or set(phases) != set(PHASES):
        raise CommandError("Greenfield Preview has incomplete pipeline phases")
    phase_summary = {}
    for phase in PHASES:
        item = phases[phase]
        if not isinstance(item, dict):
            raise CommandError("Greenfield Preview phase is invalid")
        status = item.get("status")
        allowed_count = lambda n: n is None or (type(n) is int and 0 <= n <= 100000)
        if (
            not isinstance(status, str)
            or re.fullmatch(r"[a-z][a-z0-9-]{0,63}", status) is None
            or item.get("apply_allowed") not in (None, True, False)
            or not allowed_count(item.get("operation_count"))
            or not allowed_count(item.get("change_count"))
        ):
            raise CommandError("Greenfield Preview phase contains malformed values")
        phase_summary[phase] = {
            "status": status,
            "apply_allowed": item.get("apply_allowed"),
            "operation_count": item.get("operation_count"),
            "change_count": item.get("change_count"),
        }
    # Build a strict allowlisted result, never echo arbitrary MCP/WordPress content.
    return {
        "plan_id": value["plan_id"],
        "operation": "greenfield-canonical",
        "status": "planned",
        "apply_allowed": value["apply_allowed"],
        "confirmation_required": True,
        "blueprint_sha256": value["blueprint_sha256"],
        "source_revision": value["source_revision"],
        "contract_fingerprint": value["contract_fingerprint"],
        "expires_at": value["expires_at"],
        "phases": phase_summary,
        "public_site_apply": "not_executed",
        "launch_approval": "not_granted",
    }


def execute(command_path: Path, result_dir: Path) -> int:
    command = load_validated_command(command_path)
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
        raise CommandError("Greenfield Plan only permits the existing production MCP URL")
    tenant = str(command["tenant"])
    client = base.McpClient(f"{base_url}/{tenant}", token)
    try:
        client.initialize()
        client.call("research_capabilities")
        plan = safe_plan(client.call(TOOL))
        result = {
            "version": "1", "status": "success", "tenant": tenant,
            "action": ACTION, "command_id": command_id, "read_only": False,
            "stored_plan_created": True, "public_site_apply": "not_executed",
            "plan": plan,
        }
        path = write_result(result_dir, command_id, result)
        print(f"GREENFIELD_PLAN_CREATED {path}")
        return 0
    except Exception:
        # No transport/credential/response values are ever persisted in failures.
        result = {
            "version": "1", "status": "failed", "tenant": tenant,
            "action": ACTION, "command_id": command_id, "read_only": False,
            "error": {
                "code": "greenfield_plan_failed",
                "message": "Canonical Greenfield Plan was not verified; no Apply was requested.",
            },
            "public_site_apply": "not_executed",
        }
        path = write_result(result_dir, command_id, result)
        print(f"GREENFIELD_PLAN_FAILED {path}", file=sys.stderr)
        return 1
    finally:
        client.close()


def self_test() -> int:
    command = {
        "version": "1", "command_id": "cmd-selftest-greenfield-plan-0001",
        "tenant": "eduardo-research", "action": ACTION,
        "requested_by": "chatgpt", "payload": {},
    }
    validate_command(command)
    for payload in ({"blueprint": {}}, {"apply": True}, {"tool": "arbitrary"}, {"url": "https://example.test"}):
        try:
            validate_command({**command, "payload": payload})
        except CommandError:
            pass
        else:
            raise AssertionError("Greenfield Plan accepted a caller-selected payload")
    fixture = {
        "product": "Site Architect", "track": "Greenfield",
        "operation": "greenfield-canonical", "status": "planned",
        "confirmation_required": True, "apply_allowed": True,
        "stored_plan_created": True, "public_site_apply": "not_executed",
        "plan_id": "erm-remote-plan-" + "a" * 20 + "-1234abcd",
        "source_revision": "b" * 64, "blueprint_sha256": "c" * 64,
        "contract_fingerprint": "d" * 64, "expires_at": "2026-11-05T15:00:00+00:00",
        "phases": {name: {
            "status": "steady", "apply_allowed": True,
            "operation_count": 0, "change_count": 0,
            "private_content": "NEVER_PERSIST",
        } for name in PHASES},
        "credentials": "NEVER_PERSIST",
    }
    approved = safe_plan(fixture)
    assert approved["plan_id"] == fixture["plan_id"]
    assert approved["public_site_apply"] == "not_executed"
    assert approved["launch_approval"] == "not_granted"
    assert "NEVER_PERSIST" not in str(approved)
    for tampered in (
        {**fixture, "operation": "page-slots-update"},
        {**fixture, "public_site_apply": "applied"},
        {**fixture, "phases": {"bootstrap": {}}},
        {**fixture, "plan_id": "../unsafe"},
    ):
        try:
            safe_plan(tampered)
        except CommandError:
            pass
        else:
            raise AssertionError("Greenfield Plan accepted tampered MCP response")
    print("PASS: Site Architect canonical Plan is bounded and never Apply")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", nargs="?", type=Path)
    parser.add_argument("--result-dir", type=Path, default=ROOT / "commands" / "gateway-v2" / "results")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            return self_test()
        if args.command is None:
            parser.error("command is required unless --self-test")
        return execute(args.command, args.result_dir)
    except CommandError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
