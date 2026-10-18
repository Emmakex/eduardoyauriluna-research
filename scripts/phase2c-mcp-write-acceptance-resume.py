#!/usr/bin/env python3
"""Resume Phase 2C production write acceptance from Evidence onward.

Insights, Research Objects and Translations were accepted in run 39154953057.
This runner does not repeat them. It preserves MCP tool error payloads even when
Inspector exits non-zero, performs one safe Evidence re-plan on stale preview,
and rolls back every applied operation immediately.
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
EVIDENCE = Path(os.environ.get("MCP_EVIDENCE_DIR", "").strip() or ".phase2c-write-evidence-resume")
RUN_ID = os.environ.get("GITHUB_RUN_ID", str(int(time.time())))
EVIDENCE_REFERENCE = f"Phase 2C production MCP reversible acceptance fixture run {RUN_ID}; exact rollback required."

if not SERVER or not TOKEN:
    raise SystemExit("MCP_SERVER_URL and MCP_TOKEN are required")

EVIDENCE.mkdir(parents=True, exist_ok=True)
TMP = Path(tempfile.mkdtemp(prefix="phase2c-write-resume-"))
CONFIG = TMP / "mcp.json"
CONFIG.write_text(json.dumps({"mcpServers": {"phase2c": {"type": "http", "url": SERVER, "headers": {"Authorization": f"Bearer {TOKEN}"}, "connectionTimeout": 10000, "requestTimeout": 60000}}}), encoding="utf-8")
CONFIG.chmod(0o600)
BASE = ["npx", "--yes", "@modelcontextprotocol/inspector@2.5.0", "--cli", "--config", str(CONFIG), "--server", "phase2c", "--stored-auth-only", "--format", "json"]
pending: list[tuple[str, str, dict[str, Any]]] = []
summary: list[dict[str, Any]] = []


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
        env={**os.environ, "MCP_STORAGE_DIR": str(TMP / "storage"), "MCP_INSPECTOR_OAUTH_STATE_PATH": str(TMP / "oauth.json")},
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
            text = " ".join(str(row.get("text", "")) for row in result.get("content", []) if isinstance(row, dict) and row.get("type") == "text")
            raise RuntimeError(sanitize(text or proc.stdout or proc.stderr or "MCP tool returned isError:true"))
        if proc.returncode == 0:
            return payload
    if proc.returncode != 0:
        raise RuntimeError(sanitize(f"inspector exit={proc.returncode}\nstdout={proc.stdout}\nstderr={proc.stderr}"))
    raise RuntimeError(sanitize(f"inspector returned invalid JSON: {proc.stdout}"))


def call(tool: str, args: dict[str, Any] | None = None) -> Any:
    payload = inspector(["--method", "tools/call", "--tool-name", tool, "--tool-args-json", json.dumps(args or {}, separators=(",", ":"))])
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


def ids(value: Any, keys: tuple[str, ...]) -> list[str]:
    found: set[str] = set()
    for obj in objects(value):
        for key in keys:
            val = obj.get(key)
            if isinstance(val, (str, int)) and str(val):
                found.add(str(val))
    return sorted(found)


def key_values(value: Any, key: str) -> list[Any]:
    return [obj[key] for obj in objects(value) if key in obj]


def any_verified(value: Any) -> bool:
    return any(obj.get("verified") is True or obj.get("status") == "verified" for obj in objects(value))


def rollback_ok(value: Any) -> bool:
    for obj in objects(value):
        if obj.get("status") in ("rolled-back", "rolled_back") or obj.get("rollback_restored_baseline") is True:
            return True
        rr = obj.get("rollback_result")
        if isinstance(rr, dict) and rr.get("verified") is True:
            return True
    return False


def design_fingerprint(value: Any) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for obj in objects(value):
        row = {key: obj[key] for key in ("palette", "typography", "density") if key in obj and isinstance(obj[key], str)}
        if row and row not in rows:
            rows.append(row)
    return sorted(rows, key=lambda row: json.dumps(row, sort_keys=True))


def seo_candidate(value: Any) -> dict[str, Any] | None:
    for obj in objects(value):
        check_id = obj.get("check_id")
        if obj.get("auto_remediable") is True and isinstance(check_id, str) and re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,127}", check_id):
            return obj
    return None


def seo_fingerprint(value: Any, check_id: str) -> dict[str, Any] | None:
    for obj in objects(value):
        if obj.get("check_id") == check_id:
            return {key: obj.get(key) for key in ("check_id", "status", "auto_remediable", "resource")}
    return None


def add_pending(domain: str, tool: str, operation_id: str) -> None:
    pending.append((domain, tool, {"operationId": operation_id, "confirm": True}))


def clear_pending(domain: str) -> None:
    pending[:] = [row for row in pending if row[0] != domain]


def require_verified(tool: str, value: Any) -> None:
    if not any_verified(value):
        raise RuntimeError(f"{tool}: verification did not report verified=true")


def require_rollback(tool: str, value: Any) -> None:
    if not rollback_ok(value):
        raise RuntimeError(f"{tool}: rollback did not report restored/rolled-back state")


def record(domain: str, status: str, **extra: Any) -> None:
    summary.append({"domain": domain, "status": status, **extra})
    print(f"{domain}: {status}")


def cleanup_pending() -> None:
    for domain, tool, args in reversed(pending):
        try:
            call(tool, args)
            print(f"RECOVERY {domain}: rollback attempted")
        except Exception as exc:  # noqa: BLE001
            print(f"RECOVERY {domain}: rollback failed: {sanitize(str(exc))}")


def evidence_plan(marker: str) -> tuple[Any, str]:
    plan = call("site_evidence_create_plan", {
        "group": "methods",
        "data": {"title": marker, "summary": "Temporary reversible Phase 2C production acceptance evidence record.", "status": "unverified"},
        "evidenceConfirmed": True,
        "evidenceReference": EVIDENCE_REFERENCE,
    })
    plan_id = regex_string(plan, r"erm-evidence-plan-[a-f0-9]{18}-[a-f0-9]{8}")
    apply_allowed = next((obj.get("apply_allowed") for obj in objects(plan) if "apply_allowed" in obj), None)
    risk = next((obj.get("risk") for obj in objects(plan) if "risk" in obj), None)
    print(f"Evidence plan: apply_allowed={apply_allowed!r} risk={risk!r} plan_id={plan_id}")
    return plan, plan_id


try:
    inspector(["--method", "initialize"])
    print("HANDSHAKE: OK")
    marker = f"Phase 2C MCP acceptance {RUN_ID}"

    # Evidence. If a whole-store baseline changed between Preview and Apply, re-plan once.
    before_ids = ids(call("site_evidence_list", {"group": "methods"}), ("record_id", "id"))
    _, plan_id = evidence_plan(marker)
    try:
        applied = call("site_evidence_apply", {"planId": plan_id, "confirm": True})
    except RuntimeError as first_error:
        message = str(first_error)
        print(f"Evidence first Apply diagnostic: {sanitize(message)}")
        if "stale" not in message.lower() and "stale_preview" not in message.lower():
            raise
        print("Evidence: stale Preview detected; rebuilding exact plan once")
        _, plan_id = evidence_plan(marker)
        applied = call("site_evidence_apply", {"planId": plan_id, "confirm": True})
    op_id = regex_string(applied, r"erm-evidence-op-[a-f0-9]{32}")
    add_pending("Evidence", "site_evidence_rollback", op_id)
    verified = call("site_evidence_verify", {"operationId": op_id})
    require_verified("site_evidence_verify", verified)
    rolled = call("site_evidence_rollback", {"operationId": op_id, "confirm": True})
    require_rollback("site_evidence_rollback", rolled)
    clear_pending("Evidence")
    after_ids = ids(call("site_evidence_list", {"group": "methods"}), ("record_id", "id"))
    if before_ids != after_ids:
        raise RuntimeError("Evidence: baseline record inventory was not restored")
    record("Evidence", "PASS", plan_id=plan_id, operation_id=op_id, baseline_restored=True)

    # Media.
    media_title = f"Phase 2C acceptance pixel {RUN_ID}"
    before_ids = ids(call("site_media_list", {"search": media_title, "page": 1, "perPage": 100}), ("attachment_id", "post_id", "id"))
    png = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII="
    plan = call("site_media_ingest_plan", {"fileName": f"phase2c-acceptance-{RUN_ID}.png", "mimeType": "image/png", "contentBase64": png, "altText": "Phase 2C acceptance pixel", "title": media_title})
    plan_id = regex_string(plan, r"erm-media-ingest-plan-[a-f0-9]{20}-[a-f0-9]{8}")
    applied = call("site_media_ingest_apply", {"planId": plan_id, "confirm": True})
    op_id = regex_string(applied, r"erm-media-ingest-op-[a-f0-9]{32}")
    add_pending("Media", "site_media_ingest_rollback", op_id)
    require_verified("site_media_ingest_verify", call("site_media_ingest_verify", {"operationId": op_id}))
    rolled = call("site_media_ingest_rollback", {"operationId": op_id, "confirm": True})
    require_rollback("site_media_ingest_rollback", rolled)
    clear_pending("Media")
    after_ids = ids(call("site_media_list", {"search": media_title, "page": 1, "perPage": 100}), ("attachment_id", "post_id", "id"))
    if before_ids != after_ids:
        raise RuntimeError("Media: temporary attachment inventory was not restored")
    record("Media", "PASS", plan_id=plan_id, operation_id=op_id, baseline_restored=True)

    # Design.
    design_before = call("site_design_inspect")
    design_fp = design_fingerprint(design_before)
    current_density = next((value for value in key_values(design_before, "density") if value in ("compact", "balanced", "airy")), None)
    if not current_density:
        raise RuntimeError("Design: current registered density could not be determined")
    target_density = next(value for value in ("compact", "balanced", "airy") if value != current_density)
    plan = call("site_design_preset_update_plan", {"changes": {"density": target_density}, "intent": "Phase 2C production MCP reversible acceptance"})
    plan_id = regex_string(plan, r"erm-design-plan-[a-f0-9]{18}-[a-f0-9]{8}")
    applied = call("site_design_preset_apply", {"planId": plan_id, "confirm": True})
    op_id = regex_string(applied, r"erm-design-op-[a-f0-9]{32}")
    add_pending("Design", "site_design_preset_rollback", op_id)
    require_verified("site_design_preset_verify", call("site_design_preset_verify", {"operationId": op_id}))
    rolled = call("site_design_preset_rollback", {"operationId": op_id, "confirm": True})
    require_rollback("site_design_preset_rollback", rolled)
    clear_pending("Design")
    if design_fp != design_fingerprint(call("site_design_inspect")):
        raise RuntimeError("Design: exact preset baseline was not restored")
    record("Design", "PASS", plan_id=plan_id, operation_id=op_id, baseline_restored=True)

    # SEO/GEO only when a real deterministic remediation exists.
    seo_before = call("site_seo_geo_site")
    candidate = seo_candidate(seo_before)
    if candidate is None:
        record("SEO/GEO", "SKIP_LEGITIMATE", reason="no current auto-remediable production finding")
    else:
        check_id = str(candidate["check_id"])
        before_fp = seo_fingerprint(seo_before, check_id)
        plan = call("site_seo_geo_remediation_plan", {"checkId": check_id, "intent": "Phase 2C production MCP reversible acceptance"})
        plan_id = regex_string(plan, r"erm-(?:seo-plan-[a-f0-9]{24}|indexability-plan-[a-f0-9]{18}-[a-f0-9]{8})")
        applied = call("site_seo_geo_remediation_apply", {"planId": plan_id, "confirm": True})
        op_id = regex_string(applied, r"erm-(?:seo-op|indexability-op)-[a-f0-9]{32}")
        add_pending("SEO/GEO", "site_seo_geo_remediation_rollback", op_id)
        require_verified("site_seo_geo_remediation_verify", call("site_seo_geo_remediation_verify", {"operationId": op_id, "rendered": True}))
        rolled = call("site_seo_geo_remediation_rollback", {"operationId": op_id, "confirm": True})
        require_rollback("site_seo_geo_remediation_rollback", rolled)
        clear_pending("SEO/GEO")
        if before_fp != seo_fingerprint(call("site_seo_geo_site"), check_id):
            raise RuntimeError("SEO/GEO: diagnostic baseline was not restored")
        record("SEO/GEO", "PASS", check_id=check_id, plan_id=plan_id, operation_id=op_id, baseline_restored=True)

    evidence = {
        "ok": True,
        "phase": "phase2c",
        "surface": "production-mcp-write-acceptance-resume",
        "server": SERVER,
        "run_id": RUN_ID,
        "previous_acceptance_run": "39154953057",
        "previously_accepted_domains": ["Insights", "Research Objects", "Translations"],
        "navigation_previously_accepted": True,
        "domains": summary,
    }
    (EVIDENCE / "summary.json").write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print("PASS: Phase 2C MCP write acceptance resume")
finally:
    cleanup_pending()
    try:
        CONFIG.unlink(missing_ok=True)
    except OSError:
        pass
