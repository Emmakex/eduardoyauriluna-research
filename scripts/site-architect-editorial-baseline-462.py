#!/usr/bin/env python3
"""One read-only live Theme inventory compare to archived public editorial slots.

No Plan, Apply, Verify or Rollback. No protected credentials or stored slot text
leave the OVH host. Historical snapshots are input evidence, not authority to
overwrite current WordPress state.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
from pathlib import Path
import sys
from typing import Any

HERE = Path(__file__).resolve().parent
SNAPSHOT_DIR = HERE / "editorial-snapshots"
BLUEPRINT = HERE / "eduardo-research-blueprint-audit.json"
SOURCE = HERE / "site-architect-hydration-readonly-audit.py"
PAIRS = ("home", "about", "cv", "contact")
LANGUAGES = ("en", "es")
POLICY = {
    "home": {"hero-eyebrow": "KEEP_LIVE",
             "research-lines-intro": "NEW_COPY",
             "selected-outputs-intro": "KEEP_LIVE"},
    "about": {"eyebrow": "KEEP_LIVE", "lead": "ADOPT_BLUEPRINT", "sections": "KEEP_LIVE"},
    "cv": {"lead": "KEEP_LIVE", "sections": "KEEP_LIVE"},
    "contact": {"sections": "KEEP_LIVE"},
}
EXPECTED_DELTAS = {
    "home": frozenset(("hero-eyebrow", "research-lines-intro", "selected-outputs-intro")),
    "about": frozenset(("eyebrow", "lead", "sections")),
    "cv": frozenset(("lead", "sections")),
    "contact": frozenset(("sections",)),
}
SNAPSHOTS = {
    ("home", "en"): "cmd-v2-resource-page-home-en-20261028-01.json",
    ("home", "es"): "cmd-v2-resource-page-home-es-20261028-01.json",
    ("about", "en"): "cmd-v2-resource-page-about-en-20261028-01.json",
    ("about", "es"): "cmd-v2-resource-page-about-es-20261028-01.json",
    ("cv", "en"): "cmd-phase5-cv-en-inspect-20261101-08.json",
    ("cv", "es"): "cmd-phase5-cv-es-inspect-20261101-09.json",
    ("contact", "en"): "cmd-v2-resource-page-contact-en-20261028-01.json",
    ("contact", "es"): "cmd-v2-resource-page-contact-es-20261028-01.json",
}
DECISIONS = ("KEEP_LIVE", "ADOPT_BLUEPRINT", "NEW_COPY", "DEFER")


class EditorialAuditError(Exception):
    pass


def trusted_module():
    spec = importlib.util.spec_from_file_location("editorial_hydration_source", SOURCE)
    if spec is None or spec.loader is None:
        raise EditorialAuditError("trusted audit dependency missing")
    mod = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = mod
    spec.loader.exec_module(mod)
    return mod


def reference_snapshot(path: Path, page: str, language: str) -> dict[str, Any]:
    try:
        source = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        raise EditorialAuditError("unavailable historical reference") from None
    if (not isinstance(source, dict) or source.get("status") != "success"
        or source.get("read_only") is not True or not isinstance(source.get("data"), dict)):
        raise EditorialAuditError("historical reference invalid")
    record = source["data"]
    if record.get("key") != page or record.get("language") != language:
        raise EditorialAuditError("historical reference identity mismatch")
    stored = record.get("stored_slots")
    if not isinstance(stored, dict) or not all(isinstance(k, str) for k in stored):
        raise EditorialAuditError("historical stored slot reference missing")
    return stored


def review_rows(inventory: Any, blueprint: dict[str, Any],
                snapshots: dict[tuple[str, str], dict[str, Any]]) -> dict[str, Any]:
    if (not isinstance(inventory, dict) or inventory.get("count") != 12
        or inventory.get("languages") != list(LANGUAGES)):
        raise EditorialAuditError("live inventory contract mismatch")
    items = inventory.get("items")
    if not isinstance(items, list) or len(items) != 12:
        raise EditorialAuditError("incomplete live inventory")
    mapped = {item.get("key"): item for item in items if isinstance(item, dict)}
    if len(mapped) != 12 or set(mapped) != set(blueprint):
        raise EditorialAuditError("live page keys outside canonical Blueprint")
    output: list[dict[str, Any]] = []
    match_count = 0
    changes_count = 0
    for page in PAIRS:
        record = mapped[page]
        if record.get("exists") is not True:
            raise EditorialAuditError("expected page absent")
        langs = record.get("languages")
        if not isinstance(langs, dict):
            raise EditorialAuditError("invalid live language storage")
        for language in LANGUAGES:
            state = langs.get(language)
            if not isinstance(state, dict) or state.get("available") is not True:
                raise EditorialAuditError("live language unavailable")
            if state.get("contract_aligned") is not True:
                raise EditorialAuditError("live Theme contract drift")
            stored = state.get("stored_slots")
            allowed = state.get("allowed_slots")
            if (not isinstance(stored, dict) or not isinstance(allowed, list)
                or not all(isinstance(k, str) for k in allowed)
                or not set(stored).issubset(set(allowed))):
                raise EditorialAuditError("invalid Theme slot storage")
            historical = snapshots.get((page, language))
            if historical is None:
                raise EditorialAuditError("missing historical baseline")
            historical_match = stored == historical
            changes = sorted(k for k, v in blueprint[page][language].items()
                             if k not in stored or type(stored[k]) is not type(v) or stored[k] != v)
            if set(changes) != EXPECTED_DELTAS[page]:
                # Do not apply historical editorial classification to a changed source.
                historical_match = False
            if historical_match:
                match_count += 1
            changes_count += len(changes)
            output.append({
                "page": page,
                "language": language,
                "archival_baseline_confirmed": historical_match,
                "changed_slot_names": changes,
                "recommendations": {
                    slot: POLICY[page][slot] if historical_match and slot in POLICY[page] else "REVIEW_LIVE_DRIFT"
                    for slot in changes
                },
            })
    tally = {code: sum(
        1 for row in output for status in row["recommendations"].values() if status == code)
        for code in DECISIONS}
    return {
        "issue": 462, "status": "read_only",
        "current_archival_matches": match_count,
        "checked_page_language_pairs": len(output),
        "checked_changed_slots": changes_count,
        "all_priority_baselines_current": match_count == 8,
        "decisions": tally,
        "rows": output,
        "identity_change": "not_directly_verified",
        "public_site_apply": "not_executed",
        "values_exported": False,
        "secrets_exported": False,
    }


def self_test() -> None:
    assert len(SNAPSHOTS) == 8 and set(POLICY) == set(PAIRS)
    assert sum(len(v) for v in POLICY.values()) * 2 == 18
    assert all(set(POLICY[k]) == EXPECTED_DELTAS[k] for k in PAIRS)
    desired = {f"k{i}": {lang: {"lead": "desired"} for lang in LANGUAGES} for i in range(12)}
    desired.update({k: {lang: dict.fromkeys(EXPECTED_DELTAS[k], "new") for lang in LANGUAGES}
                    for k in PAIRS})
    items, snaps = [], {}
    for key, pair in desired.items():
        states = {}
        for lang, fields in pair.items():
            old = {f: "old" for f in fields}
            states[lang] = {"available": True, "contract_aligned": True,
                            "allowed_slots": list(fields), "stored_slots": old}
            if key in PAIRS:
                snaps[(key, lang)] = old.copy()
        items.append({"key": key, "exists": True, "languages": states})
    live = {"count": 12, "languages": list(LANGUAGES), "items": items}
    ok = review_rows(live, desired, snaps)
    assert ok["current_archival_matches"] == 8
    assert ok["decisions"] == {"KEEP_LIVE": 14, "ADOPT_BLUEPRINT": 2, "NEW_COPY": 2, "DEFER": 0}
    assert ok["checked_changed_slots"] == 18
    live["items"][0]["languages"]["en"]["stored_slots"]["lead"] = "changed"
    # k0 is outside priority, so it should not invalidate priority baseline.
    assert review_rows(live, desired, snaps)["all_priority_baselines_current"] is True
    for item in items:
        if item["key"] == "home":
            item["languages"]["es"]["stored_slots"]["hero-eyebrow"] = "late editorial edit"
    changed = review_rows(live, desired, snaps)
    assert changed["current_archival_matches"] == 7
    assert changed["rows"][1]["recommendations"]["hero-eyebrow"] == "REVIEW_LIVE_DRIFT"
    assert "late editorial edit" not in json.dumps(changed)
    print("PASS: narrow 18-slot editorial live-baseline guard; no content or secrets exported")


def main() -> int:
    parser = argparse.ArgumentParser()
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--self-test", action="store_true")
    modes.add_argument("--inspect-live", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            self_test()
            return 0
        mod = trusted_module()
        desired = mod.blueprint_records(BLUEPRINT.read_bytes())
        inventory = mod.read_live_inventory()
        snapshots = {
            (page, lang): reference_snapshot(SNAPSHOT_DIR / filename, page, lang)
            for (page, lang), filename in SNAPSHOTS.items()
        }
        result = review_rows(inventory, desired, snapshots)
        print("SITE_ARCHITECT_462_EDITORIAL_BASELINE " + json.dumps(result, sort_keys=True, separators=(",", ":")))
        return 0 if result["all_priority_baselines_current"] else 2
    except Exception:
        print("ERROR: bounded editorial read-only comparison failed closed; no public Apply", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
