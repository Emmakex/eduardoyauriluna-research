import assert from 'node:assert/strict';
import test from 'node:test';
import { ResearchManagerA3WriteClient } from '../src/a3-write-client.js';

const writeToken='ermt1_a3-write-token';
const translationPlanId='erm-translation-plan-0123456789abcdefab-1234abcd';
const translationOperationId='erm-translation-op-0123456789abcdef0123456789abcdef';
const evidencePlanId='erm-evidence-plan-0123456789abcdefab-1234abcd';
const evidenceOperationId='erm-evidence-op-0123456789abcdef0123456789abcdef';
const identityPlanId='erm-identity-plan-0123456789abcdef01234567';
const identityOperationId='erm-identity-op-0123456789abcdef0123456789abcdef';

function client(fetchImpl:typeof fetch,token:string|null=writeToken){return new ResearchManagerA3WriteClient({managerBaseUrl:new URL('https://example.test'),managerWriteToken:token,managerTimeoutMs:2_000},fetchImpl);}
function ok(data:unknown){return new Response(JSON.stringify({ok:true,data}),{status:200,headers:{'Content-Type':'application/json'}});}

test('structured translation pair/unpair plans use exact gateway payloads with evidence context',async()=>{
  const requests:Array<{path:string;body:unknown}>=[];
  const api=client(async(input,init)=>{requests.push({path:new URL(input instanceof Request?input.url:input.toString()).pathname,body:JSON.parse(String(init?.body))});return ok({plan_id:translationPlanId});});
  await api.planTranslationPair({firstId:11,secondId:12,evidenceConfirmed:true,evidenceReference:'Published bilingual record'});
  await api.planTranslationUnpair({postId:11,evidenceConfirmed:false});
  assert.deepEqual(requests,[
    {path:'/wp-json/research-manager/v1/research-translations/plan',body:{operation:'translation-pair',payload:{first_id:11,second_id:12,evidence_confirmed:true,evidence_reference:'Published bilingual record'}}},
    {path:'/wp-json/research-manager/v1/research-translations/plan',body:{operation:'translation-unpair',payload:{post_id:11,evidence_confirmed:false,evidence_reference:''}}},
  ]);
});

test('structured translation Apply preflights its domain and sends confirm only',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{const path=new URL(input instanceof Request?input.url:input.toString()).pathname;requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});if(init?.method==='GET')return ok({operation:'translation-pair',plan_id:translationPlanId});return ok({operation:'translation-pair',operation_id:translationOperationId});});
  await api.applyTranslation({planId:translationPlanId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/research-translations/plans/${translationPlanId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/research-translations/plans/${translationPlanId}/apply`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('evidence create/update/delete plans stay inside ordinary evidence operations',async()=>{
  const requests:Array<{path:string;body:unknown}>=[];
  const api=client(async(input,init)=>{requests.push({path:new URL(input instanceof Request?input.url:input.toString()).pathname,body:JSON.parse(String(init?.body))});return ok({plan_id:evidencePlanId});});
  await api.planEvidenceCreate({group:'affiliations',data:{status:'verified',title:'Research affiliation',organization:'Example Lab',translations:{es:{title:'Afiliación de investigación'}}},evidenceConfirmed:true,evidenceReference:'Institution directory'});
  await api.planEvidenceUpdate({group:'affiliations',recordId:'evidence-abc123',data:{summary:'Updated verified affiliation'},evidenceConfirmed:true,evidenceReference:'Institution directory'});
  await api.planEvidenceDelete({group:'affiliations',recordId:'evidence-abc123',evidenceConfirmed:true,evidenceReference:'Correction request'});
  assert.deepEqual(requests.map((request)=>request.body),[
    {operation:'evidence-create',payload:{group:'affiliations',data:{status:'verified',title:'Research affiliation',organization:'Example Lab',translations:{es:{title:'Afiliación de investigación'}}},evidence_confirmed:true,evidence_reference:'Institution directory'}},
    {operation:'evidence-update',payload:{group:'affiliations',record_id:'evidence-abc123',data:{summary:'Updated verified affiliation'},evidence_confirmed:true,evidence_reference:'Institution directory'}},
    {operation:'evidence-delete',payload:{group:'affiliations',record_id:'evidence-abc123',evidence_confirmed:true,evidence_reference:'Correction request'}},
  ]);
});

test('ordinary evidence lifecycle refuses identity-update during preflight',async()=>{
  let calls=0;
  const api=client(async()=>{calls+=1;return ok({operation:'identity-update',plan_id:evidencePlanId});});
  await assert.rejects(()=>api.applyEvidence({planId:evidencePlanId,confirm:true}),/does not reference an allowed operation/);
  assert.equal(calls,1);
});

test('ordinary evidence Verify and Rollback refuse identity operations during preflight',async()=>{
  const api=client(async()=>ok({operation:'identity-update',operation_id:evidenceOperationId}));
  await assert.rejects(()=>api.verifyEvidence({operationId:evidenceOperationId}),/does not reference an allowed operation/);
  await assert.rejects(()=>api.rollbackEvidence({operationId:evidenceOperationId,confirm:true}),/does not reference an allowed operation/);
});

test('Identity Profile plan uses only canonical bounded changes and evidence context',async()=>{
  const requests:Array<{path:string;body:unknown}>=[];
  const api=client(async(input,init)=>{requests.push({path:new URL(input instanceof Request?input.url:input.toString()).pathname,body:JSON.parse(String(init?.body))});return ok({plan_id:identityPlanId,operation:'identity-profile-update'});});
  await api.planIdentityUpdate({changes:{headline:'Research systems and causal AI',headline_es:'Sistemas de investigación e IA causal'},evidenceConfirmed:true,evidenceReference:'Verified researcher profile'});
  assert.deepEqual(requests,[{path:'/wp-json/research-manager/v1/identity-profile/plan',body:{changes:{headline:'Research systems and causal AI',headline_es:'Sistemas de investigación e IA causal'},evidence_confirmed:true,evidence_reference:'Verified researcher profile'}}]);
});

test('Identity Profile Apply/Verify/Rollback preflight exact identity operation domain',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{const path=new URL(input instanceof Request?input.url:input.toString()).pathname;requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});if(init?.method==='GET')return ok({operation:'identity-profile-update',plan_id:identityPlanId,operation_id:identityOperationId});return ok({operation:'identity-profile-update',operation_id:identityOperationId});});
  await api.applyIdentity({planId:identityPlanId,confirm:true});
  await api.verifyIdentity({operationId:identityOperationId});
  await api.rollbackIdentity({operationId:identityOperationId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/identity-profile/plans/${identityPlanId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/identity-profile/plans/${identityPlanId}/apply`,method:'POST',body:'{"confirm":true}'},
    {path:`/wp-json/research-manager/v1/identity-profile/operations/${identityOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/identity-profile/operations/${identityOperationId}/verify`,method:'POST',body:'{}'},
    {path:`/wp-json/research-manager/v1/identity-profile/operations/${identityOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/identity-profile/operations/${identityOperationId}/rollback`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('A3 lifecycle IDs are domain-specific and rejected before transport',()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({});});
  assert.throws(()=>api.applyTranslation({planId:evidencePlanId,confirm:true}),/translation plan ID has an invalid format/);
  assert.throws(()=>api.applyEvidence({planId:translationPlanId,confirm:true}),/evidence plan ID has an invalid format/);
  assert.throws(()=>api.applyIdentity({planId:evidencePlanId,confirm:true}),/Identity Profile plan ID has an invalid format/);
  assert.equal(calls,0);
});

test('evidence confirmation requires a reference before transport',()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({});});
  assert.throws(()=>api.planEvidenceCreate({group:'profile',data:{title:'Profile'},evidenceConfirmed:true}),/requires a non-empty evidence reference/);
  assert.throws(()=>api.planIdentityUpdate({changes:{name:'Researcher'},evidenceConfirmed:true}),/requires a non-empty evidence reference/);
  assert.equal(calls,0);
});

test('A3 writes fail closed without the write credential',async()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({});},null);
  await assert.rejects(()=>api.planTranslationPair({firstId:1,secondId:2}),/write credential is not configured/);
  await assert.rejects(()=>api.planEvidenceCreate({group:'profile',data:{title:'Profile'}}),/write credential is not configured/);
  await assert.rejects(()=>api.planIdentityUpdate({changes:{name:'Researcher'}}),/write credential is not configured/);
  assert.equal(calls,0);
});
