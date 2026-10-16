import assert from 'node:assert/strict';
import test from 'node:test';
import { loadConfig } from '../src/config.js';
import { ResearchManagerClient, ResearchManagerError } from '../src/manager-client.js';
import { TenantRegistry } from '../src/tenant-registry.js';

const token = 'ermt1_test-only-not-a-secret';

function clientWith(fetchImpl: typeof fetch): ResearchManagerClient {
  return new ResearchManagerClient(
    {
      managerBaseUrl: new URL('https://eduardoyauriluna.com'),
      managerReadToken: token,
      managerTimeoutMs: 2_000,
    },
    fetchImpl,
  );
}

test('read-only status call uses only the fixed Research Manager v1 route and signed request headers', async () => {
  let seenUrl = '';
  let seenInit: RequestInit | undefined;
  const client = clientWith(async (input, init) => {
    seenUrl = input instanceof Request ? input.url : input.toString();
    seenInit = init;
    return new Response(JSON.stringify({ ok: true, data: { manager_version: '0.10.4' } }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  });

  const result = await client.status();
  assert.deepEqual(result, { manager_version: '0.10.4' });
  const parsedUrl = new URL(seenUrl);
  assert.equal(parsedUrl.origin, 'https://eduardoyauriluna.com');
  assert.equal(parsedUrl.pathname, '/wp-json/research-manager/v1/status');
  assert.equal(seenInit?.method, 'GET');

  const headers = new Headers(seenInit?.headers);
  assert.equal(headers.get('Authorization'), `Bearer ${token}`);
  assert.match(headers.get('X-Research-Manager-Request-Id') ?? '', /^mcp-status-/);
  assert.match(headers.get('X-Research-Manager-Nonce') ?? '', /^[a-f0-9]{32}$/);
  assert.match(headers.get('X-Research-Manager-Timestamp') ?? '', /^\d{10,}$/);
});

test('Pages inventory uses the fixed Research Manager /pages route', async () => {
  let seenUrl = '';
  const client = clientWith(async (input) => {
    seenUrl = input instanceof Request ? input.url : input.toString();
    return new Response(JSON.stringify({ ok: true, data: { items: [], count: 0, languages: ['en', 'es'] } }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  });

  const result = await client.pages();
  assert.deepEqual(result, { items: [], count: 0, languages: ['en', 'es'] });
  const url = new URL(seenUrl);
  assert.equal(url.pathname, '/wp-json/research-manager/v1/pages');
  assert.equal(url.search, '');
});

test('Page inspection constructs only the bounded page route and language query', async () => {
  let seenUrl = '';
  const client = clientWith(async (input) => {
    seenUrl = input instanceof Request ? input.url : input.toString();
    return new Response(JSON.stringify({ ok: true, data: { key: 'about', language: 'es', page_id: 12 } }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  });

  const result = await client.page('about', 'es');
  assert.deepEqual(result, { key: 'about', language: 'es', page_id: 12 });
  const url = new URL(seenUrl);
  assert.equal(url.pathname, '/wp-json/research-manager/v1/pages/about');
  assert.equal(url.searchParams.get('language'), 'es');
  assert.match(url.search, /^\?language=es$/);
});

test('Page inspection rejects route-like keys and invalid languages before transport', async () => {
  let calls = 0;
  const client = clientWith(async () => {
    calls += 1;
    return new Response(JSON.stringify({ ok: true, data: {} }), { status: 200 });
  });

  assert.throws(() => client.page('../wp/v2/users', 'en'), /Page key/);
  assert.throws(() => client.page('about', '../en'), /Page language/);
  assert.equal(calls, 0);
});

test('Manager errors expose typed non-secret diagnostics and never echo the bearer token', async () => {
  const client = clientWith(async () =>
    new Response(JSON.stringify({ ok: false, code: 'scope_denied' }), {
      status: 403,
      headers: { 'Content-Type': 'application/json' },
    }),
  );

  await assert.rejects(
    () => client.capabilities(),
    (error: unknown) => {
      assert.ok(error instanceof ResearchManagerError);
      assert.equal(error.status, 403);
      assert.equal(error.code, 'scope_denied');
      assert.equal(error.message.includes(token), false);
      assert.match(error.requestId, /^mcp-capabilities-/);
      return true;
    },
  );
});

test('configuration refuses a non-loopback listener without inbound bridge authentication', () => {
  assert.throws(
    () =>
      loadConfig({
        ERM_BASE_URL: 'https://eduardoyauriluna.com',
        ERM_READ_TOKEN: token,
        MCP_BIND_HOST: '0.0.0.0',
      }),
    /MCP_BEARER_TOKEN is required/,
  );
});

test('commercial registry resolves only preconfigured tenants and keeps credentials outside registry JSON', () => {
  const config = loadConfig({
    MCP_TENANTS_JSON: JSON.stringify([
      {
        id: 'client-one',
        label: 'Client One',
        adapter: 'research-manager',
        baseUrl: 'https://client-one.example',
        readTokenEnv: 'CLIENT_ONE_READ_TOKEN',
        languages: ['es', 'en'],
      },
      {
        id: 'client-two',
        label: 'Client Two',
        adapter: 'research-manager',
        baseUrl: 'https://client-two.example',
        readTokenEnv: 'CLIENT_TWO_READ_TOKEN',
        languages: ['en'],
      },
    ]),
    CLIENT_ONE_READ_TOKEN: token,
    CLIENT_TWO_READ_TOKEN: 'ermt1_second-test-token',
    MCP_TENANT_ID: 'client-two',
  });

  assert.equal(config.tenants.length, 2);
  assert.equal(config.activeTenantId, 'client-two');
  const registry = new TenantRegistry(config.tenants);
  assert.deepEqual(registry.metadata('client-one'), {
    id: 'client-one',
    label: 'Client One',
    adapter: 'research-manager',
    languages: ['es', 'en'],
  });
  assert.throws(() => registry.require('not-registered'), /Unknown tenant id/);
  assert.equal(config.tenants[0]?.managerReadToken, token);
  assert.equal((process.env.MCP_TENANTS_JSON ?? '').includes(token), false);
});
