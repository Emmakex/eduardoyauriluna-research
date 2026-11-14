import test from 'node:test';
import assert from 'node:assert/strict';
import {reduceActions} from './site-architect-47525-discover-workflow.mjs';

const sha='3'.repeat(40);
const run=(id,status='completed',conclusion='success')=>({
 id,head_sha:sha,event:'push',status,conclusion,
 actor:{login:'NEVER_EXPOSE'},name:'secret title should not be copied'
});

test('known GitHub push action run is discoverable without MCP data',()=>{
 const x=reduceActions(sha,[run(43640001234)],[{
   context:'site-architect/eduardo-mcp-readback',state:'pending',
   target_url:'https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/43640001234',
   description:'Awaiting protected runner',creator:{login:'PRIVATE'}
 }]);
 assert.equal(x.outcome,'GITHUB_RUN_AND_STATUS_OBSERVED');
 assert.equal(x.matching_runs[0].run_id,43640001234);
 assert.equal(x.mcp_payloads_accessed,false);
 assert.equal(x.release_certification,'NOT_RELEASE_CERTIFIED');
 assert.ok(!JSON.stringify(x).includes('NEVER_EXPOSE'));
 assert.ok(!JSON.stringify(x).includes('PRIVATE'));
});

test('running or absent job is never certified',()=>{
 assert.equal(reduceActions(sha,[run(43640001234,'waiting',null)],[]).outcome,
 'PROTECTED_WORKFLOW_NOT_COMPLETED');
 assert.equal(reduceActions(sha,[],[]).outcome,'NO_MAIN_WORKFLOW_RUN_VISIBLE');
 assert.equal(reduceActions(sha,[run(43640001234)],[]).outcome,
 'COMPLETED_WITHOUT_COMMIT_STATUS');
});

test('untrusted metadata rejected before artifact',()=>{
 assert.throws(()=>reduceActions(sha,[{...run(123),event:'forked'}],[]));
 assert.throws(()=>reduceActions('x',[],[]));
 assert.throws(()=>reduceActions(sha,[],[{context:'site-architect/eduardo-mcp-readback',state:'unknown'}]));
});
