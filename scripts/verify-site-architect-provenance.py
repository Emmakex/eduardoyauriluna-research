#!/usr/bin/env python3
"""Fail closed if an imported Site Architect source tree drifts from its upstream export."""
from __future__ import annotations

import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "wordpress/plugins/eduardo-research-manager"
PROVENANCE = ROOT / "UPSTREAM_PROVENANCE.json"
SOURCE_COMMIT = ROOT / "SOURCE_COMMIT"


def die(message: str) -> None:
    raise SystemExit("FAIL: upstream Site Architect provenance: " + message)


def sha(blob: bytes) -> str:
    return hashlib.sha256(blob).hexdigest()


def main() -> None:
    if not PROVENANCE.is_file() or not SOURCE_COMMIT.is_file():
        die("missing upstream provenance or exact source commit")
    try:
        manifest = json.loads(PROVENANCE.read_text(encoding="utf-8"))
    except (ValueError, OSError) as exc:
        die("unreadable source inventory: " + type(exc).__name__)
    if not isinstance(manifest, dict):
        die("invalid source inventory")
    commit = SOURCE_COMMIT.read_text(encoding="utf-8").strip()
    if not re.fullmatch(r"[0-9a-f]{40}", commit):
        die("source commit must be an exact SHA")
    if manifest.get("schema_version") != 1 or manifest.get("product") != "Site Architect":
        die("unexpected provenance schema/product")
    if (manifest.get("source_repository") != "Emmakex/eduardoyauriluna-research"
            or manifest.get("source_commit") != commit
            or manifest.get("plugin_path") != "wordpress/plugins/eduardo-research-manager"
            or manifest.get("plugin_version") != "0.11.1"):
        die("upstream source identity mismatch")
    inventory = manifest.get("plugin_files")
    if not isinstance(inventory, list) or len(inventory) < 50:
        die("incomplete provenance inventory")
    expected = {}
    for item in inventory:
        if (not isinstance(item, dict) or set(item) != {"path", "sha256"}
                or not isinstance(item["path"], str)
                or not isinstance(item["sha256"], str)
                or not re.fullmatch(r"[0-9a-f]{64}", item["sha256"])):
            die("invalid per-file provenance item")
        path = item["path"]
        if (not path or path.startswith("/") or "\\" in path
                or any(part in ("", ".", "..") for part in path.split("/"))
                or path in expected):
            die("unsafe or duplicate inventory path")
        expected[path] = item["sha256"]
    if len(expected) != len(inventory) or list(expected) != sorted(expected):
        die("duplicate or unsorted inventory")
    if not SOURCE.is_dir() or SOURCE.is_symlink():
        die("source plugin directory missing or linked")
    found = {}
    for path in SOURCE.rglob("*"):
        if path.is_symlink():
            die("symlink forbidden in plugin source")
        if path.is_file():
            relative = path.relative_to(SOURCE).as_posix()
            found[relative] = sha(path.read_bytes())
    if found != expected:
        missing = sorted(expected.keys() - found.keys())
        extra = sorted(found.keys() - expected.keys())
        changed = sorted(k for k in expected.keys() & found.keys() if expected[k] != found[k])
        die(f"source inventory drift: missing={len(missing)} extra={len(extra)} changed={len(changed)}")
    header = (SOURCE / "eduardo-research-manager.php").read_text(encoding="utf-8")
    if "Plugin Name: Site Architect" not in header or "Version: 0.11.1" not in header:
        die("plugin identity drift")
    print(f"PASS: Site Architect 0.11.1 upstream source parity for {len(found)} files at {commit}")


if __name__ == "__main__":
    main()
