import assert from 'node:assert/strict';
import test from 'node:test';
import { loadConfig } from '../src/config.js';

const readToken = 'ermt1_read-only-credential';
const writeToken = 'ermt1_write-credential';
const elevatedToken = 'ermt1_elevated-credential';

function tenantDefinition(writeTokenEnv?: string, elevatedTokenEnv?: string): Record<string, unknown> {
  return {
    id: 'client-one',
    label: 'Client One',
    adapter: 'research-manager',
    baseUrl: 'https://client-one.example',
    readTokenEnv: 'CLIENT_ONE_READ_TOKEN',
    ...(writeTokenEnv ? { writeTokenEnv } : {}),
    ...(elevatedTokenEnv ? { elevatedTokenEnv } : {}),
    languages: ['en', 'es'],
    entitlements: ['site.read', 'pages.write', 'operations.rollback'],
  };
}

test('tenant config keeps read, write and elevated Research Manager credentials separate', () => {
  const config = loadConfig({
    MCP_TENANTS_JSON: JSON.stringify([tenantDefinition('CLIENT_ONE_WRITE_TOKEN', 'CLIENT_ONE_ELEVATED_TOKEN')]),
    CLIENT_ONE_READ_TOKEN: readToken,
    CLIENT_ONE_WRITE_TOKEN: writeToken,
    CLIENT_ONE_ELEVATED_TOKEN: elevatedToken,
  });
  assert.equal(config.tenants[0]?.managerReadToken, readToken);
  assert.equal(config.tenants[0]?.managerWriteToken, writeToken);
  assert.equal(config.tenants[0]?.managerElevatedToken, elevatedToken);
  assert.notEqual(config.tenants[0]?.managerReadToken, config.tenants[0]?.managerWriteToken);
  assert.notEqual(config.tenants[0]?.managerWriteToken, config.tenants[0]?.managerElevatedToken);
});

test('write and elevated credentials are independently optional until their bounded surfaces are provisioned', () => {
  const config = loadConfig({
    MCP_TENANTS_JSON: JSON.stringify([tenantDefinition()]),
    CLIENT_ONE_READ_TOKEN: readToken,
  });
  assert.equal(config.tenants[0]?.managerReadToken, readToken);
  assert.equal(config.tenants[0]?.managerWriteToken, null);
  assert.equal(config.tenants[0]?.managerElevatedToken, null);
});

test('legacy single-tenant env keeps ERM_WRITE_TOKEN and ERM_ELEVATED_TOKEN separate and optional', () => {
  const withoutMutation = loadConfig({ ERM_BASE_URL: 'https://client-one.example', ERM_READ_TOKEN: readToken });
  assert.equal(withoutMutation.tenants[0]?.managerWriteToken, null);
  assert.equal(withoutMutation.tenants[0]?.managerElevatedToken, null);

  const withProfiles = loadConfig({
    ERM_BASE_URL: 'https://client-one.example',
    ERM_READ_TOKEN: readToken,
    ERM_WRITE_TOKEN: writeToken,
    ERM_ELEVATED_TOKEN: elevatedToken,
  });
  assert.equal(withProfiles.tenants[0]?.managerReadToken, readToken);
  assert.equal(withProfiles.tenants[0]?.managerWriteToken, writeToken);
  assert.equal(withProfiles.tenants[0]?.managerElevatedToken, elevatedToken);
});
