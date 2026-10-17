import assert from 'node:assert/strict';
import test from 'node:test';
import { ResearchManagerError } from '../src/manager-client.js';
import { ResearchManagerA4WriteClient } from '../src/a4-write-client.js';

const writeToken='ermt1_seo-write-token';
const seoPlanId='erm-seo-plan-0123456789abcdef01234567';
const seoOperationId='erm-seo-op-0123456789abcdef0123456789abcdef';
const indexPlanId='erm-indexability-plan-0123456789abcdefab-1234abcd';
const indexOperationId='erm-indexability-op-0123456789abcdef0123456789abcdef';

function client(fetchImpl:typeof fetch,token:string|null=writeToken){return new ResearchManagerA4WriteClient({managerBaseUrl:new URL('https://example.test'),managerWriteToken:token,managerTimeoutMs:2_000},fetchImpl);}
function ok(data:unknown){return new Response(JSON.stringify({ok:true,data}),{status:200,headers:{'Content-Type':'application/json'}});}

test('SEO/GEO remediation planning sends only bounded diagnostic check ID and optional intent',async()=>{
  const requests:Array<{path:string;body:unknown}>=[];
  const api=client(async(input,init)=>{requests.push({path:new URL(input instanceof Request?input.url:input.toString()).pathname,body:JSON.parse(String(init?.body))});return ok({plan_id:seoPlanId,operation:'seo-remediate'});});
  await api.plan({checkId:'front-page',intent:'Restore canonical front page contract'});
  await api.plan({checkId:'search-indexability'});
  assert.deepEqual(requests,[
    {path:'/wp-json/research-manager/v1/seo-geo/remediation/plan',body:{check_id:'front-page',intent:'Restore canonical front page contract'}},
    {path:'/wp-json/research-manager/v1/seo-geo/remediation/plan',body:{check_id:'search-indexability'}},
  ]);
});

test('invalid remediation check IDs are rejected before transport',()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({});});
  assert.throws(()=>api.plan({checkId:'../../wp-options'}),/bounded lowercase diagnostic ID/);
  assert.equal(calls,0);
});

test('Manager rejection of a fabricated or non-current diagnostic candidate is propagated',async()=>{
  const api=client(async()=>new Response(JSON.stringify({ok:false,code:'research_manager_remediation_check_missing',message:'Diagnostic check is not currently exposed.'}),{status:400,headers:{'Content-Type':'application/json'}}));
  await assert.rejects(()=>api.plan({checkId:'invented-remediation'}),(error:unknown)=>error instanceof ResearchManagerError&&error.code==='research_manager_remediation_check_missing'&&error.status===400);
});

test('generic SEO remediation Apply preflights exact plan domain and sends confirm only',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{const path=new URL(input instanceof Request?input.url:input.toString()).pathname;requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});if(init?.method==='GET')return ok({plan_id:seoPlanId,operation:'seo-remediate',status:'planned'});return ok({operation_id:seoOperationId,operation:'seo-remediate',status:'applied'});});
  await api.apply({planId:seoPlanId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/seo-geo/plans/${seoPlanId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/seo-geo/plans/${seoPlanId}/apply`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('search-indexability Apply uses the same bounded dispatcher with its specialized ID',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{const path=new URL(input instanceof Request?input.url:input.toString()).pathname;requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});if(init?.method==='GET')return ok({plan_id:indexPlanId,operation:'search-indexability',status:'planned'});return ok({operation_id:indexOperationId,operation:'search-indexability',status:'applied'});});
  await api.apply({planId:indexPlanId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/seo-geo/plans/${indexPlanId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/seo-geo/plans/${indexPlanId}/apply`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('SEO/GEO Apply refuses a foreign operation during preflight',async()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({plan_id:seoPlanId,operation:'page-slots-update'});});
  await assert.rejects(()=>api.apply({planId:seoPlanId,confirm:true}),/does not reference an allowed operation/);
  assert.equal(calls,1);
});

test('SEO/GEO Verify and Rollback preflight domain and preserve exact bodies',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{const path=new URL(input instanceof Request?input.url:input.toString()).pathname;requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});if(init?.method==='GET')return ok({operation_id:seoOperationId,operation:'seo-remediate',status:'applied'});return ok({operation_id:seoOperationId,operation:'seo-remediate'});});
  await api.verify({operationId:seoOperationId,rendered:true});
  await api.rollback({operationId:seoOperationId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/seo-geo/operations/${seoOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/seo-geo/operations/${seoOperationId}/verify`,method:'POST',body:'{"rendered":true}'},
    {path:`/wp-json/research-manager/v1/seo-geo/operations/${seoOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/seo-geo/operations/${seoOperationId}/rollback`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('SEO/GEO lifecycle IDs accept only remediation/indexability domains before transport',()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({});});
  assert.throws(()=>api.apply({planId:'erm-remote-plan-0123456789abcdefabcd-1234abcd',confirm:true}),/plan ID has an invalid format/);
  assert.throws(()=>api.verify({operationId:'erm-operation-0123456789abcdef0123456789abcdef',rendered:true}),/operation ID has an invalid format/);
  assert.equal(calls,0);
});

test('SEO/GEO writes fail closed without a write credential',async()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({});},null);
  await assert.rejects(()=>api.plan({checkId:'front-page'}),/write credential is not configured/);
  assert.equal(calls,0);
});
