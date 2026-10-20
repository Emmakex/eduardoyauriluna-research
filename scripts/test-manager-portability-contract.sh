#!/usr/bin/env bash
set -euo pipefail

PLUGIN="wordpress/plugins/eduardo-research-manager"
MAIN="$PLUGIN/eduardo-research-manager.php"
PORTABILITY="$PLUGIN/includes/class-research-manager-portability.php"
BASELINE="$PLUGIN/includes/class-research-manager-site-baseline.php"
PROFILE="$PLUGIN/includes/class-research-manager-site-profile.php"
V1="$PLUGIN/includes/class-research-manager-v1-capabilities.php"
MCP_TOOL="bridge/research-manager-mcp/src/portable-tools.ts"
GENERIC_SERVER="bridge/research-manager-mcp/src/generic-server.ts"
EXTENDED="bridge/research-manager-mcp/src/extended-server.ts"
CONFIG="bridge/research-manager-mcp/src/config.ts"

for path in "$MAIN" "$PORTABILITY" "$BASELINE" "$PROFILE" "$V1" "$MCP_TOOL" "$GENERIC_SERVER" "$EXTENDED" "$CONFIG"; do
  test -f "$path"
done

grep -Fq 'Version: 0.11.0' "$MAIN"
grep -Fq "define('EDUARDO_RESEARCH_MANAGER_VERSION', '0.11.0')" "$MAIN"
grep -Fq "public const GENERIC = 'generic-wordpress'" "$PROFILE"
grep -Fq "public const RESEARCH = 'research-theme'" "$PROFILE"
grep -Fq "'/audit/baseline'" "$PORTABILITY"
grep -Fq "'site.read','site.diagnostics'" "$PORTABILITY"
grep -Fq "'writes_enabled' => false" "$PORTABILITY"
grep -Fq "unset(\$endpoints[\$route])" "$PORTABILITY"
grep -Fq "remove_menu_page('eduardo-research-workspace')" "$V1"
grep -Fq "'lighthouse_core_web_vitals'" "$BASELINE"
grep -Fq "'accessibility_axe_wcag'" "$BASELINE"
grep -Fq "'search_console'" "$BASELINE"
grep -Fq "'analytics'" "$BASELINE"
grep -Fq "'status' => 'not_measured'" "$BASELINE"
grep -Fq "'site_baseline_audit'" "$MCP_TOOL"
grep -Fq "requireCapability('diagnostics.read')" "$MCP_TOOL"
grep -Fq "'site_status'" "$GENERIC_SERVER"
grep -Fq "'site_versions'" "$GENERIC_SERVER"
grep -Fq "'site_capabilities'" "$GENERIC_SERVER"
grep -Fq 'registerPortableTools(server,adapter)' "$GENERIC_SERVER"
grep -Fq "adapter.productProfile==='generic-wordpress'" "$EXTENDED"
grep -Fq 'return createGenericBridgeServer(adapter)' "$EXTENDED"
grep -Fq "export type TenantProductProfile = 'research-theme' | 'generic-wordpress'" "$CONFIG"
grep -Fq 'productProfile: productProfile(definition.profile' "$CONFIG"

# Generic profile must not whitelist any mutation endpoint.
if sed -n '/\$allowed = array(/,/);/p' "$PORTABILITY" | grep -Eq '/operations|/pages|/insights|/research-|/seo-geo|/design|/media|/navigation'; then
  echo 'Generic endpoint allowlist unexpectedly contains a Research/mutation surface.' >&2
  exit 1
fi

# Generic MCP server must not register Research/write tools.
if grep -Eq 'research_|registerResearchWriteTools|registerA[3-7]WriteTools|site_navigation|site_media|site_seo|site_design' "$GENERIC_SERVER"; then
  echo 'Generic MCP server unexpectedly registers Research/write tools.' >&2
  exit 1
fi

echo 'Manager 0.11 portability contract: OK'
