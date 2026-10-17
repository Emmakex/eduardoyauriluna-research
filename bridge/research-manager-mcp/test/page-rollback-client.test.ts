import assert from 'node:assert/strict';
import test from 'node:test';
import type { TenantConfig } from '../src/config.js';
import type { SitePageSlotsRollbackRequest } from '../src/adapters/site-control.js';
import { ResearchManagerAdapter } from '../src/adapters/research-manager.js';
import { ResearchManagerWriteClient } from '../src/write-manager-client.js';

const readToken = 'ermt1_page-rollback-read-token';
const writeToken = 'ermt1_page-rollback-write-token';
const operationId = 'erm-operation-0123456789abcdef0123456789abcdef';

function tenant(writeCredential: string | null = writeToken): TenantConfig {
  return {
    id: 'rollback-tenant',
    label: 'Rollback Tenant',
    adapter: 'research-manager',
    managerBaseUrl: new URL('https://example.test'),
    managerReadToken: readToken,
    managerWriteToken: writeCredential,
    managerTimeoutMs: 2_000,
    languages: ['en', 'es'],
    entitlements: ['pages.write', 'operations.rollback'],
  };
}

test('Page Rollback preflights operation domain with read credential then rolls back with write credential and confirm=true', async () => {
  const calls: Array<{ url: string; init: RequestInit | undefined }> = [];
  const fetchImpl = async (input: string | URL | Request, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : input.toString();
    calls.push({ url, init });
    const pathname = new URL(url).pathname;
    if (pathname === `/wp-json/research-manager/v1/operations/${operationId}` && init?.method === 'GET') {
      return new Response(JSON.stringify({
        ok: true,
        data: { operation_id: operationId, operation: 'page-slots-update', status: 'verified' },
      }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    }
    return new Response(JSON.stringify({
      ok: true,
      data: {
        operation_id: operationId,
        operation: 'page-slots-update',
        status: 'rolled-back',
        rollback_result: { restored: true },
      },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  };
  const adapter = new ResearchManagerAdapter(tenant(), fetchImpl as typeof fetch);

  const result = await adapter.rollbackPageSlots({ operationId, confirm: true });
  assert.equal(calls.length, 2);
  assert.equal(new URL(calls[0]!.url).pathname, `/wp-json/research-manager/v1/operations/${operationId}`);
  assert.equal(calls[0]!.init?.method, 'GET');
  assert.equal(new Headers(calls[0]!.init?.headers).get('authorization'), `Bearer ${readToken}`);

  assert.equal(new URL(calls[1]!.url).pathname, `/wp-json/research-manager/v1/operations/${operationId}/rollback`);
  assert.equal(calls[1]!.init?.method, 'POST');
  assert.equal(new Headers(calls[1]!.init?.headers).get('authorization'), `Bearer ${writeToken}`);
  assert.match(new Headers(calls[1]!.init?.headers).get('x-research-manager-request-id') ?? '', /^mcp-page-slots-rollback-/);
  assert.deepEqual(JSON.parse(String(calls[1]!.init?.body)), { confirm: true });
  assert.deepEqual(result, {
    operation_id: operationId,
    operation: 'page-slots-update',
    status: 'rolled-back',
    rollback_result: { restored: true },
  });
});

test('Page Rollback refuses an operation from another commercial mutation domain before write transport', async () => {
  let calls = 0;
  const fetchImpl = async (): Promise<Response> => {
    calls += 1;
    return new Response(JSON.stringify({
      ok: true,
      data: { operation_id: operationId, operation: 'insight-update', status: 'verified' },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  };
  const adapter = new ResearchManagerAdapter(tenant(), fetchImpl as typeof fetch);

  await assert.rejects(
    () => adapter.rollbackPageSlots({ operationId, confirm: true }),
    /does not reference a page-slots-update operation/,
  );
  assert.equal(calls, 1);
});

test('Page Rollback rejects invalid operation IDs and missing explicit confirmation before write transport', () => {
  let calls = 0;
  const client = new ResearchManagerWriteClient(tenant(), async () => {
    calls += 1;
    return new Response(JSON.stringify({ ok: true, data: {} }), { status: 200 });
  });

  assert.throws(
    () => client.rollbackPageSlots({ operationId: '../operations/anything', confirm: true }),
    /operation ID has an invalid format/,
  );
  assert.throws(
    () => client.rollbackPageSlots({ operationId, confirm: false } as unknown as SitePageSlotsRollbackRequest),
    /requires explicit confirmation/,
  );
  assert.equal(calls, 0);
});

test('Page Rollback fails closed when no write credential is provisioned', async () => {
  let calls = 0;
  const client = new ResearchManagerWriteClient(tenant(null), async () => {
    calls += 1;
    return new Response(JSON.stringify({ ok: true, data: {} }), { status: 200 });
  });

  await assert.rejects(
    () => client.rollbackPageSlots({ operationId, confirm: true }),
    /write credential is not configured/,
  );
  assert.equal(calls, 0);
});
