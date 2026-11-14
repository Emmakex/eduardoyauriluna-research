#!/usr/bin/env node
/**
 * #475.27: observe the four real Research collections without publishing
 * titles, content, post IDs, author names, URLs or any field values.
 *
 * WordPress object-editor.php uses get_posts(posts_per_page=100,
 * post_status=any). Its EN filter requires explicit _research_language=en,
 * while the actual public Theme accepts EN or missing-language meta. Therefore
 * do NOT claim the EN filtered response is the full public EN census.
 */
import fs from 'node:fs';
import path from 'node:path';
import {decodeResult} from './site-architect-47523-sanitize-mcp.mjs';

export const CONTRACT = Object.freeze({
  output: {page:'publications',post_type:'research_output'},
  project: {page:'projects',post_type:'research_project'},
  software: {page:'software',post_type:'research_software'},
  dataset: {page:'datasets',post_type:'research_dataset'},
});
export const FILTERS = Object.freeze(['all','en','es']);
const STATUSES=Object.freeze(['publish','draft','pending','future','private']);
const statusSet=new Set(STATUSES);
const LANGUAGES=new Set(['en','es']);

const record = v=>v!==null&&typeof v==='object'&&!Array.isArray(v);
const emptyStatuses = ()=>Object.fromEntries(STATUSES.map(s=>[s,0]));
const asInt=v=>Number.isSafeInteger(v)&&v>=0&&v<=100 ? v:null;

export function summarize(raw,kind,filter) {
  const data=decodeResult(raw);
  const expected=CONTRACT[kind];
  const wantedLanguage=filter==='all'?'':filter;
  if(!expected||!FILTERS.includes(filter)||!record(data)||
    data.kind!==kind||data.language!==wantedLanguage||
    !Array.isArray(data.items)||asInt(data.count)===null||
    data.items.length!==data.count) {
    return {observed:false,items_count:null,may_be_truncated:null,
      status_counts:null,language_status_counts:null,unexpected_rows:null};
  }
  const statuses=emptyStatuses();
  const languageCounts=Object.fromEntries(['en','es'].map(lang=>[lang,emptyStatuses()]));
  let unexpected=0;
  for (const item of data.items) {
    if(!record(item)||item.post_type!==expected.post_type||
      !statusSet.has(item.status)||!LANGUAGES.has(item.language)||
      (filter!=='all'&&item.language!==filter)) {
      unexpected++;
      continue;
    }
    statuses[item.status]++;
    languageCounts[item.language][item.status]++;
  }
  return {
    observed:unexpected===0,
    items_count:data.count,
    may_be_truncated:data.count===100,
    status_counts:statuses,
    language_status_counts:languageCounts,
    unexpected_rows:unexpected,
  };
}

export function analyze(raw,sourceSha,observedAt='2026-11-14T00:00:00Z') {
  if(!/^[0-9a-f]{40}$/.test(sourceSha))throw Error('Invalid source SHA');
  const kinds={};
  let everyResponseObserved=true;
  for(const kind of Object.keys(CONTRACT)) {
    const observations={};
    for(const filter of FILTERS) {
      const name='site_research_objects_list-'+kind+'-'+filter;
      observations[filter]=summarize(raw[name],kind,filter);
      if(!observations[filter].observed)everyResponseObserved=false;
    }
    const full=observations.all, en=observations.en, es=observations.es;
    const countFor=(obj,language)=>obj?.language_status_counts?.[language]?.publish??null;
    const enPublished=countFor(full,'en');
    const esPublished=countFor(full,'es');
    const enExplicit=countFor(en,'en'),esExplicit=countFor(es,'es');
    // get_posts(post_status=any) includes non-trash statuses and caps at 100.
    const bounded=full.observed && !full.may_be_truncated;
    const enMissingMetaPossible=bounded&&en.observed&&enExplicit!==null&&enPublished!==null
      ? enPublished-enExplicit:null;
    const filteringCoherent=bounded&&en.observed&&es.observed&&
      enExplicit<=enPublished && esExplicit<=esPublished &&
      // ES language meta is always explicit in the public Theme filter.
      esExplicit===esPublished &&
      // Filtered listings cannot exceed the unfiltered inventory.
      en.items_count<=full.items_count&&es.items_count<=full.items_count;

    kinds[kind]={
      page:CONTRACT[kind].page,
      post_type:CONTRACT[kind].post_type,
      observations,
      published_by_effective_theme_language:{
        en:enPublished,es:esPublished
      },
      published_with_explicit_language_meta:{
        en:enExplicit,es:esExplicit
      },
      possible_en_without_explicit_meta:enMissingMetaPossible,
      lang_filter_consistent:filteringCoherent,
      public_visibility_evidence:{
        en:bounded&&filteringCoherent?(enPublished>0?'published_record_present':'no_published_record_in_complete_bounded_list'):'not_proven',
        es:bounded&&filteringCoherent?(esPublished>0?'published_record_present':'no_published_record_in_complete_bounded_list'):'not_proven',
      },
      bounded_inventory_complete:bounded,
    };
  }
  const counts=Object.values(kinds);
  const fullCoverage=everyResponseObserved&&counts.every(x=>x.lang_filter_consistent&&x.bounded_inventory_complete);
  return {
    schema_version:1,
    mode:'EDUARDO_RESEARCH_COLLECTION_READONLY',
    target:'eduardoyauriluna.com',
    source_commit:sourceSha,
    observed_at_utc:observedAt,
    scope:'four_theme_collection_types_en_es',
    remote_post_limit_per_query:100,
    wordpress_editor_listing_excludes_trash:true,
    en_theme_includes_missing_language_meta:true,
    result:fullCoverage?'BOUNDED_RESEARCH_INVENTORY_OBSERVED':'RESEARCH_INVENTORY_PARTIAL',
    kinds,
    total_queries:12,
    verified_queries:Object.values(kinds).flatMap(x=>Object.values(x.observations)).filter(x=>x.observed).length,
    release_certification:'NOT_RELEASE_CERTIFIED',
    native_db_full_census:false,
    raw_mcp_payloads_exported:false,
    production_mutations_performed:false,
  };
}

function main(){
  const args=process.argv.slice(2);
  const opt=k=>args[args.indexOf(k)+1];
  const input=opt('--input'),out=opt('--output'),sha=opt('--source-sha');
  if(!input||!out||!sha||
    !/^\/.+\/live-research-collection-readback\.json$/.test(out)||
    !fs.statSync(input).isDirectory())throw Error('Invalid bounded input/output');
  const raw={};
  for(const kind of Object.keys(CONTRACT))for(const filter of FILTERS){
    const name='site_research_objects_list-'+kind+'-'+filter;
    try{raw[name]=JSON.parse(fs.readFileSync(path.join(input,name+'.json'),'utf8'));}
    catch{raw[name]=null;}
  }
  const report=analyze(raw,sha,new Date().toISOString());
  fs.writeFileSync(out,JSON.stringify(report,null,2)+'\n',{mode:0o600});
  console.log('RESEARCH_COLLECTION_READBACK='+report.result+
    ' query_receipts='+report.verified_queries+'/12');
}
if(process.argv[1]?.endsWith('site-architect-47527-research-inventory.mjs'))main();
