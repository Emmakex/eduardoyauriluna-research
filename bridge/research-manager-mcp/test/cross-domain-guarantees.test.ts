import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';

const SRC_DIR = new URL('../src/', import.meta.url);
const WRITE_CLIENTS = [
  'write-manager-client.ts',
  'a3-write-client.ts',
  'a4-write-client.ts',
  'a5-write-client.ts',
  'a6-write-client.ts',
  'a7-write-client.ts',
] as const;

function source(name: string): string {
  return readFileSync(new URL(`../src/${name}`, import.meta.url), 'utf8');
}

function allSourceFiles(): string[] {
  const root = SRC_DIR.pathname;
  const files: string[] = [];
  const walk = (dir: string): void => {
    for (const entry of readdirSync(dir)) {
      const absolute = join(dir, entry);
      if (statSync(absolute).isDirectory()) walk(absolute);
      else if (entry.endsWith('.ts')) files.push(absolute);
    }
  };
  walk(root);
  return files;
}

test('every bounded write transport stays inside Research Manager with correlation and replay guards', () => {
  for (const file of WRITE_CLIENTS) {
    const value = source(file);
    assert.match(value, /\/wp-json\/research-manager\/v1/, `${file} must target only the Research Manager namespace`);
    assert.match(value, /managerWriteToken/, `${file} must use the separate write credential profile`);
    assert.match(value, /X-Research-Manager-Request-Id/, `${file} must preserve request correlation`);
    assert.match(value, /X-Research-Manager-Nonce/, `${file} must preserve Manager replay protection`);
    assert.match(value, /X-Research-Manager-Timestamp/, `${file} must preserve Manager replay-window protection`);
    assert.match(value, /redirect\s*:\s*['"]error['"]/, `${file} must fail closed on redirects`);
    assert.match(value, /AbortSignal\.timeout/, `${file} must have a bounded transport timeout`);
    assert.match(value, /Research Manager write credential is not configured/, `${file} must fail closed without a write credential`);
    assert.doesNotMatch(value, /\/wp-json\/wp\/v2/, `${file} must not expose generic WordPress REST writes`);
  }
});

test('Apply transports can only confirm an already stored Manager plan', () => {
  let applyCalls = 0;
  for (const file of WRITE_CLIENTS) {
    const lines = source(file).split('\n').filter((line) => line.includes('this.post') && line.includes('/apply'));
    for (const line of lines) {
      applyCalls += 1;
      assert.match(line, /\{\s*confirm\s*:\s*true\s*\}/, `${file} Apply must send confirmation only`);
      assert.doesNotMatch(line, /\b(changes|payload|slots|data|after|state)\s*:/, `${file} Apply must not carry a replacement mutation payload`);
    }
  }
  assert.ok(applyCalls >= 10, 'cross-domain Apply guard must cover the implemented write surface');
});

test('the MCP source tree contains no generic backend-route or WordPress write escape hatch', () => {
  const forbidden = [
    /\/wp-json\/wp\/v2/,
    /registerTool\([^\n]*(proxy|arbitrary[_-]?route|raw[_-]?request)/i,
    /fetchImpl\(\s*(request|args|input)\.(url|route|endpoint)/,
    /new URL\(\s*(request|args|input)\.(url|route|endpoint)/,
  ];
  for (const file of allSourceFiles()) {
    const value = readFileSync(file, 'utf8');
    for (const pattern of forbidden) assert.doesNotMatch(value, pattern, `${file} introduced an unbounded backend escape hatch`);
  }
});

test('outer authorization binds principal, tenant, entitlement intersection and usage boundary before MCP dispatch', () => {
  const http = source('http.ts');
  const entitlements = source('entitlements.ts');
  assert.match(http, /principalRegistry\.authenticate/);
  assert.match(http, /principalRegistry\.canAccess/);
  assert.match(http, /usage\.admit/);
  assert.match(http, /runWithAuthorizationContext/);
  assert.match(http, /accessAuditEvent/);
  assert.match(http, /mcp_entitlement_denied/);
  assert.match(entitlements, /tenantEntitlements\.includes\(capability\)/);
  assert.match(entitlements, /principalCapabilities\.includes\(capability\)/);
});
