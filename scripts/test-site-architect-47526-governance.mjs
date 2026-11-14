import test from 'node:test';
import assert from 'node:assert/strict';
import {PAGE_KEYS,LANGUAGES,AUDIT_READ_TOOLS,reduceGovernance}
  from './site-architect-47526-sanitize-governance.mjs';
import {validateGovernance} from './site-architect-47526-validate-governance.mjs';

const sha='d'.repeat(40);
const wrap=v=>({result:{content:[{type:'text',text:JSON.stringify({ok:true,data:v})}]}});
function all(){
 const inventory=PAGE_KEYS.map(key=>({key,exists:true,
   page_id:999,title:'PRIVATE TITLE MUST NOT LEAK',
   languages:Object.fromEntries(LANGUAGES.map(lang=>[lang,
    {language:lang,available:true,contract_aligned:true,url:'https://private.internal/path',
     allowed_slots:['hero_title','bio'],stored_slots:{hero_title:'PRIVATE CV NAME'},
     effective_slots:{hero_title:'PRIVATE CV NAME',bio:'PRIVATE DETAILS'}}]))}));
 const raw={
   research_status:wrap({manager_version:'0.11.1',theme_version:'1.1.3',
    runtime_url:'https://eduardoyauriluna.com/',preset:{compatible:true}}),
   site_pages_list:wrap({count:12,items:inventory}),
   site_navigation_inspect:wrap({arbitrary_urls:false,
    state:{primary:['about','research','cv'],footer:['privacy-policy','legal-notice']},
    revision:'DONT_EXPORT'}),
 };
 for(const tool of AUDIT_READ_TOOLS)raw[tool]=wrap({ready:true,
   secret:'never export this object',checks:[{user_email:'private@email.test'}]});
 for(const key of PAGE_KEYS)for(const lang of LANGUAGES){
  raw['site_page_inspect-'+key+'-'+lang]=wrap({
    key,language:lang,status:'publish',published:true,contract_aligned:true,
    page_id:999,allowed_slots:['hero_title','bio'],
    stored_slots:{hero_title:'PRIVATE DOCTORAL CV'},
    effective_slots:{bio:'PRIVATE DATA'},url:'https://private.internal/path'
  });
 }
 return raw;
}

test('complete 12 key / 24 language governance is measurable and all private values redacted',()=>{
 const report=reduceGovernance(all(),sha,'2026-11-14T00:00:00Z');
 const result=validateGovernance(report);
 assert.equal(report.outcome,'MCP_GOVERNANCE_COVERAGE_OBSERVED');
 assert.equal(result.pages,24);
 assert.equal(result.aligned,24);
 assert.equal(report.navigation.primary_item_count,3);
 assert.equal(report.navigation.footer_item_count,2);
 assert.equal(report.inventory_language_available,24);
 assert.equal(report.inventory_language_contract_aligned,24);
 assert.equal(report.production_mutations_performed,false);
 assert.equal(report.release_certification,'NOT_RELEASE_CERTIFIED');
 for(const forbidden of ['PRIVATE TITLE','PRIVATE CV NAME','PRIVATE DOCTORAL CV',
   'private.internal','DONT_EXPORT','private@email.test','page_id','effective_slots']){
   assert.ok(!JSON.stringify(report).includes(forbidden),forbidden);
 }
});

test('MCP errors/denied pages are partial, never fake certification',()=>{
 const raw=all();
 raw['site_page_inspect-cv-es']={result:{isError:true,content:[]}};
 const report=reduceGovernance(raw,sha);
 assert.equal(report.outcome,'MCP_GOVERNANCE_PARTIAL');
 assert.equal(report.page_inspections_observed,23);
 assert.ok(report.open_flags.includes('page_inspections_incomplete'));
 validateGovernance(report);
});

test('host mismatch cannot certify even when tools return good data',()=>{
 const raw=all();
 raw.research_status=wrap({manager_version:'0.11.1',
  theme_version:'1.1.3',runtime_url:'https://attacker.invalid',
  preset:{compatible:true}});
 const report=reduceGovernance(raw,sha);
 assert.equal(report.outcome,'MCP_GOVERNANCE_PARTIAL');
 validateGovernance(report);
});

test('read-only response cannot export secrets or mark cutover certified',()=>{
 const report=reduceGovernance(all(),sha);
 report.release_certification='DELIVERED';
 assert.throws(()=>validateGovernance(report));
 report.release_certification='NOT_RELEASE_CERTIFIED';
 report.inventory[0].languages.en.post_content='private';
 assert.throws(()=>validateGovernance(report));
});

test('contract drift is observable, not conflated with successful alignment',()=>{
 const raw=all();
 raw['site_page_inspect-privacy-policy-es']=wrap({
  key:'privacy-policy',language:'es',status:'publish',
  contract_aligned:false,allowed_slots:['title'],stored_slots:{},
  published:true
 });
 const report=reduceGovernance(raw,sha);
 assert.equal(report.outcome,'MCP_GOVERNANCE_COVERAGE_OBSERVED');
 assert.equal(report.page_inspections_contract_aligned,23);
 assert.ok(report.open_flags.includes('page_contract_alignment_requires_review'));
 validateGovernance(report);
});
