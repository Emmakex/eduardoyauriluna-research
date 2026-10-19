#!/usr/bin/env python3
"""Rollback only the exact Phase 2E Research Line create acceptance operation."""
from __future__ import annotations

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
EXPECTED_ACTION = "research.line.create"
EXPECTED_COMMAND_ID = "cmd-phase2e-research-line-create-0001"
EXPECTED_POST_ID = 102
EXPECTED_RESULT = ROOT / "commands" / "phase2d" / "results" / f"{EXPECTED_COMMAND_ID}.json"
LINE_OP_RE = re.compile(r"^erm-line-op-[a-f0-9]{32}$")

spec = importlib.util.spec_from_file_location("phase2d_command_runner_line_create_restore", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)


class RestoreError(RuntimeError):
    pass


def load_result(path: Path) -> dict[str, Any]:
    value = base.load_json(path)
    if value.get("version") != "1" or value.get("status") != "success" or value.get("verified") is not True:
        raise RestoreError("Research Line create result is not a verified success")
    if value.get("tenant") != EXPECTED_TENANT or value.get("action") != EXPECTED_ACTION or value.get("command_id") != EXPECTED_COMMAND_ID:
        raise RestoreError("Research Line create result identity is outside the cleanup contract")
    resource = value.get("resource")
    if not isinstance(resource, dict) or resource.get("kind") != "line" or resource.get("post_id") != EXPECTED_POST_ID:
        raise RestoreError("Research Line create target is outside the cleanup contract")
    if resource.get("state") != "draft":
        raise RestoreError("Research Line acceptance fixture is not the reviewed draft")
    correlation = value.get("correlation")
    if not isinstance(correlation, dict):
        raise RestoreError("Research Line create result has no correlation object")
    operation_id = correlation.get("operation_id")
    if not isinstance(operation_id, str) or not LINE_OP_RE.fullmatch(operation_id):
        raise RestoreError("Research Line create result has no valid operation_id")
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
    operation_id = accepted["correlation"]["operation_id"]
    client = production_client()
    try:
        client.initialize()
        rolled = client.call("site_research_line_rollback", {"operationId": operation_id, "confirm": True})
        if not base.rollback_verified(rolled):
            raise RestoreError("Research Line create rollback did not verify baseline restoration")
    finally:
        client.close()
    evidence = {
        "version": "1",
        "status": "success",
        "tenant": EXPECTED_TENANT,
        "action": "research.line.create.acceptance.restore",
        "source_command_id": EXPECTED_COMMAND_ID,
        "operation_id": operation_id,
        "rollback_verified": True,
        "resource": {"kind": "line", "post_id": EXPECTED_POST_ID, "restored": "absent"},
    }
    write_json(evidence_path, evidence)
    print(f"RESEARCH_LINE_CREATE_ACCEPTANCE_RESTORED {evidence_path}")
    return 0


def self_test() -> int:
    import tempfile
    good = {
        "version": "1", "status": "success", "tenant": EXPECTED_TENANT, "action": EXPECTED_ACTION,
        "command_id": EXPECTED_COMMAND_ID, "verified": True,
        "resource": {"kind": "line", "post_id": EXPECTED_POST_ID, "state": "draft", "language": "en", "title": "fixture"},
        "correlation": {"plan_id": "erm-line-plan-" + "a" * 20 + "-" + "b" * 8, "operation_id": "erm-line-op-" + "c" * 32},
    }
    with tempfile.TemporaryDirectory(prefix="phase2e-line-create-restore-") as tmp:
        path = Path(tmp) / "result.json"
        path.write_text(json.dumps(good), encoding="utf-8")
        load_result(path)
        for mutate in (
            lambda x: x.update(command_id="cmd-other-0001"),
            lambda x: x["resource"].update(post_id=999),
            lambda x: x["resource"].update(state="publish"),
            lambda x: x.update(verified=False),
        ):
            bad = json.loads(json.dumps(good)); mutate(bad); path.write_text(json.dumps(bad), encoding="utf-8")
            try:
                load_result(path)
            except (RestoreError, base.CommandError):
                pass
            else:
                raise RestoreError("self-test: malformed Line create restore input did not fail closed")
    print("phase2e-research-line-create-acceptance-restore self-test: OK")
    return 0


def main() -> int:
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--result", type=Path, default=EXPECTED_RESULT)
    parser.add_argument("--evidence", type=Path, default=ROOT / "commands" / "phase2d" / "results" / f"{EXPECTED_COMMAND_ID}-restore.json")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        return self_test() if args.self_test else restore(args.result, args.evidence)
    except (RestoreError, base.CommandError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
