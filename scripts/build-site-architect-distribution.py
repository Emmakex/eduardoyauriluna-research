#!/usr/bin/env python3
"""Package the REAL installed Site Architect 0.11.1; no reconstructed Core."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "wordpress/plugins/eduardo-research-manager"
PLUGIN_SLUG = "eduardo-research-manager"  # WordPress production compatibility.
PLUGIN_FILE = "eduardo-research-manager.php"
VERSION = "0.11.1"
FILENAME = "site-architect-0.11.1-research-candidate.zip"


def sha256(blob: bytes) -> str:
    return hashlib.sha256(blob).hexdigest()


def inventory() -> list[tuple[str, bytes]]:
    if not SOURCE.is_dir():
        raise SystemExit("Site Architect 0.11.1 real source directory missing")
    files: list[tuple[str, bytes]] = []
    for path in sorted(SOURCE.rglob("*")):
        if path.is_symlink():
            raise SystemExit(f"Symlinks forbidden in release input: {path}")
        if not path.is_file():
            continue
        relative = path.relative_to(SOURCE).as_posix()
        if ".." in Path(relative).parts or not relative or relative.startswith("/"):
            raise SystemExit(f"Unsafe release path: {relative}")
        forbidden_names = {".env", ".env.local", "wp-config.php", "id_rsa", "id_ed25519"}
        forbidden_extensions = {".pem", ".pfx", ".p12", ".key", ".sqlite", ".sql"}
        if Path(relative).name.lower() in forbidden_names or Path(relative).suffix.lower() in forbidden_extensions:
            raise SystemExit(f"Secret or instance data must never be shipped: {relative}")
        files.append((relative, path.read_bytes()))
    main = dict(files).get(PLUGIN_FILE, b"").decode("utf-8", errors="replace")
    if (
        not re.search(r"(?m)^\s*\*\s*Plugin Name:\s*Site Architect\s*$", main)
        or not re.search(r"(?m)^\s*\*\s*Version:\s*0\.11\.1\s*$", main)
        or "define('EDUARDO_RESEARCH_MANAGER_VERSION', '0.11.1');" not in main
        or "register_activation_hook(__FILE__" not in main
        or "includes/class-research-manager.php" not in main
    ):
        raise SystemExit("Source is not the complete certified Site Architect 0.11.1")
    required = (
        "includes/class-research-manager.php",
        "includes/class-research-manager-executor.php",
        "includes/class-research-manager-blueprint-hydrator.php",
        "includes/class-research-manager-greenfield-pipeline.php",
        "includes/class-site-architect-theme-profile.php",
        "includes/class-research-manager-research-theme-profile.php",
        "includes/class-site-architect-blueprint-identity-operations.php",
        "includes/class-research-manager-research-blueprint-identity-operations.php",
        "includes/class-research-manager-remote-rest.php",
    )
    missing = sorted(set(required) - set(dict(files)))
    if missing:
        raise SystemExit("Missing runtime modules: " + ", ".join(missing))
    if len(files) < 50:
        raise SystemExit("Incomplete plugin tree, refused to produce a partial engine")
    return files


def build(output: Path, source_commit: str) -> None:
    if not re.fullmatch(r"[0-9a-f]{40}", source_commit):
        raise SystemExit("Provide an exact 40-digit source commit")
    files = inventory()
    output.mkdir(parents=True, exist_ok=True)
    target = output / FILENAME
    with ZipFile(target, "w", compression=ZIP_DEFLATED, compresslevel=9) as archive:
        for relative, blob in files:
            item = ZipInfo(f"{PLUGIN_SLUG}/{relative}", (2024, 1, 1, 0, 0, 0))
            item.create_system = 3
            item.external_attr = 0o100644 << 16
            item.compress_type = ZIP_DEFLATED
            archive.writestr(item, blob, compress_type=ZIP_DEFLATED, compresslevel=9)
    manifest = {
        "schema_version": 1,
        "product": "Site Architect",
        "version": VERSION,
        "status": "candidate_not_approved_for_cutover",
        "source": {
            "repository": "Emmakex/eduardoyauriluna-research",
            "commit": source_commit,
            "path": "wordpress/plugins/eduardo-research-manager",
        },
        "archive": {
            "filename": FILENAME,
            "sha256": sha256(target.read_bytes()),
            "wordpress_plugin_slug": PLUGIN_SLUG,
            "wordpress_plugin_entry": f"{PLUGIN_SLUG}/{PLUGIN_FILE}",
            "file_count": len(files),
        },
        "file_inventory": [
            {"path": relative, "sha256": sha256(blob)} for relative, blob in files
        ],
        "profile_contracts": {
            "research-theme": {
                "status": "existing_production_behavior_under_regression",
                "theme_stylesheet": "eduardo-research",
                "theme_preset_id": "research",
                "write_authority": "existing_scopes_theme_contract_evidence_and_executor_gates",
            },
            "generic-wordpress": {
                "status": "read_only_portability_compatibility",
                "write_authority": "none",
            },
            "custom-greenfield-theme": {
                "status": "not_yet_certified",
                "write_authority": "none_until_explicit_contract_and_parity",
            },
        },
        "includes_theme": False,
        "includes_instance_blueprint": False,
        "includes_credentials": False,
        "changes_legacy_storage_or_plugin_slug": False,
        "site_cutover_certified": False,
        "external_theme_parity_certified": False,
        "requires_isolated_wordpress_acceptance": True,
    }
    manifest_path = output / "site-architect-0.11.1-research-candidate.manifest.json"
    manifest_path.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n")
    (output / (FILENAME + ".sha256")).write_text(
        f"{manifest['archive']['sha256']}  {FILENAME}\n"
    )
    print(f"Site Architect 0.11.1 full runtime candidate: {len(files)} files")
    print(target)
    print(manifest_path)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-commit", required=True)
    parser.add_argument("--output", default="dist/site-architect")
    args = parser.parse_args()
    build((ROOT / args.output).resolve(), args.source_commit)
