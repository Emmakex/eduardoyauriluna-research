#!/usr/bin/env python3
"""Prove the exported product tree builds the EXACT same WordPress ZIP on its own."""
from __future__ import annotations

import hashlib
import json
import subprocess
import sys
from pathlib import Path
from tempfile import TemporaryDirectory
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "wordpress/plugins/eduardo-research-manager"
EXPORTER = ROOT / "scripts/export-site-architect-product.py"
BASE_BUILDER = ROOT / "scripts/build-site-architect-distribution.py"
ARCHIVE = "site-architect-0.11.1-standalone-source-candidate.zip"
INSTALLABLE = "site-architect-0.11.1-research-candidate.zip"
REVISION = "a" * 40


def check(ok: bool, msg: str) -> None:
    if not ok:
        raise SystemExit("FAIL: " + msg)


def run(*args: str, cwd: Path) -> None:
    subprocess.run([sys.executable, *args], check=True, cwd=cwd, stdout=subprocess.DEVNULL)


def digest(blob: bytes) -> str:
    return hashlib.sha256(blob).hexdigest()


with TemporaryDirectory() as tmp:
    base = Path(tmp)
    a, b = base / "first", base / "second"
    run(str(EXPORTER), "--source-commit", REVISION, "--output", str(a), cwd=ROOT)
    run(str(EXPORTER), "--source-commit", REVISION, "--output", str(b), cwd=ROOT)
    z1, z2 = a / ARCHIVE, b / ARCHIVE
    check(z1.read_bytes() == z2.read_bytes(), "Reproducible standalone source archive")
    manifest_name = "site-architect-0.11.1-standalone-source-candidate.manifest.json"
    check((a / manifest_name).read_bytes() == (b / manifest_name).read_bytes(), "Reproducible provenance")
    manifest = json.loads((a / manifest_name).read_text())
    check(manifest["original_source"]["commit"] == REVISION, "Exact upstream SHA")
    check(manifest["archive"]["sha256"] == digest(z1.read_bytes()), "Complete source checksum")
    check(manifest["requires_external_repository_creation"], "Do not pretend repository exists")
    check(manifest["upstream_plugin_inventory_embedded"], "Exact source inventory included in external tree")
    check(manifest["upstream_source_drift_verified_in_exported_ci"], "External CI checks upstream file drift")
    check(not manifest["production_cutover_certified"], "Cutover not certified")
    expected = {x["path"]: x["sha256"] for x in manifest["file_inventory"]}
    check(len(expected) == manifest["archive"]["file_count"], "Full file inventory")
    with ZipFile(z1) as z:
        names = z.namelist()
        check(len(names) == len(expected), "Archive and manifest count agree")
        content = {p.removeprefix("site-architect/"): z.read(p) for p in names}
        check(all(p.startswith("site-architect/") for p in names), "Single root directory")
        check({p: digest(blob) for p, blob in content.items()} == expected, "Per-file provenance")
        check(all(p.startswith(("wordpress/plugins/eduardo-research-manager/", "scripts/", "docs/", ".github/")) or p in
                  ("README.md", ".gitignore", "SOURCE_COMMIT", "UPSTREAM_PROVENANCE.json") for p in content), "No private project data")
        check(not any(p.startswith(("wordpress/themes/", "config/", "bridge/")) for p in content), "Theme/instance/bridge excluded")
        for source in SOURCE.rglob("*"):
            if not source.is_file():
                continue
            relative = source.relative_to(SOURCE).as_posix()
            exported = "wordpress/plugins/eduardo-research-manager/" + relative
            check(content.get(exported) == source.read_bytes(), "Exact full live plugin bytes: " + relative)
        check(content["SOURCE_COMMIT"] == (REVISION + "\n").encode(), "Upstream identity in export")
        upstream = json.loads(content["UPSTREAM_PROVENANCE.json"])
        check(upstream["source_commit"] == REVISION and upstream["plugin_version"] == "0.11.1",
              "Embedded upstream commit and product version")
        check({item["path"]: item["sha256"] for item in upstream["plugin_files"]} ==
              {p.removeprefix("wordpress/plugins/eduardo-research-manager/"): digest(blob)
               for p, blob in content.items() if p.startswith("wordpress/plugins/eduardo-research-manager/")},
              "Embedded upstream plugin inventory is complete and exact")
        ci = content[".github/workflows/ci.yml"].decode("utf-8")
        check("python3 scripts/verify-site-architect-provenance.py" in ci,
              "Exported CI enforces upstream plugin source provenance")
        check("generic-wordpress-smoke:" in ci and "needs: complete-product-source" in ci,
              "Exported repo CI independently checks generic WordPress runtime")
        check("site-architect-0.11.1-standalone-installable-candidate" in ci
              and "sha256sum -c site-architect-0.11.1-research-candidate.zip.sha256" in ci,
              "Runtime job consumes the exact packaged artifact with integrity validation")
        check("generic-wordpress" in ci and 'array("preview", "apply")' in ci
              and "Generic WordPress changed after rejected Research operation" in ci,
              "Runtime gate refuses both Research writes and checks no state mutation")
        check("github.token" not in ci and "contents: write" not in ci,
              "Exported CI does not request GitHub mutation privileges")

        check(b"Plugin Name: Site Architect" in content["wordpress/plugins/eduardo-research-manager/eduardo-research-manager.php"],
              "Real plugin product preserved")
        z.extractall(base / "outside-original")
    standalone = base / "outside-original/site-architect"
    check((standalone / "scripts/build-site-architect-distribution.py").is_file(), "Standalone builder present")
    check((standalone / ".github/workflows/ci.yml").is_file(), "Self-contained CI present")
    verify_path = standalone / "scripts/verify-site-architect-provenance.py"
    check(verify_path.is_file(), "Standalone exact-source verifier present")
    run(str(verify_path), cwd=standalone)
    entry = standalone / "wordpress/plugins/eduardo-research-manager/eduardo-research-manager.php"
    original_entry = entry.read_bytes()
    try:
        entry.write_bytes(original_entry + b"\n# source drift check\n")
        failed = subprocess.run([sys.executable, str(verify_path)], cwd=standalone,
                                capture_output=True, check=False)
        check(failed.returncode != 0, "Verifier refuses modified WordPress plugin entrypoint")
    finally:
        entry.write_bytes(original_entry)
    extra = standalone / "wordpress/plugins/eduardo-research-manager/unapproved-extra.php"
    try:
        extra.write_bytes(b"<?php // extra source file")
        failed = subprocess.run([sys.executable, str(verify_path)], cwd=standalone,
                                capture_output=True, check=False)
        check(failed.returncode != 0, "Verifier refuses unapproved plugin file")
    finally:
        extra.unlink(missing_ok=True)
    run(str(verify_path), cwd=standalone)
    run("scripts/test-site-architect-distribution.py", cwd=standalone)
    run("scripts/build-site-architect-distribution.py", "--source-commit", REVISION, cwd=standalone)
    run(str(BASE_BUILDER), "--source-commit", REVISION, "--output", str(base / "direct"), cwd=ROOT)
    rebuilt = standalone / "dist/site-architect" / INSTALLABLE
    direct = base / "direct" / INSTALLABLE
    check(rebuilt.read_bytes() == direct.read_bytes(), "Independent rebuild has EXACT original WordPress release bytes")
    check((standalone / "dist/site-architect/site-architect-0.11.1-research-candidate.manifest.json").read_bytes()
          == (base / "direct/site-architect-0.11.1-research-candidate.manifest.json").read_bytes(),
          "Independent release manifest is byte identical")
    check((a / (ARCHIVE + ".sha256")).read_text().strip()
          == f"{digest(z1.read_bytes())}  {ARCHIVE}", "Release checksum sidecar")
print("PASS: standalone source is deterministic, complete, isolated and independently rebuilds identical 0.11.1")
