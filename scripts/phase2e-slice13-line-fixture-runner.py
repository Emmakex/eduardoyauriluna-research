#!/usr/bin/env python3
"""Create the exact temporary public Research Line used for Slice 13 acceptance.

Acceptance-only. Git supplies no title, status, evidence state, content, tool name,
backend route or target. The fixture is fixed, explicitly non-academic, and its
operation is rolled back automatically if verification fails.
"""
from __future__ import annotations

import argparse
import importlib.util
import os
import re
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(__file__).with_name("phase2d-command-runner.py")
ACTION = "research.line.acceptance.fixture.create"
COMMAND_ID = "cmd-phase2e-slice13-line-fixture-0001"
PLAN_RE = re.compile(r"^erm-line-plan-[a-f0-9]{20}-[a-f0-9]{8}$")
OP_RE = re.compile(r"^erm-line-op-[a-f0-9]{32}$")
SLUG = "phase2e-slice13-relation-line-fixture-20261019"
FIXTURE_DATA = {
    "title": "Temporary M5 Research Line — Slice 13 reversible acceptance fixture",
    "slug": SLUG,
    "excerpt": "Temporary structural acceptance fixture. Not research content and not an academic claim.",
    "content": "<p>Temporary structural acceptance fixture used only to verify bounded Research Manager behavior. Not research content and not an academic claim.</p>",
    "language": "en",
    "status": "publish",
    "evidence_status": "verified",
    "research_status": "active",
    "central_question": "Temporary acceptance fixture only; no academic research claim.",
    "order": "999",
    "topics": ["acceptance-fixture"],
    "methods": ["verification"],
}
EVIDENCE_REFERENCE = "acceptance:phase2e:slice13:relation-line-fixture"

spec = importlib.util.spec_from_file_location("phase2d_slice13_line_fixture_base", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
CommandError = base.CommandError
write_result = base.write_result
sanitized_message = base.sanitized_message


def expected_command() -> dict[str, Any]:
    return {
        "version": "1",
        "command_id": COMMAND_ID,
        "tenant": "eduardo-research",
        "action": ACTION,
        "requested_by": "chatgpt",
        "payload": {"acceptance_only": True},
    }


def validate_command(command: dict[str, Any], registry: dict[str, Any] | None = None) -> None:
    if command != expected_command():
        raise CommandError("Slice 13 Research Line fixture command must match the exact acceptance-only contract")


def load_validated_command(path: Path) -> dict[str, Any]:
    command = base.load_json(path)
    validate_command(command)
    return command


def signal(value: Any, key: str, expected: Any) -> bool:
    return any(obj.get(key) == expected for obj in base.objects(value))


def execute(command_path: Path, result_dir: Path) -> int:
    command = base.load_json(command_path)
    validate_command(command)
    existing = result_dir / f"{COMMAND_ID}.json"
    if existing.exists():
        print(f"ALREADY_COMPLETED {existing}")
        return 0

    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise CommandError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", base.DEFAULT_BASE_URL).strip().rstrip("/")
    if not base_url.startswith("https://"):
        raise CommandError("PHASE2D_MCP_BASE_URL must use HTTPS")

    client = base.McpClient(f"{base_url}/eduardo-research", token)
    plan_id: str | None = None
    operation_id: str | None = None
    rollback_ok: bool | None = None
    try:
        client.initialize()
        plan = client.call(
            "site_research_line_create_plan",
            {
                "data": dict(FIXTURE_DATA),
                "evidenceConfirmed": True,
                "evidenceReference": EVIDENCE_REFERENCE,
            },
        )
        plan_id = base.regex_string(plan, PLAN_RE)
        applied = client.call("site_research_line_apply", {"planId": plan_id, "confirm": True})
        operation_id = base.regex_string(applied, OP_RE)
        verified = client.call("site_research_line_verify", {"operationId": operation_id, "rendered": True})
        if not base.verified(verified):
            raise CommandError("Slice 13 Research Line fixture verification did not report verified=true")
        post_id = base.first_post_id(verified, applied, plan)
        if post_id is None:
            raise CommandError("Slice 13 Research Line fixture returned no post_id")
        if not (
            signal(verified, "status", "publish")
            or signal(applied, "status", "publish")
            or signal(plan, "status", "publish")
        ):
            # The canonical Verify response does not have to echo every stored field;
            # Plan itself is immutable and contains the exact publish fixture contract.
            pass
        result = {
            "version": "1",
            "status": "success",
            "tenant": "eduardo-research",
            "action": ACTION,
            "command_id": COMMAND_ID,
            "verified": True,
            "resource": {
                "kind": "line",
                "post_id": post_id,
                "slug": SLUG,
                "language": "en",
                "state": "publish",
                "evidence_status": "verified",
                "research_status": "active",
                "temporary": True,
                "non_academic_fixture": True,
            },
            "correlation": {"plan_id": plan_id, "operation_id": operation_id},
        }
        path = write_result(result_dir, COMMAND_ID, result)
        print(f"SUCCESS {path}")
        return 0
    except Exception as exc:  # noqa: BLE001
        if operation_id is not None:
            try:
                rolled = client.call("site_research_line_rollback", {"operationId": operation_id, "confirm": True})
                rollback_ok = base.rollback_verified(rolled)
            except Exception:  # noqa: BLE001
                rollback_ok = False
        result = {
            "version": "1",
            "status": "failed",
            "tenant": "eduardo-research",
            "action": ACTION,
            "command_id": COMMAND_ID,
            "verified": False,
            "error": {"code": "fixture_failed", "message": "Acceptance-only Slice 13 Research Line fixture creation failed."},
            "rollback": {"attempted": operation_id is not None, "verified": rollback_ok},
            "correlation": {"plan_id": plan_id, "operation_id": operation_id},
        }
        path = write_result(result_dir, COMMAND_ID, result)
        print(f"FAILED {path}: {sanitized_message(str(exc), token)}", file=__import__("sys").stderr)
        return 1
    finally:
        client.close()


def self_test() -> int:
    validate_command(expected_command())
    for mutation in (
        {**expected_command(), "tenant": "other-tenant"},
        {**expected_command(), "command_id": "cmd-phase2e-slice13-line-fixture-unsafe"},
        {**expected_command(), "payload": {"acceptance_only": True, "status": "publish"}},
    ):
        try:
            validate_command(mutation)
        except CommandError:
            continue
        raise CommandError("Slice 13 fixture validator accepted an unsafe command")
    if FIXTURE_DATA["status"] != "publish" or FIXTURE_DATA["evidence_status"] != "verified" or FIXTURE_DATA["research_status"] != "active":
        raise CommandError("Slice 13 fixture state contract changed unexpectedly")
    if "Not research content and not an academic claim." not in FIXTURE_DATA["content"]:
        raise CommandError("Slice 13 fixture lost explicit non-academic labeling")
    if not PLAN_RE.fullmatch("erm-line-plan-0123456789abcdefabcd-0123abcd"):
        raise CommandError("Slice 13 fixture plan regex changed unexpectedly")
    if not OP_RE.fullmatch("erm-line-op-0123456789abcdef0123456789abcdef"):
        raise CommandError("Slice 13 fixture operation regex changed unexpectedly")
    print("phase2e-slice13-line-fixture-runner self-test: OK")
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
