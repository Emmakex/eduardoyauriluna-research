#!/usr/bin/env python3
"""Acceptance-only published Research Software fixtures for Phase 2E Slice 14.

This helper exists only to prove the permanent bounded Research translation gateway
against reversible production fixtures. It can create exactly two temporary,
non-academic Research Software posts (EN/ES) in publish state and later roll back
those exact create operations. It does not expose publication as a permanent command.
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
DEFAULT_BASE_URL = "https://mcp.kairoseth.com/mcp"
TENANT = "eduardo-research"
REQUEST_PATH = ROOT / "ops" / "phase2e" / "slice14-research-translation-fixtures.json"
PREPARE_EVIDENCE = ROOT / "commands" / "phase2d" / "results" / "cmd-phase2e-slice14-fixtures-prepare-0001.json"
CLEANUP_EVIDENCE = ROOT / "commands" / "phase2d" / "results" / "cmd-phase2e-slice14-fixtures-cleanup-0001.json"
PLAN_RE = re.compile(r"^erm-object-plan-[a-f0-9]{18}-[a-f0-9]{8}$")
OP_RE = re.compile(r"^erm-object-op-[a-f0-9]{32}$")
EVIDENCE_REF = "Phase 2E Slice 14 reversible structured Research translation acceptance fixture"

spec = importlib.util.spec_from_file_location("phase2d_command_runner_slice14_fixture_base", BASE_RUNNER)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)


class FixtureError(RuntimeError):
    pass


def write_json(path: Path, value: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def load_request(path: Path) -> dict[str, Any]:
    value = base.load_json(path)
    if set(value) != {"version", "tenant", "action"}:
        raise FixtureError("request keys do not match the reviewed Slice 14 fixture contract")
    if value.get("version") != "1" or value.get("tenant") != TENANT:
        raise FixtureError("request version/tenant is outside the reviewed Slice 14 fixture contract")
    if value.get("action") not in {"prepare", "cleanup"}:
        raise FixtureError("request action must be prepare or cleanup")
    return value


def production_client() -> Any:
    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise FixtureError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", DEFAULT_BASE_URL).strip().rstrip("/")
    if base_url != DEFAULT_BASE_URL:
        raise FixtureError("fixture helper only permits the production MCP base URL")
    return base.McpClient(f"{base_url}/{TENANT}", token)


def fixture_data(language: str) -> dict[str, Any]:
    if language not in {"en", "es"}:
        raise FixtureError("unsupported fixture language")
    label = "EN" if language == "en" else "ES"
    return {
        "title": f"Temporary Slice 14 Translation Software {label} — reversible acceptance fixture",
        "excerpt": "Temporary non-academic fixture used only to verify the structured Research translation gateway.",
        "content": "This temporary production fixture is not a research claim and will be removed after Slice 14 acceptance.",
        "language": language,
        "status": "publish",
    }


def create_fixture(client: Any, language: str) -> dict[str, Any]:
    data = fixture_data(language)
    plan = client.call(
        "site_research_object_create_plan",
        {
            "kind": "software",
            "data": data,
            "evidenceConfirmed": True,
            "evidenceReference": EVIDENCE_REF,
        },
    )
    plan_id = base.regex_string(plan, PLAN_RE)
    applied = client.call("site_research_object_apply", {"planId": plan_id, "confirm": True})
    operation_id = base.regex_string(applied, OP_RE)
    verification = client.call("site_research_object_verify", {"operationId": operation_id, "rendered": True})
    if not base.verified(verification):
        raise FixtureError(f"{language} fixture verification did not report verified=true")
    post_id = base.first_post_id(verification, applied, plan)
    if post_id is None:
        raise FixtureError(f"{language} fixture did not return a post_id")
    return {
        "language": language,
        "post_id": post_id,
        "state": "publish",
        "plan_id": plan_id,
        "operation_id": operation_id,
    }


def validate_prepare_evidence(path: Path) -> dict[str, Any]:
    value = base.load_json(path)
    if value.get("version") != "1" or value.get("status") != "success" or value.get("tenant") != TENANT:
        raise FixtureError("prepare evidence is not a successful Slice 14 fixture record")
    if value.get("action") != "slice14.research_translation.fixtures.prepare" or value.get("verified") is not True:
        raise FixtureError("prepare evidence action/verification is invalid")
    fixtures = value.get("fixtures")
    if not isinstance(fixtures, list) or len(fixtures) != 2:
        raise FixtureError("prepare evidence must contain exactly two fixtures")
    seen = set()
    for fixture in fixtures:
        if not isinstance(fixture, dict) or set(fixture) != {"language", "post_id", "state", "plan_id", "operation_id"}:
            raise FixtureError("fixture evidence shape is invalid")
        language = fixture.get("language")
        if language not in {"en", "es"} or language in seen:
            raise FixtureError("fixture languages must be exactly EN/ES")
        seen.add(language)
        post_id = fixture.get("post_id")
        if not isinstance(post_id, int) or isinstance(post_id, bool) or post_id <= 0:
            raise FixtureError("fixture post_id is invalid")
        if fixture.get("state") != "publish":
            raise FixtureError("fixture state must be publish")
        if not isinstance(fixture.get("plan_id"), str) or not PLAN_RE.fullmatch(fixture["plan_id"]):
            raise FixtureError("fixture plan_id is invalid")
        if not isinstance(fixture.get("operation_id"), str) or not OP_RE.fullmatch(fixture["operation_id"]):
            raise FixtureError("fixture operation_id is invalid")
    if seen != {"en", "es"} or fixtures[0]["post_id"] == fixtures[1]["post_id"]:
        raise FixtureError("fixture identity set is invalid")
    return value


def prepare(path: Path) -> int:
    if path.exists():
        validate_prepare_evidence(path)
        print(f"SLICE14_FIXTURES_ALREADY_PREPARED {path}")
        return 0
    client = production_client()
    created: list[dict[str, Any]] = []
    try:
        client.initialize()
        for language in ("en", "es"):
            created.append(create_fixture(client, language))
        evidence = {
            "version": "1",
            "status": "success",
            "tenant": TENANT,
            "action": "slice14.research_translation.fixtures.prepare",
            "verified": True,
            "fixtures": created,
        }
        write_json(path, evidence)
        print(f"SLICE14_FIXTURES_PREPARED {path}")
        return 0
    except Exception:
        for fixture in reversed(created):
            try:
                rolled = client.call("site_research_object_rollback", {"operationId": fixture["operation_id"], "confirm": True})
                if not base.rollback_verified(rolled):
                    print(f"WARNING: emergency rollback was not verified for fixture {fixture['post_id']}", file=sys.stderr)
            except Exception:
                print(f"WARNING: emergency rollback failed for fixture {fixture['post_id']}", file=sys.stderr)
        raise
    finally:
        client.close()


def cleanup(prepare_path: Path, cleanup_path: Path) -> int:
    prepared = validate_prepare_evidence(prepare_path)
    if cleanup_path.exists():
        value = base.load_json(cleanup_path)
        if value.get("status") != "success" or value.get("action") != "slice14.research_translation.fixtures.cleanup" or value.get("rollback_verified") is not True:
            raise FixtureError("existing cleanup evidence is invalid")
        print(f"SLICE14_FIXTURES_ALREADY_CLEAN {cleanup_path}")
        return 0
    client = production_client()
    cleaned: list[dict[str, Any]] = []
    try:
        client.initialize()
        for fixture in reversed(prepared["fixtures"]):
            rolled = client.call("site_research_object_rollback", {"operationId": fixture["operation_id"], "confirm": True})
            if not base.rollback_verified(rolled):
                raise FixtureError(f"rollback was not verified for fixture {fixture['post_id']}")
            cleaned.append({"language": fixture["language"], "post_id": fixture["post_id"], "operation_id": fixture["operation_id"], "restored": "absent"})
    finally:
        client.close()
    evidence = {
        "version": "1",
        "status": "success",
        "tenant": TENANT,
        "action": "slice14.research_translation.fixtures.cleanup",
        "rollback_verified": True,
        "fixtures": cleaned,
    }
    write_json(cleanup_path, evidence)
    print(f"SLICE14_FIXTURES_CLEANED {cleanup_path}")
    return 0


def self_test() -> int:
    import tempfile
    with tempfile.TemporaryDirectory(prefix="phase2e-slice14-fixtures-") as tmp:
        request = Path(tmp) / "request.json"
        good = {"version": "1", "tenant": TENANT, "action": "prepare"}
        request.write_text(json.dumps(good), encoding="utf-8")
        load_request(request)
        for key, value in (("tenant", "other"), ("action", "delete"), ("version", "2")):
            bad = dict(good); bad[key] = value
            request.write_text(json.dumps(bad), encoding="utf-8")
            try:
                load_request(request)
            except (FixtureError, base.CommandError):
                pass
            else:
                raise FixtureError(f"self-test: unsafe {key} request did not fail closed")
        if fixture_data("en")["status"] != "publish" or fixture_data("es")["status"] != "publish":
            raise FixtureError("self-test: fixture publication contract changed")
    print("phase2e-slice14-research-translation-fixtures self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--request", type=Path, default=REQUEST_PATH)
    parser.add_argument("--prepare-evidence", type=Path, default=PREPARE_EVIDENCE)
    parser.add_argument("--cleanup-evidence", type=Path, default=CLEANUP_EVIDENCE)
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            return self_test()
        request = load_request(args.request)
        if request["action"] == "prepare":
            return prepare(args.prepare_evidence)
        return cleanup(args.prepare_evidence, args.cleanup_evidence)
    except (FixtureError, base.CommandError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
