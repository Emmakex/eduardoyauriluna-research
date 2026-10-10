#!/usr/bin/env bash
set -euo pipefail

workflow_dir="${1:-.github/workflows}"
missing=0
checked=0

while IFS= read -r -d '' workflow; do
  if grep -Eq '^[[:space:]]*tools:[[:space:]]*wp-cli([[:space:]]|$)' "$workflow"; then
    checked=$((checked + 1))
    if ! grep -Fq '.github/scripts/ensure-wp-cli.sh' "$workflow"; then
      echo "WP-CLI workflow is missing resilient bootstrap: $workflow" >&2
      missing=$((missing + 1))
    fi
  fi
done < <(find "$workflow_dir" -maxdepth 1 -type f \( -name '*.yml' -o -name '*.yaml' \) -print0 | sort -z)

if [[ "$checked" -eq 0 ]]; then
  echo "No workflows declaring tools: wp-cli were found." >&2
  exit 1
fi

if [[ "$missing" -ne 0 ]]; then
  echo "$missing WP-CLI workflow(s) violate the resilient bootstrap contract." >&2
  exit 1
fi

echo "WP-CLI workflow contract OK: $checked workflow(s) use the shared fallback."
