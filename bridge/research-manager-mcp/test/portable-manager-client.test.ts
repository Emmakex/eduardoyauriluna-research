import assert from 'node:assert/strict';
import test from 'node:test';
import { PortableManagerClient } from '../src/portable-manager-client.js';
import { ResearchManagerError } from '../src/manager-client.js';

const token='ermt1_portable-test-not-a-secret';

function clientWith(fetchImpl:typeof fetch):PortableManagerClient{
  return new PortableManagerClient({
    managerBaseUrl:new URL('https://example.test'),
    managerReadToken:token,
    managerTimeoutMs:2_000,
  },fetchImpl);
}

test('Site Baseline Audit uses only the dedicated diagnostics route with signed headers',async()=>{
  let seenUrl='';
  let seenInit:RequestInit|undefined;
  const client=clientWith(async(input,init)=>{
    seenUrl=input instanceof Request?input.url:input.toString();
    seenInit=init;
    return new Response(JSON.stringify({ok:true,data:{schema_version:1,profile:{id:'generic-wordpress'}}}),{status:200,headers:{'Content-Type':'application/json'}});
  });

  const result=await client.baseline();
  assert.deepEqual(result,{schema_version:1,profile:{id:'generic-wordpress'}});
  const url=new URL(seenUrl);
  assert.equal(url.origin,'https://example.test');
  assert.equal(url.pathname,'/wp-json/research-manager/v1/audit/baseline');
  assert.equal(url.search,'');
  assert.equal(seenInit?.method,'GET');
  const headers=new Headers(seenInit?.headers);
  assert.equal(headers.get('Authorization'),`Bearer ${token}`);
  assert.match(headers.get('X-Research-Manager-Request-Id')??'',/^mcp-site-baseline-/);
  assert.match(headers.get('X-Research-Manager-Nonce')??'',/^[a-f0-9]{32}$/);
  assert.match(headers.get('X-Research-Manager-Timestamp')??'',/^\d{10,}$/);
});

test('Site Baseline Audit diagnostics failure stays typed and never leaks the token',async()=>{
  const client=clientWith(async()=>new Response(JSON.stringify({ok:false,code:'scope_denied'}),{status:403,headers:{'Content-Type':'application/json'}}));
  await assert.rejects(
    ()=>client.baseline(),
    (error:unknown)=>{
      assert.ok(error instanceof ResearchManagerError);
      assert.equal(error.status,403);
      assert.equal(error.code,'scope_denied');
      assert.equal(error.message.includes(token),false);
      assert.match(error.requestId,/^mcp-site-baseline-/);
      return true;
    },
  );
});
