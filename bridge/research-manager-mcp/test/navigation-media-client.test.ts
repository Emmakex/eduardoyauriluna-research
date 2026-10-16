import assert from 'node:assert/strict';
import test from 'node:test';
import { ResearchManagerClient } from '../src/manager-client.js';

const token='ermt1_navigation-media-test';
function clientWith(fetchImpl:typeof fetch){return new ResearchManagerClient({managerBaseUrl:new URL('https://example.test'),managerReadToken:token,managerTimeoutMs:2000},fetchImpl);}
function ok(data:unknown={}){return new Response(JSON.stringify({ok:true,data}),{status:200,headers:{'Content-Type':'application/json'}});}

test('navigation inspection uses the fixed bounded route',async()=>{
  let seen='';const client=clientWith(async(input)=>{seen=input instanceof Request?input.url:input.toString();return ok({primary:[],footer:[]});});
  await client.navigation();assert.equal(new URL(seen).pathname,'/wp-json/research-manager/v1/navigation');
});

test('media inventory maps only bounded pagination search and MIME filters',async()=>{
  let seen='';const client=clientWith(async(input)=>{seen=input instanceof Request?input.url:input.toString();return ok({items:[]});});
  await client.mediaInventory({page:2,perPage:40,search:'research figure',mimeType:'image'});const url=new URL(seen);
  assert.equal(url.pathname,'/wp-json/research-manager/v1/media');
  assert.equal(url.searchParams.get('page'),'2');assert.equal(url.searchParams.get('per_page'),'40');assert.equal(url.searchParams.get('search'),'research figure');assert.equal(url.searchParams.get('mime_type'),'image');
});

test('media item and diagnostic item use positive numeric routes only',async()=>{
  const seen:string[]=[];const client=clientWith(async(input)=>{seen.push(input instanceof Request?input.url:input.toString());return ok({});});
  await client.mediaItem(23);await client.mediaDiagnosticItem(23);
  assert.equal(new URL(seen[0]!).pathname,'/wp-json/research-manager/v1/media/23');
  assert.equal(new URL(seen[1]!).pathname,'/wp-json/research-manager/v1/media/diagnostics/23');
});

test('media diagnostics uses fixed route and enforces maximum scan limit',async()=>{
  let seen='';const client=clientWith(async(input)=>{seen=input instanceof Request?input.url:input.toString();return ok({});});
  await client.mediaDiagnostics(500);const url=new URL(seen);assert.equal(url.pathname,'/wp-json/research-manager/v1/media/diagnostics');assert.equal(url.searchParams.get('limit'),'500');
});

test('invalid media filters and IDs fail before transport',()=>{
  let calls=0;const client=clientWith(async()=>{calls+=1;return ok({});});
  assert.throws(()=>client.mediaInventory({page:0}),/between 1 and 100000/);
  assert.throws(()=>client.mediaInventory({perPage:101}),/between 1 and 100/);
  assert.throws(()=>client.mediaInventory({mimeType:'../application' as never}),/Unsupported media MIME filter/);
  assert.throws(()=>client.mediaInventory({search:'x'.repeat(129)}),/128 characters or fewer/);
  assert.throws(()=>client.mediaDiagnostics(501),/between 1 and 500/);
  assert.throws(()=>client.mediaItem(0),/positive integer/);
  assert.throws(()=>client.mediaDiagnosticItem(-1),/positive integer/);
  assert.equal(calls,0);
});
