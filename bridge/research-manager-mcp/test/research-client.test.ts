import assert from 'node:assert/strict';
import test from 'node:test';
import { ResearchManagerAdapter } from '../src/adapters/research-manager.js';
import { ResearchManagerClient } from '../src/manager-client.js';
import type { TenantConfig } from '../src/config.js';

const token = 'ermt1_research-test-token';

function clientWith(fetchImpl: typeof fetch): ResearchManagerClient {
  return new ResearchManagerClient({ managerBaseUrl: new URL('https://example.test'), managerReadToken: token, managerTimeoutMs: 2000 }, fetchImpl);
}

test('Research objects inventory uses only fixed route and closed query filters', async () => {
  const seen: string[] = [];
  const client = clientWith(async (input) => {
    seen.push(input instanceof Request ? input.url : input.toString());
    return new Response(JSON.stringify({ ok: true, data: { count: 0, groups: {} } }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  });
  await client.researchObjects();
  await client.researchObjects('software', 'es');
  assert.equal(new URL(seen[0]!).pathname, '/wp-json/research-manager/v1/research-objects');
  const filtered = new URL(seen[1]!);
  assert.equal(filtered.pathname, '/wp-json/research-manager/v1/research-objects');
  assert.equal(filtered.searchParams.get('kind'), 'software');
  assert.equal(filtered.searchParams.get('language'), 'es');
});

test('Research object inspection uses closed kind and positive post ID', async () => {
  let seen = '';
  const client = clientWith(async (input) => {
    seen = input instanceof Request ? input.url : input.toString();
    return new Response(JSON.stringify({ ok: true, data: { post_id: 9 } }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  });
  await client.researchObject('dataset', 9);
  assert.equal(new URL(seen).pathname, '/wp-json/research-manager/v1/research-objects/dataset/9');
});

test('Research lines inventory/detail use bounded routes', async () => {
  const seen: string[] = [];
  const client = clientWith(async (input) => {
    seen.push(input instanceof Request ? input.url : input.toString());
    return new Response(JSON.stringify({ ok: true, data: {} }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  });
  await client.researchLines('en');
  await client.researchLine(12);
  const list = new URL(seen[0]!);
  assert.equal(list.pathname, '/wp-json/research-manager/v1/research-lines');
  assert.equal(list.searchParams.get('language'), 'en');
  assert.equal(new URL(seen[1]!).pathname, '/wp-json/research-manager/v1/research-lines/12');
});

test('Research read inputs fail before transport when invalid', () => {
  let calls = 0;
  const client = clientWith(async () => { calls += 1; return new Response(JSON.stringify({ ok: true, data: {} }), { status: 200 }); });
  assert.throws(() => client.researchObjects('evil' as never), /Unsupported Research Object kind/);
  assert.throws(() => client.researchObjects('output', '../es'), /language/);
  assert.throws(() => client.researchObject('project', 0), /positive integer/);
  assert.throws(() => client.researchLine(Number.NaN), /positive integer/);
  assert.equal(calls, 0);
});

test('Research adapter enforces tenant language contract before transport', () => {
  let calls = 0;
  const tenant: TenantConfig = {
    id: 'client-one', label: 'Client One', adapter: 'research-manager', managerBaseUrl: new URL('https://example.test'),
    managerReadToken: token, managerWriteToken: null, managerTimeoutMs: 2000, languages: ['en', 'es'], entitlements: ['site.read', 'research.read'],
  };
  const adapter = new ResearchManagerAdapter(tenant, async () => { calls += 1; return new Response(JSON.stringify({ ok: true, data: {} }), { status: 200 }); });
  assert.throws(() => adapter.listResearchObjects({ kind: 'output', language: 'fr' }), /not enabled for tenant/);
  assert.throws(() => adapter.listResearchLines({ language: 'fr' }), /not enabled for tenant/);
  assert.equal(calls, 0);
});
