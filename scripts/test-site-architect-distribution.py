#!/usr/bin/env python3
"""#475.16 certifies *byte-for-byte* ZIP/source parity and no instance bundle."""
import hashlib
import json
import subprocess
import sys
import tempfile
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parent.parent
BUILDER = ROOT / "scripts/build-site-architect-distribution.py"
COMMIT = "a" * 40
NAME = "site-architect-0.11.1-research-candidate.zip"
MANIFEST = "site-architect-0.11.1-research-candidate.manifest.json"
SOURCE = ROOT / "wordpress/plugins/eduardo-research-manager"


def check(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit("FAIL: " + message)


with tempfile.TemporaryDirectory() as tmp:
    base = Path(tmp)
    paths = [base / "first", base / "second"]
    for path in paths:
        subprocess.run(
            [sys.executable, str(BUILDER), "--source-commit", COMMIT, "--output", str(path)],
            check=True,
            cwd=ROOT,
            stdout=subprocess.DEVNULL,
        )
    one, two = paths
    archive = one / NAME
    content = archive.read_bytes()
    check(content == (two / NAME).read_bytes(), "ZIP reproducibility")
    check((one / MANIFEST).read_bytes() == (two / MANIFEST).read_bytes(), "Manifest reproducibility")
    meta = json.loads((one / MANIFEST).read_text())
    check(meta["product"] == "Site Architect" and meta["version"] == "0.11.1", "Real product identity")
    check(meta["archive"]["wordpress_plugin_slug"] == "eduardo-research-manager", "Legacy WordPress slug preserved")
    check(meta["source"]["commit"] == COMMIT, "Exact source revision")
    check(meta["archive"]["sha256"] == hashlib.sha256(content).hexdigest(), "ZIP integrity")
    check(meta["profile_contracts"]["research-theme"]["theme_preset_id"] == "research", "Research Theme contract")
    check(meta["profile_contracts"]["generic-wordpress"]["write_authority"] == "none", "Generic writes not advertised")
    check(meta["profile_contracts"]["custom-greenfield-theme"]["status"] == "not_yet_certified", "No false custom-Theme certification")
    check(not meta["site_cutover_certified"] and not meta["includes_instance_blueprint"], "Candidate not production cutover")
    with ZipFile(archive) as z:
        members = z.namelist()
        check(len(members) == meta["archive"]["file_count"], "No hidden additions or missing files")
        files = sorted(path for path in SOURCE.rglob("*") if path.is_file())
        expected = [f"eduardo-research-manager/{p.relative_to(SOURCE).as_posix()}" for p in files]
        check(sorted(members) == sorted(expected), "Full WordPress runtime, no rebuilding or dropped files")
        actual = {
            name.removeprefix("eduardo-research-manager/"): hashlib.sha256(z.read(name)).hexdigest()
            for name in members
        }
        check(all(name.startswith("eduardo-research-manager/") for name in members), "Safe single plugin directory")
        check(actual == {x["path"]: x["sha256"] for x in meta["file_inventory"]}, "Byte-for-byte source inventory")
        check(all(z.read(member) == (SOURCE / member.removeprefix("eduardo-research-manager/")).read_bytes()
                  for member in members), "Exact bytes from 0.11.1 production runtime")
        check(not any(name.startswith(("config/", "wordpress/themes/")) for name in members), "No instance blueprint or Theme bundled")
        main = z.read(meta["archive"]["wordpress_plugin_entry"]).decode()
        check("Plugin Name: Site Architect" in main and "Version: 0.11.1" in main, "Plugin ZIP installation entry")
    checksum = (one / (NAME + ".sha256")).read_text().strip()
    check(checksum == f"{hashlib.sha256(content).hexdigest()}  {NAME}", "Checksum sidecar")
print("PASS: Site Architect candidate ZIP is deterministic, complete, byte-identical to 0.11.1 and Theme/instance-separated")
