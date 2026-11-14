#!/usr/bin/env bash
# #475.23: pre-existing Eduardo MCP principal, READS ONLY.
# Raw authenticated responses exist only in a private temporary runner dir.
set -euo pipefail
umask 077
test -n "$SA47523_MCP_TOKEN"
test -n "$SA47523_EVIDENCE_DIR"
test -n "$SA47523_SOURCE_SHA"
MCP_URL="https://mcp.kairoseth.com/mcp/eduardo-research"
INSPECTOR_VERSION="2.5.0"
[[ "$SA47523_SOURCE_SHA" =~ ^[0-9a-f]{40}$ ]]
mkdir -p "$SA47523_EVIDENCE_DIR"
chmod 0700 "$SA47523_EVIDENCE_DIR"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT
CONFIG="$WORK_DIR/mcp.json"
export MCP_STORAGE_DIR="$WORK_DIR/mcp-storage"
export MCP_INSPECTOR_OAUTH_STATE_PATH="$WORK_DIR/inspector-auth.json"
mkdir -p "$MCP_STORAGE_DIR"

# Secret enters only a chmod-600 config via environment, never argv/logs.
node - "$CONFIG" "$MCP_URL" <<'NODE'
const fs = require('node:fs');
const [path, url] = process.argv.slice(2);
const bearer = process.env.SA47523_MCP_TOKEN || '';
if (!bearer || !/^https:\/\/mcp\.kairoseth\.com\/mcp\/eduardo-research$/.test(url)) {
  throw Error('Refusing unknown principal or MCP endpoint');
}
fs.writeFileSync(path, JSON.stringify({
  mcpServers: { eduardo: {
    type: 'http', url,
    headers: { Authorization: 'Bearer ' + bearer },
    connectionTimeout: 10000, requestTimeout: 50000
  } }
}), { mode: 0o600 });
NODE

# Existing READ tools only. Never add plan/apply/rollback/create/delete.
for tool in research_status research_versions research_capabilities site_pages_list site_navigation_inspect site_media_list; do
  case "$tool" in
    research_status|research_versions|research_capabilities|site_pages_list|site_navigation_inspect|site_media_list) ;;
    *) echo 'Refusing unknown or write tool' >&2; exit 2 ;;
  esac
  if timeout 75s npx --yes "@modelcontextprotocol/inspector@$INSPECTOR_VERSION" \
    --cli --config "$CONFIG" --server eduardo \
    --stored-auth-only --format json \
    --method tools/call --tool-name "$tool" --tool-args-json '{}' \
    > "$WORK_DIR/$tool.json" 2> "$WORK_DIR/$tool-stderr.txt"; then
    printf '%s\tok\n' "$tool" >> "$WORK_DIR/call-status.tsv"
  else
    # Do not print raw errors: may contain private remote data or headers.
    printf '%s\tfailed\n' "$tool" >> "$WORK_DIR/call-status.tsv"
    printf '{"result":{"isError":true,"content":[]}}\n' > "$WORK_DIR/$tool.json"
  fi
done

# Additional exact #475.26 read-only governance verification of the live
# Theme contract: 12 allowed Page keys x 2 languages, then six safe surfaces.
# Do not extract or print post bodies, raw slots, authors, tokens or URLs.
sa47526_read() {
  local tool="$1" args="$2" filename="$3"
  case "$tool" in
    site_page_inspect|site_design_inspect|site_design_variants|site_design_readiness|site_seo_geo_site|site_seo_geo_indexability|site_media_diagnostics) ;;
    *) echo 'Refusing MCP tool outside read-only governance allowlist' >&2; exit 2 ;;
  esac
  if timeout 45s npx --yes "@modelcontextprotocol/inspector@$INSPECTOR_VERSION" \
    --cli --config "$CONFIG" --server eduardo \
    --stored-auth-only --format json \
    --method tools/call --tool-name "$tool" --tool-args-json "$args" \
    > "$WORK_DIR/$filename.json" 2> "$WORK_DIR/$filename-stderr.txt"; then
    printf '%s\tok\n' "$filename" >> "$WORK_DIR/call-status.tsv"
  else
    printf '%s\tfailed\n' "$filename" >> "$WORK_DIR/call-status.tsv"
    printf '{"result":{"isError":true,"content":[]}}\n' > "$WORK_DIR/$filename.json"
  fi
}
for key in home about research publications projects software datasets cv insights contact privacy-policy legal-notice; do
  for lang in en es; do
    case "$key" in
      home|about|research|publications|projects|software|datasets|cv|insights|contact|privacy-policy|legal-notice) ;;
      *) exit 2 ;;
    esac
    # Keys and languages are constant Theme-owned public identifiers only.
    args="$(printf '{\"key\":\"%s\",\"language\":\"%s\"}' "$key" "$lang")"
    sa47526_read site_page_inspect "$args" "site_page_inspect-$key-$lang"
  done
done
for tool in site_design_inspect site_design_variants site_design_readiness site_seo_geo_site site_seo_geo_indexability site_media_diagnostics; do
  args='{}'
  if [ "$tool" = site_design_readiness ]; then args='{"rendered":true}'; fi
  sa47526_read "$tool" "$args" "$tool"
done
node "$GITHUB_WORKSPACE/scripts/site-architect-47526-sanitize-governance.mjs" \
  --input "$WORK_DIR" \
  --output "$SA47523_EVIDENCE_DIR/live-governance-readback.json" \
  --source-sha "$SA47523_SOURCE_SHA"
node "$GITHUB_WORKSPACE/scripts/site-architect-47526-validate-governance.mjs" \
  "$SA47523_EVIDENCE_DIR/live-governance-readback.json"
node "$GITHUB_WORKSPACE/scripts/site-architect-47523-sanitize-mcp.mjs" \
  --input "$WORK_DIR" \
  --output "$SA47523_EVIDENCE_DIR/live-mcp-readback.json" \
  --source-sha "$SA47523_SOURCE_SHA"

# Green workflow = read-only attempt + safe evidence. Report determines whether
# PRIVATE_READBACK_CONFIRMED or READBACK_INCOMPLETE; never cutover certification.
node "$GITHUB_WORKSPACE/scripts/site-architect-47523-validate-mcp.mjs" \
  "$SA47523_EVIDENCE_DIR/live-mcp-readback.json"

if grep -R -F -- "$SA47523_MCP_TOKEN" "$SA47523_EVIDENCE_DIR" >/dev/null 2>&1; then
  echo 'Credential leaked into sanitized evidence; aborting upload' >&2
  rm -f "$SA47523_EVIDENCE_DIR/live-mcp-readback.json" "$SA47523_EVIDENCE_DIR/live-governance-readback.json"
  exit 1
fi
echo 'PASS: production MCP READ operations attempted; inspect sanitized report status.'
