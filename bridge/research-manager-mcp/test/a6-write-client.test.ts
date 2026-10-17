import assert from 'node:assert/strict';
import test from 'node:test';
import { ResearchManagerA6WriteClient } from '../src/a6-write-client.js';

const writeToken='ermt1_navigation-write-token';
const planId='erm-nav-plan-0123456789abcdef01-1234abcd';
const operationId='erm-nav-op-0123456789abcdef0123456789abcdef';

function client(fetchImpl:typeof fetch,token:string|null=writeToken){return new ResearchManagerA6WriteClient({managerBaseUrl:new URL('https://example.test'),managerWriteToken:token,managerTimeoutMs:2_000},fetchImpl);}
function ok(data:unknown){return new Response(JSON.stringify({ok:true,data}),{status:200,headers:{'Content-Type':'application/json'}});}

test('Navigation Plan sends only registered Theme location and targets',async()=>{
  let body:unknown;
  const api=client(async(input,init)=>{assert.equal(new URL(input instanceof Request?input.url:input.toString()).pathname,'/wp-json/research-manager/v1/navigation/plan');body=JSON.parse(String(init?.body));return ok({plan_id:planId,operation:'navigation-update'});});
  await api.plan({location:'primary',items:['about','research','publications'],intent:'Reorder primary research navigation'});
  assert.deepEqual(body,{location:'primary',items:['about','research','publications'],intent:'Reorder primary research navigation'});
});

test('Navigation Plan rejects arbitrary targets and duplicates before transport',()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({});});
  assert.throws(()=>api.plan({location:'primary',items:['about','https://example.test'] as never}),/registered Research Theme pages/);
  assert.throws(()=>api.plan({location:'primary',items:['about','about']}),/must be unique/);
  assert.throws(()=>api.plan({location:'sidebar' as never,items:['about']}),/registered Theme contract/);
  assert.equal(calls,0);
});

test('Navigation Apply preflights exact navigation-update plan domain',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{const path=new URL(input instanceof Request?input.url:input.toString()).pathname;requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});if(init?.method==='GET')return ok({plan_id:planId,operation:'navigation-update',status:'planned'});return ok({operation_id:operationId,operation:'navigation-update'});});
  await api.apply({planId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/navigation/plans/${planId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/navigation/plans/${planId}/apply`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('Navigation Verify requires rendered true and preflights operation domain',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{const path=new URL(input instanceof Request?input.url:input.toString()).pathname;requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});if(init?.method==='GET')return ok({operation_id:operationId,operation:'navigation-update',status:'applied'});return ok({operation_id:operationId,operation:'navigation-update',status:'verified'});});
  await api.verify({operationId,rendered:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/navigation/operations/${operationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/navigation/operations/${operationId}/verify`,method:'POST',body:'{"rendered":true}'},
  ]);
});

test('Navigation Rollback preflights exact operation and confirms restoration',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{const path=new URL(input instanceof Request?input.url:input.toString()).pathname;requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});if(init?.method==='GET')return ok({operation_id:operationId,operation:'navigation-update',status:'verified'});return ok({operation_id:operationId,operation:'navigation-update',status:'rolled-back'});});
  await api.rollback({operationId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/navigation/operations/${operationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/navigation/operations/${operationId}/rollback`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('Navigation lifecycle rejects foreign IDs and operation domains before mutation',async()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({operation:'media-ingest'});});
  assert.throws(()=>api.apply({planId:'erm-media-plan-0123456789abcdef0123-1234abcd',confirm:true}),/Navigation plan ID has an invalid format/);
  assert.throws(()=>api.rollback({operationId:'erm-media-op-0123456789abcdef0123456789abcdef',confirm:true}),/Navigation operation ID has an invalid format/);
  await assert.rejects(()=>api.apply({planId,confirm:true}),/does not reference navigation-update/);
  assert.equal(calls,1);
});

test('Navigation writes fail closed without the tenant write credential',async()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({});},null);
  await assert.rejects(()=>api.plan({location:'footer',items:['privacy-policy','legal-notice']}),/write credential is not configured/);
  assert.equal(calls,0);
});
