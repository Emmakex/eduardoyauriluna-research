#!/usr/bin/env python3
"""Export the REAL Site Architect plugin into a standalone, push-ready repository snapshot."""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import re
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

ROOT = Path(__file__).resolve().parent.parent
PLUGIN_DIR = ROOT / "wordpress/plugins/eduardo-research-manager"
INNER = "site-architect"
ARCHIVE = "site-architect-0.11.1-standalone-source-candidate.zip"
SOURCE_BUILDER = ROOT / "scripts/build-site-architect-distribution.py"
SOURCE_TEST = ROOT / "scripts/test-site-architect-distribution.py"
SOURCE_DOC = ROOT / "docs/SITE_ARCHITECT_DISTRIBUTION_CANDIDATE.md"
SOURCE_PATH = "wordpress/plugins/eduardo-research-manager"

README = """# Site Architect — standalone product source candidate

This is the FULL Site Architect 0.11.1 WordPress plugin, sourced from the
actual production-compatible plugin implementation. It is NOT a rebuilt engine.

## Release boundary

- The WordPress plugin is wordpress/plugins/eduardo-research-manager/
  (legacy technical slug retained intentionally).
- Research is the installed, bounded vertical compatible with the Eduardo
  Research Theme. The WordPress plugin display name is Site Architect.
- A generic WordPress installation has only the existing read-only profile.
- Custom Greenfield Theme writes are NOT certified and must remain fail-closed.
- Do not install this candidate over the running Eduardo production plugin.
- No Eduardo Theme, Blueprint, content, server configuration or credentials
  are included in this source repository.

## Verify and build

Requires Python 3.9+ and PHP 8.1+. From the extracted repository root:

    python3 scripts/test-site-architect-distribution.py
    python3 scripts/build-site-architect-distribution.py --source-commit "$(cat SOURCE_COMMIT)"

The generated candidate WordPress ZIP and SHA-256 manifest are in
dist/site-architect/. Its complete source inventory is also verifiable.
The .github/workflows/ci.yml workflow runs those checks independently.

## Repository independence

This directory is intentionally runnable outside the Eduardo engineering
repository. The SOURCE_COMMIT is the original upstream exact revision, NOT
the Git commit subsequently created when this source is imported elsewhere.
Its full archive file inventory and content hashes are recorded externally
alongside the export archive in its export manifest.

This is a source/release candidate, not proof of production cutover or
multi-Theme mutation authority. See docs/ for remaining gates.

No license is implied by this export. Repository visibility and publication
must be approved by the copyright holder before an external release.
"""

CI = """name: Site Architect Standalone Product CI

on:
  push:
  pull_request:
  workflow_dispatch:

permissions:
  contents: read

jobs:
  complete-product-source:
    runs-on: ubuntu-latest
    timeout-minutes: 12
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
          coverage: none
      - name: Lint ALL WordPress plugin modules
        shell: bash
        run: |
          set -euo pipefail
          find wordpress/plugins/eduardo-research-manager -type f -name '*.php' -print0 |
            xargs -0 -n1 php -l >/tmp/site-architect-php-lint.log
          wc -l /tmp/site-architect-php-lint.log
      - name: Rebuild complete verified 0.11.1 WordPress installable
        shell: bash
        run: |
          set -euo pipefail
          python3 scripts/test-site-architect-distribution.py
          python3 scripts/build-site-architect-distribution.py --source-commit "$(cat SOURCE_COMMIT)"
          (cd dist/site-architect && sha256sum -c site-architect-0.11.1-research-candidate.zip.sha256)
      - uses: actions/upload-artifact@v4
        with:
          name: site-architect-0.11.1-standalone-installable-candidate
          path: dist/site-architect/
          if-no-files-found: error
          retention-days: 30
"""

GITIGNORE = "dist/\n__pycache__/\n*.pyc\n.env\n.env.*\n"


def sha(blob: bytes) -> str:
    return hashlib.sha256(blob).hexdigest()


def payload(source_commit: str) -> dict[str, bytes]:
    if not re.fullmatch(r"[0-9a-f]{40}", source_commit):
        raise SystemExit("An exact 40-character upstream source commit is required")
    module_spec = importlib.util.spec_from_file_location("site_architect_original", SOURCE_BUILDER)
    if module_spec is None or module_spec.loader is None:
        raise SystemExit("Original complete-product builder unavailable")
    original = importlib.util.module_from_spec(module_spec)
    module_spec.loader.exec_module(original)
    # Original inventory rejects secrets, symlinks, missing modules and partial engines.
    source_files = original.inventory()
    files: dict[str, bytes] = {
        f"{SOURCE_PATH}/{path}": data for path, data in source_files
    }
    files.update({
        "README.md": README.encode(),
        ".gitignore": GITIGNORE.encode(),
        ".github/workflows/ci.yml": CI.encode(),
        "SOURCE_COMMIT": (source_commit + "\n").encode(),
        "scripts/build-site-architect-distribution.py": SOURCE_BUILDER.read_bytes(),
        "scripts/test-site-architect-distribution.py": SOURCE_TEST.read_bytes(),
        "docs/SITE_ARCHITECT_DISTRIBUTION_CANDIDATE.md": SOURCE_DOC.read_bytes(),
    })
    for name in files:
        if not name or name.startswith("/") or ".." in Path(name).parts:
            raise SystemExit(f"Invalid standalone source path: {name}")
    if len(source_files) < 50 or len(files) != len(source_files) + 7:
        raise SystemExit("Incomplete source export")
    return files


def build(source_commit: str, output: Path) -> None:
    files = payload(source_commit)
    output.mkdir(parents=True, exist_ok=True)
    archive = output / ARCHIVE
    with ZipFile(archive, "w", compression=ZIP_DEFLATED, compresslevel=9) as zipped:
        for path, blob in sorted(files.items()):
            entry = ZipInfo(f"{INNER}/{path}", (2024, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            entry.compress_type = ZIP_DEFLATED
            zipped.writestr(entry, blob, compress_type=ZIP_DEFLATED, compresslevel=9)
    manifest = {
        "schema_version": 1,
        "product": "Site Architect",
        "version": "0.11.1",
        "kind": "standalone_product_source_candidate",
        "status": "not_approved_for_production_cutover",
        "original_source": {
            "repository": "Emmakex/eduardoyauriluna-research",
            "commit": source_commit,
            "plugin_root": SOURCE_PATH,
        },
        "archive": {
            "filename": ARCHIVE,
            "sha256": sha(archive.read_bytes()),
            "file_count": len(files),
            "entry_root": INNER,
        },
        "file_inventory": [
            {"path": path, "sha256": sha(blob)} for path, blob in sorted(files.items())
        ],
        "requires_external_repository_creation": True,
        "independent_runtime_source": True,
        "includes_theme": False,
        "includes_instance_blueprint": False,
        "includes_credentials": False,
        "changes_legacy_storage_or_plugin_slug": False,
        "custom_theme_mutations_certified": False,
        "production_cutover_certified": False,
    }
    (output / "site-architect-0.11.1-standalone-source-candidate.manifest.json").write_text(
        json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    (output / (ARCHIVE + ".sha256")).write_text(
        f"{manifest['archive']['sha256']}  {ARCHIVE}\n", encoding="utf-8"
    )
    print(f"Built standalone candidate with {len(files)} files from {source_commit}")
    print(archive)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-commit", required=True)
    parser.add_argument("--output", default="dist/site-architect-standalone")
    args = parser.parse_args()
    build(args.source_commit, (ROOT / args.output).resolve())
