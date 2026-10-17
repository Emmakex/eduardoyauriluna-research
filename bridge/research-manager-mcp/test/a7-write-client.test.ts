import assert from 'node:assert/strict';
import test from 'node:test';
import { ResearchManagerA7WriteClient } from '../src/a7-write-client.js';

const token='ermt1_design-write-token';
const presetPlanId='erm-design-plan-0123456789abcdef01-1234abcd';
const presetOperationId='erm-design-op-0123456789abcdef0123456789abcdef';
const variantPlanId='erm-design-variant-plan-0123456789abcdef01-1234abcd';
const variantOperationId='erm-design-variant-op-0123456789abcdef0123456789abcdef';
function client(fetchImpl:typeof fetch,writeToken:string|null=token){return new ResearchManagerA7WriteClient({managerBaseUrl:new URL('https://example.test'),managerWriteToken:writeToken,managerTimeoutMs:2_000},fetchImpl);}
function ok(data:unknown){return new Response(JSON.stringify({ok:true,data}),{status:200,headers:{'Content-Type':'application/json'}});}

test('Design preset Plan sends only registered palette typography density changes',async()=>{
  let body:unknown;
  const api=client(async(input,init)=>{assert.equal(new URL(input instanceof Request?input.url:input.toString()).pathname,'/wp-json/research-manager/v1/design/plan');body=JSON.parse(String(init?.body));return ok({plan_id:presetPlanId,operation:'design-preset-update'});});
  await api.planPreset({changes:{palette:'ocean-lab',typography:'academic-mixed',density:'airy'},intent:'Bounded visual refresh'});
  assert.deepEqual(body,{changes:{palette:'ocean-lab',typography:'academic-mixed',density:'airy'},intent:'Bounded visual refresh'});
});

test('Design preset Plan rejects arbitrary values before transport',()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({});});
  assert.throws(()=>api.planPreset({changes:{palette:'#fff' as never}}),/palette is outside/);
  assert.throws(()=>api.planPreset({changes:{typography:'https://fonts.example/font.woff2' as never}}),/typography is outside/);
  assert.throws(()=>api.planPreset({changes:{}}),/at least one bounded change/);
  assert.equal(calls,0);
});

test('Design preset lifecycle preflights exact domain and bodies',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{const path=new URL(input instanceof Request?input.url:input.toString()).pathname;requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});if(init?.method==='GET')return ok(path.includes('/plans/')?{plan_id:presetPlanId,operation:'design-preset-update'}:{operation_id:presetOperationId,operation:'design-preset-update'});return ok({operation:'design-preset-update'});});
  await api.applyPreset({planId:presetPlanId,confirm:true});
  await api.verifyPreset({operationId:presetOperationId});
  await api.rollbackPreset({operationId:presetOperationId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/design/plans/${presetPlanId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/design/plans/${presetPlanId}/apply`,method:'POST',body:'{"confirm":true}'},
    {path:`/wp-json/research-manager/v1/design/operations/${presetOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/design/operations/${presetOperationId}/verify`,method:'POST',body:'{}'},
    {path:`/wp-json/research-manager/v1/design/operations/${presetOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/design/operations/${presetOperationId}/rollback`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('Design variant Plan sends only registered Theme component dimensions',async()=>{
  let body:unknown;
  const api=client(async(input,init)=>{assert.equal(new URL(input instanceof Request?input.url:input.toString()).pathname,'/wp-json/research-manager/v1/design/variants/plan');body=JSON.parse(String(init?.body));return ok({plan_id:variantPlanId,operation:'design-variant-update'});});
  await api.planVariant({changes:{hero:'editorial',sections:'bands',cards:'lifted',collections:'compact-grid'}});
  assert.deepEqual(body,{changes:{hero:'editorial',sections:'bands',cards:'lifted',collections:'compact-grid'}});
});

test('Design variant Plan rejects arbitrary classes CSS and unknown variants before transport',()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({});});
  assert.throws(()=>api.planVariant({changes:{hero:'custom-class' as never}}),/hero variant is outside/);
  assert.throws(()=>api.planVariant({changes:{cards:'transform:scale(2)' as never}}),/cards variant is outside/);
  assert.throws(()=>api.planVariant({changes:{}}),/at least one bounded change/);
  assert.equal(calls,0);
});

test('Design variant lifecycle preflights exact domain and bodies',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{const path=new URL(input instanceof Request?input.url:input.toString()).pathname;requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});if(init?.method==='GET')return ok(path.includes('/plans/')?{plan_id:variantPlanId,operation:'design-variant-update'}:{operation_id:variantOperationId,operation:'design-variant-update'});return ok({operation:'design-variant-update'});});
  await api.applyVariant({planId:variantPlanId,confirm:true});
  await api.verifyVariant({operationId:variantOperationId});
  await api.rollbackVariant({operationId:variantOperationId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/design/variants/plans/${variantPlanId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/design/variants/plans/${variantPlanId}/apply`,method:'POST',body:'{"confirm":true}'},
    {path:`/wp-json/research-manager/v1/design/variants/operations/${variantOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/design/variants/operations/${variantOperationId}/verify`,method:'POST',body:'{}'},
    {path:`/wp-json/research-manager/v1/design/variants/operations/${variantOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/design/variants/operations/${variantOperationId}/rollback`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('Design domains cannot be crossed',async()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({operation:'design-variant-update'});});
  assert.throws(()=>api.applyPreset({planId:variantPlanId,confirm:true}),/preset plan ID/);
  assert.throws(()=>api.applyVariant({planId:presetPlanId,confirm:true}),/variant plan ID/);
  await assert.rejects(()=>api.applyPreset({planId:presetPlanId,confirm:true}),/does not reference design-preset-update/);
  assert.equal(calls,1);
});

test('Design writes fail closed without write credential',async()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({});},null);
  await assert.rejects(()=>api.planPreset({changes:{density:'compact'}}),/write credential is not configured/);
  assert.equal(calls,0);
});
