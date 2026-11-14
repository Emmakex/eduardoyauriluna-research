#!/usr/bin/env node
/** Strict closed-schema acceptance for the redacted #475.26 live governance report. */
import fs from 'node:fs';
import { PAGE_KEYS,LANGUAGES,AUDIT_READ_TOOLS } from './site-architect-47526-sanitize-governance.mjs';
const SAFE_STATES=new Set(['publish','draft','private','future','pending','trash',null]);
const safeCount=x=>x===null||(Number.isSafeInteger(x)&&x>=0&&x<100000);
const equal=(a,b)=>JSON.stringify([...a].sort())===JSON.stringify([...b].sort());
const fail=m=>{throw new Error('Site Architect governance redacted report invalid: '+m);};
export function validateGovernance(r){
 const root=[
  'schema_version','mode','target','source_commit','observed_at_utc',
  'outcome','identity_compatible','expected_page_keys','expected_page_language_views',
  'inventory_observed','inventory','page_inspections',
  'page_inspections_observed','page_inspections_contract_aligned',
  'inventory_language_available','inventory_language_contract_aligned',
  'draft_language_views','navigation','read_tools','scoped_checks','open_flags',
  'content_or_private_fields_exported','production_mutations_performed','release_certification'
 ];
 if(!r||typeof r!=='object'||Array.isArray(r)||!equal(Object.keys(r),root))fail('envelope');
 if(r.schema_version!==1||r.mode!=='SITE_ARCHITECT_EDUARDO_GOVERNANCE_READ_ONLY'
  ||r.target!=='eduardoyauriluna.com'||!/^[0-9a-f]{40}$/.test(r.source_commit))fail('source');
 if(!['MCP_GOVERNANCE_COVERAGE_OBSERVED','MCP_GOVERNANCE_PARTIAL'].includes(r.outcome)
  ||r.release_certification!=='NOT_RELEASE_CERTIFIED'
  ||r.content_or_private_fields_exported!==false
  ||r.production_mutations_performed!==false)fail('unsafe status or cutover claim');
 if(r.expected_page_keys!==12||r.expected_page_language_views!==24
  ||r.inventory.length>12||r.page_inspections.length!==24)fail('contract dimensions');
 const keys=new Set();
 for(const item of r.inventory){
  if(!item||!equal(Object.keys(item),['key','exists','languages'])
    ||!PAGE_KEYS.includes(item.key)||keys.has(item.key)
    ||!equal(Object.keys(item.languages),LANGUAGES)
    ||(item.exists!==true&&item.exists!==false&&item.exists!==null))fail('Page inventory schema');
  keys.add(item.key);
  for(const lang of LANGUAGES){
   const v=item.languages[lang];
   if(!v||!equal(Object.keys(v),['available','contract_aligned','allowed_slots','stored_slots'])
    || !safeCount(v.allowed_slots)||!safeCount(v.stored_slots)
    || ![true,false,null].includes(v.available)
    || ![true,false,null].includes(v.contract_aligned))fail('Language slot schema');
  }
 }
 const pairs=new Set();
 for(const item of r.page_inspections){
  if(!item||!equal(Object.keys(item),['key','language','observed','status',
    'contract_aligned','allowed_slots','stored_slots','published'])
    ||!PAGE_KEYS.includes(item.key)||!LANGUAGES.includes(item.language)
    ||!SAFE_STATES.has(item.status)||typeof item.observed!=='boolean'
    ||![true,false,null].includes(item.contract_aligned)
    ||![true,false,null].includes(item.published)
    ||!safeCount(item.allowed_slots)||!safeCount(item.stored_slots))fail('Inspected Page schema');
  const pair=item.key+'/'+item.language;
  if(pairs.has(pair))fail('Duplicate Page language');
  pairs.add(pair);
 }
 if(pairs.size!==24
  ||r.page_inspections_observed!==r.page_inspections.filter(x=>x.observed).length
  ||r.page_inspections_contract_aligned!==r.page_inspections.filter(x=>x.contract_aligned===true).length
  ||r.draft_language_views!==r.page_inspections.filter(x=>x.status==='draft').length
  ||r.inventory_language_available!==r.inventory.flatMap(x=>LANGUAGES.map(l=>x.languages[l])).filter(x=>x.available===true).length
  ||r.inventory_language_contract_aligned!==r.inventory.flatMap(x=>LANGUAGES.map(l=>x.languages[l])).filter(x=>x.contract_aligned===true).length)fail('Counter mismatch');
 const nav=r.navigation;
 if(!nav||!equal(Object.keys(nav),['observed','primary_item_count','footer_item_count','registered_targets_only'])
  ||typeof nav.observed!=='boolean'
  ||!safeCount(nav.primary_item_count)||!safeCount(nav.footer_item_count)
  ||![true,false].includes(nav.registered_targets_only))fail('Navigation');
 if(!equal(Object.keys(r.read_tools),AUDIT_READ_TOOLS)
  ||Object.values(r.read_tools).some(x=>!['payload_read','unverified'].includes(x)))fail('Read tool set');
 const checks=['design_contract_observed','design_variants_observed',
  'design_readiness_observed','seo_site_observed','seo_indexability_observed',
  'media_diagnostics_observed'];
 if(!equal(Object.keys(r.scoped_checks),checks)
  ||Object.values(r.scoped_checks).some(x=>typeof x!=='boolean'))fail('Checks');
 if(!Array.isArray(r.open_flags)||!r.open_flags.includes('production_visual_desktop_mobile_unverified')
  ||!r.open_flags.includes('native_wordpress_database_inventory_unverified'))fail('Release gates');
 const allowedFlags=new Set([
  'runtime_identity_unverified','theme_inventory_incomplete',
  'page_inspections_incomplete','page_contract_alignment_requires_review',
  'page_inventory_alignment_requires_review','navigation_contract_unverified',
  'design_seo_media_coverage_partial','production_visual_desktop_mobile_unverified',
  'native_wordpress_database_inventory_unverified'
 ]);
 if(r.open_flags.some(x=>!allowedFlags.has(x)))fail('Untrusted finding text');
 const expectedComplete=r.identity_compatible===true&&r.inventory_observed===true
  &&r.page_inspections_observed===24
  &&nav.observed===true&&nav.registered_targets_only===true
  &&nav.primary_item_count!==null&&nav.footer_item_count!==null
  &&Object.values(r.scoped_checks).every(Boolean);
 if((r.outcome==='MCP_GOVERNANCE_COVERAGE_OBSERVED')!==expectedComplete)fail('False complete outcome');
 const serialized=JSON.stringify(r);
 if(/(?:Bearer\s|ermt1_|erm1_|token_hash|token_prefix|connection_id|post_content|post_title|post_id|media_path|wp_user|password|author_email)/i.test(serialized))fail('Private payload leak');
 return {outcome:r.outcome,pages:r.page_inspections_observed,aligned:r.page_inspections_contract_aligned};
}
if(process.argv[1]?.endsWith('site-architect-47526-validate-governance.mjs')){
 const filename=process.argv[2];
 if(!filename||!filename.endsWith('/live-governance-readback.json'))throw Error('Expected sanitized output path');
 const report=JSON.parse(fs.readFileSync(filename,'utf8'));
 const result=validateGovernance(report);
 console.log('PASS: #475.26 closed-schema live governance readback; outcome='+result.outcome+
  ' Page inspections='+result.pages+'/24 aligned='+result.aligned+'/24');
}
