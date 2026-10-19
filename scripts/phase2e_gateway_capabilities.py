#!/usr/bin/env python3
"""Canonical reviewed capability contract for the ChatGPT GitHub MCP principal.

This module is source-of-truth only. Importing it never mutates production.
The manifest is intentionally validated against the exact capability set that has
already been accepted in production so an unreviewed scope cannot enter through
an Infrastructure-as-Code edit alone.

Repository executions read ``config/phase2e-gateway-capability-manifest.json``.
OVH staging executions may copy the same manifest beside this module in ``/tmp``;
that sibling fallback keeps provisioning/expansion scripts self-contained without
weakening the reviewed capability contract.
"""
from __future__ import annotations

import json
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
REPO_MANIFEST_PATH = ROOT / "config" / "phase2e-gateway-capability-manifest.json"
STAGED_MANIFEST_PATH = Path(__file__).with_name("phase2e-gateway-capability-manifest.json")
EXPECTED_TENANT = "eduardo-research"
EXPECTED_PRINCIPAL_ID = "phase2d-github"
BASELINE_CAPABILITIES = (
    "site.read",
    "insights.write",
    "operations.rollback",
)
REVIEWED_PHASE2E_ADDITIONS = (
    "pages.write",
    "translations.write",
    "research.write",
    "seo.read",
    "seo.write",
)
EXPECTED_CAPABILITIES = BASELINE_CAPABILITIES + REVIEWED_PHASE2E_ADDITIONS


class CapabilityManifestError(RuntimeError):
    pass


def default_manifest_path() -> Path:
    if REPO_MANIFEST_PATH.is_file():
        return REPO_MANIFEST_PATH
    if STAGED_MANIFEST_PATH.is_file():
        return STAGED_MANIFEST_PATH
    raise CapabilityManifestError("gateway capability manifest is not available in repository or staged form")


def load_manifest(path: Path | None = None) -> dict[str, Any]:
    if path is None:
        path = default_manifest_path()
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise CapabilityManifestError("gateway capability manifest is unreadable") from exc
    if not isinstance(value, dict):
        raise CapabilityManifestError("gateway capability manifest must be an object")
    if set(value) != {"version", "tenant", "principal_id", "capabilities"}:
        raise CapabilityManifestError("gateway capability manifest keys changed unexpectedly")
    if value.get("version") != "1":
        raise CapabilityManifestError("unsupported gateway capability manifest version")
    if value.get("tenant") != EXPECTED_TENANT:
        raise CapabilityManifestError("gateway capability tenant changed unexpectedly")
    if value.get("principal_id") != EXPECTED_PRINCIPAL_ID:
        raise CapabilityManifestError("gateway capability principal changed unexpectedly")
    capabilities = value.get("capabilities")
    if not isinstance(capabilities, list) or not all(isinstance(item, str) and item for item in capabilities):
        raise CapabilityManifestError("gateway capabilities must be a non-empty-string array")
    if len(capabilities) != len(set(capabilities)):
        raise CapabilityManifestError("gateway capability manifest contains duplicates")
    if tuple(capabilities) != EXPECTED_CAPABILITIES:
        raise CapabilityManifestError("gateway capability manifest differs from the reviewed production contract")
    return value


def reviewed_capabilities(path: Path | None = None) -> list[str]:
    return list(load_manifest(path)["capabilities"])


def validate_subset(capabilities: list[str] | tuple[str, ...], path: Path | None = None) -> list[str]:
    if not capabilities or not all(isinstance(item, str) and item for item in capabilities):
        raise CapabilityManifestError("requested capability subset must contain non-empty strings")
    requested = list(capabilities)
    if len(requested) != len(set(requested)):
        raise CapabilityManifestError("requested capability subset contains duplicates")
    reviewed = reviewed_capabilities(path)
    unknown = [item for item in requested if item not in reviewed]
    if unknown:
        raise CapabilityManifestError("requested capability subset contains unreviewed scopes: " + ", ".join(unknown))
    return requested


def self_test() -> int:
    manifest = load_manifest()
    if tuple(manifest["capabilities"]) != EXPECTED_CAPABILITIES:
        raise CapabilityManifestError("self-test capability ordering mismatch")
    if validate_subset(["pages.write", "seo.read"]) != ["pages.write", "seo.read"]:
        raise CapabilityManifestError("self-test reviewed subset mismatch")
    try:
        validate_subset(["admin.write"])
    except CapabilityManifestError:
        pass
    else:
        raise CapabilityManifestError("self-test accepted an unreviewed capability")
    print("phase2e gateway capability manifest self-test: OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(self_test())
