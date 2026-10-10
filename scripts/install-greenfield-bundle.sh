#!/usr/bin/env bash
set -euo pipefail

BUNDLE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_BIN="${WP_CLI_BIN:-wp}"
WP_PATH="${GREENFIELD_WP_PATH:-$(pwd)}"
ADMIN_USER="${GREENFIELD_ADMIN_USER:-${1:-}}"

if [[ -z "$ADMIN_USER" ]]; then
  echo "Usage: GREENFIELD_ADMIN_USER=<administrator> $0" >&2
  echo "       or: $0 <administrator>" >&2
  exit 64
fi

if ! command -v "$WP_BIN" >/dev/null 2>&1; then
  echo "WP-CLI is required but was not found: $WP_BIN" >&2
  exit 69
fi

WP_FLAGS=(--path="$WP_PATH")
if [[ "$(id -u)" -eq 0 ]]; then
  WP_FLAGS+=(--allow-root)
fi

wp_cmd() {
  "$WP_BIN" "${WP_FLAGS[@]}" "$@"
}

verify_checksums() {
  cd "$BUNDLE_DIR"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum -c SHA256SUMS
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 -c SHA256SUMS
  else
    echo "A SHA-256 verification utility (sha256sum or shasum) is required." >&2
    exit 69
  fi
}

single_match() {
  local label="$1"
  shift
  local matches=("$@")
  if [[ ${#matches[@]} -ne 1 || ! -f "${matches[0]}" ]]; then
    echo "Expected exactly one $label package; found ${#matches[@]}." >&2
    exit 65
  fi
  printf '%s\n' "${matches[0]}"
}

verify_checksums

shopt -s nullglob
THEME_MATCHES=("$BUNDLE_DIR"/packages/eduardo-research-[0-9]*.zip)
MANAGER_MATCHES=("$BUNDLE_DIR"/packages/eduardo-research-manager-*.zip)
shopt -u nullglob

THEME_ZIP="$(single_match 'Theme' "${THEME_MATCHES[@]}")"
MANAGER_ZIP="$(single_match 'Manager' "${MANAGER_MATCHES[@]}")"

wp_cmd core is-installed
wp_cmd theme install "$THEME_ZIP" --force --activate
wp_cmd plugin install "$MANAGER_ZIP" --force --activate
wp_cmd rewrite structure '/%postname%/' --hard
wp_cmd rewrite flush --hard

wp_cmd research-manager greenfield preview
wp_cmd research-manager greenfield apply --user="$ADMIN_USER" --yes
wp_cmd research-manager greenfield status

echo "Greenfield Research bundle installed and verified."
