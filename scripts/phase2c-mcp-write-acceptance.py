#!/usr/bin/env python3
"""Production acceptance for the remaining Phase 2C bounded write domains.

Runs only through the public commercial MCP endpoint. Every applied operation is
rolled back immediately; an exception triggers best-effort rollback of any still
pending operation. Evidence contains only domain status and correlation IDs.
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
EVIDENCE = Path(os.environ.get("MCP_EVIDENCE_DIR", "").strip() or ".phase2c-write-evidence")
RUN_ID = os.environ.get("GITHUB_RUN_ID", str(int(time.time())))

if not SERVER or not TOKEN:
    raise SystemExit("MCP_SERVER_URL and MCP_TOKEN are required")

EVIDENCE.mkdir(parents=True, exist_ok=True)
TMP = Path(tempfile.mkdtemp(prefix="phase2c-write-"))
CONFIG = TMP / "mcp.json"
CONFIG.write_text(json.dumps({"mcpServers": {"phase2c": {"type": "http", "url": SERVER, "headers": {"Authorization": f"Bearer {TOKEN}"}, "connectionTimeout": 10000, "requestTimeout": 60000}}}), encoding="utf-8")
CONFIG.chmod(0o600)

BASE = ["npx", "--yes", "@modelcontextprotocol/inspector@2.5.0", "--cli", "--config", str(CONFIG), "--server", "phase2c", "--stored-auth-only", "--format", "json"]
pending: list[tuple[str, str, dict[str, Any]]] = []
summary: list[dict[str, Any]] = []


def run_inspector(extra: list[str]) -> dict[str, Any]:
    proc = subprocess.run(BASE + extra, text=True, capture_output=True, check=False, env={**os.environ, "MCP_STORAGE_DIR": str(TMP / "storage"), "MCP_INSPECTOR_OAUTH_STATE_PATH": str(TMP / "oauth.json")})
    if proc.returncode != 0:
        raise RuntimeError(f"inspector failed ({proc.returncode}): {proc.stderr[-1200:]}")
    try:
        return json.loads(proc.stdout)
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"inspector returned invalid JSON: {proc.stdout[-1200:]}") from exc


def call(tool: str, args: dict[str, Any] | None = None) -> Any:
    payload = run_inspector(["--method", "tools/call", "--tool-name", tool, "--tool-args-json", json.dumps(args or {}, separators=(",", ":"))])
    result = payload.get("result") or {}
    if result.get("isError"):
        text = " ".join(str(row.get("text", "")) for row in result.get("content", []) if isinstance(row, dict) and row.get("type") == "text")
        raise RuntimeError(f"{tool}: {text or 'MCP tool returned isError:true'}")
    structured = result.get("structuredContent")
    if isinstance(structured, dict) and "data" in structured:
        return structured["data"]
    for row in result.get("content", []):
        if isinstance(row, dict) and row.get("type") == "text":
            try:
                return json.loads(row.get("text", ""))
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


def key_values(value: Any, key: str) -> list[Any]:
    return [obj[key] for obj in objects(value) if key in obj]


def any_verified(value: Any) -> bool:
    for obj in objects(value):
        if obj.get("verified") is True:
            return True
        if obj.get("status") == "verified":
            return True
    return False


def rollback_ok(value: Any) -> bool:
    for obj in objects(value):
        if obj.get("status") in ("rolled-back", "rolled_back"):
            return True
        if obj.get("rollback_restored_baseline") is True:
            return True
        rr = obj.get("rollback_result")
        if isinstance(rr, dict) and rr.get("verified") is True:
            return True
    return False


def ids(value: Any, keys: tuple[str, ...]) -> list[str]:
    found: set[str] = set()
    for obj in objects(value):
        for key in keys:
            val = obj.get(key)
            if isinstance(val, (str, int)) and str(val):
                found.add(str(val))
    return sorted(found)


def add_pending(domain: str, tool: str, operation_id: str) -> None:
    pending.append((domain, tool, {"operationId": operation_id, "confirm": True}))


def clear_pending(domain: str) -> None:
    pending[:] = [row for row in pending if row[0] != domain]


def record(domain: str, status: str, **extra: Any) -> None:
    row = {"domain": domain, "status": status, **extra}
    summary.append(row)
    print(f"{domain}: {status}")


def require_verified(tool: str, result: Any) -> None:
    if not any_verified(result):
        raise RuntimeError(f"{tool}: verification did not report verified=true")


def require_rollback(tool: str, result: Any) -> None:
    if not rollback_ok(result):
        raise RuntimeError(f"{tool}: rollback did not report restored/rolled-back state")


def pairing_fingerprint(value: Any) -> dict[str, Any]:
    root = value if isinstance(value, dict) else {}
    return {key: root.get(key) for key in ("post_id", "post_type", "status", "language", "counterpart_language", "counterpart_id", "counterpart_exists", "counterpart_type", "counterpart_status", "evidence_reference")}


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


def cleanup_pending() -> None:
    if not pending:
        return
    print("RECOVERY: rolling back pending operations...")
    for domain, tool, args in reversed(pending):
        try:
            call(tool, args)
            print(f"RECOVERY {domain}: rollback attempted")
        except Exception as exc:  # noqa: BLE001
            print(f"RECOVERY {domain}: rollback failed: {exc}")


try:
    run_inspector(["--method", "initialize"])
    print("HANDSHAKE: OK")

    # Insights: create a temporary draft, verify, delete via exact rollback.
    caps = call("research_capabilities")
    editorial_types: list[str] = []
    for value in key_values(caps, "editorial_types"):
        if isinstance(value, list):
            editorial_types.extend(item for item in value if isinstance(item, str) and item)
    if not editorial_types:
        raise RuntimeError("Insights: no live editorial type found in Research Manager capabilities")
    insight_type = editorial_types[0]
    before = call("site_insights_list", {"language": "en"})
    before_ids = ids(before, ("post_id",))
    marker = f"Phase 2C MCP acceptance {RUN_ID}"
    plan = call("site_insight_create_plan", {"data": {"title": marker, "excerpt": "Temporary reversible production acceptance draft.", "content": "Temporary reversible production acceptance draft.", "language": "en", "insight_type": insight_type, "status": "draft"}, "reason": "Phase 2C production MCP reversible acceptance"})
    plan_id = regex_string(plan, r"erm-remote-plan-[a-f0-9]{20}-[a-f0-9]{8}")
    applied = call("site_insight_apply", {"planId": plan_id, "confirm": True})
    op_id = regex_string(applied, r"erm-operation-[a-f0-9]{32}")
    add_pending("Insights", "site_insight_rollback", op_id)
    verified = call("site_insight_verify", {"operationId": op_id, "rendered": True})
    require_verified("site_insight_verify", verified)
    rolled = call("site_insight_rollback", {"operationId": op_id, "confirm": True})
    require_rollback("site_insight_rollback", rolled)
    clear_pending("Insights")
    after_ids = ids(call("site_insights_list", {"language": "en"}), ("post_id",))
    if before_ids != after_ids:
        raise RuntimeError("Insights: baseline ID inventory was not restored")
    record("Insights", "PASS", plan_id=plan_id, operation_id=op_id, baseline_restored=True)

    # Research Objects: temporary draft Output, then exact rollback.
    before_ids = ids(call("site_research_objects_list", {"kind": "output", "language": "en"}), ("post_id",))
    plan = call("site_research_object_create_plan", {"kind": "output", "data": {"title": marker, "excerpt": "Temporary reversible production acceptance object.", "content": "Temporary reversible production acceptance object.", "language": "en", "status": "draft"}})
    plan_id = regex_string(plan, r"erm-object-plan-[a-f0-9]{18}-[a-f0-9]{8}")
    applied = call("site_research_object_apply", {"planId": plan_id, "confirm": True})
    op_id = regex_string(applied, r"erm-object-op-[a-f0-9]{32}")
    add_pending("Research Objects", "site_research_object_rollback", op_id)
    verified = call("site_research_object_verify", {"operationId": op_id, "rendered": False})
    require_verified("site_research_object_verify", verified)
    rolled = call("site_research_object_rollback", {"operationId": op_id, "confirm": True})
    require_rollback("site_research_object_rollback", rolled)
    clear_pending("Research Objects")
    after_ids = ids(call("site_research_objects_list", {"kind": "output", "language": "en"}), ("post_id",))
    if before_ids != after_ids:
        raise RuntimeError("Research Objects: baseline ID inventory was not restored")
    record("Research Objects", "PASS", plan_id=plan_id, operation_id=op_id, baseline_restored=True)

    # Structured Translations: temporarily unpair one existing published EN/ES pair.
    paired: dict[str, Any] | None = None
    for post_type in ("research_output", "research_project", "research_software", "research_dataset", "research_line"):
        if paired:
            break
        for language in ("en", "es"):
            listing = call("site_translations_list", {"postType": post_type, "language": language})
            for obj in objects(listing):
                if isinstance(obj.get("post_id"), int) and isinstance(obj.get("counterpart_id"), int) and obj.get("counterpart_id", 0) > 0 and obj.get("counterpart_exists") is True:
                    paired = obj
                    break
            if paired:
                break
    if not paired:
        raise RuntimeError("Translations: no legitimate existing structured EN/ES pair is available for reversible production acceptance")
    post_id = int(paired["post_id"])
    translation_before = call("site_translation_inspect", {"postId": post_id})
    translation_fp = pairing_fingerprint(translation_before)
    plan = call("site_translation_unpair_plan", {"postId": post_id})
    plan_id = regex_string(plan, r"erm-translation-plan-[a-f0-9]{18}-[a-f0-9]{8}")
    applied = call("site_translation_apply", {"planId": plan_id, "confirm": True})
    op_id = regex_string(applied, r"erm-translation-op-[a-f0-9]{32}")
    add_pending("Translations", "site_translation_rollback", op_id)
    verified = call("site_translation_verify", {"operationId": op_id, "rendered": False})
    require_verified("site_translation_verify", verified)
    rolled = call("site_translation_rollback", {"operationId": op_id, "confirm": True})
    require_rollback("site_translation_rollback", rolled)
    clear_pending("Translations")
    if translation_fp != pairing_fingerprint(call("site_translation_inspect", {"postId": post_id})):
        raise RuntimeError("Translations: exact pairing baseline was not restored")
    record("Translations", "PASS", plan_id=plan_id, operation_id=op_id, baseline_restored=True)

    # Evidence: create an ordinary non-identity record, verify provenance readback, rollback.
    before_ids = ids(call("site_evidence_list", {"group": "methods"}), ("record_id", "id"))
    plan = call("site_evidence_create_plan", {"group": "methods", "data": {"title": marker, "summary": "Temporary reversible Phase 2C production acceptance evidence record.", "status": "unverified"}})
    plan_id = regex_string(plan, r"erm-evidence-plan-[a-f0-9]{18}-[a-f0-9]{8}")
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

    # Media: ingest one isolated 1x1 PNG, verify checksum/provenance, rollback deletion.
    media_title = f"Phase 2C acceptance pixel {RUN_ID}"
    before_ids = ids(call("site_media_list", {"search": media_title, "page": 1, "perPage": 100}), ("attachment_id", "post_id", "id"))
    png = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII="
    plan = call("site_media_ingest_plan", {"filename": f"phase2c-acceptance-{RUN_ID}.png", "mimeType": "image/png", "contentBase64": png, "altText": "Phase 2C acceptance pixel", "title": media_title})
    plan_id = regex_string(plan, r"erm-media-ingest-plan-[a-f0-9]{18}-[a-f0-9]{8}")
    applied = call("site_media_ingest_apply", {"planId": plan_id, "confirm": True})
    op_id = regex_string(applied, r"erm-media-ingest-op-[a-f0-9]{32}")
    add_pending("Media", "site_media_ingest_rollback", op_id)
    verified = call("site_media_ingest_verify", {"operationId": op_id})
    require_verified("site_media_ingest_verify", verified)
    rolled = call("site_media_ingest_rollback", {"operationId": op_id, "confirm": True})
    require_rollback("site_media_ingest_rollback", rolled)
    clear_pending("Media")
    after_ids = ids(call("site_media_list", {"search": media_title, "page": 1, "perPage": 100}), ("attachment_id", "post_id", "id"))
    if before_ids != after_ids:
        raise RuntimeError("Media: temporary attachment inventory was not restored")
    record("Media", "PASS", plan_id=plan_id, operation_id=op_id, baseline_restored=True)

    # Design: change density only to another registered preset, verify, rollback exact state.
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
    verified = call("site_design_preset_verify", {"operationId": op_id})
    require_verified("site_design_preset_verify", verified)
    rolled = call("site_design_preset_rollback", {"operationId": op_id, "confirm": True})
    require_rollback("site_design_preset_rollback", rolled)
    clear_pending("Design")
    if design_fp != design_fingerprint(call("site_design_inspect")):
        raise RuntimeError("Design: exact preset baseline was not restored")
    record("Design", "PASS", plan_id=plan_id, operation_id=op_id, baseline_restored=True)

    # SEO/GEO: strictly conditional on a current auto-remediable finding.
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
        verified = call("site_seo_geo_remediation_verify", {"operationId": op_id, "rendered": True})
        require_verified("site_seo_geo_remediation_verify", verified)
        rolled = call("site_seo_geo_remediation_rollback", {"operationId": op_id, "confirm": True})
        require_rollback("site_seo_geo_remediation_rollback", rolled)
        clear_pending("SEO/GEO")
        after_fp = seo_fingerprint(call("site_seo_geo_site"), check_id)
        if before_fp != after_fp:
            raise RuntimeError("SEO/GEO: diagnostic baseline was not restored")
        record("SEO/GEO", "PASS", check_id=check_id, plan_id=plan_id, operation_id=op_id, baseline_restored=True)

    evidence = {"ok": True, "phase": "phase2c", "surface": "production-mcp-write-acceptance", "server": SERVER, "run_id": RUN_ID, "navigation_previously_accepted": True, "domains": summary}
    (EVIDENCE / "summary.json").write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print("PASS: Phase 2C remaining MCP write acceptance")
finally:
    cleanup_pending()
    try:
        CONFIG.unlink(missing_ok=True)
    except OSError:
        pass
