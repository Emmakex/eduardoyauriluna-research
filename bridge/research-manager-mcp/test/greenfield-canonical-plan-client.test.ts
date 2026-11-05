import assert from 'node:assert/strict';
import test from 'node:test';
import { ResearchManagerWriteClient } from '../src/write-manager-client.js';
import { summarizeCanonicalGreenfieldPlan } from '../src/greenfield-canonical-plan.js';

const ID = 'erm-remote-plan-' + 'a'.repeat(20) + '-1234abcd';
const sha = (letter: string) => letter.repeat(64);
function canonicalPlan() {
  return {
    plan_id: ID,
    operation: 'greenfield-canonical',
    status: 'planned',
    apply_allowed: true,
    confirmation_required: true,
    source_revision: sha('b'),
    contract_fingerprint: sha('c'),
    blueprint_sha256: sha('d'),
    expires_at: '2026-11-05T16:00:00+00:00',
    preview: {
      phases: {
        bootstrap: { status: 'steady', operation_count: 0, change_count: 0, apply_allowed: true, secret: 'NEVER-EXPOSE' },
        pairing: { status: 'steady', operation_count: 0, change_count: 0, apply_allowed: true },
        hydration: { status: 'steady', operation_count: 24, change_count: 0, apply_allowed: true },
        relations: { status: 'steady', operation_count: 8, change_count: 0, apply_allowed: true },
      },
    },
    requested: { private: 'NEVER-EXPOSE' },
    connection_id: 'NEVER-EXPOSE',
  };
}

test('canonical Greenfield Plan uses only the fixed existing WordPress Plan route and write credential', async () => {
  let seenUrl = '';
  let init: RequestInit | undefined;
  const token = 'ermt1-test-write-token';
  const client = new ResearchManagerWriteClient({
    managerBaseUrl: new URL('https://example.test'),
    managerWriteToken: token,
    managerTimeoutMs: 2000,
  }, async (input, requestInit) => {
    seenUrl = String(input);
    init = requestInit;
    return new Response(JSON.stringify({ ok: true, data: canonicalPlan() }), {status: 200});
  });
  const preview = await client.planCanonicalGreenfield();
  assert.equal(new URL(seenUrl).pathname, '/wp-json/research-manager/v1/operations/plan');
  assert.equal(init?.method, 'POST');
  const headers = new Headers(init?.headers);
  assert.equal(headers.get('authorization'), `Bearer ${token}`);
  assert.match(headers.get('x-research-manager-request-id') ?? '', /^mcp-greenfield-canonical-plan-/);
  assert.deepEqual(JSON.parse(String(init?.body)), {operation: 'greenfield-canonical', payload: {}});
  assert.equal((preview as {plan_id:string}).plan_id, ID);
  assert.equal((preview as {public_site_apply:string}).public_site_apply, 'not_executed');
  assert.equal((preview as {confirmation_required:boolean}).confirmation_required, true);
  assert.equal(JSON.stringify(preview).includes('NEVER-EXPOSE'), false);
  assert.deepEqual(Object.keys((preview as {phases:Record<string,unknown>}).phases), ['bootstrap','pairing','hydration','relations']);
});

test('canonical Greenfield Preview does not contact WordPress without its write credential', async () => {
  let calls=0;
  const client = new ResearchManagerWriteClient({
    managerBaseUrl: new URL('https://example.test'),
    managerWriteToken: null,
    managerTimeoutMs: 2000,
  }, async () => {
    calls++;
    throw new Error('should not reach transport');
  });
  await assert.rejects(() => client.planCanonicalGreenfield(), /write credential is not configured/);
  assert.equal(calls,0);
});

test('canonical Greenfield Preview rejects tampered and incomplete Plan authorities', () => {
  assert.throws(() => summarizeCanonicalGreenfieldPlan({...canonicalPlan(), operation:'page-slots-update'}), /incomplete or unexpected/);
  assert.throws(() => summarizeCanonicalGreenfieldPlan({...canonicalPlan(), plan_id:'../unsafe'}), /incomplete or unexpected/);
  assert.throws(() => summarizeCanonicalGreenfieldPlan({...canonicalPlan(), confirmation_required:false}), /incomplete or unexpected/);
  assert.throws(() => summarizeCanonicalGreenfieldPlan({...canonicalPlan(), preview:{phases:{bootstrap:{status:'steady'}}}}), /phases/);
  assert.throws(() => summarizeCanonicalGreenfieldPlan({
    ...canonicalPlan(),
    preview:{phases:{...canonicalPlan().preview.phases,bootstrap:{status:'steady',operation_count:-1}}},
  }), /operation count/);
});
