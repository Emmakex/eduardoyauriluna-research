import test from 'node:test';
import assert from 'node:assert/strict';
import { deriveStatus } from './site-architect-47524-status-contract.mjs';
import { sanitizeObserved } from './site-architect-47523-sanitize-mcp.mjs';

const sha = 'c'.repeat(40);
const wrap = data => ({ result: { content: [{type:'text', text:JSON.stringify(data)}] } });
const complete = () => sanitizeObserved({
  research_status:wrap({manager_version:'0.11.1', runtime_url:'https://eduardoyauriluna.com/', preset:{id:'research',compatible:true}}),
  research_versions:wrap({manager:'0.11.1', theme:'1.1.3'}),
  research_capabilities:wrap({granted_scopes:['site.read'],writes_enabled:false}),
  site_pages_list:wrap({total:24,items:[]}),
  site_navigation_inspect:wrap({primary:[],footer:[]}),
  site_media_list:wrap({total:8,items:[]})
},sha);

test('pending is only an observation status, not release ready',()=>{
  const s=deriveStatus('pending','unknown',null);
  assert.equal(s.state,'pending');
  assert.equal(s.context,'site-architect/eduardo-mcp-readback');
  assert.ok(!s.description.includes('certified'));
});

test('validated live observed outcome becomes green status but explicitly not cutover',()=>{
  const report=complete();
  assert.equal(report.outcome,'MCP_PRIVATE_READBACK_OBSERVED');
  const s=deriveStatus('outcome','success',report);
  assert.equal(s.state,'success');
  assert.ok(s.description.includes('cutover still open'));
  assert.ok(!JSON.stringify(s).includes('pages_summary'));
});

test('missing protected job or artifact is an error not green',()=>{
  for(const result of ['failure','skipped','cancelled','timed_out']){
    assert.equal(deriveStatus('outcome',result,complete()).state,'error');
  }
  assert.equal(deriveStatus('outcome','success',null).state,'error');
});

test('incomplete MCP result is a failing status',()=>{
  const report=sanitizeObserved({},sha);
  assert.equal(report.outcome,'READBACK_INCOMPLETE');
  assert.equal(deriveStatus('outcome','success',report).state,'failure');
});

test('invalid evidence rejected and cannot become green status',()=>{
  const report=complete();
  report.release_certification='PRODUCTION_READY';
  assert.equal(deriveStatus('outcome','success',report).state,'error');
});

test('unknown statuses/modes never accepted',()=>{
  assert.throws(()=>deriveStatus('unexpected','success',complete()));
  assert.throws(()=>deriveStatus('outcome','new_state',complete()));
});
