#!/usr/bin/env bash
set -euo pipefail

: "${MCP_SERVER_URL:?set MCP_SERVER_URL to the deployed /mcp/{tenant-id} endpoint}"
: "${MCP_TOKEN:?set MCP_TOKEN to the temporary outer principal bearer}"

INSPECTOR_VERSION="${MCP_INSPECTOR_VERSION:-2.5.0}"
EXPECTED_TOOL_COUNT="${MCP_EXPECTED_TOOL_COUNT:-96}"
EXPECTED_ROUTING="${MCP_EXPECTED_ROUTING:-shared}"
EVIDENCE_DIR="${MCP_EVIDENCE_DIR:-$(pwd)/tmp/phase2c-mcp-remote-acceptance}"

case "$EXPECTED_ROUTING" in
  shared|single-tenant) ;;
  *) echo "MCP_EXPECTED_ROUTING must be shared or single-tenant" >&2; exit 2 ;;
esac

mkdir -p "$EVIDENCE_DIR"
chmod 700 "$EVIDENCE_DIR"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT
CONFIG="$WORK_DIR/mcp.json"
export MCP_STORAGE_DIR="$WORK_DIR/storage"
export MCP_INSPECTOR_OAUTH_STATE_PATH="$WORK_DIR/oauth.json"
mkdir -p "$MCP_STORAGE_DIR"

# Keep the bearer out of argv/process listings and persistent Inspector state.
node - "$CONFIG" "$MCP_SERVER_URL" "$MCP_TOKEN" <<'NODE'
const fs = require('node:fs');
const [path, url, token] = process.argv.slice(2);
fs.writeFileSync(path, JSON.stringify({
  mcpServers: {
    phase2c: {
      type: 'http',
      url,
      headers: { Authorization: `Bearer ${token}` },
      connectionTimeout: 10000,
      requestTimeout: 60000,
    },
  },
}, null, 2), { mode: 0o600 });
NODE
chmod 600 "$CONFIG"

mcp() {
  npx --yes "@modelcontextprotocol/inspector@${INSPECTOR_VERSION}" --cli \
    --config "$CONFIG" --server phase2c \
    --stored-auth-only --format json "$@"
}

health_url="$(node - "$MCP_SERVER_URL" <<'NODE'
const url = new URL(process.argv[2]);
process.stdout.write(`${url.origin}/healthz`);
NODE
)"

printf 'Phase 2C remote acceptance: %s\n' "$MCP_SERVER_URL"
printf 'Evidence directory: %s\n' "$EVIDENCE_DIR"

# 1. Public health is deliberately non-secret and must describe the deployed runtime.
curl --fail --silent --show-error "$health_url" > "$EVIDENCE_DIR/health.json"
jq -e --arg routing "$EXPECTED_ROUTING" \
  '.ok == true and .service == "site-control-mcp" and .phase == "phase2c" and .routing == $routing and .persistent_telemetry == true' \
  "$EVIDENCE_DIR/health.json" >/dev/null
if jq -e 'has("token") or has("credentials") or has("principals") or has("tenants")' "$EVIDENCE_DIR/health.json" >/dev/null; then
  echo 'health response exposes forbidden secret/tenant inventory fields' >&2
  exit 1
fi
printf 'ok: health + persistent telemetry\n'

# 2. The selected tenant endpoint must require the temporary outer principal bearer.
anon_code="$(curl --silent --show-error --output "$WORK_DIR/anonymous.json" --write-out '%{http_code}' "$MCP_SERVER_URL")"
test "$anon_code" = "401"
jq -e '.code == "authentication_failed"' "$WORK_DIR/anonymous.json" >/dev/null
printf 'ok: outer authentication required\n'

# 3. Handshake and bounded catalog.
mcp --method initialize > "$EVIDENCE_DIR/initialize.json"
jq -e '.result.protocolVersion and .result.serverInfo' "$EVIDENCE_DIR/initialize.json" >/dev/null
printf 'ok: MCP handshake\n'

mcp --method tools/list > "$EVIDENCE_DIR/tools-list.json"
actual_tool_count="$(jq '.result.tools | length' "$EVIDENCE_DIR/tools-list.json")"
test "$actual_tool_count" = "$EXPECTED_TOOL_COUNT"

required_tools='[
  "research_status",
  "research_versions",
  "research_capabilities",
  "site_pages_list",
  "site_insights_list",
  "site_research_objects_list",
  "site_translations_list",
  "site_evidence_inventory",
  "site_navigation_inspect",
  "site_media_list",
  "site_seo_geo_site",
  "site_design_inspect",
  "site_page_slots_plan",
  "site_insight_create_plan",
  "site_research_object_create_plan",
  "site_translation_pair_plan",
  "site_evidence_create_plan",
  "site_seo_geo_remediation_plan",
  "site_media_ingest_plan",
  "site_navigation_update_plan",
  "site_design_preset_update_plan",
  "site_design_variant_update_plan"
]'
jq -e --argjson want "$required_tools" \
  '[.result.tools[].name] as $have | ($want - $have | length) == 0' \
  "$EVIDENCE_DIR/tools-list.json" >/dev/null
printf 'ok: bounded catalog %s/%s + critical tools present\n' "$actual_tool_count" "$EXPECTED_TOOL_COUNT"

# 4. Safe reference-tenant reads through the commercial MCP layer.
mcp --method tools/call --tool-name research_status --tool-args-json '{}' > "$EVIDENCE_DIR/research-status.json"
mcp --method tools/call --tool-name research_versions --tool-args-json '{}' > "$EVIDENCE_DIR/research-versions.json"
mcp --method tools/call --tool-name research_capabilities --tool-args-json '{}' > "$EVIDENCE_DIR/research-capabilities.json"
mcp --method tools/call --tool-name site_navigation_inspect --tool-args-json '{}' > "$EVIDENCE_DIR/navigation.json"
mcp --method tools/call --tool-name site_design_readiness --tool-args-json '{"rendered":true}' > "$EVIDENCE_DIR/design-readiness.json"
for file in research-status research-versions research-capabilities navigation design-readiness; do
  jq -e '.result' "$EVIDENCE_DIR/${file}.json" >/dev/null
  if grep -Eqi 'Bearer[[:space:]]+[A-Za-z0-9._~+/-]+|ermt1_|erm1_' "$EVIDENCE_DIR/${file}.json"; then
    echo "secret-like material detected in ${file} evidence" >&2
    exit 1
  fi
done
printf 'ok: reference-tenant safe reads\n'

# 5. Shared-mode anti-enumeration and optional second-tenant isolation.
if [ "$EXPECTED_ROUTING" = 'shared' ]; then
  tenant_parent="${MCP_SERVER_URL%/*}"
  unknown_url="${tenant_parent}/phase2c-does-not-exist"

  unknown_anon_code="$(curl --silent --show-error --output "$WORK_DIR/unknown-anon.json" --write-out '%{http_code}' "$unknown_url")"
  test "$unknown_anon_code" = "401"
  cmp "$WORK_DIR/anonymous.json" "$WORK_DIR/unknown-anon.json"

  unknown_auth_code="$(curl --silent --show-error --output "$WORK_DIR/unknown-auth.json" --write-out '%{http_code}' \
    -H "Authorization: Bearer ${MCP_TOKEN}" "$unknown_url")"
  test "$unknown_auth_code" = "403"
  jq -e '.code == "tenant_access_denied"' "$WORK_DIR/unknown-auth.json" >/dev/null

  if [ -n "${MCP_DENIED_TENANT_URL:-}" ]; then
    denied_code="$(curl --silent --show-error --output "$WORK_DIR/denied-tenant.json" --write-out '%{http_code}' \
      -H "Authorization: Bearer ${MCP_TOKEN}" "$MCP_DENIED_TENANT_URL")"
    test "$denied_code" = "403"
    cmp "$WORK_DIR/unknown-auth.json" "$WORK_DIR/denied-tenant.json"
    printf 'ok: second-tenant access denied and indistinguishable from unknown tenant\n'
  else
    printf 'ok: tenant non-enumeration; set MCP_DENIED_TENANT_URL to add live two-tenant isolation proof\n'
  fi
fi

# Evidence emitted by the Inspector/curl contains no configured outer bearer by contract.
if grep -R -F -- "$MCP_TOKEN" "$EVIDENCE_DIR" >/dev/null 2>&1; then
  echo 'outer bearer leaked into acceptance evidence' >&2
  exit 1
fi

cat > "$EVIDENCE_DIR/result.json" <<EOF
{
  "status": "PASS",
  "server_url": "${MCP_SERVER_URL}",
  "routing": "${EXPECTED_ROUTING}",
  "tool_count": ${actual_tool_count},
  "persistent_telemetry": true,
  "two_tenant_isolation_checked": $([ -n "${MCP_DENIED_TENANT_URL:-}" ] && printf true || printf false)
}
EOF
chmod 600 "$EVIDENCE_DIR"/*.json
printf 'PASS: Phase 2C remote read/isolation acceptance\n'
