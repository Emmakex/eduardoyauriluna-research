import assert from 'node:assert/strict';
import test from 'node:test';
import { ResearchManagerWriteClient } from '../src/write-manager-client.js';

const writeToken = 'ermt1_page-plan-write-token';

function request() {
  return {
    key: 'about',
    language: 'en',
    slots: {
      'hero-title': 'Updated research profile',
      'focus-items': ['AI agents', 'Digital transformation'],
    },
    reason: 'Phase 2B.2 bounded Preview acceptance',
  };
}

test('page slot planning uses only the fixed operations plan route and write credential', async () => {
  let seenUrl = '';
  let seenInit: RequestInit | undefined;
  const client = new ResearchManagerWriteClient(
    {
      managerBaseUrl: new URL('https://example.test'),
      managerWriteToken: writeToken,
      managerTimeoutMs: 2_000,
    },
    async (input, init) => {
      seenUrl = input instanceof Request ? input.url : input.toString();
      seenInit = init;
      return new Response(JSON.stringify({
        ok: true,
        data: {
          plan_id: 'erm-remote-plan-test',
          operation: 'page-slots-update',
          confirmation_required: true,
          status: 'planned',
        },
      }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    },
  );

  const result = await client.planPageSlots(request());
  assert.equal(new URL(seenUrl).pathname, '/wp-json/research-manager/v1/operations/plan');
  assert.equal(seenInit?.method, 'POST');
  const headers = new Headers(seenInit?.headers);
  assert.equal(headers.get('authorization'), `Bearer ${writeToken}`);
  assert.equal(headers.get('content-type'), 'application/json');
  assert.match(headers.get('x-research-manager-request-id') ?? '', /^mcp-page-slots-plan-/);

  const body = JSON.parse(String(seenInit?.body)) as Record<string, unknown>;
  assert.equal(body.operation, 'page-slots-update');
  assert.deepEqual(body.payload, request());
  assert.deepEqual(result, {
    plan_id: 'erm-remote-plan-test',
    operation: 'page-slots-update',
    confirmation_required: true,
    status: 'planned',
  });
});

test('page slot planning fails closed when no write credential is provisioned', async () => {
  let calls = 0;
  const client = new ResearchManagerWriteClient(
    {
      managerBaseUrl: new URL('https://example.test'),
      managerWriteToken: null,
      managerTimeoutMs: 2_000,
    },
    async () => {
      calls += 1;
      return new Response(JSON.stringify({ ok: true, data: {} }), { status: 200 });
    },
  );

  await assert.rejects(() => client.planPageSlots(request()), /write credential is not configured/);
  assert.equal(calls, 0);
});

test('page slot planning rejects unbounded inputs before transport', () => {
  let calls = 0;
  const client = new ResearchManagerWriteClient(
    {
      managerBaseUrl: new URL('https://example.test'),
      managerWriteToken: writeToken,
      managerTimeoutMs: 2_000,
    },
    async () => {
      calls += 1;
      return new Response(JSON.stringify({ ok: true, data: {} }), { status: 200 });
    },
  );

  assert.throws(() => client.planPageSlots({ ...request(), key: '../wp-admin' }), /Page key/);
  assert.throws(() => client.planPageSlots({ ...request(), language: '../es' }), /Page language/);
  assert.throws(() => client.planPageSlots({ ...request(), slots: {} }), /between 1 and 32/);
  assert.throws(() => client.planPageSlots({ ...request(), slots: { '../unsafe': 'x' } }), /slot name/);
  assert.throws(() => client.planPageSlots({ ...request(), slots: { title: 'x'.repeat(12_001) } }), /exceeds 12000/);
  assert.equal(calls, 0);
});
