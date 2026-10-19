#!/usr/bin/env python3
"""Rollback only the exact Phase 2E Research Software create acceptance operation.

This is an acceptance-cleanup helper, not a general delete command. It reads the
sanitized successful command result, validates the fixed tenant/action/kind/command
identity, and rolls back that exact operation through the accepted Research Object
rollback lifecycle.
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
BASE = Path(__file__).with_name("phase2d-command-runner.py")
DEFAULT_BASE_URL = "https://mcp.kairoseth.com/mcp"
EXPECTED_TENANT = "eduardo-research"
EXPECTED_ACTION = "research.object.create"
EXPECTED_KIND = "software"
EXPECTED_COMMAND_ID = "cmd-phase2e-research-software-create-0001"
EXPECTED_RESULT = ROOT / "commands" / "phase2d" / "results" / f"{EXPECTED_COMMAND_ID}.json"
OBJECT_OP_RE = re.compile(r"^erm-object-op-[a-f0-9]{32}$")

spec = importlib.util.spec_from_file_location("phase2d_command_runner_restore", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)


class RestoreError(RuntimeError):
    """Fail-closed acceptance restore error."""


def load_result(path: Path) -> dict[str, Any]:
    value = base.load_json(path)
    if value.get("version") != "1" or value.get("status") != "success" or value.get("verified") is not True:
        raise RestoreError("Research acceptance result is not a verified success")
    if value.get("tenant") != EXPECTED_TENANT:
        raise RestoreError("Research acceptance result tenant is outside the cleanup contract")
    if value.get("action") != EXPECTED_ACTION or value.get("command_id") != EXPECTED_COMMAND_ID:
        raise RestoreError("Research acceptance result identity is outside the cleanup contract")
    resource = value.get("resource")
    if not isinstance(resource, dict) or resource.get("kind") != EXPECTED_KIND or resource.get("state") != "draft":
        raise RestoreError("Research acceptance resource is outside the cleanup contract")
    post_id = resource.get("post_id")
    if not isinstance(post_id, int) or isinstance(post_id, bool) or post_id <= 0:
        raise RestoreError("Research acceptance result has no valid post_id")
    correlation = value.get("correlation")
    if not isinstance(correlation, dict):
        raise RestoreError("Research acceptance result has no correlation object")
    operation_id = correlation.get("operation_id")
    if not isinstance(operation_id, str) or not OBJECT_OP_RE.fullmatch(operation_id):
        raise RestoreError("Research acceptance result has no valid operation_id")
    return value


def production_client() -> Any:
    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise RestoreError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", DEFAULT_BASE_URL).strip().rstrip("/")
    if base_url != DEFAULT_BASE_URL:
        raise RestoreError("acceptance restore only permits the production MCP base URL")
    return base.McpClient(f"{base_url}/{EXPECTED_TENANT}", token)


def write_json(path: Path, value: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def restore(result_path: Path, evidence_path: Path) -> int:
    accepted = load_result(result_path)
    resource = accepted["resource"]
    operation_id = accepted["correlation"]["operation_id"]
    client = production_client()
    try:
        client.initialize()
        rolled = client.call("site_research_object_rollback", {"operationId": operation_id, "confirm": True})
        if not base.rollback_verified(rolled):
            raise RestoreError("Research Software rollback did not verify baseline restoration")
    finally:
        client.close()

    evidence = {
        "version": "1",
        "status": "success",
        "tenant": EXPECTED_TENANT,
        "action": "research.object.acceptance.restore",
        "source_command_id": EXPECTED_COMMAND_ID,
        "operation_id": operation_id,
        "rollback_verified": True,
        "resource": {
            "kind": EXPECTED_KIND,
            "post_id": resource["post_id"],
            "restored": "absent",
        },
    }
    write_json(evidence_path, evidence)
    print(f"RESEARCH_OBJECT_ACCEPTANCE_RESTORED {evidence_path}")
    return 0


def self_test() -> int:
    import tempfile

    with tempfile.TemporaryDirectory(prefix="phase2e-research-restore-test-") as tmp:
        path = Path(tmp) / "result.json"
        good = {
            "version": "1",
            "status": "success",
            "tenant": EXPECTED_TENANT,
            "action": EXPECTED_ACTION,
            "command_id": EXPECTED_COMMAND_ID,
            "verified": True,
            "resource": {"kind": EXPECTED_KIND, "post_id": 123, "state": "draft", "language": "en", "title": "x"},
            "correlation": {"plan_id": "erm-object-plan-" + "a" * 18 + "-" + "b" * 8, "operation_id": "erm-object-op-" + "c" * 32},
        }
        path.write_text(json.dumps(good), encoding="utf-8")
        load_result(path)
        for field, value in (("command_id", "cmd-other-0001"), ("tenant", "other"), ("verified", False)):
            bad = json.loads(json.dumps(good))
            bad[field] = value
            path.write_text(json.dumps(bad), encoding="utf-8")
            try:
                load_result(path)
            except (RestoreError, base.CommandError):
                pass
            else:
                raise RestoreError(f"self-test: malformed {field} did not fail closed")
        bad = json.loads(json.dumps(good))
        bad["resource"]["kind"] = "project"
        path.write_text(json.dumps(bad), encoding="utf-8")
        try:
            load_result(path)
        except (RestoreError, base.CommandError):
            pass
        else:
            raise RestoreError("self-test: wrong resource kind did not fail closed")
    print("phase2e-research-object-acceptance-restore self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--result", type=Path, default=EXPECTED_RESULT)
    parser.add_argument("--evidence", type=Path, default=ROOT / "commands" / "phase2d" / "results" / f"{EXPECTED_COMMAND_ID}-restore.json")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            return self_test()
        return restore(args.result, args.evidence)
    except (RestoreError, base.CommandError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
