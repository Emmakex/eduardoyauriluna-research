import assert from 'node:assert/strict';
import test from 'node:test';
import type { SitePageSlotsApplyRequest } from '../src/adapters/site-control.js';
import { ResearchManagerWriteClient } from '../src/write-manager-client.js';

const writeToken = 'ermt1_page-apply-write-token';
const planId = 'erm-remote-plan-0123456789abcdefabcd-0123abcd';

function clientWith(fetchImpl: typeof fetch, token: string | null = writeToken) {
  return new ResearchManagerWriteClient(
    {
      managerBaseUrl: new URL('https://example.test'),
      managerWriteToken: token,
      managerTimeoutMs: 2_000,
    },
    fetchImpl,
  );
}

test('page slot Apply uses only the exact plan route, write credential and confirm=true body', async () => {
  let seenUrl = '';
  let seenInit: RequestInit | undefined;
  const client = clientWith(async (input, init) => {
    seenUrl = input instanceof Request ? input.url : input.toString();
    seenInit = init;
    return new Response(JSON.stringify({
      ok: true,
      data: {
        operation_id: 'erm-operation-0123456789abcdef',
        plan_id: planId,
        operation: 'page-slots-update',
        status: 'applied',
      },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  });

  const result = await client.applyPageSlots({ planId, confirm: true });
  assert.equal(new URL(seenUrl).pathname, `/wp-json/research-manager/v1/plans/${planId}/apply`);
  assert.equal(seenInit?.method, 'POST');
  const headers = new Headers(seenInit?.headers);
  assert.equal(headers.get('authorization'), `Bearer ${writeToken}`);
  assert.equal(headers.get('content-type'), 'application/json');
  assert.match(headers.get('x-research-manager-request-id') ?? '', /^mcp-page-slots-apply-/);
  assert.deepEqual(JSON.parse(String(seenInit?.body)), { confirm: true });
  assert.deepEqual(result, {
    operation_id: 'erm-operation-0123456789abcdef',
    plan_id: planId,
    operation: 'page-slots-update',
    status: 'applied',
  });
});

test('page slot Apply rejects invalid plan IDs and missing explicit confirmation before transport', () => {
  let calls = 0;
  const client = clientWith(async () => {
    calls += 1;
    return new Response(JSON.stringify({ ok: true, data: {} }), { status: 200 });
  });

  assert.throws(
    () => client.applyPageSlots({ planId: '../operations/anything', confirm: true }),
    /plan ID has an invalid format/,
  );
  assert.throws(
    () => client.applyPageSlots({ planId, confirm: false } as unknown as SitePageSlotsApplyRequest),
    /requires explicit confirmation/,
  );
  assert.equal(calls, 0);
});

test('page slot Apply fails closed when no write credential is provisioned', async () => {
  let calls = 0;
  const client = clientWith(async () => {
    calls += 1;
    return new Response(JSON.stringify({ ok: true, data: {} }), { status: 200 });
  }, null);

  await assert.rejects(() => client.applyPageSlots({ planId, confirm: true }), /write credential is not configured/);
  assert.equal(calls, 0);
});
