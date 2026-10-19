#!/usr/bin/env python3
"""Create one exact temporary managed Media attachment for Phase 2E acceptance.

This helper is acceptance-only, not a general command surface. The tiny PNG bytes
are hard-coded public test data inside source code; no user media, URL or filesystem
path is accepted. It persists only IDs/checksum needed for later rollback.
"""
from __future__ import annotations

import base64
import hashlib
import importlib.util
import json
import os
import re
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(__file__).with_name("phase2d-command-runner.py")
TENANT = "eduardo-research"
DEFAULT_BASE_URL = "https://mcp.kairoseth.com/mcp"
PNG_BASE64 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4//8/AAX+Av4N70a4AAAAAElFTkSuQmCC"
PNG_BYTES = base64.b64decode(PNG_BASE64)
PNG_SHA256 = hashlib.sha256(PNG_BYTES).hexdigest()
PLAN_ID_RE = re.compile(r"^erm-media-ingest-plan-[a-f0-9]{20}-[a-f0-9]{8}$")
OP_ID_RE = re.compile(r"^erm-media-ingest-op-[a-f0-9]{32}$")

spec = importlib.util.spec_from_file_location("phase2d_command_runner_media_fixture", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)


class FixtureError(RuntimeError):
    pass


def signal_true(value: Any, key: str) -> bool:
    return any(obj.get(key) is True for obj in base.objects(value))


def attachment_id(value: Any) -> int | None:
    for obj in base.objects(value):
        candidate = obj.get("attachment_id")
        if isinstance(candidate, int) and not isinstance(candidate, bool) and candidate > 0:
            return candidate
        if isinstance(candidate, str) and candidate.isdigit() and int(candidate) > 0:
            return int(candidate)
    return None


def create_fixture(output: Path) -> int:
    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise FixtureError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", DEFAULT_BASE_URL).strip().rstrip("/")
    if base_url != DEFAULT_BASE_URL:
        raise FixtureError("Media acceptance fixture only permits the production MCP base URL")
    client = base.McpClient(f"{base_url}/{TENANT}", token)
    plan_id: str | None = None
    operation_id: str | None = None
    try:
        client.initialize()
        plan = client.call("site_media_ingest_plan", {
            "fileName": "phase2e-media-metadata-acceptance.png",
            "mimeType": "image/png",
            "contentBase64": PNG_BASE64,
            "title": "Phase 2E temporary Media acceptance fixture",
            "altText": "Temporary one-pixel Media acceptance fixture; not site content.",
            "caption": "Temporary reversible fixture for Media metadata gateway acceptance.",
            "description": "Acceptance-only attachment created by the reviewed Phase 2E helper and removed after verification.",
        })
        plan_id = base.regex_string(plan, PLAN_ID_RE)
        applied = client.call("site_media_ingest_apply", {"planId": plan_id, "confirm": True})
        operation_id = base.regex_string(applied, OP_ID_RE)
        verify = client.call("site_media_ingest_verify", {"operationId": operation_id})
        if not base.verified(verify) or not signal_true(verify, "checksum_matches") or not signal_true(verify, "managed_by_manager"):
            raise FixtureError("temporary Media fixture failed checksum/provenance verification")
        aid = attachment_id(applied) or attachment_id(verify)
        if aid is None:
            raise FixtureError("temporary Media fixture did not return attachment_id")
        result = {
            "version": "1",
            "status": "success",
            "tenant": TENANT,
            "action": "media.acceptance.fixture.create",
            "resource": {
                "attachment_id": aid,
                "file_name": "phase2e-media-metadata-acceptance.png",
                "mime_type": "image/png",
                "byte_size": len(PNG_BYTES),
                "sha256": PNG_SHA256,
                "temporary": True,
            },
            "verification": {"verified": True, "checksum_matches": True, "managed_by_manager": True},
            "correlation": {"plan_id": plan_id, "operation_id": operation_id},
        }
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        print(f"MEDIA_FIXTURE_OK {output}")
        return 0
    except Exception:
        if operation_id is not None:
            try:
                client.call("site_media_ingest_rollback", {"operationId": operation_id, "confirm": True})
            except Exception:
                pass
        elif plan_id is not None:
            try:
                client.call("site_media_ingest_cancel", {"planId": plan_id})
            except Exception:
                pass
        raise
    finally:
        client.close()


def self_test() -> int:
    if len(PNG_BYTES) != 69 or not PNG_BYTES.startswith(b"\x89PNG\r\n\x1a\n"):
        raise FixtureError("acceptance PNG fixture changed unexpectedly")
    if PNG_SHA256 != "e878950f8091ec010cf5cc723bdea027a8539cf7147cfea199c2f666232dcd4e":
        raise FixtureError("acceptance PNG checksum changed unexpectedly")
    if not PLAN_ID_RE.fullmatch("erm-media-ingest-plan-" + "a" * 20 + "-" + "b" * 8):
        raise FixtureError("fixture plan ID contract self-test failed")
    print("phase2e-media-acceptance-fixture self-test: OK")
    return 0


def main() -> int:
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, default=ROOT / "commands" / "phase2d" / "results" / "phase2e-media-acceptance-fixture.json")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        return self_test() if args.self_test else create_fixture(args.output)
    except (FixtureError, base.CommandError) as exc:
        print(f"ERROR: {base.sanitized_message(str(exc), os.environ.get('PHASE2D_MCP_TOKEN', '').strip())}", file=__import__("sys").stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
