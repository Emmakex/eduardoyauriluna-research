import assert from 'node:assert/strict';
import test from 'node:test';
import { ResearchManagerClient } from '../src/manager-client.js';

const token='ermt1_seo-design-test';
function clientWith(fetchImpl:typeof fetch){return new ResearchManagerClient({managerBaseUrl:new URL('https://example.test'),managerReadToken:token,managerTimeoutMs:2000},fetchImpl);}
function ok(data:unknown={}){return new Response(JSON.stringify({ok:true,data}),{status:200,headers:{'Content-Type':'application/json'}});}

test('SEO site, indexability and readiness use fixed routes',async()=>{
  const seen:string[]=[];const client=clientWith(async(input)=>{seen.push(input instanceof Request?input.url:input.toString());return ok({});});
  await client.seoSite();await client.seoIndexability();await client.seoReadiness(5);
  assert.equal(new URL(seen[0]!).pathname,'/wp-json/research-manager/v1/seo-geo/site');
  assert.equal(new URL(seen[1]!).pathname,'/wp-json/research-manager/v1/seo-geo/indexability');
  const readiness=new URL(seen[2]!);assert.equal(readiness.pathname,'/wp-json/research-manager/v1/seo-geo/readiness');assert.equal(readiness.searchParams.get('per_type_limit'),'5');
});

test('SEO page resource uses closed page key and enabled-language query shape',async()=>{
  let seen='';const client=clientWith(async(input)=>{seen=input instanceof Request?input.url:input.toString();return ok({});});
  await client.seoResource({type:'page',key:'about',language:'es'});const url=new URL(seen);
  assert.equal(url.pathname,'/wp-json/research-manager/v1/seo-geo/resource');assert.equal(url.searchParams.get('type'),'page');assert.equal(url.searchParams.get('key'),'about');assert.equal(url.searchParams.get('language'),'es');assert.equal(url.searchParams.has('id'),false);
});

test('SEO Research resource actions and indexability use positive post IDs only',async()=>{
  const seen:string[]=[];const client=clientWith(async(input)=>{seen.push(input instanceof Request?input.url:input.toString());return ok({});});
  await client.seoResourceActions({type:'software',postId:41});await client.seoResourceIndexability({type:'insight',postId:9});
  const actions=new URL(seen[0]!);assert.equal(actions.pathname,'/wp-json/research-manager/v1/seo-geo/resource/actions');assert.equal(actions.searchParams.get('type'),'software');assert.equal(actions.searchParams.get('id'),'41');
  const indexability=new URL(seen[1]!);assert.equal(indexability.pathname,'/wp-json/research-manager/v1/seo-geo/indexability/resource');assert.equal(indexability.searchParams.get('id'),'9');
});

test('SEO internal links and readiness enforce bounded limits before transport',()=>{
  let calls=0;const client=clientWith(async()=>{calls+=1;return ok({});});
  assert.throws(()=>client.seoInternalLinks(0),/between 1 and 500/);assert.throws(()=>client.seoInternalLinks(501),/between 1 and 500/);
  assert.throws(()=>client.seoReadiness(0),/between 1 and 5/);assert.throws(()=>client.seoReadiness(6),/between 1 and 5/);
  assert.equal(calls,0);
});

test('SEO invalid page keys and Research post IDs fail before transport',()=>{
  let calls=0;const client=clientWith(async()=>{calls+=1;return ok({});});
  assert.throws(()=>client.seoResource({type:'page',key:'../wp/v2/users',language:'es'}),/lowercase registered key/);
  assert.throws(()=>client.seoResource({type:'dataset',postId:0}),/positive integer/);
  assert.equal(calls,0);
});

test('Design reads use fixed routes and rendered readiness is a boolean query only',async()=>{
  const seen:string[]=[];const client=clientWith(async(input)=>{seen.push(input instanceof Request?input.url:input.toString());return ok({});});
  await client.design();await client.designVariants();await client.designReadiness(false);
  assert.equal(new URL(seen[0]!).pathname,'/wp-json/research-manager/v1/design');assert.equal(new URL(seen[1]!).pathname,'/wp-json/research-manager/v1/design/variants');
  const readiness=new URL(seen[2]!);assert.equal(readiness.pathname,'/wp-json/research-manager/v1/design/readiness');assert.equal(readiness.searchParams.get('rendered'),'false');
});
