#!/usr/bin/env python3
"""Fail-closed release guard for the two Eduardo Research Privacy Policy surfaces.

This is a factual authorization checkpoint, NOT legal advice and not a substitute
for human review. It never reads passwords, private addresses or account content.
It does not mutate WordPress. A8 remains the only legal-content write authority.
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
LEDGER = ROOT / "config" / "phase6-privacy-release-evidence.json"
REQUIREMENTS = (
    "controller_and_contact",
    "technical_site_processing",
    "email_processing",
    "lawful_bases",
    "contractual_host_and_email_processors",
    "recipients_and_international_transfers",
    "purpose_specific_retention",
    "browser_storage_and_analytics",
    "owner_review_of_final_bilingual_policy",
)
STATUS = {"pending", "observed", "verified"}
PLACEHOLDERS = re.compile(
    r"\b(?:MUST VERIFY|REQUIERE VALIDAR|TODO|TBD|PLACEHOLDER|PENDING APPROVAL|POR DETERMINAR)\b",
    flags=re.IGNORECASE,
)


class PrivacyReleaseBlocked(RuntimeError):
    """The independent privacy-facts or owner-review gate is not satisfied."""


def validate_ledger(data: Any) -> dict:
    """Validate closed schema; never infer approval from partial or malformed facts."""
    if not isinstance(data, dict) or set(data) != {
        "version", "tenant", "resource", "release_authorized", "languages", "requirements"
    }:
        raise PrivacyReleaseBlocked("privacy evidence ledger schema is invalid")
    if data["version"] != "1" or data["tenant"] != "eduardo-research" or data["resource"] != "privacy-policy":
        raise PrivacyReleaseBlocked("privacy evidence ledger identity mismatch")
    if type(data["release_authorized"]) is not bool:
        raise PrivacyReleaseBlocked("privacy release authorization is not a boolean")
    languages = data["languages"]
    if not isinstance(languages, dict) or set(languages) != {"en", "es"}:
        raise PrivacyReleaseBlocked("privacy language approval set mismatch")
    if any(value not in {"draft", "approved"} for value in languages.values()):
        raise PrivacyReleaseBlocked("privacy language approval value is invalid")
    requirements = data["requirements"]
    if not isinstance(requirements, dict) or set(requirements) != set(REQUIREMENTS):
        raise PrivacyReleaseBlocked("privacy evidence requirement set changed")
    for name in REQUIREMENTS:
        entry = requirements[name]
        if not isinstance(entry, dict) or set(entry) != {"status", "evidence"}:
            raise PrivacyReleaseBlocked(f"privacy evidence shape mismatch: {name}")
        if entry["status"] not in STATUS:
            raise PrivacyReleaseBlocked(f"privacy evidence status invalid: {name}")
        evidence = entry["evidence"]
        if not isinstance(evidence, str) or len(evidence) > 400 or any(c in evidence for c in "<>\x00"):
            raise PrivacyReleaseBlocked(f"privacy evidence provenance invalid: {name}")
        if entry["status"] == "verified" and len(evidence.strip()) < 12:
            raise PrivacyReleaseBlocked(f"verified privacy fact missing provenance: {name}")
    return data


def load_ledger(path: Path = LEDGER) -> dict:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        raise PrivacyReleaseBlocked("privacy evidence ledger unavailable") from exc
    return validate_ledger(data)


def assert_release_ready(ledger: dict, language: str, sections: list[dict]) -> None:
    """Fail before MCP Preview if owner-reviewed evidence is still unresolved."""
    ledger = validate_ledger(ledger)
    if language not in {"en", "es"}:
        raise PrivacyReleaseBlocked("privacy language is not supported")
    if ledger["release_authorized"] is not True:
        raise PrivacyReleaseBlocked("privacy release requires explicit owner authorization")
    if ledger["languages"] != {"en": "approved", "es": "approved"}:
        raise PrivacyReleaseBlocked("privacy requires approved final text in both languages")
    incomplete = [name for name in REQUIREMENTS if ledger["requirements"][name]["status"] != "verified"]
    if incomplete:
        raise PrivacyReleaseBlocked("privacy evidence not verified: " + ", ".join(incomplete))
    if not isinstance(sections, list) or not 2 <= len(sections) <= 6:
        raise PrivacyReleaseBlocked("privacy section count outside A8 contract")
    for section in sections:
        if not isinstance(section, dict) or set(section) != {"title", "body"}:
            raise PrivacyReleaseBlocked("privacy section structure invalid")
        title, body = section["title"], section["body"]
        if not isinstance(title, str) or not isinstance(body, str):
            raise PrivacyReleaseBlocked("privacy section text invalid")
        if PLACEHOLDERS.search(title) or PLACEHOLDERS.search(body):
            raise PrivacyReleaseBlocked("privacy copy contains unresolved editorial placeholders")


def self_test() -> None:
    current = load_ledger()
    assert current["release_authorized"] is False
    try:
        assert_release_ready(current, "es", [
            {"title": "Responsable", "body": "Eduardo Jose Yauri Luna, contacto hola@emmake.com."},
            {"title": "Derechos", "body": "Puede consultar sus derechos."}
        ])
    except PrivacyReleaseBlocked:
        pass
    else:
        raise AssertionError("current incomplete privacy evidence was accepted")
    fixture = {
        "version": "1", "tenant": "eduardo-research", "resource": "privacy-policy",
        "release_authorized": True, "languages": {"en": "approved", "es": "approved"},
        "requirements": {name: {"status": "verified", "evidence": "verified-owner-review:issue-315"}
                         for name in REQUIREMENTS},
    }
    sections = [
        {"title": "Controller", "body": "The individual owner is responsible for this academic website."},
        {"title": "Rights", "body": "Visitors can contact the owner to exercise applicable privacy rights."},
    ]
    assert_release_ready(fixture, "en", sections)
    fixture["requirements"]["purpose_specific_retention"]["status"] = "observed"
    try:
        assert_release_ready(fixture, "en", sections)
    except PrivacyReleaseBlocked:
        pass
    else:
        raise AssertionError("unverified retention was accepted")
    fixture["requirements"]["purpose_specific_retention"]["status"] = "verified"
    try:
        assert_release_ready(fixture, "es", [
            {"title": "Datos", "body": "REQUIERE VALIDAR: duración real de conservación."},
            sections[1],
        ])
    except PrivacyReleaseBlocked:
        pass
    else:
        raise AssertionError("placeholder content was accepted")
    print("PASS: Privacy EN/ES release gate rejects unresolved processing facts and placeholders")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    if args.self_test:
        self_test()
        return
    current = load_ledger()
    missing = [name for name in REQUIREMENTS if current["requirements"][name]["status"] != "verified"]
    print(json.dumps({
        "phase": "6",
        "resource": "privacy-policy",
        "release_ready": current["release_authorized"] and not missing
                         and current["languages"] == {"en": "approved", "es": "approved"},
        "pending_requirements": missing,
        "language_approvals": current["languages"],
        "unverified_is_not_automatically_approved": True,
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
