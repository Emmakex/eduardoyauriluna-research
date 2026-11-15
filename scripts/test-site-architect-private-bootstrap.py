#!/usr/bin/env python3
"""Offline acceptance for the Site Architect private-repository bootstrap."""
from __future__ import annotations

import hashlib
import importlib.util
import json
import subprocess
import sys
import tempfile
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parent.parent
BOOTSTRAP = ROOT / "scripts/bootstrap-site-architect-private-repo.py"
EXPORTER = ROOT / "scripts/export-site-architect-product.py"
SOURCE_SHA = subprocess.check_output(
    ["git", "rev-parse", "HEAD"], cwd=ROOT, text=True
).strip()
ARCHIVE = "site-architect-0.11.1-standalone-source-candidate.zip"
MANIFEST = "site-architect-0.11.1-standalone-source-candidate.manifest.json"

spec = importlib.util.spec_from_file_location("bootstrap_sa", BOOTSTRAP)
assert spec and spec.loader
bootstrap = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bootstrap)


def reject(fn, reason: str) -> None:
    try:
        fn()
    except SystemExit as exc:
        if exc.code in (0, None):
            raise AssertionError("Unexpected successful exit: " + reason)
    else:
        raise AssertionError("Unsafe import accepted: " + reason)


with tempfile.TemporaryDirectory(prefix="site-architect-bootstrap-test-") as tmp:
    base = Path(tmp)
    export = base / "export"
    subprocess.run([sys.executable, str(EXPORTER), "--source-commit",
                    SOURCE_SHA, "--output", str(export)],
                   cwd=ROOT, check=True, capture_output=True)
    extracted = base / "standalone"
    count = bootstrap.unpack_verified(export, extracted, SOURCE_SHA)
    assert count >= 50
    assert (extracted / "UPSTREAM_PROVENANCE.json").is_file()
    assert (extracted / ".github/workflows/ci.yml").is_file()
    assert not (extracted / "wordpress/themes").exists()
    assert not (extracted / "config").exists()
    assert (extracted / "SOURCE_COMMIT").read_text().strip() == SOURCE_SHA
    reject(lambda: bootstrap.unpack_verified(export, base / "wrong-commit",
                                              "f" * 40), "different upstream SHA")
    reject(lambda: bootstrap.unpack_verified(export, extracted, SOURCE_SHA),
           "overwriting a preexisting extraction")
    sidecar = export / (ARCHIVE + ".sha256")
    good_sidecar = sidecar.read_bytes()
    try:
        sidecar.write_text("0" * 64 + "  " + ARCHIVE + "\n")
        reject(lambda: bootstrap.unpack_verified(export, base / "bad-checksum", SOURCE_SHA),
               "corrupted checksum")
    finally:
        sidecar.write_bytes(good_sidecar)
    manifest_path = export / MANIFEST
    good_manifest = manifest_path.read_bytes()
    try:
        metadata = json.loads(good_manifest)
        metadata["original_source"]["commit"] = "0" * 40
        manifest_path.write_text(json.dumps(metadata))
        reject(lambda: bootstrap.unpack_verified(export, base / "bad-manifest", SOURCE_SHA),
               "wrong manifest SHA")
    finally:
        manifest_path.write_bytes(good_manifest)
    archive_path = export / ARCHIVE
    good_archive = archive_path.read_bytes()
    try:
        with ZipFile(archive_path, "a") as zipped:
            zipped.writestr("site-architect/unapproved-source.php", "<?php // injected")
        modified = archive_path.read_bytes()
        metadata = json.loads(good_manifest)
        metadata["archive"]["sha256"] = hashlib.sha256(modified).hexdigest()
        manifest_path.write_text(json.dumps(metadata))
        sidecar.write_text(metadata["archive"]["sha256"] + "  " + ARCHIVE + "\n")
        reject(lambda: bootstrap.unpack_verified(export, base / "bad-extra", SOURCE_SHA),
               "extra archive source file even with synchronized outer checksum")
    finally:
        archive_path.write_bytes(good_archive)
        manifest_path.write_bytes(good_manifest)
        sidecar.write_bytes(good_sidecar)
    assert not (base / "bad-extra").exists()
    assert not (base / "bad-checksum").exists()
print("PASS: one-command private repo importer prepares exact standalone product and refuses drift")
