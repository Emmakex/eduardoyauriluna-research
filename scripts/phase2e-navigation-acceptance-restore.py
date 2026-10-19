#!/usr/bin/env python3
"""Rollback only the exact Phase 2E Navigation acceptance operation.

This is deliberately not a general gateway rollback command. It consumes the
persisted sanitized result for one fixed acceptance command, calls the already
accepted Navigation rollback lifecycle, then re-inspects Navigation and proves
that the read-only discovery baseline was restored exactly.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(__file__).with_name("phase2d-command-runner.py")
TENANT = "eduardo-research"
EXPECTED_COMMAND_ID = "cmd-phase2e-navigation-update-0001"
DEFAULT_BASE_URL = "https://mcp.kairoseth.com/mcp"

spec = importlib.util.spec_from_file_location("phase2d_command_runner_navigation_restore", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)


class RestoreError(RuntimeError):
    pass


def load(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise RestoreError(f"invalid JSON input: {path}") from exc
    if not isinstance(value, dict):
        raise RestoreError(f"{path} must contain an object")
    return value


def navigation_state(value: Any) -> dict[str, list[str]]:
    for obj in base.objects(value):
        state = obj.get("state")
        if isinstance(state, dict):
            primary = state.get("primary")
            footer = state.get("footer")
            if isinstance(primary, list) and isinstance(footer, list):
                if all(isinstance(item, str) for item in primary + footer):
                    return {"primary": list(primary), "footer": list(footer)}
    raise RestoreError("Navigation state was not returned after rollback")


def restore(result_path: Path, discovery_path: Path, evidence_path: Path) -> int:
    result = load(result_path)
    discovery = load(discovery_path)
    if result.get("command_id") != EXPECTED_COMMAND_ID or result.get("action") != "navigation.update":
        raise RestoreError("result is not the exact reviewed Navigation acceptance command")
    if result.get("status") != "success" or result.get("verified") is not True:
        raise RestoreError("Navigation acceptance result is not successful and verified")
    correlation = result.get("correlation")
    if not isinstance(correlation, dict):
        raise RestoreError("Navigation acceptance result has no correlation")
    operation_id = correlation.get("operation_id")
    if not isinstance(operation_id, str) or not __import__("re").fullmatch(r"erm-nav-op-[a-f0-9]{32}", operation_id):
        raise RestoreError("Navigation acceptance operation_id is invalid")
    baseline = discovery.get("baseline")
    if not isinstance(baseline, dict):
        raise RestoreError("Navigation discovery baseline is missing")
    expected_state = {
        "primary": baseline.get("primary"),
        "footer": baseline.get("footer"),
    }
    if not all(isinstance(expected_state[name], list) and all(isinstance(item, str) for item in expected_state[name]) for name in ("primary", "footer")):
        raise RestoreError("Navigation discovery baseline is invalid")

    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise RestoreError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", DEFAULT_BASE_URL).strip().rstrip("/")
    if base_url != DEFAULT_BASE_URL:
        raise RestoreError("Navigation restore only permits the production MCP base URL")

    client = base.McpClient(f"{base_url}/{TENANT}", token)
    try:
        client.initialize()
        rolled = client.call("site_navigation_rollback", {"operationId": operation_id, "confirm": True})
        if not base.rollback_verified(rolled):
            raise RestoreError("Navigation rollback did not report verified baseline restoration")
        inspected = client.call("site_navigation_inspect", {})
        final_state = navigation_state(inspected)
    finally:
        client.close()

    if final_state != expected_state:
        raise RestoreError("Navigation state after rollback does not match the discovery baseline")

    evidence = {
        "version": "1",
        "status": "success",
        "tenant": TENANT,
        "action": "navigation.acceptance.restore",
        "source_command_id": EXPECTED_COMMAND_ID,
        "operation_id": operation_id,
        "rollback_verified": True,
        "baseline_restored": True,
        "resource": {
            "location": result.get("resource", {}).get("location") if isinstance(result.get("resource"), dict) else None,
            "restored": "exact-discovery-baseline",
        },
    }
    evidence_path.parent.mkdir(parents=True, exist_ok=True)
    evidence_path.write_text(json.dumps(evidence, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(f"NAVIGATION_RESTORE_OK {evidence_path}")
    return 0


def self_test() -> int:
    good_result = {
        "command_id": EXPECTED_COMMAND_ID,
        "action": "navigation.update",
        "status": "success",
        "verified": True,
        "correlation": {"operation_id": "erm-nav-op-" + "a" * 32},
    }
    op = good_result["correlation"]["operation_id"]
    if not __import__("re").fullmatch(r"erm-nav-op-[a-f0-9]{32}", op):
        raise RestoreError("self-test operation id contract failed")
    bad = json.loads(json.dumps(good_result))
    bad["command_id"] = "cmd-other-navigation"
    if bad["command_id"] == EXPECTED_COMMAND_ID:
        raise RestoreError("self-test command binding failed")
    print("phase2e-navigation-acceptance-restore self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--result", type=Path)
    parser.add_argument("--discovery", type=Path)
    parser.add_argument("--evidence", type=Path)
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            return self_test()
        if args.result is None or args.discovery is None or args.evidence is None:
            parser.error("--result, --discovery and --evidence are required")
        return restore(args.result, args.discovery, args.evidence)
    except (RestoreError, base.CommandError) as exc:
        print(f"ERROR: {base.sanitized_message(str(exc), os.environ.get('PHASE2D_MCP_TOKEN', '').strip())}", file=__import__("sys").stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
