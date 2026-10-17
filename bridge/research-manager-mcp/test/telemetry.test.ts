import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { loadConfig } from '../src/config.js';
import { createTelemetrySink, JsonlTelemetrySink, type AccessTelemetryEvent, type EntitlementTelemetryEvent } from '../src/telemetry.js';
import { accessAuditRecord, UsageBoundary } from '../src/usage-boundary.js';

test('JSONL telemetry is append-only, private and machine-readable', () => {
  const directory = mkdtempSync(join(tmpdir(), 'mcp-telemetry-'));
  const path = join(directory, 'events.jsonl');
  const sink = new JsonlTelemetrySink(path);
  const access: AccessTelemetryEvent = {
    event: 'mcp_access',
    timestamp: '2026-10-17T12:00:00.000Z',
    request_id: 'mcp-http-test-1',
    principal_id: 'workspace-one',
    tenant_id: 'tenant-one',
    outcome: 'allowed',
    request_path: '/mcp/tenant-one',
    usage: { window_started_at: 1_000_000, allowed: 1, denied: 0, rate_limited: 0 },
  };
  const entitlement: EntitlementTelemetryEvent = {
    event: 'mcp_entitlement_denied',
    timestamp: '2026-10-17T12:00:01.000Z',
    request_id: 'mcp-http-test-1',
    principal_id: 'workspace-one',
    tenant_id: 'tenant-one',
    capability: 'design.write',
    reason: 'principal_not_granted',
  };
  sink.write(access);
  sink.write(entitlement);

  const lines = readFileSync(path, 'utf8').trim().split('\n').map((line) => JSON.parse(line) as Record<string, unknown>);
  assert.equal(lines.length, 2);
  assert.deepEqual(lines[0], access);
  assert.deepEqual(lines[1], entitlement);
  assert.equal(statSync(path).mode & 0o777, 0o600);
});

test('persistent telemetry requirement fails closed when no destination is configured', () => {
  assert.throws(() => createTelemetrySink(null, true), /Persistent MCP telemetry is required/);
  assert.throws(() => new JsonlTelemetrySink('relative/events.jsonl'), /absolute filesystem path/);

  assert.throws(
    () => loadConfig({
      ERM_BASE_URL: 'https://example.invalid',
      ERM_READ_TOKEN: 'ermt1_read',
      MCP_REQUIRE_PERSISTENT_TELEMETRY: 'true',
    }),
    /requires MCP_TELEMETRY_PATH/,
  );
});

test('configured persistent telemetry path is preserved without exposing it as a secret reference', () => {
  const directory = mkdtempSync(join(tmpdir(), 'mcp-config-telemetry-'));
  const path = join(directory, 'events.jsonl');
  const config = loadConfig({
    ERM_BASE_URL: 'https://example.invalid',
    ERM_READ_TOKEN: 'ermt1_read',
    MCP_TELEMETRY_PATH: path,
    MCP_REQUIRE_PERSISTENT_TELEMETRY: 'true',
  });
  assert.equal(config.telemetryPath, path);
  assert.equal(config.requirePersistentTelemetry, true);
});

test('access audit records carry request correlation and current principal-by-tenant usage counters', () => {
  let now = 1_000_000;
  const usage = new UsageBoundary(2, () => now);
  const key = { principalId: 'workspace-one', tenantId: 'tenant-one' };
  assert.equal(usage.admit(key), true);
  usage.deny(key);
  const record = accessAuditRecord({
    requestId: 'mcp-http-correlation',
    principalId: key.principalId,
    tenantId: key.tenantId,
    outcome: 'allowed',
    requestPath: '/mcp/tenant-one',
    usage: usage.snapshot(key),
    timestamp: '2026-10-17T12:00:00.000Z',
  });
  assert.equal(record.request_id, 'mcp-http-correlation');
  assert.deepEqual(record.usage, {
    window_started_at: 960_000,
    allowed: 1,
    denied: 1,
    rate_limited: 0,
  });
  now += 60_000;
  assert.equal(usage.snapshot(key).allowed, 0);
});
