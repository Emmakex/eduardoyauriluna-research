#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${GREENFIELD_DIST_DIR:-$ROOT/dist/greenfield}"
THEME_SOURCE="$ROOT/wordpress/themes/eduardo-research"
MANAGER_SOURCE="$ROOT/wordpress/plugins/eduardo-research-manager"
BLUEPRINT_SOURCE="$ROOT/config/research-site-blueprint.json"
INSTALLER_SOURCE="$ROOT/scripts/install-greenfield-bundle.sh"
SOURCE_SHA="${GREENFIELD_SOURCE_SHA:-$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || printf 'unknown')}"

for required in "$THEME_SOURCE/style.css" "$MANAGER_SOURCE/eduardo-research-manager.php" "$BLUEPRINT_SOURCE" "$INSTALLER_SOURCE"; do
  if [[ ! -s "$required" ]]; then
    echo "Required Greenfield source is missing: $required" >&2
    exit 66
  fi
done

THEME_VERSION="$(awk -F': ' '/^Version: / {print $2; exit}' "$THEME_SOURCE/style.css" | tr -d '\r')"
MANAGER_VERSION="$(awk -F': ' '/^ \* Version: / {print $2; exit}' "$MANAGER_SOURCE/eduardo-research-manager.php" | tr -d '\r')"

if [[ -z "$THEME_VERSION" || -z "$MANAGER_VERSION" ]]; then
  echo "Could not resolve Theme or Manager version." >&2
  exit 65
fi

BUNDLE_NAME="eduardo-research-greenfield-t${THEME_VERSION}-m${MANAGER_VERSION}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
STAGE="$WORK/$BUNDLE_NAME"
mkdir -p "$STAGE/packages" "$STAGE/config" "$DIST_DIR"

cp -R "$THEME_SOURCE" "$WORK/eduardo-research"
cp -R "$MANAGER_SOURCE" "$WORK/eduardo-research-manager"
cp "$BLUEPRINT_SOURCE" "$STAGE/config/research-site-blueprint.json"
cp "$INSTALLER_SOURCE" "$STAGE/install.sh"
chmod +x "$STAGE/install.sh"

python3 - "$WORK/eduardo-research" "$WORK/eduardo-research-manager" "$STAGE" <<'PY'
import os
import sys

EPOCH = 315532800  # 1980-01-01 UTC, compatible with ZIP timestamps.
for root in sys.argv[1:]:
    for base, dirs, files in os.walk(root):
        for name in dirs + files:
            path = os.path.join(base, name)
            try:
                os.utime(path, (EPOCH, EPOCH), follow_symlinks=False)
            except (FileNotFoundError, NotImplementedError):
                pass
    os.utime(root, (EPOCH, EPOCH), follow_symlinks=False)
PY

THEME_ZIP="$STAGE/packages/eduardo-research-${THEME_VERSION}.zip"
MANAGER_ZIP="$STAGE/packages/eduardo-research-manager-${MANAGER_VERSION}.zip"

(
  cd "$WORK"
  find eduardo-research -type f -print | LC_ALL=C sort | zip -X -q "$THEME_ZIP" -@
  find eduardo-research-manager -type f -print | LC_ALL=C sort | zip -X -q "$MANAGER_ZIP" -@
)

unzip -t "$THEME_ZIP" >/dev/null
unzip -t "$MANAGER_ZIP" >/dev/null

python3 - "$STAGE" "$THEME_VERSION" "$MANAGER_VERSION" "$SOURCE_SHA" <<'PY'
import hashlib
import json
import pathlib
import sys

stage = pathlib.Path(sys.argv[1])
theme_version = sys.argv[2]
manager_version = sys.argv[3]
source_sha = sys.argv[4]

def metadata(relative):
    path = stage / relative
    data = path.read_bytes()
    return {
        "file": relative.as_posix() if isinstance(relative, pathlib.Path) else str(relative),
        "sha256": hashlib.sha256(data).hexdigest(),
        "bytes": len(data),
    }

theme_rel = pathlib.Path("packages") / f"eduardo-research-{theme_version}.zip"
manager_rel = pathlib.Path("packages") / f"eduardo-research-manager-{manager_version}.zip"
blueprint_rel = pathlib.Path("config/research-site-blueprint.json")
installer_rel = pathlib.Path("install.sh")
blueprint = json.loads((stage / blueprint_rel).read_text(encoding="utf-8"))

manifest = {
    "schema_version": 1,
    "bundle": "eduardo-research-greenfield",
    "source_commit": source_sha,
    "requirements": {
        "wordpress": ">=6.6",
        "php": ">=8.1",
        "wp_cli": True,
    },
    "theme": {
        "slug": "eduardo-research",
        "version": theme_version,
        **metadata(theme_rel),
    },
    "manager": {
        "slug": "eduardo-research-manager",
        "version": manager_version,
        **metadata(manager_rel),
    },
    "blueprint": {
        "version": int(blueprint.get("version", 1)),
        "canonical_domain": blueprint.get("site", {}).get("canonical_domain", ""),
        "languages": blueprint.get("languages", []),
        **metadata(blueprint_rel),
    },
    "installer": metadata(installer_rel),
    "install_flow": [
        "verify-checksums",
        "install-theme",
        "install-manager",
        "preview",
        "apply",
        "status",
    ],
}
(stage / "manifest.json").write_text(
    json.dumps(manifest, indent=2, ensure_ascii=False, sort_keys=True) + "\n",
    encoding="utf-8",
)
PY

cat > "$STAGE/README.txt" <<EOF
Eduardo Research Greenfield bundle

Theme: eduardo-research ${THEME_VERSION}
Manager: eduardo-research-manager ${MANAGER_VERSION}

Requirements:
- WordPress 6.6+
- PHP 8.1+
- WP-CLI
- an existing clean WordPress installation
- an administrator username for the controlled Apply step

Example:
  GREENFIELD_WP_PATH=/var/www/html GREENFIELD_ADMIN_USER=admin bash install.sh

The installer verifies SHA-256 checksums, installs and activates the Theme and Manager,
then runs Preview -> Apply -> Status through the Research Manager Greenfield CLI.
EOF

python3 - "$STAGE" <<'PY'
import hashlib
import pathlib
import sys

stage = pathlib.Path(sys.argv[1])
paths = [
    pathlib.Path("packages") / p.name
    for p in sorted((stage / "packages").glob("*.zip"))
]
paths += [
    pathlib.Path("config/research-site-blueprint.json"),
    pathlib.Path("install.sh"),
    pathlib.Path("manifest.json"),
    pathlib.Path("README.txt"),
]
with (stage / "SHA256SUMS").open("w", encoding="utf-8") as out:
    for rel in paths:
        digest = hashlib.sha256((stage / rel).read_bytes()).hexdigest()
        out.write(f"{digest}  {rel.as_posix()}\n")
PY

python3 - "$STAGE" <<'PY'
import os
import sys
EPOCH = 315532800
root = sys.argv[1]
for base, dirs, files in os.walk(root):
    for name in dirs + files:
        path = os.path.join(base, name)
        try:
            os.utime(path, (EPOCH, EPOCH), follow_symlinks=False)
        except (FileNotFoundError, NotImplementedError):
            pass
os.utime(root, (EPOCH, EPOCH), follow_symlinks=False)
PY

BUNDLE_ZIP="$DIST_DIR/${BUNDLE_NAME}.zip"
rm -f "$BUNDLE_ZIP" "$BUNDLE_ZIP.sha256"
(
  cd "$WORK"
  find "$BUNDLE_NAME" -type f -print | LC_ALL=C sort | zip -X -q "$BUNDLE_ZIP" -@
)
unzip -t "$BUNDLE_ZIP" >/dev/null

BUNDLE_SHA="$(sha256sum "$BUNDLE_ZIP" | awk '{print $1}')"
printf '%s  %s\n' "$BUNDLE_SHA" "$(basename "$BUNDLE_ZIP")" > "$BUNDLE_ZIP.sha256"

printf '%s\n' "$BUNDLE_ZIP"
printf '%s\n' "$BUNDLE_ZIP.sha256"
