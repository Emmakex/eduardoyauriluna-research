import assert from 'node:assert/strict';
import test from 'node:test';
import { ResearchManagerAdapter } from '../src/adapters/research-manager.js';
import { ResearchManagerClient } from '../src/manager-client.js';
import type { TenantConfig } from '../src/config.js';

const token = 'ermt1_insights-test-token';

function clientWith(fetchImpl: typeof fetch): ResearchManagerClient {
  return new ResearchManagerClient(
    {
      managerBaseUrl: new URL('https://example.test'),
      managerReadToken: token,
      managerTimeoutMs: 2_000,
    },
    fetchImpl,
  );
}

test('Insights inventory uses fixed route and optional language query only', async () => {
  const seen: string[] = [];
  const client = clientWith(async (input) => {
    seen.push(input instanceof Request ? input.url : input.toString());
    return new Response(JSON.stringify({ ok: true, data: { items: [], count: 0 } }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  });

  await client.insights();
  await client.insights('es');

  const all = new URL(seen[0]!);
  assert.equal(all.pathname, '/wp-json/research-manager/v1/insights');
  assert.equal(all.search, '');
  const spanish = new URL(seen[1]!);
  assert.equal(spanish.pathname, '/wp-json/research-manager/v1/insights');
  assert.equal(spanish.searchParams.get('language'), 'es');
});

test('Insight inspection constructs only a positive numeric post route', async () => {
  let seenUrl = '';
  const client = clientWith(async (input) => {
    seenUrl = input instanceof Request ? input.url : input.toString();
    return new Response(JSON.stringify({ ok: true, data: { post_id: 42, title: 'Bounded insight' } }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  });

  const result = await client.insight(42);
  assert.deepEqual(result, { post_id: 42, title: 'Bounded insight' });
  assert.equal(new URL(seenUrl).pathname, '/wp-json/research-manager/v1/insights/42');
});

test('Insight client rejects invalid language and post IDs before transport', () => {
  let calls = 0;
  const client = clientWith(async () => {
    calls += 1;
    return new Response(JSON.stringify({ ok: true, data: {} }), { status: 200 });
  });

  assert.throws(() => client.insights('../es'), /Insight language/);
  assert.throws(() => client.insight(0), /positive integer/);
  assert.throws(() => client.insight(Number.NaN), /positive integer/);
  assert.equal(calls, 0);
});

test('Research Manager adapter rejects Insight languages outside the tenant contract before transport', () => {
  let calls = 0;
  const tenant: TenantConfig = {
    id: 'client-one',
    label: 'Client One',
    adapter: 'research-manager',
    managerBaseUrl: new URL('https://example.test'),
    managerReadToken: token,
    managerTimeoutMs: 2_000,
    languages: ['en', 'es'],
    entitlements: ['site.read', 'insights.read'],
  };
  const adapter = new ResearchManagerAdapter(tenant, async () => {
    calls += 1;
    return new Response(JSON.stringify({ ok: true, data: {} }), { status: 200 });
  });

  assert.throws(() => adapter.listInsights({ language: 'fr' }), /not enabled for tenant/);
  assert.equal(calls, 0);
});
