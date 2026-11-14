import test from 'node:test';
import assert from 'node:assert/strict';
import {CONTRACT,FILTERS,analyze,summarize} from './site-architect-47527-research-inventory.mjs';
import {validateResearchInventory} from './site-architect-47527-validate-research-inventory.mjs';

const SHA='e'.repeat(40);
const wrap=data=>({result:{content:[{type:'text',text:JSON.stringify({ok:true,data})}]}});
const rawRow=(kind,language,status='publish',nonce='private')=>({
 post_id:13942,post_type:CONTRACT[kind].post_type,status,language,
 title:'PRIVATE PAPER NAME '+nonce,
 content:'PRIVATE DOCTORAL CONTENT',
 excerpt:'PRIVATE ACADEMIC PARTNER',
 authors:['PRIVATE PERSON'],
 url:'https://secret.example.edu/research/'+nonce,
 creation_token:'PRIVATE_TOKEN_123'
});
function build(groups={}){
 const raw={};
 for(const kind of Object.keys(CONTRACT)){
  const all=groups[kind]||[];
  for(const filter of FILTERS){
   const subset=filter==='all'?all:all.filter(x=>x.language===filter&&x.explicit!==false);
   raw['site_research_objects_list-'+kind+'-'+filter]=wrap({
    kind,language:filter==='all'?'':filter,
    count:subset.length,items:subset
   });
  }
 }
 return raw;
}

test('no published Research objects means each public collection is legitimately gated',()=>{
 const report=analyze(build(),SHA);
 const result=validateResearchInventory(report);
 assert.equal(result.outcome,'BOUNDED_RESEARCH_INVENTORY_OBSERVED');
 assert.equal(result.queries,12);
 for(const kind of Object.keys(CONTRACT)){
  assert.equal(report.kinds[kind].published_by_effective_theme_language.en,0);
  assert.equal(report.kinds[kind].published_by_effective_theme_language.es,0);
  assert.equal(report.kinds[kind].public_visibility_evidence.en,'no_published_record_in_complete_bounded_list');
  assert.equal(report.kinds[kind].public_visibility_evidence.es,'no_published_record_in_complete_bounded_list');
 }
 assert.equal(report.release_certification,'NOT_RELEASE_CERTIFIED');
});

test('published and draft counts are safe; 404 for a published collection requires review',()=>{
 const report=analyze(build({
  output:[rawRow('output','en'),rawRow('output','es'),rawRow('output','es','draft')],
  project:[rawRow('project','en','draft')]
 }),SHA);
 const x=validateResearchInventory(report);
 assert.equal(x.queries,12);
 assert.deepEqual(report.kinds.output.published_by_effective_theme_language,{en:1,es:1});
 assert.equal(report.kinds.output.public_visibility_evidence.en,'published_record_present');
 assert.equal(report.kinds.project.public_visibility_evidence.en,'no_published_record_in_complete_bounded_list');
 for(const privateString of [
  'PRIVATE PAPER NAME','PRIVATE DOCTORAL CONTENT','PRIVATE ACADEMIC PARTNER',
  'PRIVATE PERSON','secret.example.edu','PRIVATE_TOKEN','post_id','post_title'
 ]) assert.ok(!JSON.stringify(report).includes(privateString));
});

test('Theme treats missing EN language meta as EN but filtered WordPress editor does not',()=>{
 const legacy={...rawRow('software','en'),explicit:false};
 const report=analyze(build({software:[legacy]}),SHA);
 const x=validateResearchInventory(report);
 assert.equal(x.outcome,'BOUNDED_RESEARCH_INVENTORY_OBSERVED');
 assert.equal(report.kinds.software.published_by_effective_theme_language.en,1);
 assert.equal(report.kinds.software.published_with_explicit_language_meta.en,0);
 assert.equal(report.kinds.software.possible_en_without_explicit_meta,1);
 assert.equal(report.kinds.software.public_visibility_evidence.en,'published_record_present');
});

test('100-row cap is never treated as a complete census',()=>{
 const data=Array.from({length:100},(_,i)=>rawRow('dataset','en','draft','_'+i));
 const report=analyze(build({dataset:data}),SHA);
 validateResearchInventory(report);
 assert.equal(report.result,'RESEARCH_INVENTORY_PARTIAL');
 assert.equal(report.kinds.dataset.observations.all.may_be_truncated,true);
 assert.equal(report.kinds.dataset.public_visibility_evidence.en,'not_proven');
});

test('MCP denial, mismatched post type and missing response are not certified',()=>{
 const raw=build();
 raw['site_research_objects_list-output-en']={result:{isError:true,content:[]}};
 const report=analyze(raw,SHA);
 assert.equal(report.verified_queries,11);
 assert.equal(report.result,'RESEARCH_INVENTORY_PARTIAL');
 validateResearchInventory(report);
 const wrong=build({dataset:[{...rawRow('dataset','en'),post_type:'post'}]});
 const report2=analyze(wrong,SHA);
 assert.equal(report2.kinds.dataset.observations.all.observed,false);
 assert.equal(report2.result,'RESEARCH_INVENTORY_PARTIAL');
 validateResearchInventory(report2);
});

test('closed schema rejects forged publication counts and cutover',()=>{
 const report=analyze(build(),SHA);
 report.release_certification='PRODUCTION_READY';
 assert.throws(()=>validateResearchInventory(report));
 report.release_certification='NOT_RELEASE_CERTIFIED';
 report.kinds.output.observations.all.items_count=1;
 assert.throws(()=>validateResearchInventory(report));
 report.kinds.output.observations.all.items_count=0;
 report.kinds.output.record_title='DO NOT SHARE';
 assert.throws(()=>validateResearchInventory(report));
});
