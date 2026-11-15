#!/usr/bin/env python3
"""Strict validation of #475.22 redacted WordPress inventory.

Clone gate must fail if a fixture looks production-ready but lacks owned
pages, plugin authority, Theme contract, or safe aggregate-only schema.
Production snapshots remain separately unverified until explicitly obtained.
"""
from __future__ import annotations
import argparse
import hashlib
import json
from pathlib import Path
import sys

REQUIRED_TOP_LEVEL = {
    "schema_version","origin","read_only_transaction","site_host_verified",
    "observed_at_utc","plugin","theme","owned_pages","page_language_count",
    "aligned_page_language_count","post_status_counts","media_type_counts",
    "menu_item_counts","remote","excluded_sensitive_fields","certification",
}
FORBIDDEN_KEYS = {
    "token", "token_hash", "token_prefix", "connection_id", "wordpress_user_id",
    "user_email", "password", "secret", "username", "post_content", "post_title",
    "media_path", "filename", "attachment_id", "post_id", "private_url",
    "scopes", "raw_options", "email", "author_id", "staged_path", "nonce",
}
SAFE_PAGE_KEYS = {
    "key","language","exists","status","contract_aligned",
    "stored_slot_count","allowed_slot_count",
}
SAFE_REMOTE_KEYS = {
    "primary_configured","primary_enabled","credential_present","scope_count",
    "write_scope_count","temporary_active_count",
}

def reject_sensitive(node: object, location: str = "root") -> None:
    if isinstance(node, dict):
        for key, value in node.items():
            if key in FORBIDDEN_KEYS:
                raise ValueError("Sensitive field in inventory at " + location + "." + key)
            reject_sensitive(value, location + "." + key)
    elif isinstance(node, list):
        for index, item in enumerate(node):
            reject_sensitive(item, location + "[" + str(index) + "]")

def validate(report: dict, *, expected_origin: str, clone_http: dict | None = None,
             source_entrypoint: Path | None = None) -> dict:
    if not isinstance(report, dict) or set(report) != REQUIRED_TOP_LEVEL:
        raise ValueError("Unexpected or incomplete private inventory envelope")
    reject_sensitive(report)
    if report["schema_version"] != 1 or report["origin"] != expected_origin:
        raise ValueError("Report schema/origin mismatch")
    if report["read_only_transaction"] is not True or report["site_host_verified"] is not True:
        raise ValueError("Read-only transaction and strict site pin are mandatory")
    if report["certification"] != "PRIVATE_READBACK_ONLY_NOT_CUTOVER":
        raise ValueError("Private inventory must not imply cutover certification")
    plugin = report["plugin"]
    if set(plugin) != {"active","product","version","entrypoint_sha256"}:
        raise ValueError("Unexpected Plugin fields")
    if not isinstance(plugin["active"], bool) or (
        plugin["entrypoint_sha256"] is not None
        and (not isinstance(plugin["entrypoint_sha256"], str)
             or len(plugin["entrypoint_sha256"]) != 64)
    ):
        raise ValueError("Invalid Plugin integrity metadata")
    theme = report["theme"]
    if set(theme) != {
        "stylesheet","version","preset_id","preset_version","compatible",
        "languages","page_contract_count",
    }:
        raise ValueError("Unexpected Theme fields")
    pages = report["owned_pages"]
    if not isinstance(pages, list):
        raise ValueError("Theme Page inventory must be a list")
    for item in pages:
        if not isinstance(item, dict) or set(item) != SAFE_PAGE_KEYS:
            raise ValueError("Owned Page has unexpected or sensitive fields")
        if not isinstance(item["contract_aligned"], bool) or not isinstance(item["exists"], bool):
            raise ValueError("Owned Page contract status must be boolean")
        if any(not isinstance(item[k], int) or item[k] < 0
               for k in ("allowed_slot_count","stored_slot_count")):
            raise ValueError("Slot counts must be bounded nonnegative integers")
        if item["stored_slot_count"] > item["allowed_slot_count"]:
            raise ValueError("Stored Page slots cannot exceed Theme schema")
    if report["page_language_count"] != len(pages) or report["aligned_page_language_count"] != sum(
            item["contract_aligned"] for item in pages):
        raise ValueError("Page/contract counters inconsistent")
    remote = report["remote"]
    if not isinstance(remote, dict) or set(remote) != SAFE_REMOTE_KEYS:
        raise ValueError("Remote state must expose counts/flags only")
    for key in ("primary_configured","primary_enabled","credential_present"):
        if not isinstance(remote[key], bool):
            raise ValueError("Remote flags must be booleans")
    for key in ("scope_count","write_scope_count","temporary_active_count"):
        if not isinstance(remote[key], int) or remote[key] < 0:
            raise ValueError("Remote scope/credential counts invalid")
    if remote["write_scope_count"] > remote["scope_count"]:
        raise ValueError("Write scopes cannot exceed all scopes")
    for t, statuses in report["post_status_counts"].items():
        if not isinstance(t, str) or not isinstance(statuses, dict):
            raise ValueError("Invalid aggregate post grouping")
        for status, count in statuses.items():
            if not isinstance(status, str) or not isinstance(count, int) or count < 0:
                raise ValueError("Invalid aggregate post count")
    if set(report["media_type_counts"]) != {"image","application","other"}:
        raise ValueError("Media summary has unexpected categories")
    if any(not isinstance(x, int) or x < 0 for x in report["media_type_counts"].values()):
        raise ValueError("Media count invalid")

    result = {
        "origin": expected_origin,
        "record_count": len(pages),
        "public_http_consistency": None,
        "entrypoint_integrity": "NOT_CHECKED",
    }
    if expected_origin == "production":
        # Validate the *source* entrypoint independently from the live WP file.
        # An otherwise valid read-only report does not certify product source parity.
        if source_entrypoint is None:
            result["entrypoint_integrity"] = "UNVERIFIED"
        else:
            if not source_entrypoint.is_file():
                raise ValueError("Canonical Site Architect source entrypoint unavailable")
            source_sha256 = hashlib.sha256(source_entrypoint.read_bytes()).hexdigest()
            observed_sha256 = plugin["entrypoint_sha256"]
            result["entrypoint_integrity"] = (
                "MATCH" if observed_sha256 == source_sha256 else "DRIFT_REVIEW"
            )
    if expected_origin == "clone":
        if not (plugin["active"] and plugin["product"] == "Site Architect"
                and plugin["version"] == "0.11.1" and theme["stylesheet"] == "eduardo-research"
                and theme["preset_id"] == "research" and theme["compatible"] is True
                and theme["languages"] == ["en","es"] and theme["page_contract_count"] == 12
                and len(pages) == 24 and report["aligned_page_language_count"] == 24):
            raise ValueError("Real exported Site Architect / Theme clone is not complete")
        if clone_http is not None:
            if not clone_http.get("pass") or not clone_http.get("hydrate_rollback_verified"):
                raise ValueError("Expected certified and rolled-back clone HTTP evidence")
            by_id = {p["key"] + "/" + p["language"]: p for p in pages}
            if len(by_id) != 24:
                raise ValueError("Duplicate/missing Theme language slots")
            statuses = {
                **{key:200 for key in clone_http.get("pages_verified", {})},
                **{key:404 for key in clone_http.get("pages_not_public", {})},
            }
            if len(statuses) != 24:
                raise ValueError("The clone HTTP report did not cover every page/language")
            for key, item in by_id.items():
                if key not in statuses:
                    raise ValueError("Missing clone HTTP page " + key)
                if item["status"] != "publish" and statuses[key] == 200:
                    raise ValueError("Private draft Page is publicly visible in the clone: " + key)
            result["public_http_consistency"] = True
    return result

def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", required=True, type=Path)
    parser.add_argument("--origin", required=True, choices=["clone","production"])
    parser.add_argument("--clone-http", type=Path)
    parser.add_argument("--source-entrypoint", type=Path)
    args = parser.parse_args()
    report = json.loads(args.report.read_text(encoding="utf-8"))
    clone = json.loads(args.clone_http.read_text(encoding="utf-8")) if args.clone_http else None
    result = validate(
        report, expected_origin=args.origin, clone_http=clone,
        source_entrypoint=args.source_entrypoint,
    )
    if args.origin == "production" and result["entrypoint_integrity"] != "MATCH":
        print(
            "DRIFT_REVIEW: native readback schema is valid, but canonical "
            "Site Architect source integrity is not verified. "
            "No plugin replacement or production cutover authorized. "
            + json.dumps(result, sort_keys=True),
            file=sys.stderr,
        )
        sys.exit(2)
    print("PASS: #475.22 redact-only private inventory validation: "
          + json.dumps(result, sort_keys=True))
    if args.origin == "production":
        print("NOT CERTIFIED: this is private readback, not production cutover or visual parity")

if __name__ == "__main__":
    main()
