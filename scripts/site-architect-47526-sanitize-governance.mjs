#!/usr/bin/env node
/**
 * #475.26: explicit production governance evidence, no confidential content.
 * Reads the transient RAW MCP responses from the pre-existing read-only
 * observer, and emits ONLY predefined public Theme keys and safe booleans/counts.
 * No WP mutations, uploads, PII, URLs, slot contents or unbounded error texts.
 */
import fs from 'node:fs';
import path from 'node:path';
import { decodeResult } from './site-architect-47523-sanitize-mcp.mjs';

export const PAGE_KEYS = Object.freeze([
  'home','about','research','publications','projects','software','datasets',
  'cv','insights','contact','privacy-policy','legal-notice'
]);
export const LANGUAGES = Object.freeze(['en','es']);
export const AUDIT_READ_TOOLS = Object.freeze([
  'site_design_inspect','site_design_variants','site_design_readiness',
  'site_seo_geo_site','site_seo_geo_indexability','site_media_diagnostics'
]);
const SAFE_STATUSES = new Set(['publish','draft','private','future','pending','trash']);
const record = v => v !== null && typeof v === 'object' && !Array.isArray(v);
const count = v => Array.isArray(v) ? v.length : record(v) ? Object.keys(v).length : null;
const trueFalse = v => typeof v === 'boolean' ? v : null;
const integer = v => Number.isSafeInteger(v) && v >= 0 && v < 100000 ? v : null;
const expected = new Set(PAGE_KEYS);
const isSafeState = v => SAFE_STATUSES.has(v) ? v : null;
const sanitizeOneLanguage = (state) => {
  if (!record(state)) return {available:null,contract_aligned:null,allowed_slots:null,stored_slots:null};
  return {
    available:trueFalse(state.available),
    contract_aligned:trueFalse(state.contract_aligned),
    allowed_slots:count(state.allowed_slots),
    stored_slots:count(state.stored_slots),
  };
};

export function reduceGovernance(raw, sha, observedAt='2026-11-14T00:00:00Z') {
  if (!/^[0-9a-f]{40}$/.test(sha)) throw new Error('Commit SHA must be exact');
  const status = decodeResult(raw.research_status);
  const host = (() => { try { const u=new URL(status?.runtime_url ?? '');
    return u.protocol==='https:' && u.hostname==='eduardoyauriluna.com' &&
      (u.port==='' || u.port==='443');
  } catch {return false;} })();
  const verifiedIdentity = host && status?.manager_version === '0.11.1' &&
    status?.theme_version === '1.1.3' && status?.preset?.compatible === true;

  const pagesRaw = decodeResult(raw.site_pages_list);
  const listed = Array.isArray(pagesRaw?.items) ? pagesRaw.items : [];
  const seen = new Set();
  const inventory = [];
  for (const item of listed) {
    if (!record(item) || typeof item.key !== 'string' || !expected.has(item.key) ||
      seen.has(item.key)) continue;
    seen.add(item.key);
    inventory.push({
      key:item.key,
      exists:trueFalse(item.exists),
      languages:Object.fromEntries(LANGUAGES.map(lang => [
        lang,sanitizeOneLanguage(item.languages?.[lang])
      ]))
    });
  }
  inventory.sort((a,b)=>PAGE_KEYS.indexOf(a.key)-PAGE_KEYS.indexOf(b.key));
  const inventoryComplete = inventory.length===12 && seen.size===12 &&
    listed.length===12 && pagesRaw?.count===12;
  const inspections=[];
  for(const key of PAGE_KEYS) for(const lang of LANGUAGES) {
    const filename='site_page_inspect-'+key+'-'+lang;
    const item=decodeResult(raw[filename]);
    const match=record(item) && item.key===key && item.language===lang &&
      SAFE_STATUSES.has(item.status);
    inspections.push({
      key,language:lang,
      observed:!!match,
      status:match?isSafeState(item.status):null,
      contract_aligned:match?trueFalse(item.contract_aligned):null,
      allowed_slots:match?count(item.allowed_slots):null,
      stored_slots:match?count(item.stored_slots):null,
      published:match?trueFalse(item.published):null
    });
  }
  const perTool={};
  for (const tool of AUDIT_READ_TOOLS) perTool[tool] =
    decodeResult(raw[tool]) ? 'payload_read' : 'unverified';

  const navigation=decodeResult(raw.site_navigation_inspect);
  const state=record(navigation?.state)?navigation.state:null;
  const primary=state?state.primary:null;
  const footer=state?state.footer:null;
  const nav={
    observed:!!navigation,
    primary_item_count:count(primary),
    footer_item_count:count(footer),
    registered_targets_only:trueFalse(navigation?.arbitrary_urls)===false
  };
  const design=decodeResult(raw.site_design_inspect);
  const variants=decodeResult(raw.site_design_variants);
  const readiness=decodeResult(raw.site_design_readiness);
  const seo=decodeResult(raw.site_seo_geo_site);
  const indexability=decodeResult(raw.site_seo_geo_indexability);
  const media=decodeResult(raw.site_media_diagnostics);
  const scopedChecks={
    design_contract_observed:!!design,
    design_variants_observed:!!variants,
    design_readiness_observed:!!readiness,
    seo_site_observed:!!seo,
    seo_indexability_observed:!!indexability,
    media_diagnostics_observed:!!media,
  };
  const pageInspected=inspections.filter(p=>p.observed).length;
  const aligned=inspections.filter(p=>p.contract_aligned===true).length;
  const draft=inspections.filter(p=>p.status==='draft').length;
  const availability=inventory.flatMap(p=>LANGUAGES.map(lang=>p.languages[lang]));
  const available=availability.filter(x=>x.available===true).length;
  const matched=availability.filter(x=>x.contract_aligned===true).length;
  const navValid=nav.observed && nav.registered_targets_only &&
    integer(nav.primary_item_count)!==null && integer(nav.footer_item_count)!==null;
  const allChecks=Object.values(scopedChecks).every(v=>v===true);
  const complete=verifiedIdentity && inventoryComplete && pageInspected===24 &&
    navValid && allChecks;
  const openFlags=[
    ...(!verifiedIdentity?['runtime_identity_unverified']:[]),
    ...(!inventoryComplete?['theme_inventory_incomplete']:[]),
    ...(pageInspected!==24?['page_inspections_incomplete']:[]),
    ...(aligned!==24?['page_contract_alignment_requires_review']:[]),
    ...(matched!==24?['page_inventory_alignment_requires_review']:[]),
    ...(!navValid?['navigation_contract_unverified']:[]),
    ...(!allChecks?['design_seo_media_coverage_partial']:[]),
    'production_visual_desktop_mobile_unverified',
    'native_wordpress_database_inventory_unverified',
  ];
  return {
    schema_version:1,
    mode:'SITE_ARCHITECT_EDUARDO_GOVERNANCE_READ_ONLY',
    target:'eduardoyauriluna.com',
    source_commit:sha,observed_at_utc:observedAt,
    outcome:complete?'MCP_GOVERNANCE_COVERAGE_OBSERVED':'MCP_GOVERNANCE_PARTIAL',
    identity_compatible:verifiedIdentity,
    expected_page_keys:PAGE_KEYS.length,
    expected_page_language_views:24,
    inventory_observed:inventoryComplete,
    inventory,
    page_inspections:inspections,
    page_inspections_observed:pageInspected,
    page_inspections_contract_aligned:aligned,
    inventory_language_available:available,
    inventory_language_contract_aligned:matched,
    draft_language_views:draft,
    navigation:nav,
    read_tools:perTool,
    scoped_checks:scopedChecks,
    open_flags:openFlags,
    content_or_private_fields_exported:false,
    production_mutations_performed:false,
    release_certification:'NOT_RELEASE_CERTIFIED',
  };
}

function main() {
  const argv=process.argv.slice(2);
  const arg=x=>argv[argv.indexOf(x)+1];
  const input=arg('--input'),out=arg('--output'),sha=arg('--source-sha');
  if (!input || !out || !sha || !/^\/.+\/live-governance-readback\.json$/.test(out) ||
    !fs.statSync(input).isDirectory()) throw new Error('Invalid private working directory or redacted output');
  const raw={};
  for(const tool of ['research_status','site_pages_list','site_navigation_inspect',
    ...AUDIT_READ_TOOLS,...PAGE_KEYS.flatMap(key=>LANGUAGES.map(lang=>'site_page_inspect-'+key+'-'+lang))]) {
    try {raw[tool]=JSON.parse(fs.readFileSync(path.join(input,tool+'.json'),'utf8'));}
    catch {raw[tool]=null;}
  }
  const report=reduceGovernance(raw,sha,new Date().toISOString());
  fs.writeFileSync(out,JSON.stringify(report,null,2)+'\n',{mode:0o600});
  console.log('GOVERNANCE_READ_ONLY_OUTCOME='+report.outcome+
    ' inspected='+report.page_inspections_observed+'/24'+
    ' aligned='+report.page_inspections_contract_aligned+'/24'+
    ' read_surfaces='+Object.values(report.read_tools).filter(x=>x==='payload_read').length+'/6');
}
if (process.argv[1]?.endsWith('site-architect-47526-sanitize-governance.mjs')) main();
