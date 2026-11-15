#!/usr/bin/env node
// Validate redacted live MCP report; a green workflow MUST NOT imply production-ready.
import fs from 'node:fs';

export function validate(report) {
  const fail = (msg) => { throw new Error('Unsafe #475.23 redacted report: ' + msg); };
  if (!report || typeof report !== 'object' || Array.isArray(report)) fail('envelope');
  const allowed = [
    'schema_version','mode','target','source_commit','observed_at_utc',
    'outcome','release_certification','raw_mcp_payloads_exported',
    'tool_call_states','runtime_status','runtime_versions',
    'granted_capability_summary','pages_summary','media_summary',
    'navigation_summary','private_database_full_inventory',
    'production_mutations_performed','next_checks',
  ].sort();
  if (JSON.stringify(Object.keys(report).sort()) !== JSON.stringify(allowed)) fail('unexpected root keys');
  if (report.schema_version !== 1 || report.mode !== 'EXISTING_PRODUCTION_MCP_READ_OPERATIONS_ONLY'
    || report.target !== 'eduardoyauriluna.com'
    || !/^[0-9a-f]{40}$/.test(report.source_commit)) fail('identity');
  if (!['READBACK_INCOMPLETE','VERSION_DRIFT_REVIEW',
      'MCP_PRIVATE_READBACK_OBSERVED','MCP_VERSION_CAPABILITIES_ONLY'].includes(report.outcome)) fail('outcome');
  if (report.release_certification !== 'NOT_RELEASE_CERTIFIED'
      || report.raw_mcp_payloads_exported !== false
      || report.private_database_full_inventory !== false
      || report.production_mutations_performed !== false) fail('unsafe release claim');
  const allowedStates = ['payload_read','unverified'];
  const callNames = [
    'research_status','research_versions','research_capabilities',
    'site_pages_list','site_navigation_inspect','site_media_list',
  ].sort();
  if (!report.tool_call_states
      || JSON.stringify(Object.keys(report.tool_call_states).sort()) !== JSON.stringify(callNames)
      || Object.values(report.tool_call_states).some(v => !allowedStates.includes(v))) fail('tools');
  const allowedFields = {
    runtime_status: ['manager_version','theme_version','contract_id','contract_version',
       'contract_compatible','readiness','runtime_host_is_eduardo'],
    runtime_versions: ['manager','theme','wordpress','php','preset_compatible'],
    granted_capability_summary: ['granted_scope_count','write_scope_count',
       'writes_enabled','mutation_endpoint_declared','sensitive_authority'],
    pages_summary: ['visible_item_count','total','reported_page','pagination_total_pages','visible_status_counts'],
    media_summary: ['visible_item_count','total','reported_page','pagination_total_pages','visible_status_counts'],
    navigation_summary: ['primary_count','footer_count'],
  };
  for (const [field, keys] of Object.entries(allowedFields)) {
    if (report[field] === null) continue;
    if (!report[field] || typeof report[field] !== 'object' || Array.isArray(report[field])
        || JSON.stringify(Object.keys(report[field]).sort()) !== JSON.stringify(keys.sort())) {
      fail('unexpected ' + field + ' contents');
    }
  }
  // Fail closed on unexpected authority fields or strings in the redacted file.
  if (report.granted_capability_summary !== null) {
    const summary = report.granted_capability_summary;
    const flags = summary.sensitive_authority;
    const keys = ['credential_admin','external_publish','software_update',
      'destructive_delete','operation_apply','operation_rollback'].sort();
    if (!flags || typeof flags !== 'object' || Array.isArray(flags)
      || JSON.stringify(Object.keys(flags).sort()) !== JSON.stringify(keys)) {
      fail('unexpected sensitive authority fields');
    }
    const vals = Object.values(flags);
    if (summary.granted_scope_count === null) {
      if (vals.some(x => x !== null)) fail('scope evidence with no scope inventory');
    } else if (vals.some(x => typeof x !== 'boolean')
      || !Number.isSafeInteger(summary.write_scope_count)
      || vals.filter(x => x === true).length > summary.write_scope_count) {
      fail('sensitive authority flags contradict scope counts');
    }
  }
  const serialized = JSON.stringify(report);
  if (/(?:Bearer\s+|ermt1_|erm1_|token_hash|token_prefix|connection_id|post_content|post_title|media_path|wordpress_user_id)/i.test(serialized)) fail('private field leak');
  if (!Array.isArray(report.next_checks) || report.next_checks.length !== 5) fail('follow-up gate');
  if (report.outcome === 'MCP_PRIVATE_READBACK_OBSERVED' && (
      report.runtime_status?.manager_version !== '0.11.1'
      || report.runtime_status?.runtime_host_is_eduardo !== true
      || report.runtime_versions?.manager !== '0.11.1'
      || report.pages_summary?.total == null
      || report.media_summary?.total == null
      || report.tool_call_states.site_pages_list !== 'payload_read'
      || report.tool_call_states.site_media_list !== 'payload_read')) fail('unsubstantiated private readback');
  return {
    outcome: report.outcome,
    total_tools_read: Object.values(report.tool_call_states).filter(x => x === 'payload_read').length,
    release_certification: report.release_certification,
  };
}

if (process.argv[1] && process.argv[1].endsWith('site-architect-47523-validate-mcp.mjs')) {
  const p = process.argv[2];
  if (!p || !p.endsWith('/live-mcp-readback.json')) throw Error('Expected bounded MCP sanitized report');
  const result = validate(JSON.parse(fs.readFileSync(p, 'utf8')));
  console.log('PASS: #475.23 sanitized MCP schema=' + result.outcome
    + ', read tools=' + result.total_tools_read + '/6, release=' + result.release_certification);
}
