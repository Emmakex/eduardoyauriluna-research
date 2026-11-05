#!/usr/bin/env python3
"""Read-only comparison of Eduardo Greenfield Blueprint to live Theme slots.

Run on protected OVH. One MCP site_pages_list read, no Plan, Apply or mutation.
Print only bounded metadata and slot names, never actual page values or credentials.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
from typing import Any

HERE = Path(__file__).resolve().parent
BLUEPRINT = HERE / "eduardo-research-blueprint-audit.json"
EXPECTED_SHA = "11a9c908affae5a1636a42ebf440eb223c8fa2002faecbb45d71a8f1e00c0b6c"
EXPECTED_CHANGE_COUNT = 17
EXPECTED_PAGE_COUNT = 12
LANGUAGES = ("en", "es")
READ_TOOL = "site_pages_list"
ALLOWED_STATUSES = ("stored-mismatch", "already-stored")

spec = importlib.util.spec_from_file_location("audit_protected_preview", HERE / "site-architect-greenfield-protected-preview.py")
if spec is None or spec.loader is None:
    raise SystemExit("ERROR: protected Preview transport module is unavailable")
protected = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = protected
spec.loader.exec_module(protected)


class AuditError(RuntimeError):
    pass


def blueprint_records(raw: bytes, expected_sha: str = EXPECTED_SHA) -> dict[str, dict[str, dict[str, Any]]]:
    if hashlib.sha256(raw).hexdigest() != expected_sha:
        raise AuditError("bundled Blueprint hash differs from accepted Preview")
    try:
        blueprint = json.loads(raw)
    except (TypeError, ValueError):
        raise AuditError("invalid canonical Blueprint") from None
    if not isinstance(blueprint, dict) or blueprint.get("languages") != list(LANGUAGES):
        raise AuditError("canonical Blueprint language contract changed")
    pages = blueprint.get("pages")
    if not isinstance(pages, list) or len(pages) != EXPECTED_PAGE_COUNT:
        raise AuditError("canonical Blueprint page count changed")
    found: dict[str, dict[str, dict[str, Any]]] = {}
    for item in pages:
        if not isinstance(item, dict):
            raise AuditError("invalid Blueprint page")
        key, slots = item.get("key"), item.get("slots")
        if not isinstance(key, str) or not key or key in found:
            raise AuditError("ambiguous Blueprint page key")
        if not isinstance(slots, dict) or set(slots) != set(LANGUAGES):
            raise AuditError("incomplete Blueprint language slots")
        if not all(isinstance(slots[lang], dict) and slots[lang] for lang in LANGUAGES):
            raise AuditError("empty Blueprint language slots")
        found[key] = slots
    return found


def _same(a: Any, b: Any) -> bool:
    return type(a) is type(b) and a == b


def summarize(blueprint: dict[str, dict[str, dict[str, Any]]], inventory: Any) -> dict[str, Any]:
    if not isinstance(inventory, dict) or inventory.get("count") != EXPECTED_PAGE_COUNT:
        raise AuditError("invalid live Theme inventory shape")
    if inventory.get("languages") != list(LANGUAGES):
        raise AuditError("live Theme languages differ from canonical Blueprint")
    records = inventory.get("items")
    if not isinstance(records, list) or len(records) != EXPECTED_PAGE_COUNT:
        raise AuditError("missing live Theme pages")
    rows: list[dict[str, Any]] = []
    seen: set[str] = set()
    for page in records:
        if not isinstance(page, dict):
            raise AuditError("malformed Theme page")
        key = page.get("key")
        if not isinstance(key, str) or key not in blueprint or key in seen:
            raise AuditError("unrecognized or duplicate Theme page")
        seen.add(key)
        if page.get("exists") is not True:
            raise AuditError("Theme page is not present")
        languages = page.get("languages")
        if not isinstance(languages, dict) or set(languages) != set(LANGUAGES):
            raise AuditError("live Theme language surfaces are incomplete")
        for language in LANGUAGES:
            state = languages[language]
            if not isinstance(state, dict) or state.get("available") is not True:
                raise AuditError("live Theme language page unavailable")
            allowed = state.get("allowed_slots")
            stored = state.get("stored_slots")
            if not isinstance(allowed, list) or not all(isinstance(v, str) for v in allowed) or len(set(allowed)) != len(allowed):
                raise AuditError("invalid Theme-owned slot schema")
            if not isinstance(stored, dict) or not set(stored).issubset(set(allowed)):
                raise AuditError("invalid or unsupported live slot state")
            desired = blueprint[key][language]
            if not set(desired).issubset(set(allowed)):
                raise AuditError("Blueprint slot is outside live Theme Contract")
            diff_slots = sorted(slot for slot, value in desired.items()
                                if slot not in stored or not _same(stored[slot], value))
            contract_ok = state.get("contract_aligned")
            if type(contract_ok) is not bool:
                raise AuditError("Theme contract alignment unavailable")
            changed = bool(diff_slots) or not contract_ok
            # No stored, effective, expected or credential values ever leave OVH.
            rows.append({
                "page": key, "language": language,
                "status": "stored-mismatch" if changed else "already-stored",
                "changed_slot_names": diff_slots,
                "changed_slot_count": len(diff_slots),
                "contract_aligned": contract_ok,
            })
    if seen != set(blueprint):
        raise AuditError("live Theme page set differs from Blueprint")
    page_changed = sum(row["status"] == "stored-mismatch" for row in rows)
    # 25 operations = 24 page × language + 1 conditional identity seed.
    # The identity state cannot be proven from site_pages_list alone.
    identity_inferred = EXPECTED_CHANGE_COUNT - page_changed
    return {
        "status": "inspected",
        "issue": 460,
        "mode": "read_only",
        "tool": READ_TOOL,
        "blueprint_sha256": EXPECTED_SHA,
        "page_count": EXPECTED_PAGE_COUNT,
        "page_language_pairs": len(rows),
        "page_pairs_with_stored_delta": page_changed,
        "preview_total_hydration_operations": 25,
        "preview_total_hydration_changes": EXPECTED_CHANGE_COUNT,
        "identity_change_inferred": identity_inferred if identity_inferred in (0, 1) else None,
        "reconciled_with_preview": identity_inferred in (0, 1),
        "rows": rows,
        "public_site_apply": "not_executed",
        "values_exported": False,
    }


def read_live_inventory() -> Any:
    values = protected.preflight.protected_vars(protected.ENV)
    bearer = protected.resolve_bearer(values)
    client = protected.ProtectedMcp(bearer)
    initialized = client.post({
        "jsonrpc": "2.0", "id": 1, "method": "initialize",
        "params": {"protocolVersion": protected.PROTOCOL, "capabilities": {},
                   "clientInfo": {"name": "site-architect-hydration-readonly", "version": "1.0.0"}},
    }, 1)
    if not isinstance(initialized, dict) or not isinstance(initialized.get("protocolVersion"), str):
        raise AuditError("MCP read-only audit handshake failed")
    client.post({"jsonrpc": "2.0", "method": "notifications/initialized"}, None)
    response = client.post({
        "jsonrpc": "2.0", "id": 2, "method": "tools/call",
        "params": {"name": READ_TOOL, "arguments": {}},
    }, 2)
    if not isinstance(response, dict) or response.get("isError") is True:
        raise AuditError("MCP page inventory read failed")
    structured = response.get("structuredContent")
    if isinstance(structured, dict) and isinstance(structured.get("data"), dict):
        return structured["data"]
    for item in response.get("content", []):
        if isinstance(item, dict) and item.get("type") == "text":
            try:
                value = json.loads(item.get("text", ""))
            except (TypeError, ValueError):
                continue
            if isinstance(value, dict):
                return value
    raise AuditError("MCP page inventory data unavailable")


def self_test() -> int:
    fake = {f"page-{n}": {lang: {"lead": f"value {n} {lang}", "sections": ["A"]}
                            for lang in LANGUAGES}
            for n in range(EXPECTED_PAGE_COUNT)}
    blueprint = {"languages": list(LANGUAGES), "pages":
                 [{"key": key, "slots": slots} for key, slots in fake.items()]}
    raw = json.dumps(blueprint).encode()
    digest = hashlib.sha256(raw).hexdigest()
    assert blueprint_records(raw, digest) == fake
    data = {"count": EXPECTED_PAGE_COUNT, "languages": list(LANGUAGES), "items": []}
    for key, slots in fake.items():
        data["items"].append({
            "key": key, "exists": True,
            "languages": {lang: {"available": True, "contract_aligned": True,
                                 "allowed_slots": ["lead", "sections"],
                                 "stored_slots": dict(slots[lang])}
                          for lang in LANGUAGES},
        })
    exact = summarize(fake, data)
    assert exact["page_pairs_with_stored_delta"] == 0
    assert exact["reconciled_with_preview"] is False
    data["items"][0]["languages"]["en"]["stored_slots"]["lead"] = "different"
    changed = summarize(fake, data)
    assert changed["rows"][0]["changed_slot_names"] == ["lead"]
    assert changed["page_pairs_with_stored_delta"] == 1
    assert "different" not in json.dumps(changed)
    assert "value 0 en" not in json.dumps(changed)
    data["items"][0]["languages"]["en"]["allowed_slots"] = ["sections"]
    try:
        summarize(fake, data)
    except AuditError:
        pass
    else:
        raise AssertionError("invalid Theme schema unexpectedly accepted")
    try:
        blueprint_records(raw, "f" * 64)
    except AuditError:
        pass
    else:
        raise AssertionError("Blueprint provenance drift unexpectedly accepted")
    print("PASS: read-only Hydration inventory audit uses only allowlisted fields and redacted slot names")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--self-test", action="store_true")
    modes.add_argument("--inspect-live", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            return self_test()
        desired = blueprint_records(BLUEPRINT.read_bytes())
        output = summarize(desired, read_live_inventory())
        print("GREENFIELD_HYDRATION_READONLY_AUDIT " + json.dumps(output, sort_keys=True, separators=(",", ":")))
        # Drift is a valid audit result, but it is not a verified 17-change match.
        return 0
    except Exception:
        print("ERROR: protected hydration read-only audit not certified; no Apply requested", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
