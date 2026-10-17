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

test('page slot Apply preflights the exact stored Page plan then applies with write credential and confirm=true', async () => {
  const calls: Array<{ url: string; init: RequestInit | undefined }> = [];
  const client = clientWith(async (input, init) => {
    const url = input instanceof Request ? input.url : input.toString();
    calls.push({ url, init });
    const pathname = new URL(url).pathname;
    if (pathname === `/wp-json/research-manager/v1/plans/${planId}`) {
      return new Response(JSON.stringify({
        ok: true,
        data: { plan_id: planId, operation: 'page-slots-update', status: 'planned' },
      }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    }
    return new Response(JSON.stringify({
      ok: true,
      data: {
        operation_id: 'erm-operation-0123456789abcdef0123456789abcdef',
        plan_id: planId,
        operation: 'page-slots-update',
        status: 'applied',
      },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  });

  const result = await client.applyPageSlots({ planId, confirm: true });
  assert.equal(calls.length, 2);
  assert.equal(new URL(calls[0]!.url).pathname, `/wp-json/research-manager/v1/plans/${planId}`);
  assert.equal(calls[0]!.init?.method, 'GET');
  assert.equal(new Headers(calls[0]!.init?.headers).get('authorization'), `Bearer ${writeToken}`);
  assert.match(new Headers(calls[0]!.init?.headers).get('x-research-manager-request-id') ?? '', /^mcp-page-slots-apply-preflight-/);

  assert.equal(new URL(calls[1]!.url).pathname, `/wp-json/research-manager/v1/plans/${planId}/apply`);
  assert.equal(calls[1]!.init?.method, 'POST');
  const applyHeaders = new Headers(calls[1]!.init?.headers);
  assert.equal(applyHeaders.get('authorization'), `Bearer ${writeToken}`);
  assert.equal(applyHeaders.get('content-type'), 'application/json');
  assert.match(applyHeaders.get('x-research-manager-request-id') ?? '', /^mcp-page-slots-apply-/);
  assert.deepEqual(JSON.parse(String(calls[1]!.init?.body)), { confirm: true });
  assert.deepEqual(result, {
    operation_id: 'erm-operation-0123456789abcdef0123456789abcdef',
    plan_id: planId,
    operation: 'page-slots-update',
    status: 'applied',
  });
});

test('page slot Apply refuses a valid plan ID belonging to another operation domain', async () => {
  let calls = 0;
  const client = clientWith(async () => {
    calls += 1;
    return new Response(JSON.stringify({
      ok: true,
      data: { plan_id: planId, operation: 'insight-update', status: 'planned' },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  });

  await assert.rejects(
    () => client.applyPageSlots({ planId, confirm: true }),
    /does not reference a page-slots-update operation/,
  );
  assert.equal(calls, 1);
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
