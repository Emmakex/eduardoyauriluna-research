#!/usr/bin/env python3
"""Create a PRIVATE independent Site Architect GitHub repo from the real product source.

Run from a clean checkout of Emmakex/eduardoyauriluna-research main.
The --prepare-only mode is safe for CI and never calls GitHub or writes to a site.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import stat
import subprocess
import sys
import tempfile
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parent.parent
ORIGIN = "Emmakex/eduardoyauriluna-research"
DESTINATION = "Emmakex/site-architect"
ARCHIVE = "site-architect-0.11.1-standalone-source-candidate.zip"
MANIFEST = "site-architect-0.11.1-standalone-source-candidate.manifest.json"
ARTIFACT_SOURCE = ROOT / "scripts/export-site-architect-product.py"
PLUGIN_ROOT = "wordpress/plugins/eduardo-research-manager/"


def require(ok: bool, message: str) -> None:
    if not ok:
        raise SystemExit("STOP: " + message)


def run(*cmd: str, cwd: Path = ROOT) -> str:
    try:
        result = subprocess.run(cmd, cwd=str(cwd), check=True, text=True, capture_output=True)
    except FileNotFoundError:
        raise SystemExit("STOP: command not installed: " + cmd[0])
    except subprocess.CalledProcessError as err:
        raise SystemExit("STOP: command failed: " + " ".join(cmd)
                         + "\n" + (err.stderr or "")[-1200:])
    return result.stdout.strip()


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def checkout_revision(require_main: bool) -> str:
    sha = run("git", "rev-parse", "HEAD")
    require(bool(re.fullmatch(r"[0-9a-f]{40}", sha)), "invalid source commit")
    if require_main:
        require(run("git", "branch", "--show-current") == "main",
                "publish only from the local main branch")
        require(not run("git", "status", "--porcelain"),
                "source checkout has local changes: clean it before publishing")
        remote = run("git", "remote", "get-url", "origin")
        require(bool(re.search(r"github\.com[:/]Emmakex/eduardoyauriluna-research(?:\.git)?$",
                               remote, flags=re.IGNORECASE)),
                "origin is not the intended Eduardo source repository")
        remote_main = run("git", "ls-remote", "origin", "refs/heads/main")
        require(remote_main.split()[0] == sha,
                "local main does not equal the current remote main; update first")
    return sha


def unpack_verified(export_dir: Path, output: Path, source_commit: str) -> int:
    archive = export_dir / ARCHIVE
    manifest = json.loads((export_dir / MANIFEST).read_text(encoding="utf-8"))
    checksum = (export_dir / (ARCHIVE + ".sha256")).read_text(encoding="utf-8").strip()
    require(manifest.get("product") == "Site Architect"
            and manifest.get("version") == "0.11.1"
            and manifest.get("kind") == "standalone_product_source_candidate",
            "unexpected product release identity")
    require(manifest.get("status") == "not_approved_for_production_cutover"
            and manifest.get("production_cutover_certified") is False
            and manifest.get("custom_theme_mutations_certified") is False,
            "release must not claim unauthorized deployment or Theme writes")
    require(manifest.get("original_source", {}).get("repository") == ORIGIN
            and manifest.get("original_source", {}).get("commit") == source_commit,
            "upstream source SHA or repository drift")
    release_hash = digest(archive.read_bytes())
    require(manifest.get("archive", {}).get("sha256") == release_hash
            and checksum == release_hash + "  " + ARCHIVE,
            "archive does not match its manifest and checksum")
    require(manifest.get("includes_theme") is False
            and manifest.get("includes_credentials") is False
            and manifest.get("includes_instance_blueprint") is False,
            "export includes instance secrets or Eduardo Theme data")
    listed = manifest.get("file_inventory")
    require(isinstance(listed, list) and len(listed) >= 50, "missing full source inventory")
    expected = {}
    for item in listed:
        require(isinstance(item, dict) and isinstance(item.get("path"), str)
                and isinstance(item.get("sha256"), str), "invalid inventory entry")
        name = item["path"]
        require(name not in expected and name and not name.startswith("/")
                and "\\" not in name
                and all(p not in ("", ".", "..") for p in name.split("/"))
                and re.fullmatch(r"[0-9a-f]{64}", item["sha256"]) is not None,
                "unsafe, duplicate or unverified source filename")
        expected[name] = item["sha256"]
    require(len(expected) == manifest["archive"]["file_count"], "incomplete file count")
    require(not output.exists(), "output path already exists: " + str(output))
    with ZipFile(archive) as zipped:
        source_files = {}
        for info in zipped.infolist():
            name = info.filename
            require(name.startswith("site-architect/") and not info.is_dir()
                    and stat.S_IFMT(info.external_attr >> 16) != stat.S_IFLNK,
                    "unsafe archive entry")
            relative = name[len("site-architect/"):]
            require(relative in expected and relative not in source_files,
                    "unexpected or duplicated archive entry")
            data = zipped.read(info)
            require(digest(data) == expected[relative],
                    "exported source file checksum drift: " + relative)
            source_files[relative] = data
        require(source_files.keys() == expected.keys(), "archive and inventory differ")
        require(zipped.testzip() is None, "corrupt source archive")
    require(source_files.get("SOURCE_COMMIT") == (source_commit + "\n").encode(),
            "source commit inside the archive changed")
    require("UPSTREAM_PROVENANCE.json" in source_files
            and "scripts/verify-site-architect-provenance.py" in source_files
            and ".github/workflows/ci.yml" in source_files
            and PLUGIN_ROOT + "eduardo-research-manager.php" in source_files,
            "standalone product is missing CI, provenance or WordPress plugin")
    require(not any(p.startswith(("wordpress/themes/", "config/", "bridge/"))
                    for p in source_files), "Eduardo instance files found in export")
    output.mkdir(parents=True, exist_ok=False)
    for relative, data in source_files.items():
        target = output / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
    run(sys.executable, "scripts/verify-site-architect-provenance.py", cwd=output)
    return len(source_files)


def prepare(target: Path, sha: str) -> int:
    with tempfile.TemporaryDirectory(prefix="site-architect-export-") as working:
        release = Path(working) / "release"
        run(sys.executable, str(ARTIFACT_SOURCE), "--source-commit", sha,
            "--output", str(release))
        return unpack_verified(release, target, sha)


def publish(target: Path, sha: str) -> None:
    run("gh", "auth", "status")
    require(bool(run("git", "config", "--get", "user.name")),
            "git user.name is not configured")
    require(bool(run("git", "config", "--get", "user.email")),
            "git user.email is not configured")
    # GitHub will fail safely on a name collision; never overwrite or reuse any repo.
    run("git", "init", "-b", "main", cwd=target)
    run("git", "add", "-A", cwd=target)
    run("git", "commit", "-m", "Import certified Site Architect 0.11.1 source from " + sha,
        cwd=target)
    run("gh", "repo", "create", DESTINATION, "--private", "--source", str(target),
        "--remote", "origin", "--push", cwd=target)
    metadata = json.loads(run("gh", "repo", "view", DESTINATION, "--json",
                              "nameWithOwner,visibility,defaultBranchRef", cwd=target))
    require(metadata.get("nameWithOwner") == DESTINATION
            and metadata.get("visibility") == "PRIVATE"
            and metadata.get("defaultBranchRef", {}).get("name") == "main",
            "repository remote identity, visibility or default branch mismatch")
    pushed = run("git", "ls-remote", "origin", "refs/heads/main", cwd=target)
    require(pushed.split()[0] == run("git", "rev-parse", "HEAD", cwd=target),
            "remote branch does not match exact imported standalone source")
    print("PASS: PRIVATE independent Site Architect repo is created and source pushed")
    print("https://github.com/" + DESTINATION)
    print("Verify its own CI workflow before considering any WordPress cutover.")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--prepare-only", action="store_true",
                        help="Build, extract and check standalone source; never create a GitHub repository")
    parser.add_argument("--output", type=Path,
                        help="Required with --prepare-only. Must not exist.")
    parser.add_argument("--source-commit", help="Only valid with --prepare-only; must match local HEAD.")
    args = parser.parse_args()
    require(args.prepare_only == (args.output is not None),
            "--output must be supplied exactly when using --prepare-only")
    require(args.source_commit is None or args.prepare_only,
            "--source-commit is only for an offline CI preparation")
    commit = checkout_revision(require_main=not args.prepare_only)
    if args.source_commit is not None:
        require(args.source_commit == commit, "supplied source SHA differs from local HEAD")
    if args.prepare_only:
        n = prepare(args.output.resolve(), commit)
        print(f"PASS: prepared {n} independent product files from {commit}; no GitHub writes")
        return
    with tempfile.TemporaryDirectory(prefix="site-architect-private-") as working:
        target = Path(working) / "site-architect"
        n = prepare(target, commit)
        print(f"Verified {n} Site Architect source files before private repository creation")
        publish(target, commit)


if __name__ == "__main__":
    main()
