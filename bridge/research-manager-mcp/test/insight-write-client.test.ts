import assert from 'node:assert/strict';
import test from 'node:test';
import { ResearchManagerWriteClient } from '../src/write-manager-client.js';

const writeToken='ermt1_insight-write-token';
const planId='erm-remote-plan-0123456789abcdefabcd-1234abcd';
const operationId='erm-operation-0123456789abcdef0123456789abcdef';

function client(fetchImpl: typeof fetch, token: string | null = writeToken){
  return new ResearchManagerWriteClient({managerBaseUrl:new URL('https://example.test'),managerWriteToken:token,managerTimeoutMs:2_000},fetchImpl);
}

function ok(data:unknown){return new Response(JSON.stringify({ok:true,data}),{status:200,headers:{'Content-Type':'application/json'}});}

test('Insight create planning uses fixed operations route and exact bounded payload',async()=>{
  let seenUrl=''; let seenInit:RequestInit|undefined;
  const api=client(async(input,init)=>{seenUrl=input instanceof Request?input.url:input.toString();seenInit=init;return ok({plan_id:planId,operation:'insight-create',status:'planned'});});
  const request={data:{title:'Bounded research note',slug:'bounded-research-note',excerpt:'Summary',content:'Body',language:'en',insight_type:'research_note',status:'draft' as const},reason:'Phase 2C A1'};
  const result=await api.planInsightCreate(request);
  assert.equal(new URL(seenUrl).pathname,'/wp-json/research-manager/v1/operations/plan');
  assert.equal(seenInit?.method,'POST');
  const headers=new Headers(seenInit?.headers);
  assert.equal(headers.get('authorization'),`Bearer ${writeToken}`);
  assert.match(headers.get('x-research-manager-request-id')??'',/^mcp-insight-create-plan-/);
  assert.deepEqual(JSON.parse(String(seenInit?.body)),{operation:'insight-create',payload:{data:request.data,reason:request.reason}});
  assert.deepEqual(result,{plan_id:planId,operation:'insight-create',status:'planned'});
});

test('Insight update planning preserves exact post_id and changes payload',async()=>{
  let body:unknown;
  const api=client(async(_input,init)=>{body=JSON.parse(String(init?.body));return ok({plan_id:planId,operation:'insight-update',status:'planned'});});
  await api.planInsightUpdate({postId:42,changes:{title:'Updated title',line_ids:[11,12]},reason:'Bounded editorial update'});
  assert.deepEqual(body,{operation:'insight-update',payload:{post_id:42,changes:{title:'Updated title',line_ids:[11,12]},reason:'Bounded editorial update'}});
});

test('Insight status and scheduling are rejected before transport when mixed with other changes',()=>{
  let calls=0;
  const api=client(async()=>{calls+=1;return ok({});});
  assert.throws(()=>api.planInsightUpdate({postId:42,changes:{status:'publish',title:'No mix'}}),/status must be changed in its own Preview/);
  assert.throws(()=>api.planInsightUpdate({postId:42,changes:{scheduled_at:'2026-12-01T10:00:00Z',excerpt:'No mix'}}),/scheduling must be changed in its own Preview/);
  assert.equal(calls,0);
});

test('Insight writes fail closed when no write credential is provisioned',async()=>{
  let calls=0;
  const api=client(async()=>{calls+=1;return ok({});},null);
  await assert.rejects(()=>api.planInsightCreate({data:{title:'Draft',language:'en',insight_type:'research_note',status:'draft'}}),/write credential is not configured/);
  assert.equal(calls,0);
});

test('Insight Apply preflights stored plan domain and refuses foreign operations',async()=>{
  let calls=0;
  const api=client(async()=>{calls+=1;return ok({plan_id:planId,operation:'page-slots-update'});});
  await assert.rejects(()=>api.applyInsight({planId,confirm:true}),/does not reference an allowed operation/);
  assert.equal(calls,1);
});

test('Insight Apply uses exact stored plan then confirm-only Apply body',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined;requestId:string|null}>=[];
  const api=client(async(input,init)=>{
    const path=new URL(input instanceof Request?input.url:input.toString()).pathname;
    const headers=new Headers(init?.headers);
    requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body),requestId:headers.get('x-research-manager-request-id')});
    if(path.endsWith(`/plans/${planId}`)) return ok({plan_id:planId,operation:'insight-update',status:'planned'});
    return ok({operation_id:operationId,operation:'insight-update',status:'applied'});
  });
  await api.applyInsight({planId,confirm:true});
  assert.equal(requests.length,2);
  assert.equal(requests[0]?.path,`/wp-json/research-manager/v1/plans/${planId}`);
  assert.equal(requests[0]?.method,'GET');
  assert.match(requests[0]?.requestId??'',/^mcp-insight-apply-preflight-/);
  assert.equal(requests[1]?.path,`/wp-json/research-manager/v1/plans/${planId}/apply`);
  assert.equal(requests[1]?.method,'POST');
  assert.deepEqual(JSON.parse(requests[1]?.body??'{}'),{confirm:true});
  assert.match(requests[1]?.requestId??'',/^mcp-insight-apply-/);
});

test('Insight Verify and Rollback use exact operation routes and mandatory fixed bodies',async()=>{
  const requests:Array<{path:string;body:unknown}>=[];
  const api=client(async(input,init)=>{requests.push({path:new URL(input instanceof Request?input.url:input.toString()).pathname,body:JSON.parse(String(init?.body))});return ok({operation_id:operationId});});
  await api.verifyInsight({operationId,rendered:true});
  await api.rollbackInsight({operationId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/operations/${operationId}/verify`,body:{rendered:true}},
    {path:`/wp-json/research-manager/v1/operations/${operationId}/rollback`,body:{confirm:true}},
  ]);
});
