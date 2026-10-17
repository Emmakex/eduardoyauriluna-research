import assert from 'node:assert/strict';
import test from 'node:test';
import { loadConfig } from '../src/config.js';

const readToken = 'ermt1_read-only-credential';
const writeToken = 'ermt1_write-credential';

function tenantDefinition(writeTokenEnv?: string): Record<string, unknown> {
  return {
    id: 'client-one',
    label: 'Client One',
    adapter: 'research-manager',
    baseUrl: 'https://client-one.example',
    readTokenEnv: 'CLIENT_ONE_READ_TOKEN',
    ...(writeTokenEnv ? { writeTokenEnv } : {}),
    languages: ['en', 'es'],
    entitlements: ['site.read', 'pages.write'],
  };
}

test('tenant config keeps read and write Research Manager credentials separate', () => {
  const config = loadConfig({
    MCP_TENANTS_JSON: JSON.stringify([tenantDefinition('CLIENT_ONE_WRITE_TOKEN')]),
    CLIENT_ONE_READ_TOKEN: readToken,
    CLIENT_ONE_WRITE_TOKEN: writeToken,
  });
  assert.equal(config.tenants[0]?.managerReadToken, readToken);
  assert.equal(config.tenants[0]?.managerWriteToken, writeToken);
  assert.notEqual(config.tenants[0]?.managerReadToken, config.tenants[0]?.managerWriteToken);
});

test('write credential is optional until a bounded write surface is provisioned', () => {
  const config = loadConfig({
    MCP_TENANTS_JSON: JSON.stringify([tenantDefinition()]),
    CLIENT_ONE_READ_TOKEN: readToken,
  });
  assert.equal(config.tenants[0]?.managerReadToken, readToken);
  assert.equal(config.tenants[0]?.managerWriteToken, null);
});

test('legacy single-tenant env also keeps ERM_WRITE_TOKEN separate and optional', () => {
  const withoutWrite = loadConfig({ ERM_BASE_URL: 'https://client-one.example', ERM_READ_TOKEN: readToken });
  assert.equal(withoutWrite.tenants[0]?.managerWriteToken, null);

  const withWrite = loadConfig({
    ERM_BASE_URL: 'https://client-one.example',
    ERM_READ_TOKEN: readToken,
    ERM_WRITE_TOKEN: writeToken,
  });
  assert.equal(withWrite.tenants[0]?.managerReadToken, readToken);
  assert.equal(withWrite.tenants[0]?.managerWriteToken, writeToken);
});
