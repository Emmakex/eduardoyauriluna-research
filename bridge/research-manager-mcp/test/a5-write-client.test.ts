import assert from 'node:assert/strict';
import test from 'node:test';
import { ResearchManagerA5WriteClient } from '../src/a5-write-client.js';

const writeToken='ermt1_media-write-token';
const ingestPlanId='erm-media-ingest-plan-0123456789abcdef0123-1234abcd';
const ingestOperationId='erm-media-ingest-op-0123456789abcdef0123456789abcdef';
const metadataPlanId='erm-media-plan-0123456789abcdef0123-1234abcd';
const metadataOperationId='erm-media-op-0123456789abcdef0123456789abcdef';

function client(fetchImpl:typeof fetch,token:string|null=writeToken){return new ResearchManagerA5WriteClient({managerBaseUrl:new URL('https://example.test'),managerWriteToken:token,managerTimeoutMs:2_000},fetchImpl);}
function ok(data:unknown){return new Response(JSON.stringify({ok:true,data}),{status:200,headers:{'Content-Type':'application/json'}});}

test('Media ingest Plan sends bounded multipart binary and metadata',async()=>{
  let captured:FormData|null=null;
  const api=client(async(input,init)=>{
    assert.equal(new URL(input instanceof Request?input.url:input.toString()).pathname,'/wp-json/research-manager/v1/media/ingest/plan');
    assert.equal(init?.method,'POST');
    assert.ok(init?.body instanceof FormData);
    captured=init.body;
    assert.equal(new Headers(init.headers).has('Content-Type'),false);
    return ok({plan_id:ingestPlanId,operation:'media-ingest'});
  });
  const payload=Buffer.from('bounded-image-bytes').toString('base64');
  await api.planIngest({fileName:'portrait.jpg',mimeType:'image/jpeg',contentBase64:payload,title:'Portrait',altText:'Portrait of Eduardo',caption:'Research profile'});
  assert.ok(captured);
  const file=captured.get('file');
  assert.ok(file instanceof Blob);
  assert.equal(file.type,'image/jpeg');
  assert.equal(await file.text(),'bounded-image-bytes');
  assert.equal(captured.get('title'),'Portrait');
  assert.equal(captured.get('alt_text'),'Portrait of Eduardo');
  assert.equal(captured.get('caption'),'Research profile');
});

test('Media ingest rejects unsafe input before transport',()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({});});
  const payload=Buffer.from('x').toString('base64');
  assert.throws(()=>api.planIngest({fileName:'../portrait.jpg',mimeType:'image/jpeg',contentBase64:payload,altText:'Portrait'}),/bounded basename/);
  assert.throws(()=>api.planIngest({fileName:'portrait.jpg',mimeType:'image/jpeg',contentBase64:payload}),/alt text is required/);
  assert.throws(()=>api.planIngest({fileName:'portrait.jpg',mimeType:'image/jpeg',contentBase64:'%%%invalid%%%',altText:'Portrait'}),/canonical base64/);
  assert.equal(calls,0);
});

test('Media ingest Cancel and Apply preflight exact ingest plan domain',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{const path=new URL(input instanceof Request?input.url:input.toString()).pathname;requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});if(init?.method==='GET')return ok({plan_id:ingestPlanId,operation:'media-ingest',status:'planned'});return ok({plan_id:ingestPlanId,operation:'media-ingest'});});
  await api.cancelIngest({planId:ingestPlanId});
  await api.applyIngest({planId:ingestPlanId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/media/ingest/plans/${ingestPlanId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/media/ingest/plans/${ingestPlanId}/cancel`,method:'POST',body:'{}'},
    {path:`/wp-json/research-manager/v1/media/ingest/plans/${ingestPlanId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/media/ingest/plans/${ingestPlanId}/apply`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('Media ingest Verify and Rollback preflight operation and preserve exact bodies',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{const path=new URL(input instanceof Request?input.url:input.toString()).pathname;requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});if(init?.method==='GET')return ok({operation_id:ingestOperationId,operation:'media-ingest',status:'applied'});return ok({operation_id:ingestOperationId,operation:'media-ingest'});});
  await api.verifyIngest({operationId:ingestOperationId});
  await api.rollbackIngest({operationId:ingestOperationId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/media/ingest/operations/${ingestOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/media/ingest/operations/${ingestOperationId}/verify`,method:'POST',body:'{}'},
    {path:`/wp-json/research-manager/v1/media/ingest/operations/${ingestOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/media/ingest/operations/${ingestOperationId}/rollback`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('Media metadata Plan sends only the registered metadata update contract',async()=>{
  let requestBody:unknown;
  const api=client(async(input,init)=>{assert.equal(new URL(input instanceof Request?input.url:input.toString()).pathname,'/wp-json/research-manager/v1/media/metadata/plan');requestBody=JSON.parse(String(init?.body));return ok({plan_id:metadataPlanId,operation:'media-metadata-update'});});
  await api.planMetadataUpdate({attachmentId:42,changes:{title:'Research portrait',altText:'Updated alt',caption:'Caption'}});
  assert.deepEqual(requestBody,{operation:'media-metadata-update',payload:{attachment_id:42,changes:{title:'Research portrait',alt_text:'Updated alt',caption:'Caption'}}});
});

test('Media metadata Apply Verify and Rollback preflight exact metadata domain',async()=>{
  const requests:Array<{path:string;method:string;body:string|undefined}>=[];
  const api=client(async(input,init)=>{const path=new URL(input instanceof Request?input.url:input.toString()).pathname;requests.push({path,method:String(init?.method),body:init?.body===undefined?undefined:String(init.body)});if(init?.method==='GET')return ok(path.includes('/plans/')?{plan_id:metadataPlanId,operation:'media-metadata-update',status:'planned'}:{operation_id:metadataOperationId,operation:'media-metadata-update',status:'applied'});return ok({operation:'media-metadata-update'});});
  await api.applyMetadata({planId:metadataPlanId,confirm:true});
  await api.verifyMetadata({operationId:metadataOperationId,rendered:false});
  await api.rollbackMetadata({operationId:metadataOperationId,confirm:true});
  assert.deepEqual(requests,[
    {path:`/wp-json/research-manager/v1/media/metadata/plans/${metadataPlanId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/media/metadata/plans/${metadataPlanId}/apply`,method:'POST',body:'{"confirm":true}'},
    {path:`/wp-json/research-manager/v1/media/metadata/operations/${metadataOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/media/metadata/operations/${metadataOperationId}/verify`,method:'POST',body:'{"rendered":false}'},
    {path:`/wp-json/research-manager/v1/media/metadata/operations/${metadataOperationId}`,method:'GET',body:undefined},
    {path:`/wp-json/research-manager/v1/media/metadata/operations/${metadataOperationId}/rollback`,method:'POST',body:'{"confirm":true}'},
  ]);
});

test('Media lifecycle rejects foreign IDs and foreign operation domains before mutation',async()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({operation:'page-slots-update'});});
  assert.throws(()=>api.applyIngest({planId:'erm-media-plan-0123456789abcdef0123-1234abcd',confirm:true}),/ingest plan ID has an invalid format/);
  assert.throws(()=>api.applyMetadata({planId:ingestPlanId,confirm:true}),/metadata plan ID has an invalid format/);
  await assert.rejects(()=>api.applyIngest({planId:ingestPlanId,confirm:true}),/does not reference an allowed operation/);
  assert.equal(calls,1);
});

test('Media writes fail closed without the tenant write credential',async()=>{
  let calls=0;const api=client(async()=>{calls+=1;return ok({});},null);
  await assert.rejects(()=>api.planMetadataUpdate({attachmentId:42,changes:{title:'Bounded title'}}),/write credential is not configured/);
  assert.equal(calls,0);
});
