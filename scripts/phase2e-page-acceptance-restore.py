#!/usr/bin/env python3
"""Restore the exact baseline for the bounded Phase 2E Page acceptance operation.

This is an acceptance-only helper, not a general rollback command surface. It reads
one sanitized command result, requires the exact reviewed Page target and verified
operation shape, then invokes only `site_page_slots_rollback` through the existing
production MCP bearer. No credential or slot value is persisted.
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
RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
DEFAULT_BASE_URL = "https://mcp.kairoseth.com/mcp"
EXPECTED_TENANT = "eduardo-research"
EXPECTED_ACTION = "page.slot.update"
EXPECTED_KEY = "contact"
EXPECTED_LANGUAGE = "en"
EXPECTED_SLOT = "eyebrow"
OP_ID_RE = re.compile(r"^erm-operation-[a-f0-9]{32}$")

spec = importlib.util.spec_from_file_location("phase2d_command_runner", RUNNER)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class RestoreError(RuntimeError):
    pass


def load_result(path: Path) -> dict[str, Any]:
    value = runner.load_json(path)
    if value.get("status") != "success" or value.get("verified") is not True:
        raise RestoreError("acceptance result is not a verified success")
    if value.get("tenant") != EXPECTED_TENANT or value.get("action") != EXPECTED_ACTION:
        raise RestoreError("acceptance result tenant/action is outside the restore contract")
    resource = value.get("resource")
    if not isinstance(resource, dict):
        raise RestoreError("acceptance result has no bounded Page resource")
    if resource != {
        "key": EXPECTED_KEY,
        "language": EXPECTED_LANGUAGE,
        "slot": EXPECTED_SLOT,
    }:
        raise RestoreError("acceptance result target is outside the restore contract")
    correlation = value.get("correlation")
    if not isinstance(correlation, dict):
        raise RestoreError("acceptance result has no correlation data")
    operation_id = correlation.get("operation_id")
    if not isinstance(operation_id, str) or not OP_ID_RE.fullmatch(operation_id):
        raise RestoreError("acceptance result has no valid operation_id")
    command_id = value.get("command_id")
    if not isinstance(command_id, str) or not runner.COMMAND_ID_RE.fullmatch(command_id):
        raise RestoreError("acceptance result has no valid command_id")
    return value


def restore(result_path: Path, evidence_path: Path) -> int:
    result = load_result(result_path)
    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise RestoreError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", DEFAULT_BASE_URL).strip().rstrip("/")
    if base_url != DEFAULT_BASE_URL:
        raise RestoreError("acceptance restore only permits the production MCP base URL")

    client = runner.McpClient(f"{base_url}/{EXPECTED_TENANT}", token)
    try:
        client.initialize()
        rolled = client.call(
            "site_page_slots_rollback",
            {"operationId": result["correlation"]["operation_id"], "confirm": True},
        )
        if not runner.rollback_verified(rolled):
            raise RestoreError("Page rollback response did not verify baseline restoration")
    finally:
        client.close()

    evidence = {
        "version": "1",
        "status": "success",
        "command_id": result["command_id"],
        "tenant": EXPECTED_TENANT,
        "action": "page.slot.acceptance_restore",
        "operation_id": result["correlation"]["operation_id"],
        "rollback_verified": True,
        "resource": {
            "key": EXPECTED_KEY,
            "language": EXPECTED_LANGUAGE,
            "slot": EXPECTED_SLOT,
        },
    }
    evidence_path.parent.mkdir(parents=True, exist_ok=True)
    evidence_path.write_text(
        json.dumps(evidence, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    print(f"PAGE_ACCEPTANCE_RESTORE_OK {evidence_path}")
    return 0


def self_test() -> int:
    import tempfile

    with tempfile.TemporaryDirectory(prefix="phase2e-page-restore-test-") as tmp:
        path = Path(tmp) / "result.json"
        good = {
            "version": "1",
            "status": "success",
            "verified": True,
            "tenant": EXPECTED_TENANT,
            "action": EXPECTED_ACTION,
            "command_id": "cmd-phase2e-page-slot-0001",
            "correlation": {"operation_id": "erm-operation-" + "a" * 32},
            "resource": {"key": EXPECTED_KEY, "language": EXPECTED_LANGUAGE, "slot": EXPECTED_SLOT},
        }
        path.write_text(json.dumps(good), encoding="utf-8")
        load_result(path)
        for mutation in (
            lambda value: value.update({"tenant": "other-tenant"}),
            lambda value: value["resource"].update({"slot": "hero"}),
            lambda value: value["correlation"].update({"operation_id": "invalid"}),
            lambda value: value.update({"verified": False}),
        ):
            bad = json.loads(json.dumps(good))
            mutation(bad)
            path.write_text(json.dumps(bad), encoding="utf-8")
            try:
                load_result(path)
            except (RestoreError, runner.CommandError):
                pass
            else:
                raise RestoreError("self-test: malformed acceptance result did not fail closed")
    print("phase2e-page-acceptance-restore self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--result", type=Path)
    parser.add_argument("--evidence", type=Path)
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            return self_test()
        if args.result is None or args.evidence is None:
            parser.error("--result and --evidence are required")
        return restore(args.result, args.evidence)
    except (RestoreError, runner.CommandError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
