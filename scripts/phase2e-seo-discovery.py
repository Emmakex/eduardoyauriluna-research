#!/usr/bin/env python3
"""Read-only production discovery for bounded SEO/GEO remediation candidates.

This helper never plans or applies a change. It reads the Manager readiness report
through the tenant-bound MCP, then persists only the minimal sanitized classification
needed to decide which bounded gateway (if any) can resolve each residual action.
"""
from __future__ import annotations

import importlib.util
import json
import os
import subprocess
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(__file__).with_name("phase2d-command-runner.py")
DEFAULT_BASE_URL = "https://mcp.kairoseth.com/mcp"
TENANT = "eduardo-research"

spec = importlib.util.spec_from_file_location("phase2d_command_runner_seo_discovery", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)


class DiscoveryError(RuntimeError):
    pass


def install_observable_subprocess() -> None:
    """Preserve sanitized MCP tool payload on inspector failures for diagnosis only."""
    real_run = base.subprocess.run

    def observable_run(*args: Any, **kwargs: Any) -> subprocess.CompletedProcess[Any]:
        completed = real_run(*args, **kwargs)
        if completed.returncode != 0 and isinstance(completed.stdout, str) and completed.stdout.strip():
            stderr = completed.stderr if isinstance(completed.stderr, str) else ""
            combined = f"{stderr.rstrip()}\nMCP tool payload: {completed.stdout.strip()}".strip()
            token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
            combined = base.sanitized_message(combined, token)
            return subprocess.CompletedProcess(completed.args, completed.returncode, completed.stdout, combined)
        return completed

    base.subprocess.run = observable_run


def readiness_payload(value: Any) -> dict[str, Any]:
    for obj in base.objects(value):
        if isinstance(obj.get("residual_actions"), dict) and "blocking_count" in obj and "ready" in obj:
            return obj
    raise DiscoveryError("SEO/GEO readiness payload was not found in MCP response")


def bounded_text(value: Any, limit: int = 500) -> str:
    return value[:limit] if isinstance(value, str) else ""


def safe_required_input(value: Any) -> list[str]:
    if not isinstance(value, list):
        return []
    result: list[str] = []
    for item in value:
        if isinstance(item, str) and item and len(item) <= 128 and item not in result:
            result.append(item)
    return result[:20]


def sanitized_target(value: Any) -> dict[str, Any]:
    if not isinstance(value, dict):
        return {}
    result: dict[str, Any] = {}
    for field in ("resource", "type", "language", "kind"):
        candidate = value.get(field)
        if isinstance(candidate, str) and candidate:
            result[field] = bounded_text(candidate, 200)
    identifier = value.get("identifier")
    if isinstance(identifier, (str, int)) and not isinstance(identifier, bool):
        result["identifier"] = bounded_text(identifier, 200) if isinstance(identifier, str) else identifier
    post_id = value.get("post_id")
    if isinstance(post_id, int) and not isinstance(post_id, bool) and post_id > 0:
        result["post_id"] = post_id
    return result


def safe_candidates(readiness: dict[str, Any]) -> list[dict[str, Any]]:
    residual = readiness.get("residual_actions")
    if not isinstance(residual, dict):
        return []
    rows = residual.get("safe_remediation")
    if not isinstance(rows, list):
        return []
    result: list[dict[str, Any]] = []
    seen: set[str] = set()
    for item in rows:
        if not isinstance(item, dict) or item.get("source") != "site-diagnostics":
            continue
        action = item.get("action")
        if not isinstance(action, dict) or action.get("gateway") != "seo-geo":
            continue
        operation = action.get("operation")
        if operation not in {"seo-remediate", "search-indexability"}:
            continue
        required_input = action.get("required_input")
        if required_input not in ([], None):
            continue
        if action.get("requires_evidence") not in (False, None):
            continue
        check_id = item.get("check_id")
        if not isinstance(check_id, str) or not check_id or len(check_id) > 128:
            continue
        if check_id in seen:
            continue
        seen.add(check_id)
        result.append({
            "check_id": check_id,
            "operation": operation,
            "status": bounded_text(item.get("status"), 32),
            "dimension": bounded_text(item.get("dimension"), 32),
            "target": sanitized_target(item.get("target")),
            "reason": bounded_text(item.get("reason"), 500),
        })
    return result


def structured_actions(readiness: dict[str, Any]) -> list[dict[str, Any]]:
    """Expose only routing metadata and required field names, never input values/content."""
    residual = readiness.get("residual_actions")
    if not isinstance(residual, dict):
        return []
    rows = residual.get("structured_input")
    if not isinstance(rows, list):
        return []
    result: list[dict[str, Any]] = []
    for item in rows[:20]:
        if not isinstance(item, dict):
            continue
        action = item.get("action") if isinstance(item.get("action"), dict) else {}
        required = safe_required_input(action.get("required_input"))
        if not required:
            continue
        result.append({
            "source": bounded_text(item.get("source"), 64),
            "check_id": bounded_text(item.get("check_id"), 128),
            "status": bounded_text(item.get("status"), 32),
            "dimension": bounded_text(item.get("dimension"), 32),
            "target": sanitized_target(item.get("target")),
            "gateway": bounded_text(action.get("gateway"), 64),
            "operation": bounded_text(action.get("operation"), 128),
            "required_input": required,
            "requires_evidence": action.get("requires_evidence") is True,
            "reason": bounded_text(item.get("reason"), 500),
        })
    return result


def summary(readiness: dict[str, Any]) -> dict[str, Any]:
    residual = readiness.get("residual_actions") if isinstance(readiness.get("residual_actions"), dict) else {}
    counts = residual.get("summary") if isinstance(residual.get("summary"), dict) else {}
    return {
        "version": "1",
        "status": "success",
        "tenant": TENANT,
        "action": "seo.geo.discovery",
        "read_only": True,
        "ready": readiness.get("ready") is True,
        "optimization_complete": readiness.get("optimization_complete") is True,
        "blocking_count": int(readiness.get("blocking_count", 0) or 0),
        "advisory_count": int(readiness.get("advisory_count", 0) or 0),
        "residual_summary": {
            "safe_remediation": int(counts.get("safe_remediation", 0) or 0),
            "structured_input": int(counts.get("structured_input", 0) or 0),
            "evidence_required": int(counts.get("evidence_required", 0) or 0),
            "manual_review": int(counts.get("manual_review", 0) or 0),
            "total": int(counts.get("total", 0) or 0),
        },
        "safe_site_candidates": safe_candidates(readiness),
        "structured_input_actions": structured_actions(readiness),
    }


def discover(output: Path) -> int:
    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise DiscoveryError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", DEFAULT_BASE_URL).strip().rstrip("/")
    if base_url != DEFAULT_BASE_URL:
        raise DiscoveryError("SEO discovery only permits the production MCP base URL")
    install_observable_subprocess()
    client = base.McpClient(f"{base_url}/{TENANT}", token)
    try:
        client.initialize()
        response = client.call("site_seo_geo_readiness", {"perTypeLimit": 1})
        payload = readiness_payload(response)
    finally:
        client.close()
    value = summary(payload)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(f"SEO_DISCOVERY_OK {output}")
    return 0


def self_test() -> int:
    sample = {
        "ready": False,
        "optimization_complete": False,
        "blocking_count": 1,
        "advisory_count": 2,
        "residual_actions": {
            "summary": {"safe_remediation": 1, "structured_input": 1, "evidence_required": 0, "manual_review": 0, "total": 2},
            "safe_remediation": [
                {"source": "site-diagnostics", "status": "fail", "check_id": "search-indexability", "dimension": "seo", "target": {"resource": "site:indexability"}, "reason": "bounded", "action": {"gateway": "seo-geo", "operation": "search-indexability", "required_input": [], "requires_evidence": False}},
            ],
            "structured_input": [
                {"source": "resource", "status": "fail", "check_id": "meta-description", "dimension": "seo", "target": {"type": "page", "identifier": "home", "language": "en"}, "reason": "missing bounded metadata", "action": {"gateway": "page", "operation": "metadata-update", "required_input": ["meta_description"], "requires_evidence": False, "values": {"meta_description": "MUST_NOT_LEAK"}}},
            ],
        }
    }
    wrapped = {"result": {"structuredContent": {"data": sample}}}
    parsed = readiness_payload(wrapped)
    result = summary(parsed)
    candidates = result["safe_site_candidates"]
    if len(candidates) != 1 or candidates[0].get("check_id") != "search-indexability":
        raise DiscoveryError("self-test failed to keep only safe site-diagnostics remediation")
    structured = result["structured_input_actions"]
    if len(structured) != 1 or structured[0].get("required_input") != ["meta_description"]:
        raise DiscoveryError("self-test failed to preserve structured input field names")
    serialized = json.dumps(result)
    if "MUST_NOT_LEAK" in serialized or "values" in serialized:
        raise DiscoveryError("self-test leaked structured input values")
    if result["read_only"] is not True or result["residual_summary"]["total"] != 2:
        raise DiscoveryError("self-test summary mismatch")
    print("phase2e-seo-discovery self-test: OK")
    return 0


def main() -> int:
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, default=ROOT / "commands" / "phase2d" / "results" / "phase2e-seo-discovery.json")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        return self_test() if args.self_test else discover(args.output)
    except (DiscoveryError, base.CommandError) as exc:
        token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
        print(f"ERROR: {base.sanitized_message(str(exc), token)}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
