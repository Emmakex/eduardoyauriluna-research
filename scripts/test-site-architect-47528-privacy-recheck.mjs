import test from 'node:test';
import assert from 'node:assert/strict';
import {reduceTargeted,validateTargeted}
 from './site-architect-47528-targeted-private-recheck.mjs';

const sha='f'.repeat(40);
const wrap=x=>({result:{content:[{type:'text',text:JSON.stringify({ok:true,data:x})}]}});
const liveStatus=()=>wrap({manager_version:'0.11.1',theme_version:'1.1.3',
 runtime_url:'https://eduardoyauriluna.com/',preset:{compatible:true},
 token_hash:'PRIVATE MUST NOT LEAK'});
const livePage=()=>wrap({key:'privacy-policy',language:'en',status:'publish',
 contract_aligned:true,allowed_slots:['title','body'],stored_slots:{
 title:'PRIVATE LEGAL TEXT'},url:'https://private.invalid',post_id:900});

test('valid exact page read becomes a bounded read receipt, not a release claim',()=>{
 const report=reduceTargeted(liveStatus(),livePage(),sha);
 assert.equal(validateTargeted(report),'TARGET_PAGE_READBACK_CONFIRMED');
 assert.equal(report.allowed_slot_count,2);
 assert.equal(report.stored_slot_count,1);
 assert.equal(report.production_mutations_performed,false);
 for(const secret of ['PRIVATE LEGAL TEXT','PRIVATE MUST NOT LEAK','private.invalid',
   'post_id','token_hash','PRIVATE LEGAL'])assert.ok(!JSON.stringify(report).includes(secret));
});

test('MCP tool error or timeout remains unverified and does not forge Page success',()=>{
 const report=reduceTargeted(liveStatus(),{result:{isError:true,content:[]}},sha);
 assert.equal(report.outcome,'READBACK_UNVERIFIED');
 assert.equal(report.inspect_observed,false);
 assert.equal(report.status,null);
 validateTargeted(report);
});

test('foreign host and version drift both fail closed',()=>{
 const wrong=wrap({manager_version:'0.11.1',theme_version:'1.1.3',
   runtime_url:'https://not-eduardo.invalid/',preset:{compatible:true}});
 const report=reduceTargeted(wrong,livePage(),sha);
 assert.equal(report.outcome,'READBACK_UNVERIFIED');
 validateTargeted(report);
});

test('draft status and contract drift are explicit non-ready outcomes',()=>{
 const raw=livePage();
 const draft=wrap({...JSON.parse(raw.result.content[0].text).data,status:'draft'});
 const report=reduceTargeted(liveStatus(),draft,sha);
 assert.equal(report.outcome,'PAGE_PUBLICATION_STATE_REVIEW');
 validateTargeted(report);
 const drift=wrap({...JSON.parse(raw.result.content[0].text).data,contract_aligned:false});
 const driftReport=reduceTargeted(liveStatus(),drift,sha);
 assert.equal(driftReport.outcome,'PAGE_CONTRACT_DRIFT_REVIEW');
 validateTargeted(driftReport);
});

test('private fields, false certification or forged clean status rejected',()=>{
 const report=reduceTargeted(liveStatus(),livePage(),sha);
 report.release_certification='READY';
 assert.throws(()=>validateTargeted(report));
 report.release_certification='NOT_RELEASE_CERTIFIED';
 report.title='PRIVATE';
 assert.throws(()=>validateTargeted(report));
 delete report.title;
 report.inspect_observed=false;
 assert.throws(()=>validateTargeted(report));
});
