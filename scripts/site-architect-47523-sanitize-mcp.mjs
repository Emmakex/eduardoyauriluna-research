#!/usr/bin/env node
// #475.23 -- whitelist-only reduction of private production MCP read results.
// NEVER upload or echo the raw Inspector responses or secrets.
import fs from 'node:fs';
import path from 'node:path';

export const TOOL_NAMES = Object.freeze([
  'research_status', 'research_versions', 'research_capabilities',
  'site_pages_list', 'site_navigation_inspect', 'site_media_list',
]);

const isRecord = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const boolOrNull = (v) => typeof v === 'boolean' ? v : null;
const safeVersion = (v) => typeof v === 'string' && /^[0-9][0-9A-Za-z._-]{0,39}$/.test(v) ? v : null;
const safeInt = (v) => Number.isSafeInteger(v) && v >= 0 && v <= 10000000 ? v : null;
const firstInt = (...values) => values.map(safeInt).find((v) => v !== null) ?? null;

export function decodeResult(raw) {
  if (!isRecord(raw) || !isRecord(raw.result) || raw.result.isError === true) return null;
  const response = raw.result;
  let obj = isRecord(response.structuredContent) ? response.structuredContent : null;
  if (!obj && Array.isArray(response.content)) {
    for (const part of response.content) {
      if (part?.type !== 'text' || typeof part.text !== 'string' || part.text.length > 300000) continue;
      try {
        const parsed = JSON.parse(part.text);
        if (isRecord(parsed)) { obj = parsed; break; }
      } catch { /* text-only responses are not evidence */ }
    }
  }
  if (!obj) obj = response;
  // The existing bridge wraps some payloads with { ok, data }.
  if (obj.ok === false || obj.isError === true) return null;
  const data = isRecord(obj.data) ? obj.data : isRecord(obj.result) ? obj.result : obj;
  if (data.ok === false || data.isError === true) return null;
  return isRecord(data) ? data : null;
}

function safeHost(v) {
  if (typeof v !== 'string' || v.length > 256) return false;
  try {
    const u = new URL(v);
    return u.protocol === 'https:' && u.hostname === 'eduardoyauriluna.com'
      && (!u.port || u.port === '443');
  } catch { return false; }
}

function extractStatus(value) {
  const preset = isRecord(value?.preset) ? value.preset : {};
  const readiness = isRecord(value?.readiness) ? value.readiness : {};
  return {
    manager_version: safeVersion(value?.manager_version),
    theme_version: safeVersion(value?.theme_version),
    contract_id: typeof preset.id === 'string' && /^[a-z0-9_-]{1,40}$/.test(preset.id) ? preset.id : null,
    contract_version: firstInt(preset.version),
    contract_compatible: boolOrNull(preset.compatible),
    readiness: boolOrNull(readiness.ready),
    runtime_host_is_eduardo: safeHost(value?.runtime_url),
  };
}

function extractVersions(value) {
  const preset = isRecord(value?.preset) ? value.preset : {};
  return {
    manager: safeVersion(value?.manager),
    theme: safeVersion(value?.theme),
    wordpress: safeVersion(value?.wordpress),
    php: safeVersion(value?.php),
    preset_compatible: boolOrNull(preset.compatible),
  };
}

function extractCapabilities(value) {
  const scopes = Array.isArray(value?.granted_scopes) ? value.granted_scopes : null;
  const write = scopes && scopes.filter((v) => typeof v === 'string' &&
    (v.endsWith('.write') || ['operations.apply','operations.rollback','credentials.manage',
     'external_publish.execute','software_update.execute','destructive_delete.execute'].includes(v))).length;
  return {
    granted_scope_count: scopes ? firstInt(scopes.length) : null,
    write_scope_count: scopes ? firstInt(write) : null,
    writes_enabled: boolOrNull(value?.writes_enabled),
    mutation_endpoint_declared: boolOrNull(value?.mutation_transport?.available),
  };
}

function extractCollection(value) {
  const arr = Array.isArray(value?.items) ? value.items
    : Array.isArray(value?.resources) ? value.resources
      : Array.isArray(value?.pages) ? value.pages
        : Array.isArray(value?.media) ? value.media : null;
  const total = firstInt(value?.total, value?.total_count, value?.count);
  const summary = {
    visible_item_count: arr ? firstInt(arr.length) : null,
    total: total ?? (arr ? firstInt(arr.length) : null),
    reported_page: firstInt(value?.page),
    pagination_total_pages: firstInt(value?.total_pages),
  };
  if (arr) {
    const statusCounts = {};
    for (const item of arr) {
      if (!isRecord(item)) continue;
      const status = item.status;
      if (!['publish','draft','future','private','pending','inherit','trash'].includes(status)) continue;
      statusCounts[status] = (statusCounts[status] || 0) + 1;
    }
    summary.visible_status_counts = statusCounts;
  } else {
    summary.visible_status_counts = {};
  }
  return summary;
}

function extractNavigation(value) {
  const count = (v) => Array.isArray(v) ? firstInt(v.length) : null;
  return {
    primary_count: count(value?.primary ?? value?.locations?.primary),
    footer_count: count(value?.footer ?? value?.locations?.footer),
  };
}

export function sanitizeObserved(rawByTool, sourceSha, observedAt = new Date().toISOString()) {
  if (!/^[0-9a-f]{40}$/.test(sourceSha)) throw new Error('Invalid source commit SHA');
  const calls = {};
  const parsed = {};
  for (const name of TOOL_NAMES) {
    const data = decodeResult(rawByTool[name]);
    calls[name] = data ? 'payload_read' : 'unverified';
    parsed[name] = data;
  }
  const status = parsed.research_status ? extractStatus(parsed.research_status) : null;
  const versions = parsed.research_versions ? extractVersions(parsed.research_versions) : null;
  const capabilities = parsed.research_capabilities
    ? extractCapabilities(parsed.research_capabilities) : null;
  const pages = parsed.site_pages_list ? extractCollection(parsed.site_pages_list) : null;
  const media = parsed.site_media_list ? extractCollection(parsed.site_media_list) : null;
  const navigation = parsed.site_navigation_inspect
    ? extractNavigation(parsed.site_navigation_inspect) : null;

  const basic = !!status && !!versions && !!capabilities
    && status.manager_version !== null && versions.manager !== null
    && status.runtime_host_is_eduardo === true && status.manager_version === versions.manager;
  const versionAligned = basic && status.manager_version === '0.11.1';
  const inventory = !!pages && !!media && pages.total !== null && media.total !== null;
  const liveState = !basic ? 'READBACK_INCOMPLETE'
    : !versionAligned ? 'VERSION_DRIFT_REVIEW'
      : inventory ? 'MCP_PRIVATE_READBACK_OBSERVED' : 'MCP_VERSION_CAPABILITIES_ONLY';
  return {
    schema_version: 1,
    mode: 'EXISTING_PRODUCTION_MCP_READ_OPERATIONS_ONLY',
    target: 'eduardoyauriluna.com',
    source_commit: sourceSha,
    observed_at_utc: observedAt,
    outcome: liveState,
    release_certification: 'NOT_RELEASE_CERTIFIED',
    raw_mcp_payloads_exported: false,
    tool_call_states: calls,
    runtime_status: status,
    runtime_versions: versions,
    granted_capability_summary: capabilities,
    pages_summary: pages,
    media_summary: media,
    navigation_summary: navigation,
    private_database_full_inventory: false,
    production_mutations_performed: false,
    next_checks: [
      'authenticated_native_wordpress_aggregate_inventory',
      'production_visual_mobile_desktop_acceptance',
      'cv_and_legal_editorial_approval',
      'independent_private_repository_ci',
      'reversible_cutover_rehearsal',
    ],
  };
}

function cli() {
  const args = process.argv.slice(2);
  const option = (name) => args[args.indexOf(name) + 1];
  const dir = option('--input');
  const output = option('--output');
  const sha = option('--source-sha');
  if (!dir || !output || !sha || !fs.statSync(dir).isDirectory()
    || !/^\/.+\/live-mcp-readback\.json$/.test(output)) {
    throw new Error('Invalid local input/output or source SHA');
  }
  const rawByTool = {};
  for (const name of TOOL_NAMES) {
    try { rawByTool[name] = JSON.parse(fs.readFileSync(path.join(dir, name + '.json'), 'utf8')); }
    catch { rawByTool[name] = null; }
  }
  const report = sanitizeObserved(rawByTool, sha);
  fs.writeFileSync(output, JSON.stringify(report, null, 2) + '\n', { mode: 0o600 });
  // Print only bounded status; no tool content, connection identity or scope list.
  process.stdout.write('MCP_READBACK_OUTCOME=' + report.outcome
    + ' CALLS_READ=' + Object.values(report.tool_call_states).filter(v => v === 'payload_read').length
    + '/6\n');
}

if (process.argv[1] && process.argv[1].endsWith('site-architect-47523-sanitize-mcp.mjs')) cli();
