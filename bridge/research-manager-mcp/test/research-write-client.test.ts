import assert from 'node:assert/strict';
import test from 'node:test';
import type { SiteResearchObjectUpdatePlanRequest } from '../src/adapters/site-control.js';
import { ResearchManagerWriteClient } from '../src/write-manager-client.js';

const writeToken='ermt1_research-write-token';
const linePlanId='erm-line-plan-0123456789abcdefabcd-1234abcd';
const lineOperationId='erm-line-op-0123456789abcdef0123456789abcdef';
const objectPlanId='erm-object-plan-0123456789abcdefab-1234abcd';
const objectOperationId='erm-object-op-0123456789abcdef0123456789abcdef';

function client(fetchImpl:typeof fetch,token:string|null=writeToken){
  return new ResearchManagerWriteClient({managerBaseUrl:new URL('https://example.test'),managerWriteToken:token,managerTimeoutMs:2_000},fetchImpl);
}
function ok(data:unknown){return new Response(JSON.stringify({ok:true,data}),{status:200,headers:{'Content-Type':'application/json'}});}

test('Research Line create/update plans use exact dedicated routes, payloads and evidence context',async()=>{
  const requests:Array<{path:string;body:unknown}>=[];
  const api=client(async(input,init)=>{
    requests.push({path:new URL(input instanceof Request?input.url:input.toString()).pathname,body:JSON.parse(String(init?.body))});
    return ok({plan_id:linePlanId,operation:'line-create'});
  });
  await api.planResearchLineCreate({data:{title:'Causal AI',language:'en',topics:['causal inference']},evidenceConfirmed:true,evidenceReference:'ORCID record'});
  await api.planResearchLineUpdate({postId:41,changes:{central_question:'How can causal systems remain auditable?'},evidenceConfirmed:false});
  assert.deepEqual(requests,[
    {path:'/wp-json/research-manager/v1/research-lines/plan',body:{operation:'line-create',payload:{data:{title:'Causal AI',language:'en',topics:['causal inference']},evidence_confirmed:true,evidence_reference:'ORCID record'}}},
    {path:'/wp-json/research-manager/v1/research-lines/plan',body:{operation:'line-update',payload:{post_id:41,changes:{central_question:'How can causal systems remain auditable?'},evidence_confirmed:false,evidence_reference:''}}},
  ]);
});

test('Research Object create/update plans use the common object gateway and preserve kind',async()=>{
  const requests:Array<{path:string;body:unknown}>=[];
  const api=client(async(input,init)=>{
    requests.push({path:new URL(input instanceof Request?input.url:input.toString()).pathname,body:JSON.parse(String(init?.body))});
    return ok({plan_id:objectPlanId,operation:'object-create'});
  });
  await api.planResearchObjectCreate({kind:'dataset',data:{title:'Replication dataset',language:'en',formats:['csv'],line_ids:[7]},evidenceConfirmed:true,evidenceReference:'Repository deposit'});
  await api.planResearchObjectUpdate({kind:'project',postId:52,changes:{role:'Principal investigator',line_ids:[7,9]},evidenceConfirmed:true,evidenceReference:'Project agreement'});
  assert.deepEqual(requests,[
    {path:'/wp-json/research-manager/v1/research-objects/plan',body:{operation:'object-create',payload:{kind:'dataset',data:{title:'Replication dataset',language:'en',formats:['csv'],line_ids:[7]},evidence_confirmed:true,evidence_reference:'Repository deposit'}}},
    {path:'/wp-json/research-manager/v1/research-objects/plan',body:{operation:'object-update',payload:{kind:'project',post_id:52,changes:{role:'Principal investigator',line_ids:[7,9]},evidence_confirmed:true,evidence_reference:'Project agreement'}}},
  ]);
});

test('Research Object update rejects status and slug outside the Manager update contract before transport',async()=>{
  let calls=0;
  const api=client(async()=>{calls+=1;return ok({});});
  const illegalStatus={kind:'dataset',postId:52,changes:{status:'publish'}} as unknown as SiteResearchObjectUpdatePlanRequest;
  const illegalSlug={kind:'project',postId:52,changes:{slug:'new-project-slug'}} as unknown as SiteResearchObjectUpdatePlanRequest;
  assert.throws(()=>api.planResearchObjectUpdate(illegalStatus),/field is not allowed: status/);
  assert.throws(()=>api.planResearchObjectUpdate(illegalSlug),/field is not allowed: slug/);
  assert.equal(calls,0);
});

test('Research Line and Object reject generic lifecycle IDs before transport',()=>{
  let calls=0;
  const api=client(async()=>{calls+=1;return ok({});});
  assert.throws(()=>api.applyResearchLine({planId:'erm-remote-plan-0123456789abcdefabcd-1234abcd',confirm:true}),/Research Line plan ID has an invalid format/);
  assert.throws(()=>api.verifyResearchLine({operationId:'erm-operation-0123456789abcdef0123456789abcdef',rendered:true}),/Research Line operation ID has an invalid format/);
  assert.throws(()=>api.applyResearchObject({planId:'erm-remote-plan-0123456789abcdefabcd-1234abcd',confirm:true}),/Research Object plan ID has an invalid format/);
  assert.throws(()=>api.verifyResearchObject({operationId:'erm-operation-0123456789abcdef0123456789abcdef',rendered:true}),/Research Object operation ID has an invalid format/);
  assert.equal(calls,0);
});

test('Research Line Apply preflights domain then sends confirm-only body',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{
    const path=new URL(input instanceof Request?input.url:input.toString()).pathname;
    requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});
    if(init?.method==='GET') return ok({plan_id:linePlanId,operation:'line-update',status:'planned'});
    return ok({operation_id:lineOperationId,operation:'line-update',status:'applied'});
  });
  await api.applyResearchLine({planId:linePlanId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/research-lines/plans/${linePlanId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/research-lines/plans/${linePlanId}/apply`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('Research Object Apply preflights domain and refuses a foreign operation',async()=>{
  let calls=0;
  const api=client(async()=>{calls+=1;return ok({plan_id:objectPlanId,operation:'line-update'});});
  await assert.rejects(()=>api.applyResearchObject({planId:objectPlanId,confirm:true}),/does not reference an allowed Research Object operation/);
  assert.equal(calls,1);
});

test('Research Object Apply uses exact stored plan then confirm-only body',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{
    const path=new URL(input instanceof Request?input.url:input.toString()).pathname;
    requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});
    if(init?.method==='GET') return ok({plan_id:objectPlanId,operation:'object-create',status:'planned'});
    return ok({operation_id:objectOperationId,operation:'object-create',status:'applied'});
  });
  await api.applyResearchObject({planId:objectPlanId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/research-objects/plans/${objectPlanId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/research-objects/plans/${objectPlanId}/apply`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('Research Line Verify/Rollback preserve rendered and confirmation bodies after operation preflight',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{
    const path=new URL(input instanceof Request?input.url:input.toString()).pathname;
    requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});
    if(init?.method==='GET') return ok({operation_id:lineOperationId,operation:'line-create',status:'applied'});
    return ok({operation_id:lineOperationId,operation:'line-create'});
  });
  await api.verifyResearchLine({operationId:lineOperationId,rendered:false});
  await api.rollbackResearchLine({operationId:lineOperationId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/research-lines/operations/${lineOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/research-lines/operations/${lineOperationId}/verify`,method:'POST',body:'{"rendered":false}'},
    {path:`/wp-json/research-manager/v1/research-lines/operations/${lineOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/research-lines/operations/${lineOperationId}/rollback`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('Research Object Verify/Rollback preserve rendered and confirmation bodies after operation preflight',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{
    const path=new URL(input instanceof Request?input.url:input.toString()).pathname;
    requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});
    if(init?.method==='GET') return ok({operation_id:objectOperationId,operation:'object-update',status:'applied'});
    return ok({operation_id:objectOperationId,operation:'object-update'});
  });
  await api.verifyResearchObject({operationId:objectOperationId,rendered:true});
  await api.rollbackResearchObject({operationId:objectOperationId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/research-objects/operations/${objectOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/research-objects/operations/${objectOperationId}/verify`,method:'POST',body:'{"rendered":true}'},
    {path:`/wp-json/research-manager/v1/research-objects/operations/${objectOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/research-objects/operations/${objectOperationId}/rollback`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('Research writes fail closed without a write credential',async()=>{
  let calls=0;
  const api=client(async()=>{calls+=1;return ok({});},null);
  await assert.rejects(()=>api.planResearchLineCreate({data:{title:'Line'}}),/write credential is not configured/);
  await assert.rejects(()=>api.planResearchObjectCreate({kind:'output',data:{title:'Paper'}}),/write credential is not configured/);
  assert.equal(calls,0);
});
