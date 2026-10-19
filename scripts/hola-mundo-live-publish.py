#!/usr/bin/env python3
"""One-off bounded publication helper for the ChatGPT Hola mundo live test."""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
import sys
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

ROOT = Path(__file__).resolve().parents[1]
RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
DEFAULT_BASE_URL = "https://mcp.kairoseth.com/mcp"
EXPECTED_TENANT = "eduardo-research"
EXPECTED_POST_ID = 110
EXPECTED_TITLE = "Hola mundo"
EXPECTED_LANGUAGE = "es"
EXPECTED_FROM_STATUS = "draft"
EXPECTED_TO_STATUS = "publish"
EXPECTED_REQUEST = ROOT / "ops" / "hola-mundo-live-publish.json"

spec = importlib.util.spec_from_file_location("phase2d_command_runner", RUNNER)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)

class PublishError(RuntimeError):
    pass

def load_request(path: Path) -> dict[str, Any]:
    value = runner.load_json(path)
    expected = {
        "version": "1",
        "tenant": EXPECTED_TENANT,
        "action": "publish",
        "post_id": EXPECTED_POST_ID,
        "title": EXPECTED_TITLE,
        "from_status": EXPECTED_FROM_STATUS,
        "to_status": EXPECTED_TO_STATUS,
    }
    if value != expected:
        raise PublishError("request is outside the exact reviewed Hola mundo publication contract")
    return value

def client() -> Any:
    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise PublishError("PHASE2D_MCP_TOKEN is required")
    base = os.environ.get("PHASE2D_MCP_BASE_URL", DEFAULT_BASE_URL).strip().rstrip("/")
    if base != DEFAULT_BASE_URL:
        raise PublishError("publication helper only permits the production MCP base URL")
    return runner.McpClient(f"{base}/{EXPECTED_TENANT}", token)

def first_key(value: Any, key: str) -> Any:
    for obj in runner.objects(value):
        if key in obj:
            return obj[key]
    return None

def checked_permalink(value: Any) -> str:
    candidate = first_key(value, "permalink")
    if not isinstance(candidate, str) or not candidate:
        candidate = first_key(value, "url")
    if not isinstance(candidate, str) or not candidate:
        raise PublishError("published Insight exposes no public permalink")
    parsed = urlparse(candidate)
    if parsed.scheme != "https" or not parsed.hostname or not parsed.hostname.endswith("eduardoyauriluna.com"):
        raise PublishError("published permalink is outside eduardoyauriluna.com")
    return candidate

def write_json(path: Path, value: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")

def publish(evidence: Path) -> int:
    c = client()
    operation_id: str | None = None
    try:
        c.initialize()
        before = c.call("site_insight_inspect", {"postId": EXPECTED_POST_ID})
        if first_key(before, "title") != EXPECTED_TITLE:
            raise PublishError("target title is not exactly Hola mundo")
        if first_key(before, "language") != EXPECTED_LANGUAGE:
            raise PublishError("target language is not es")
        if first_key(before, "status") != EXPECTED_FROM_STATUS:
            raise PublishError("target is not in the expected draft state")

        plan = c.call("site_insight_update_plan", {
            "postId": EXPECTED_POST_ID,
            "changes": {"status": EXPECTED_TO_STATUS},
            "reason": "User-requested Hola mundo live publication test",
        })
        plan_id = runner.regex_string(plan, runner.PLAN_ID_RE)
        applied = c.call("site_insight_apply", {"planId": plan_id, "confirm": True})
        operation_id = runner.regex_string(applied, runner.OP_ID_RE)
        verification = c.call("site_insight_verify", {"operationId": operation_id, "rendered": True})
        if not runner.verified(verification):
            raise PublishError("rendered publication verification did not report verified=true")

        after = c.call("site_insight_inspect", {"postId": EXPECTED_POST_ID})
        if first_key(after, "title") != EXPECTED_TITLE or first_key(after, "status") != EXPECTED_TO_STATUS:
            raise PublishError("published Insight readback does not match requested final state")
        permalink = checked_permalink(after)
        result = {
            "version": "1",
            "status": "success",
            "tenant": EXPECTED_TENANT,
            "action": "hola_mundo.publish",
            "resource": {
                "post_id": EXPECTED_POST_ID,
                "title": EXPECTED_TITLE,
                "language": EXPECTED_LANGUAGE,
                "status": EXPECTED_TO_STATUS,
                "permalink": permalink,
            },
            "correlation": {"plan_id": plan_id, "operation_id": operation_id},
            "verified": True,
            "rendered_verified": True,
        }
        write_json(evidence, result)
        print(f"HOLA_MUNDO_PUBLISHED {permalink}")
        return 0
    except Exception:
        if operation_id is not None:
            try:
                rolled = c.call("site_insight_rollback", {"operationId": operation_id, "confirm": True})
                if not runner.rollback_verified(rolled):
                    print("WARNING: emergency publication rollback was not verified", file=sys.stderr)
            except Exception:
                print("WARNING: emergency publication rollback failed", file=sys.stderr)
        raise
    finally:
        c.close()

def self_test() -> int:
    import tempfile
    with tempfile.TemporaryDirectory(prefix="hola-mundo-publish-test-") as tmp:
        path = Path(tmp) / "request.json"
        good = {
            "version": "1", "tenant": EXPECTED_TENANT, "action": "publish",
            "post_id": EXPECTED_POST_ID, "title": EXPECTED_TITLE,
            "from_status": EXPECTED_FROM_STATUS, "to_status": EXPECTED_TO_STATUS,
        }
        path.write_text(json.dumps(good), encoding="utf-8")
        load_request(path)
        bad = dict(good); bad["post_id"] = 111
        path.write_text(json.dumps(bad), encoding="utf-8")
        try:
            load_request(path)
        except (PublishError, runner.CommandError):
            pass
        else:
            raise PublishError("self-test did not reject another post ID")
    print("hola-mundo-live-publish self-test: OK")
    return 0

def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--request", type=Path, default=EXPECTED_REQUEST)
    p.add_argument("--evidence", type=Path)
    p.add_argument("--self-test", action="store_true")
    args = p.parse_args()
    try:
        if args.self_test:
            return self_test()
        if args.evidence is None:
            p.error("--evidence is required")
        load_request(args.request)
        return publish(args.evidence)
    except (PublishError, runner.CommandError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2

if __name__ == "__main__":
    raise SystemExit(main())
