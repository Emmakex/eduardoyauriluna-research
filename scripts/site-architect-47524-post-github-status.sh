#!/usr/bin/env bash
# #475.24: a bounded GitHub commit status, never raw private MCP data.
set -euo pipefail
umask 077
mode="${1:-}"
case "$mode" in pending|outcome) ;; *) echo 'Unsupported status mode' >&2; exit 2 ;; esac
: "${GH_TOKEN:?Built-in GitHub Actions token required}"
: "${GITHUB_REPOSITORY:?GitHub repository required}"
: "${GITHUB_SHA:?Exact commit SHA required}"
: "${GITHUB_RUN_ID:?Actions run ID required}"
: "${GITHUB_REF:?Protected main ref required}"
: "${GITHUB_WORKSPACE:?Checked-out code required}"
[[ "$GITHUB_REPOSITORY" =~ ^[Ee]mmakex/eduardoyauriluna-research$ ]]
[[ "$GITHUB_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$GITHUB_RUN_ID" =~ ^[0-9]+$ ]]
test "$GITHUB_REF" = 'refs/heads/main'

tmp="$(mktemp -d)"
export SA47524_STATUS_PAYLOAD='/tmp/sa-47524-status-protected.json'
trap 'rm -f "$SA47524_STATUS_PAYLOAD"; rm -rf "$tmp"' EXIT
node "$GITHUB_WORKSPACE/scripts/site-architect-47524-status-contract.mjs" "$mode"
jq -ec --arg run_url "https://github.com/$GITHUB_REPOSITORY/actions/runs/$GITHUB_RUN_ID" \
  '. + {"target_url":$run_url} |
   select(.context == "site-architect/eduardo-mcp-readback") |
   select(.state == "pending" or .state == "success" or .state == "failure" or .state == "error") |
   select((.description|type) == "string" and (.description|length) <= 140)' \
  "$SA47524_STATUS_PAYLOAD" > "$tmp/status.json"
test -s "$tmp/status.json"

# statuses:write applies only to the GitHub commit, NOT WordPress.
curl --fail --silent --show-error --retry 2 \
  --request POST \
  --header "Authorization: Bearer $GH_TOKEN" \
  --header 'Accept: application/vnd.github+json' \
  --header 'X-GitHub-Api-Version: 2022-11-28' \
  --data-binary "@$tmp/status.json" \
  "https://api.github.com/repos/$GITHUB_REPOSITORY/statuses/$GITHUB_SHA" \
  > /dev/null
echo "PASS: protected Eduardo MCP readback GitHub status published ($mode)."
