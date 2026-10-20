#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DISCOVERY="$ROOT/wordpress/plugins/eduardo-research-manager/includes/class-research-manager-site-discovery.php"
CAPABILITIES="$ROOT/wordpress/plugins/eduardo-research-manager/includes/class-research-manager-v1-capabilities.php"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[[ -f "$DISCOVERY" ]] || fail "adaptive site discovery class is missing"
[[ -f "$CAPABILITIES" ]] || fail "v1 capabilities file is missing"

grep -q "class Eduardo_Research_Manager_Site_Discovery" "$DISCOVERY" || fail "discovery class is not declared"
grep -q "register_activation_hook(EDUARDO_RESEARCH_MANAGER_FILE" "$DISCOVERY" || fail "activation discovery hook is missing"
grep -q "refresh('activation')" "$DISCOVERY" || fail "activation does not execute discovery immediately"

grep -q "activated_plugin" "$DISCOVERY" || fail "plugin activation invalidation hook is missing"
grep -q "deactivated_plugin" "$DISCOVERY" || fail "plugin deactivation invalidation hook is missing"
grep -q "switch_theme" "$DISCOVERY" || fail "theme switch invalidation hook is missing"
grep -q "upgrader_process_complete" "$DISCOVERY" || fail "upgrade invalidation hook is missing"
grep -q "update_option_permalink_structure" "$DISCOVERY" || fail "routing invalidation hook is missing"

grep -q "'content_types'" "$DISCOVERY" || fail "content type discovery is missing"
grep -q "'taxonomies'" "$DISCOVERY" || fail "taxonomy discovery is missing"
grep -q "'integrations'" "$DISCOVERY" || fail "integration discovery is missing"
grep -q "'governance'" "$DISCOVERY" || fail "governance map is missing"
grep -q "'gaps'" "$DISCOVERY" || fail "governance gaps are missing"

grep -q "site.read" "$DISCOVERY" || fail "site.read governance is missing"
grep -q "site.diagnostics" "$DISCOVERY" || fail "site.diagnostics governance is missing"
grep -q "No explicit safe adapter" "$DISCOVERY" || fail "generic write safety gate is missing"

grep -q "WooCommerce" "$DISCOVERY" || fail "WooCommerce detection is missing"
grep -q "Elementor" "$DISCOVERY" || fail "Elementor detection is missing"
grep -q "Advanced Custom Fields" "$DISCOVERY" || fail "ACF detection is missing"
grep -q "Yoast SEO" "$DISCOVERY" || fail "Yoast detection is missing"
grep -q "WPML" "$DISCOVERY" || fail "WPML detection is missing"

grep -q "1. Discovered" "$DISCOVERY" || fail "adaptive Discovered panel is missing"
grep -q "2. Governable now" "$DISCOVERY" || fail "adaptive Governable panel is missing"
grep -q "3. Missing to govern" "$DISCOVERY" || fail "adaptive Missing panel is missing"
grep -q "ChatGPT / MCP connection" "$DISCOVERY" || fail "MCP connection access is missing"

if grep -Eq "wp_remote_|curl_exec|file_get_contents\(['\"]https?://" "$DISCOVERY"; then
  fail "activation discovery must remain local and bounded; external network call detected"
fi

grep -q "class-research-manager-site-discovery.php" "$CAPABILITIES" || fail "v1 capabilities does not load site discovery"
grep -q "Eduardo_Research_Manager_Site_Discovery())->register" "$CAPABILITIES" || fail "site discovery is not registered"
grep -q "site_discovery" "$CAPABILITIES" || fail "MCP capabilities do not expose discovery summary"

pass "adaptive Site Manager discovery contract"
