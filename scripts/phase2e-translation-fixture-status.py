#!/usr/bin/env python3
"""Prepare or restore the exact Phase 2E translation-pairing acceptance fixture.

This helper is intentionally acceptance-only. It can change only Insight post 97 on
tenant ``eduardo-research`` from draft to publish, through the already-accepted
Insight Plan -> Apply -> rendered Verify lifecycle. The resulting operation id is
persisted as sanitized evidence so a later restore request can roll the exact
operation back after pair/unpair acceptance completes.

It is not a general publication command surface.
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
RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
DEFAULT_BASE_URL = "https://mcp.kairoseth.com/mcp"
EXPECTED_TENANT = "eduardo-research"
EXPECTED_POST_ID = 97
EXPECTED_FROM_STATUS = "draft"
EXPECTED_TO_STATUS = "publish"
EXPECTED_REQUEST_PATH = ROOT / "ops" / "phase2e" / "translation-fixture-status.json"

spec = importlib.util.spec_from_file_location("phase2d_command_runner", RUNNER)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class FixtureStatusError(RuntimeError):
    """Fail-closed acceptance helper error."""


def load_request(path: Path) -> dict[str, Any]:
    value = runner.load_json(path)
    expected_common = {
        "version": "1",
        "tenant": EXPECTED_TENANT,
        "post_id": EXPECTED_POST_ID,
        "from_status": EXPECTED_FROM_STATUS,
        "to_status": EXPECTED_TO_STATUS,
    }
    if set(value) != set(expected_common) | {"action"}:
        raise FixtureStatusError("request keys do not match the reviewed acceptance contract")
    for key, expected in expected_common.items():
        if value.get(key) != expected:
            raise FixtureStatusError(f"request {key} is outside the reviewed acceptance contract")
    if value.get("action") not in {"prepare", "restore"}:
        raise FixtureStatusError("request action must be prepare or restore")
    return value


def production_client() -> Any:
    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise FixtureStatusError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", DEFAULT_BASE_URL).strip().rstrip("/")
    if base_url != DEFAULT_BASE_URL:
        raise FixtureStatusError("acceptance helper only permits the production MCP base URL")
    return runner.McpClient(f"{base_url}/{EXPECTED_TENANT}", token)


def write_json(path: Path, value: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )


def prepare(evidence_path: Path) -> int:
    client = production_client()
    operation_id: str | None = None
    try:
        client.initialize()
        capabilities = client.call("research_capabilities")
        scopes = []
        for obj in runner.objects(capabilities):
            candidate = obj.get("granted_scopes")
            if isinstance(candidate, list):
                scopes = [item for item in candidate if isinstance(item, str)]
                break
        if scopes and not {"insights.write", "operations.rollback"}.issubset(set(scopes)):
            raise FixtureStatusError("live principal lacks required Insight status capabilities")

        plan = client.call(
            "site_insight_update_plan",
            {
                "postId": EXPECTED_POST_ID,
                "changes": {"status": EXPECTED_TO_STATUS},
                "reason": "Phase 2E translation pairing acceptance prerequisite",
            },
        )
        plan_id = runner.regex_string(plan, runner.PLAN_ID_RE)
        applied = client.call("site_insight_apply", {"planId": plan_id, "confirm": True})
        operation_id = runner.regex_string(applied, runner.OP_ID_RE)
        verification = client.call(
            "site_insight_verify",
            {"operationId": operation_id, "rendered": True},
        )
        if not runner.verified(verification):
            raise FixtureStatusError("status transition verification did not report verified=true")

        evidence = {
            "version": "1",
            "status": "success",
            "tenant": EXPECTED_TENANT,
            "action": "translation_fixture.status.prepare",
            "resource": {
                "post_id": EXPECTED_POST_ID,
                "from_status": EXPECTED_FROM_STATUS,
                "to_status": EXPECTED_TO_STATUS,
            },
            "correlation": {"plan_id": plan_id, "operation_id": operation_id},
            "verified": True,
        }
        write_json(evidence_path, evidence)
        print(f"TRANSLATION_FIXTURE_STATUS_PREPARED {evidence_path}")
        return 0
    except Exception:
        if operation_id is not None:
            try:
                rolled = client.call(
                    "site_insight_rollback",
                    {"operationId": operation_id, "confirm": True},
                )
                if not runner.rollback_verified(rolled):
                    print("WARNING: emergency status rollback was not verified", file=sys.stderr)
            except Exception:
                print("WARNING: emergency status rollback failed", file=sys.stderr)
        raise
    finally:
        client.close()


def validated_prepare_evidence(path: Path) -> dict[str, Any]:
    value = runner.load_json(path)
    if value.get("version") != "1" or value.get("status") != "success" or value.get("verified") is not True:
        raise FixtureStatusError("prepare evidence is not a verified success")
    if value.get("tenant") != EXPECTED_TENANT or value.get("action") != "translation_fixture.status.prepare":
        raise FixtureStatusError("prepare evidence tenant/action is outside the restore contract")
    if value.get("resource") != {
        "post_id": EXPECTED_POST_ID,
        "from_status": EXPECTED_FROM_STATUS,
        "to_status": EXPECTED_TO_STATUS,
    }:
        raise FixtureStatusError("prepare evidence resource is outside the restore contract")
    correlation = value.get("correlation")
    if not isinstance(correlation, dict):
        raise FixtureStatusError("prepare evidence has no correlation object")
    plan_id = correlation.get("plan_id")
    operation_id = correlation.get("operation_id")
    if not isinstance(plan_id, str) or not runner.PLAN_ID_RE.fullmatch(plan_id):
        raise FixtureStatusError("prepare evidence has no valid plan_id")
    if not isinstance(operation_id, str) or not runner.OP_ID_RE.fullmatch(operation_id):
        raise FixtureStatusError("prepare evidence has no valid operation_id")
    return value


def restore(evidence_path: Path, restore_path: Path) -> int:
    prepared = validated_prepare_evidence(evidence_path)
    operation_id = prepared["correlation"]["operation_id"]
    client = production_client()
    try:
        client.initialize()
        rolled = client.call(
            "site_insight_rollback",
            {"operationId": operation_id, "confirm": True},
        )
        if not runner.rollback_verified(rolled):
            raise FixtureStatusError("status rollback response did not verify baseline restoration")
    finally:
        client.close()

    evidence = {
        "version": "1",
        "status": "success",
        "tenant": EXPECTED_TENANT,
        "action": "translation_fixture.status.restore",
        "operation_id": operation_id,
        "rollback_verified": True,
        "resource": {
            "post_id": EXPECTED_POST_ID,
            "restored_status": EXPECTED_FROM_STATUS,
        },
    }
    write_json(restore_path, evidence)
    print(f"TRANSLATION_FIXTURE_STATUS_RESTORED {restore_path}")
    return 0


def self_test() -> int:
    import tempfile

    with tempfile.TemporaryDirectory(prefix="phase2e-translation-status-test-") as tmp:
        root = Path(tmp)
        request = root / "request.json"
        good_request = {
            "version": "1",
            "tenant": EXPECTED_TENANT,
            "post_id": EXPECTED_POST_ID,
            "from_status": EXPECTED_FROM_STATUS,
            "to_status": EXPECTED_TO_STATUS,
            "action": "prepare",
        }
        request.write_text(json.dumps(good_request), encoding="utf-8")
        load_request(request)
        for key, bad_value in (("post_id", 98), ("tenant", "other"), ("to_status", "future"), ("action", "delete")):
            bad = dict(good_request)
            bad[key] = bad_value
            request.write_text(json.dumps(bad), encoding="utf-8")
            try:
                load_request(request)
            except (FixtureStatusError, runner.CommandError):
                pass
            else:
                raise FixtureStatusError(f"self-test: bad request field {key} did not fail closed")

        evidence = root / "evidence.json"
        good_evidence = {
            "version": "1",
            "status": "success",
            "tenant": EXPECTED_TENANT,
            "action": "translation_fixture.status.prepare",
            "resource": {
                "post_id": EXPECTED_POST_ID,
                "from_status": EXPECTED_FROM_STATUS,
                "to_status": EXPECTED_TO_STATUS,
            },
            "correlation": {
                "plan_id": "erm-remote-plan-" + "a" * 20 + "-" + "b" * 8,
                "operation_id": "erm-operation-" + "c" * 32,
            },
            "verified": True,
        }
        evidence.write_text(json.dumps(good_evidence), encoding="utf-8")
        validated_prepare_evidence(evidence)
        bad = json.loads(json.dumps(good_evidence))
        bad["resource"]["post_id"] = 98
        evidence.write_text(json.dumps(bad), encoding="utf-8")
        try:
            validated_prepare_evidence(evidence)
        except (FixtureStatusError, runner.CommandError):
            pass
        else:
            raise FixtureStatusError("self-test: malformed prepare evidence did not fail closed")

    print("phase2e-translation-fixture-status self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--request", type=Path, default=EXPECTED_REQUEST_PATH)
    parser.add_argument("--evidence", type=Path)
    parser.add_argument("--restore-evidence", type=Path)
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            return self_test()
        if args.evidence is None or args.restore_evidence is None:
            parser.error("--evidence and --restore-evidence are required")
        request = load_request(args.request)
        if request["action"] == "prepare":
            return prepare(args.evidence)
        return restore(args.evidence, args.restore_evidence)
    except (FixtureStatusError, runner.CommandError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
