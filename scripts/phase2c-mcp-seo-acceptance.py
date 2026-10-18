#!/usr/bin/env python3
"""Run only the remaining Phase 2C SEO/GEO production MCP acceptance.

Evidence, Media and Design were already accepted in workflow run 39155709650 and
are deliberately not repeated here. A write is attempted only when Research
Manager reports a real auto-remediable production finding; otherwise the gate is
recorded as SKIP_LEGITIMATE, per the Phase 2C contract.
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import tempfile
import time
from pathlib import Path
from typing import Any, Iterable

SERVER = os.environ.get("MCP_SERVER_URL", "").strip()
TOKEN = os.environ.get("MCP_TOKEN", "").strip()
EVIDENCE = Path(os.environ.get("MCP_EVIDENCE_DIR", "").strip() or ".phase2c-seo-acceptance")
RUN_ID = os.environ.get("GITHUB_RUN_ID", str(int(time.time())))

if not SERVER or not TOKEN:
    raise SystemExit("MCP_SERVER_URL and MCP_TOKEN are required")

EVIDENCE.mkdir(parents=True, exist_ok=True)
TMP = Path(tempfile.mkdtemp(prefix="phase2c-seo-"))
CONFIG = TMP / "mcp.json"
CONFIG.write_text(
    json.dumps(
        {
            "mcpServers": {
                "phase2c": {
                    "type": "http",
                    "url": SERVER,
                    "headers": {"Authorization": f"Bearer {TOKEN}"},
                    "connectionTimeout": 10000,
                    "requestTimeout": 60000,
                }
            }
        }
    ),
    encoding="utf-8",
)
CONFIG.chmod(0o600)
BASE = [
    "npx",
    "--yes",
    "@modelcontextprotocol/inspector@2.5.0",
    "--cli",
    "--config",
    str(CONFIG),
    "--server",
    "phase2c",
    "--stored-auth-only",
    "--format",
    "json",
]


def sanitize(text: str) -> str:
    value = text.replace(TOKEN, "<redacted>") if TOKEN else text
    value = re.sub(r"(?:ermt1_|erm1_)[A-Za-z0-9._:-]+", "<redacted-manager-token>", value)
    value = re.sub(r"Bearer\s+[A-Za-z0-9._~+/=-]+", "Bearer <redacted>", value, flags=re.I)
    return value[-4000:]


def inspector(extra: list[str]) -> dict[str, Any]:
    proc = subprocess.run(
        BASE + extra,
        text=True,
        capture_output=True,
        check=False,
        env={
            **os.environ,
            "MCP_STORAGE_DIR": str(TMP / "storage"),
            "MCP_INSPECTOR_OAUTH_STATE_PATH": str(TMP / "oauth.json"),
        },
    )
    payload: dict[str, Any] | None = None
    if proc.stdout.strip():
        try:
            decoded = json.loads(proc.stdout)
            if isinstance(decoded, dict):
                payload = decoded
        except json.JSONDecodeError:
            payload = None
    if payload is not None:
        result = payload.get("result") or {}
        if isinstance(result, dict) and result.get("isError"):
            text = " ".join(
                str(row.get("text", ""))
                for row in result.get("content", [])
                if isinstance(row, dict) and row.get("type") == "text"
            )
            raise RuntimeError(sanitize(text or proc.stdout or proc.stderr or "MCP tool returned isError:true"))
        if proc.returncode == 0:
            return payload
    if proc.returncode != 0:
        raise RuntimeError(sanitize(f"inspector exit={proc.returncode}\nstdout={proc.stdout}\nstderr={proc.stderr}"))
    raise RuntimeError(sanitize(f"inspector returned invalid JSON: {proc.stdout}"))


def call(tool: str, args: dict[str, Any] | None = None) -> Any:
    payload = inspector(
        [
            "--method",
            "tools/call",
            "--tool-name",
            tool,
            "--tool-args-json",
            json.dumps(args or {}, separators=(",", ":")),
        ]
    )
    result = payload.get("result") or {}
    structured = result.get("structuredContent") if isinstance(result, dict) else None
    if isinstance(structured, dict) and "data" in structured:
        return structured["data"]
    for row in result.get("content", []) if isinstance(result, dict) else []:
        if isinstance(row, dict) and row.get("type") == "text":
            try:
                return json.loads(str(row.get("text", "")))
            except json.JSONDecodeError:
                continue
    raise RuntimeError(f"{tool}: no structured result")


def walk(value: Any) -> Iterable[Any]:
    yield value
    if isinstance(value, dict):
        for child in value.values():
            yield from walk(child)
    elif isinstance(value, list):
        for child in value:
            yield from walk(child)


def objects(value: Any) -> Iterable[dict[str, Any]]:
    for item in walk(value):
        if isinstance(item, dict):
            yield item


def regex_string(value: Any, pattern: str) -> str:
    rx = re.compile(pattern)
    for item in walk(value):
        if isinstance(item, str) and rx.fullmatch(item):
            return item
    raise RuntimeError(f"expected identifier not found: {pattern}")


def seo_candidate(value: Any) -> dict[str, Any] | None:
    for obj in objects(value):
        check_id = obj.get("check_id")
        if (
            obj.get("auto_remediable") is True
            and isinstance(check_id, str)
            and re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,127}", check_id)
        ):
            return obj
    return None


def seo_fingerprint(value: Any, check_id: str) -> dict[str, Any] | None:
    for obj in objects(value):
        if obj.get("check_id") == check_id:
            return {
                key: obj.get(key)
                for key in ("check_id", "status", "auto_remediable", "resource")
            }
    return None


def any_verified(value: Any) -> bool:
    return any(
        obj.get("verified") is True or obj.get("status") == "verified"
        for obj in objects(value)
    )


def rollback_ok(value: Any) -> bool:
    for obj in objects(value):
        if obj.get("status") in ("rolled-back", "rolled_back"):
            return True
        if obj.get("rollback_restored_baseline") is True:
            return True
        result = obj.get("rollback_result")
        if isinstance(result, dict) and result.get("verified") is True:
            return True
    return False


summary: dict[str, Any] = {
    "ok": False,
    "phase": "phase2c",
    "acceptance": "seo-geo-only",
    "run_id": RUN_ID,
    "does_not_repeat": ["Evidence", "Media", "Design"],
    "domains": [],
}
pending_operation: str | None = None

try:
    inspector(["--method", "initialize"])
    print("HANDSHAKE: OK")

    seo_before = call("site_seo_geo_site")
    candidate = seo_candidate(seo_before)

    if candidate is None:
        summary["domains"].append(
            {
                "domain": "SEO/GEO",
                "status": "SKIP_LEGITIMATE",
                "reason": "no current auto-remediable production finding",
            }
        )
        summary["ok"] = True
        print("SEO/GEO: SKIP_LEGITIMATE")
    else:
        check_id = str(candidate["check_id"])
        before_fp = seo_fingerprint(seo_before, check_id)
        if before_fp is None:
            raise RuntimeError("SEO/GEO: candidate baseline fingerprint missing")

        plan = call(
            "site_seo_geo_remediation_plan",
            {
                "checkId": check_id,
                "intent": "Phase 2C production MCP reversible SEO/GEO acceptance",
            },
        )
        plan_id = regex_string(
            plan,
            r"erm-(?:seo-plan-[a-f0-9]{24}|indexability-plan-[a-f0-9]{18}-[a-f0-9]{8})",
        )

        applied = call(
            "site_seo_geo_remediation_apply",
            {"planId": plan_id, "confirm": True},
        )
        operation_id = regex_string(
            applied,
            r"erm-(?:seo-op|indexability-op)-[a-f0-9]{32}",
        )
        pending_operation = operation_id

        verified = call(
            "site_seo_geo_remediation_verify",
            {"operationId": operation_id, "rendered": True},
        )
        if not any_verified(verified):
            raise RuntimeError("site_seo_geo_remediation_verify: verification did not report verified=true")

        rolled = call(
            "site_seo_geo_remediation_rollback",
            {"operationId": operation_id, "confirm": True},
        )
        if not rollback_ok(rolled):
            raise RuntimeError("site_seo_geo_remediation_rollback: rollback did not report restored state")
        pending_operation = None

        seo_after = call("site_seo_geo_site")
        after_fp = seo_fingerprint(seo_after, check_id)
        if before_fp != after_fp:
            raise RuntimeError("SEO/GEO: exact diagnostic baseline was not restored after rollback")

        summary["domains"].append(
            {
                "domain": "SEO/GEO",
                "status": "PASS",
                "check_id": check_id,
                "plan_id": plan_id,
                "operation_id": operation_id,
                "baseline_restored": True,
            }
        )
        summary["ok"] = True
        print("SEO/GEO: PASS")

except Exception as exc:  # noqa: BLE001
    if pending_operation:
        try:
            call(
                "site_seo_geo_remediation_rollback",
                {"operationId": pending_operation, "confirm": True},
            )
            print("RECOVERY SEO/GEO: rollback attempted")
        except Exception as rollback_exc:  # noqa: BLE001
            print("RECOVERY SEO/GEO: rollback failed: " + sanitize(str(rollback_exc)))
    summary["error"] = sanitize(str(exc))
    print("SEO/GEO: FAIL: " + sanitize(str(exc)))

finally:
    (EVIDENCE / "summary.json").write_text(
        json.dumps(summary, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )

if not summary["ok"]:
    raise SystemExit(1)
