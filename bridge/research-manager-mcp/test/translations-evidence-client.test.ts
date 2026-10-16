import assert from 'node:assert/strict';
import test from 'node:test';
import { ResearchManagerClient } from '../src/manager-client.js';

const token='ermt1_translation-evidence-test';
function clientWith(fetchImpl:typeof fetch){return new ResearchManagerClient({managerBaseUrl:new URL('https://example.test'),managerReadToken:token,managerTimeoutMs:2000},fetchImpl);}

test('translation candidates use fixed route and closed query filters',async()=>{
  let seen='';const client=clientWith(async(input)=>{seen=input instanceof Request?input.url:input.toString();return new Response(JSON.stringify({ok:true,data:{items:[]}}),{status:200,headers:{'Content-Type':'application/json'}});});
  await client.translations('research_software','es');const url=new URL(seen);assert.equal(url.pathname,'/wp-json/research-manager/v1/research-translations');assert.equal(url.searchParams.get('post_type'),'research_software');assert.equal(url.searchParams.get('language'),'es');
});

test('translation inspection uses positive numeric route only',async()=>{
  let seen='';const client=clientWith(async(input)=>{seen=input instanceof Request?input.url:input.toString();return new Response(JSON.stringify({ok:true,data:{post_id:14}}),{status:200,headers:{'Content-Type':'application/json'}});});
  await client.translation(14);assert.equal(new URL(seen).pathname,'/wp-json/research-manager/v1/research-translations/14');
});

test('translation invalid types/languages/ids fail before transport',()=>{
  let calls=0;const client=clientWith(async()=>{calls+=1;return new Response(JSON.stringify({ok:true,data:{}}),{status:200});});
  assert.throws(()=>client.translations('evil' as never),/Unsupported Research translation type/);assert.throws(()=>client.translations('research_line','../es'),/language/);assert.throws(()=>client.translation(0),/positive integer/);assert.equal(calls,0);
});

test('evidence inventory and identity use fixed routes',async()=>{
  const seen:string[]=[];const client=clientWith(async(input)=>{seen.push(input instanceof Request?input.url:input.toString());return new Response(JSON.stringify({ok:true,data:{}}),{status:200,headers:{'Content-Type':'application/json'}});});
  await client.evidenceInventory();await client.evidenceIdentity();assert.equal(new URL(seen[0]!).pathname,'/wp-json/research-manager/v1/research-evidence');assert.equal(new URL(seen[1]!).pathname,'/wp-json/research-manager/v1/research-evidence/identity');
});

test('evidence group and record routes are closed and bounded',async()=>{
  const seen:string[]=[];const client=clientWith(async(input)=>{seen.push(input instanceof Request?input.url:input.toString());return new Response(JSON.stringify({ok:true,data:{}}),{status:200,headers:{'Content-Type':'application/json'}});});
  await client.evidenceRecords('education');await client.evidenceRecord('education','degree-1');assert.equal(new URL(seen[0]!).pathname,'/wp-json/research-manager/v1/research-evidence/education');assert.equal(new URL(seen[1]!).pathname,'/wp-json/research-manager/v1/research-evidence/education/degree-1');
});

test('evidence invalid group and route-like record IDs fail before transport',()=>{
  let calls=0;const client=clientWith(async()=>{calls+=1;return new Response(JSON.stringify({ok:true,data:{}}),{status:200});});
  assert.throws(()=>client.evidenceRecords('evil' as never),/Unsupported Research evidence group/);assert.throws(()=>client.evidenceRecord('education','../wp/v2/users'),/invalid format/);assert.equal(calls,0);
});
