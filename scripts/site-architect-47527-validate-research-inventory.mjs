#!/usr/bin/env node
/** Closed-schema validation of sanitized live Research objects, NEVER raw MCP. */
import fs from 'node:fs';
import {CONTRACT,FILTERS} from './site-architect-47527-research-inventory.mjs';

const STATUS=['publish','draft','pending','future','private'];
const LANG=['en','es'];
const allowedVisibility=new Set([
  'published_record_present','no_published_record_in_complete_bounded_list','not_proven'
]);
const eq=(a,b)=>Array.isArray(a)&&Array.isArray(b)&&a.length===b.length&&
  JSON.stringify([...a].sort())===JSON.stringify([...b].sort());
const record=v=>v!==null&&typeof v==='object'&&!Array.isArray(v);
const safeInt=v=>Number.isSafeInteger(v)&&v>=0&&v<=100;
const fail=message=>{throw new Error('Unsafe research collection report: '+message);};

function checkCounts(value){
  if(!record(value)||!eq(Object.keys(value),STATUS)||STATUS.some(s=>!safeInt(value[s])))fail('status counts');
}
function checkObservation(o){
  if(!record(o)||!eq(Object.keys(o),[
    'observed','items_count','may_be_truncated','status_counts','language_status_counts','unexpected_rows'
  ])||typeof o.observed!=='boolean')fail('observation schema');
  if(o.items_count===null){
    if(o.observed!==false||o.may_be_truncated!==null||o.status_counts!==null
      ||o.language_status_counts!==null||o.unexpected_rows!==null)fail('missing data must be unverified');
    return;
  }
  if(!safeInt(o.items_count)||o.may_be_truncated!==(o.items_count===100)
    ||!safeInt(o.unexpected_rows))fail('bounded counts');
  checkCounts(o.status_counts);
  if(!record(o.language_status_counts)||!eq(Object.keys(o.language_status_counts),LANG))fail('language counts');
  for(const lang of LANG)checkCounts(o.language_status_counts[lang]);
  const statusSum=STATUS.reduce((acc,s)=>acc+o.status_counts[s],0);
  if(statusSum+o.unexpected_rows!==o.items_count)fail('status count mismatch');
  for(const s of STATUS){
    if(o.status_counts[s]!==LANG.reduce((n,l)=>n+o.language_status_counts[l][s],0))fail('language count mismatch');
  }
  if(o.observed!== (o.unexpected_rows===0))fail('malformed row classified observed');
}

export function validateResearchInventory(r){
  if(!record(r)||!eq(Object.keys(r),[
    'schema_version','mode','target','source_commit','observed_at_utc','scope',
    'remote_post_limit_per_query','wordpress_editor_listing_excludes_trash',
    'en_theme_includes_missing_language_meta','result','kinds','total_queries',
    'verified_queries','release_certification','native_db_full_census',
    'raw_mcp_payloads_exported','production_mutations_performed'
  ]))fail('root schema');
  if(r.schema_version!==1||r.mode!=='EDUARDO_RESEARCH_COLLECTION_READONLY'||
    r.target!=='eduardoyauriluna.com'||!/^[0-9a-f]{40}$/.test(r.source_commit)||
    r.scope!=='four_theme_collection_types_en_es'||r.remote_post_limit_per_query!==100||
    r.wordpress_editor_listing_excludes_trash!==true||
    r.en_theme_includes_missing_language_meta!==true||
    r.release_certification!=='NOT_RELEASE_CERTIFIED'||
    r.native_db_full_census!==false||r.raw_mcp_payloads_exported!==false||
    r.production_mutations_performed!==false)fail('identity or false certification');
  if(!record(r.kinds)||!eq(Object.keys(r.kinds),Object.keys(CONTRACT)))fail('unapproved kind');
  let observed=0,complete=true;
  for(const [kind,spec] of Object.entries(CONTRACT)){
    const x=r.kinds[kind];
    if(!record(x)||!eq(Object.keys(x),[
      'page','post_type','observations','published_by_effective_theme_language',
      'published_with_explicit_language_meta','possible_en_without_explicit_meta',
      'lang_filter_consistent','public_visibility_evidence','bounded_inventory_complete'
    ])||x.page!==spec.page||x.post_type!==spec.post_type)fail('kind schema');
    if(!record(x.observations)||!eq(Object.keys(x.observations),FILTERS))fail('filter set');
    for(const filter of FILTERS){
      checkObservation(x.observations[filter]);
      if(x.observations[filter].observed)observed++;
      else complete=false;
    }
    for(const prop of ['published_by_effective_theme_language',
      'published_with_explicit_language_meta']){
      if(!record(x[prop])||!eq(Object.keys(x[prop]),LANG))fail('language summary');
      for(const lang of LANG){
        const v=x[prop][lang];
        if(v!==null&&!safeInt(v))fail('publication totals');
      }
    }
    if(!record(x.public_visibility_evidence)||
      !eq(Object.keys(x.public_visibility_evidence),LANG)||
      LANG.some(l=>!allowedVisibility.has(x.public_visibility_evidence[l])))fail('visibility state');
    if(x.possible_en_without_explicit_meta!==null &&
      !safeInt(x.possible_en_without_explicit_meta))fail('EN legacy meta summary');
    const all=x.observations.all, en=x.observations.en, es=x.observations.es;
    const published=all.language_status_counts;
    const expected={
      en:published?.en?.publish??null,
      es:published?.es?.publish??null
    };
    const explicit={
      en:en.language_status_counts?.en?.publish??null,
      es:es.language_status_counts?.es?.publish??null
    };
    if(LANG.some(l=>x.published_by_effective_theme_language[l]!==expected[l]||
      x.published_with_explicit_language_meta[l]!==explicit[l]))fail('derived counts');
    const bounded=all.observed&&!all.may_be_truncated;
    const filtering=bounded&&en.observed&&es.observed&&
      explicit.en<=expected.en&&explicit.es<=expected.es&&
      explicit.es===expected.es&&
      en.items_count<=all.items_count&&es.items_count<=all.items_count;
    if(x.bounded_inventory_complete!==bounded||x.lang_filter_consistent!==filtering)fail('consistency gates');
    const missing=bounded&&en.observed&&expected.en!==null&&explicit.en!==null
      ?expected.en-explicit.en:null;
    if(x.possible_en_without_explicit_meta!==missing)fail('default language gap');
    for(const l of LANG){
      const value=bounded&&filtering ?
        (expected[l]>0?'published_record_present':'no_published_record_in_complete_bounded_list'):'not_proven';
      if(x.public_visibility_evidence[l]!==value)fail('unsupported visible URL inference');
    }
    if(!bounded||!filtering)complete=false;
  }
  if(r.total_queries!==12||r.verified_queries!==observed ||
    !['RESEARCH_INVENTORY_PARTIAL','BOUNDED_RESEARCH_INVENTORY_OBSERVED'].includes(r.result)||
    (r.result==='BOUNDED_RESEARCH_INVENTORY_OBSERVED')!==complete)fail('unsubstantiated outcome');
  const serialized=JSON.stringify(r);
  if(/(?:Bearer\s+|ermt1_|erm1_|token_hash|connection_id|post_id|post_content|post_title|author|password|credential|email|wp_user|creation_token|https?:\/\/)/i.test(serialized))
    fail('sensitive field leak');
  return {outcome:r.result,queries:r.verified_queries,
    published: Object.fromEntries(Object.entries(r.kinds)
      .map(([kind,x])=>[kind,x.published_by_effective_theme_language]))};
}

if(process.argv[1]?.endsWith('site-architect-47527-validate-research-inventory.mjs')){
 const p=process.argv[2];
 if(!p||!p.endsWith('/live-research-collection-readback.json'))throw Error('Expected bounded sanitized report');
 const report=JSON.parse(fs.readFileSync(p,'utf8'));
 const result=validateResearchInventory(report);
 console.log('PASS: #475.27 sanitized Research collection observation '+result.outcome+
   ' queries='+result.queries+'/12; release NOT certified');
}
