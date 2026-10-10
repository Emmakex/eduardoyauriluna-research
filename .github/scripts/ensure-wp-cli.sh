#!/usr/bin/env bash
set -euo pipefail

force_fallback="${WP_CLI_FORCE_FALLBACK:-0}"
if [[ "$force_fallback" != "1" ]] && command -v wp >/dev/null 2>&1 && wp --info >/dev/null 2>&1; then
  echo "WP-CLI already available: $(command -v wp)"
  exit 0
fi

version="${WP_CLI_FALLBACK_VERSION:-2.12.0}"
install_dir="${HOME}/.local/bin"
target="${install_dir}/wp"
mkdir -p "${install_dir}"

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

urls=(
  "https://github.com/wp-cli/wp-cli/releases/download/v${version}/wp-cli-${version}.phar"
  "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"
)

installed=0
for url in "${urls[@]}"; do
  echo "Trying WP-CLI fallback: ${url}"
  if curl --fail --location --silent --show-error \
      --retry 4 --retry-delay 2 --retry-all-errors \
      --connect-timeout 15 --max-time 120 \
      "$url" -o "$tmp"; then
    if php "$tmp" --info >/dev/null 2>&1; then
      install -m 0755 "$tmp" "$target"
      installed=1
      break
    fi
  fi
done

if [[ "$installed" -ne 1 ]]; then
  echo "WP-CLI fallback installation failed." >&2
  exit 1
fi

echo "$install_dir" >> "${GITHUB_PATH}"
"$target" --info
