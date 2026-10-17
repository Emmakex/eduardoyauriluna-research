import assert from 'node:assert/strict';
import test from 'node:test';
import { ResearchManagerWriteClient } from '../src/write-manager-client.js';

const writeToken='ermt1_insight-pairing-token';
const planId='erm-insight-pair-plan-0123456789abcdefab-1234abcd';
const operationId='erm-insight-pair-op-0123456789abcdef0123456789abcdef';

function client(fetchImpl: typeof fetch, token: string | null = writeToken){
  return new ResearchManagerWriteClient({managerBaseUrl:new URL('https://example.test'),managerWriteToken:token,managerTimeoutMs:2_000},fetchImpl);
}

function ok(data:unknown){return new Response(JSON.stringify({ok:true,data}),{status:200,headers:{'Content-Type':'application/json'}});}

test('Insight pair and unpair planning use the dedicated pairing gateway and exact payloads',async()=>{
  const requests:Array<{path:string;body:unknown;requestId:string|null}>=[];
  const api=client(async(input,init)=>{
    const headers=new Headers(init?.headers);
    requests.push({
      path:new URL(input instanceof Request?input.url:input.toString()).pathname,
      body:JSON.parse(String(init?.body)),
      requestId:headers.get('x-research-manager-request-id'),
    });
    return ok({plan_id:planId,status:'planned'});
  });

  await api.planInsightPair({firstId:41,secondId:42});
  await api.planInsightUnpair({postId:41});

  assert.deepEqual(requests.map(({path,body})=>({path,body})),[
    {path:'/wp-json/research-manager/v1/insights/pairing/plan',body:{operation:'insight-pair',payload:{first_id:41,second_id:42}}},
    {path:'/wp-json/research-manager/v1/insights/pairing/plan',body:{operation:'insight-unpair',payload:{post_id:41}}},
  ]);
  assert.match(requests[0]?.requestId??'',/^mcp-insight-pair-plan-/);
  assert.match(requests[1]?.requestId??'',/^mcp-insight-unpair-plan-/);
});

test('Insight pairing rejects generic lifecycle IDs before transport',()=>{
  let calls=0;
  const api=client(async()=>{calls+=1;return ok({});});
  assert.throws(()=>api.applyInsightPairing({planId:'erm-remote-plan-0123456789abcdefabcd-1234abcd',confirm:true}),/pairing plan ID has an invalid format/);
  assert.throws(()=>api.verifyInsightPairing({operationId:'erm-operation-0123456789abcdef0123456789abcdef',rendered:true}),/pairing operation ID has an invalid format/);
  assert.equal(calls,0);
});

test('Insight pairing Apply preflights the dedicated plan domain and refuses foreign operations',async()=>{
  let calls=0;
  const api=client(async()=>{calls+=1;return ok({plan_id:planId,operation:'insight-update'});});
  await assert.rejects(()=>api.applyInsightPairing({planId,confirm:true}),/does not reference an allowed Insight pairing operation/);
  assert.equal(calls,1);
});

test('Insight pairing Apply uses exact stored plan then confirm-only body',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{
    const path=new URL(input instanceof Request?input.url:input.toString()).pathname;
    requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});
    if(path.endsWith(`/plans/${planId}`)) return ok({plan_id:planId,operation:'insight-pair',status:'planned'});
    return ok({operation_id:operationId,operation:'insight-pair',status:'applied'});
  });

  await api.applyInsightPairing({planId,confirm:true});

  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/insights/pairing/plans/${planId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/insights/pairing/plans/${planId}/apply`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('Insight pairing Verify and Rollback preflight operation domain and preserve exact bodies',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{
    const path=new URL(input instanceof Request?input.url:input.toString()).pathname;
    requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});
    if(init?.method==='GET') return ok({operation_id:operationId,operation:'insight-unpair',status:'applied'});
    return ok({operation_id:operationId,operation:'insight-unpair'});
  });

  await api.verifyInsightPairing({operationId,rendered:false});
  await api.rollbackInsightPairing({operationId,confirm:true});

  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/insights/pairing/operations/${operationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/insights/pairing/operations/${operationId}/verify`,method:'POST',body:'{"rendered":false}'},
    {path:`/wp-json/research-manager/v1/insights/pairing/operations/${operationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/insights/pairing/operations/${operationId}/rollback`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('Insight pairing writes fail closed without the write credential',async()=>{
  let calls=0;
  const api=client(async()=>{calls+=1;return ok({});},null);
  await assert.rejects(()=>api.planInsightPair({firstId:41,secondId:42}),/write credential is not configured/);
  assert.equal(calls,0);
});
