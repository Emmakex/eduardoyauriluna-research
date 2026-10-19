#!/usr/bin/env python3
"""Read current registered Theme navigation and derive a reversible acceptance candidate.

No mutation is planned or applied. Only the closed Research Theme target keys,
location and revision are persisted. The candidate swaps the first two existing
items of one menu, preserving membership exactly.
"""
from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(__file__).with_name("phase2d-command-runner.py")
DEFAULT_BASE_URL = "https://mcp.kairoseth.com/mcp"
TENANT = "eduardo-research"
ALLOWED_TARGETS = {
    "home", "about", "research", "publications", "projects", "software",
    "datasets", "cv", "insights", "contact", "privacy-policy", "legal-notice",
}

spec = importlib.util.spec_from_file_location("phase2d_command_runner_navigation_discovery", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)


class DiscoveryError(RuntimeError):
    pass


def state_payload(value: Any) -> tuple[dict[str, Any], str]:
    for obj in base.objects(value):
        state = obj.get("state")
        if isinstance(state, dict) and ("primary" in state or "footer" in state):
            revision = obj.get("revision")
            return state, revision if isinstance(revision, str) else ""
    raise DiscoveryError("Navigation state payload was not found in MCP response")


def clean_items(value: Any, location: str) -> list[str]:
    if not isinstance(value, list):
        raise DiscoveryError(f"Navigation {location} is not an array")
    items: list[str] = []
    for item in value:
        if not isinstance(item, str) or item not in ALLOWED_TARGETS:
            raise DiscoveryError(f"Navigation {location} contains a target outside the registered Theme contract")
        if item in items:
            raise DiscoveryError(f"Navigation {location} contains duplicate targets")
        items.append(item)
    return items


def summarize(value: Any) -> dict[str, Any]:
    state, revision = state_payload(value)
    clean = {
        "primary": clean_items(state.get("primary", []), "primary"),
        "footer": clean_items(state.get("footer", []), "footer"),
    }
    location = next((name for name in ("primary", "footer") if len(clean[name]) >= 2), None)
    if location is None:
        raise DiscoveryError("No Navigation location has two existing targets for reversible reorder acceptance")
    candidate = list(clean[location])
    candidate[0], candidate[1] = candidate[1], candidate[0]
    return {
        "version": "1",
        "status": "success",
        "tenant": TENANT,
        "action": "navigation.discovery",
        "read_only": True,
        "baseline": clean,
        "revision": revision,
        "candidate": {
            "location": location,
            "items": candidate,
            "reorder_only": True,
            "membership_changed": False,
        },
    }


def discover(output: Path) -> int:
    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise DiscoveryError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", DEFAULT_BASE_URL).strip().rstrip("/")
    if base_url != DEFAULT_BASE_URL:
        raise DiscoveryError("Navigation discovery only permits the production MCP base URL")
    client = base.McpClient(f"{base_url}/{TENANT}", token)
    try:
        client.initialize()
        response = client.call("site_navigation_inspect", {})
    finally:
        client.close()
    result = summarize(response)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(f"NAVIGATION_DISCOVERY_OK {output}")
    return 0


def self_test() -> int:
    sample = {
        "result": {
            "structuredContent": {
                "data": {
                    "state": {
                        "primary": ["home", "research", "publications"],
                        "footer": ["privacy-policy", "legal-notice"],
                    },
                    "revision": "nav-revision-test",
                }
            }
        }
    }
    result = summarize(sample)
    if result["candidate"] != {
        "location": "primary",
        "items": ["research", "home", "publications"],
        "reorder_only": True,
        "membership_changed": False,
    }:
        raise DiscoveryError("self-test did not derive exact reorder-only candidate")
    bad = json.loads(json.dumps(sample))
    bad["result"]["structuredContent"]["data"]["state"]["primary"][1] = "https://example.com"
    try:
        summarize(bad)
    except DiscoveryError:
        pass
    else:
        raise DiscoveryError("self-test accepted an arbitrary Navigation target")
    print("phase2e-navigation-discovery self-test: OK")
    return 0


def main() -> int:
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, default=ROOT / "commands" / "phase2d" / "results" / "phase2e-navigation-discovery.json")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        return self_test() if args.self_test else discover(args.output)
    except (DiscoveryError, base.CommandError) as exc:
        print(f"ERROR: {base.sanitized_message(str(exc), os.environ.get('PHASE2D_MCP_TOKEN', '').strip())}", file=__import__("sys").stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
