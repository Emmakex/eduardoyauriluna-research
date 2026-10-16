import assert from 'node:assert/strict';
import test from 'node:test';
import { PrincipalRegistry } from '../src/access-control.js';
import { loadConfig } from '../src/config.js';
import { EnvSecretResolver } from '../src/secret-resolver.js';
import { UsageBoundary } from '../src/usage-boundary.js';

const clientOneManagerToken = 'ermt1_client-one-manager';
const clientTwoManagerToken = 'ermt1_client-two-manager';
const agencyBearer = 'agency-principal-secret';
const clientOneBearer = 'client-one-principal-secret';

function sharedEnv(): NodeJS.ProcessEnv {
  return {
    MCP_TENANTS_JSON: JSON.stringify([
      {
        id: 'client-one',
        label: 'Client One',
        adapter: 'research-manager',
        baseUrl: 'https://client-one.example',
        readTokenEnv: 'CLIENT_ONE_MANAGER_TOKEN',
        languages: ['es', 'en'],
        entitlements: ['site.read'],
      },
      {
        id: 'client-two',
        label: 'Client Two',
        adapter: 'research-manager',
        baseUrl: 'https://client-two.example',
        readTokenEnv: 'CLIENT_TWO_MANAGER_TOKEN',
        languages: ['en'],
        entitlements: ['site.read'],
      },
    ]),
    MCP_PRINCIPALS_JSON: JSON.stringify([
      {
        id: 'agency-workspace',
        bearerTokenEnv: 'AGENCY_BEARER_TOKEN',
        tenants: ['client-one', 'client-two'],
        capabilities: ['site.read'],
      },
      {
        id: 'client-one-workspace',
        bearerTokenEnv: 'CLIENT_ONE_BEARER_TOKEN',
        tenants: ['client-one'],
        capabilities: ['site.read'],
      },
    ]),
    MCP_SHARED_ROUTING: 'true',
    CLIENT_ONE_MANAGER_TOKEN: clientOneManagerToken,
    CLIENT_TWO_MANAGER_TOKEN: clientTwoManagerToken,
    AGENCY_BEARER_TOKEN: agencyBearer,
    CLIENT_ONE_BEARER_TOKEN: clientOneBearer,
  };
}

test('shared routing maps authenticated principals only to explicitly allowed tenants', () => {
  const config = loadConfig(sharedEnv());
  assert.equal(config.sharedRouting, true);
  assert.equal(config.tenants.length, 2);
  assert.equal(config.principals.length, 2);

  const principals = new PrincipalRegistry(config.principals);
  const agency = principals.authenticate(`Bearer ${agencyBearer}`);
  const clientOne = principals.authenticate(`Bearer ${clientOneBearer}`);

  assert.ok(agency);
  assert.ok(clientOne);
  assert.equal(principals.canAccess(agency, 'client-one'), true);
  assert.equal(principals.canAccess(agency, 'client-two'), true);
  assert.equal(principals.canAccess(clientOne, 'client-one'), true);
  assert.equal(principals.canAccess(clientOne, 'client-two'), false);
  assert.equal(principals.authenticate('Bearer wrong-token'), null);
});

test('shared routing is deny-by-default and rejects principals that reference unknown tenants', () => {
  const env = sharedEnv();
  env.MCP_PRINCIPALS_JSON = JSON.stringify([
    {
      id: 'bad-workspace',
      bearerTokenEnv: 'AGENCY_BEARER_TOKEN',
      tenants: ['client-one', 'not-provisioned'],
      capabilities: ['site.read'],
    },
  ]);
  assert.throws(() => loadConfig(env), /references unknown tenant id: not-provisioned/);

  const noPrincipals = sharedEnv();
  delete noPrincipals.MCP_PRINCIPALS_JSON;
  assert.throws(() => loadConfig(noPrincipals), /MCP_SHARED_ROUTING requires MCP_PRINCIPALS_JSON/);
});

test('principal registry rejects one bearer credential assigned to multiple principals', () => {
  assert.throws(
    () =>
      new PrincipalRegistry([
        { id: 'one', bearerToken: 'same-secret', allowedTenantIds: ['client-one'], capabilities: ['site.read'] },
        { id: 'two', bearerToken: 'same-secret', allowedTenantIds: ['client-two'], capabilities: ['site.read'] },
      ]),
    /Duplicate bearer credential/,
  );
});

test('secret resolution is reference-based and fails closed when a referenced secret is absent', () => {
  const resolver = new EnvSecretResolver({ KNOWN_SECRET: 'value-from-secret-store-contract' });
  assert.equal(resolver.resolve('KNOWN_SECRET'), 'value-from-secret-store-contract');
  assert.throws(() => resolver.resolve('MISSING_SECRET'), /Missing required secret reference/);
  assert.throws(() => resolver.resolve('../unsafe'), /Invalid environment secret reference/);
});

test('rate and usage counters are isolated by principal and tenant', () => {
  let now = 1_000_000;
  const usage = new UsageBoundary(2, () => now);
  const clientOne = { principalId: 'agency-workspace', tenantId: 'client-one' };
  const clientTwo = { principalId: 'agency-workspace', tenantId: 'client-two' };
  const otherPrincipal = { principalId: 'client-one-workspace', tenantId: 'client-one' };

  assert.equal(usage.admit(clientOne), true);
  assert.equal(usage.admit(clientOne), true);
  assert.equal(usage.admit(clientOne), false);

  assert.equal(usage.admit(clientTwo), true);
  assert.equal(usage.admit(otherPrincipal), true);

  assert.deepEqual(usage.snapshot(clientOne), {
    principalId: 'agency-workspace',
    tenantId: 'client-one',
    windowStartedAt: 960_000,
    allowed: 2,
    denied: 0,
    rateLimited: 1,
  });

  now += 60_000;
  assert.equal(usage.admit(clientOne), true);
  assert.equal(usage.snapshot(clientOne).allowed, 1);
});
