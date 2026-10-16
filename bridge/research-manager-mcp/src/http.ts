import { timingSafeEqual } from 'node:crypto';
import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';
import { toNodeHandler } from '@modelcontextprotocol/node';
import { createMcpHandler } from '@modelcontextprotocol/server';
import { PrincipalRegistry, type PrincipalAccess } from './access-control.js';
import { ResearchManagerAdapter } from './adapters/research-manager.js';
import type { SiteControlAdapter } from './adapters/site-control.js';
import { loadConfig, type TenantConfig } from './config.js';
import { createBridgeServer } from './server.js';
import { TenantRegistry } from './tenant-registry.js';
import { accessAuditEvent, UsageBoundary } from './usage-boundary.js';

const config = loadConfig();
const registry = new TenantRegistry(config.tenants);
const principalRegistry = new PrincipalRegistry(config.principals);
const usage = new UsageBoundary(config.requestsPerMinute);

function createAdapter(selected: TenantConfig): SiteControlAdapter {
  switch (selected.adapter) {
    case 'research-manager':
      return new ResearchManagerAdapter(selected);
  }
}

function createTenantRuntime(selected: TenantConfig) {
  const adapter = createAdapter(selected);
  const mcp = createMcpHandler(() => createBridgeServer(adapter));
  const handle = toNodeHandler(mcp, {
    onerror(error) {
      const message = error instanceof Error ? error.message : 'unknown adapter error';
      console.error(`[mcp] adapter error tenant=${selected.id}: ${message}`);
    },
  });
  return { adapter, mcp, handle };
}

const runtimes = new Map(config.tenants.map((tenant) => [tenant.id, createTenantRuntime(tenant)] as const));

function hostnameFromHeader(value: string | undefined): string | null {
  if (!value) return null;
  try {
    return new URL(`http://${value}`).hostname.toLowerCase();
  } catch {
    return null;
  }
}

function allowedHost(req: IncomingMessage): boolean {
  if (config.allowedHosts.length === 0) return true;
  const hostname = hostnameFromHeader(req.headers.host);
  return hostname !== null && config.allowedHosts.includes(hostname);
}

function allowedOrigin(req: IncomingMessage): boolean {
  const origin = req.headers.origin;
  if (!origin) return true;
  if (config.allowedOriginHosts.length === 0) return false;
  try {
    return config.allowedOriginHosts.includes(new URL(origin).hostname.toLowerCase());
  } catch {
    return false;
  }
}

function secureEqual(left: string, right: string): boolean {
  const supplied = Buffer.from(left, 'utf8');
  const expected = Buffer.from(right, 'utf8');
  return supplied.length === expected.length && timingSafeEqual(supplied, expected);
}

function legacyPrincipal(req: IncomingMessage): PrincipalAccess | null {
  if (config.inboundBearerToken === null) {
    return {
      id: 'loopback-local',
      bearerToken: '',
      allowedTenantIds: [config.activeTenantId],
    };
  }
  const header = req.headers.authorization ?? '';
  if (!header.startsWith('Bearer ')) return null;
  if (!secureEqual(header.slice(7), config.inboundBearerToken)) return null;
  return {
    id: 'legacy-bearer',
    bearerToken: '',
    allowedTenantIds: [config.activeTenantId],
  };
}

function json(res: ServerResponse, status: number, body: Record<string, unknown>): void {
  const payload = JSON.stringify(body);
  res.statusCode = status;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store');
  res.end(payload);
}

function sharedTenantFromPath(pathname: string): string | null {
  const match = /^\/mcp\/([a-z0-9][a-z0-9-]{1,62})$/.exec(pathname);
  return match?.[1] ?? null;
}

function audit(principalId: string, tenantId: string, outcome: 'allowed' | 'denied' | 'rate_limited', path: string): void {
  console.error(`[mcp-access] ${accessAuditEvent({ principalId, tenantId, outcome, requestPath: path })}`);
}

const httpServer = createServer(async (req, res) => {
  let pathname = '/';
  try {
    pathname = new URL(req.url ?? '/', 'http://localhost').pathname;
  } catch {
    json(res, 400, { ok: false, code: 'invalid_url' });
    return;
  }

  if (pathname === '/healthz') {
    if (req.method !== 'GET') {
      json(res, 405, { ok: false, code: 'method_not_allowed' });
      return;
    }
    const body: Record<string, unknown> = {
      ok: true,
      service: 'site-control-mcp',
      version: '0.3.0',
      phase: 'tenant-isolation',
      routing: config.sharedRouting ? 'shared' : 'single-tenant',
    };
    if (!config.sharedRouting) body.tenant = registry.metadata(config.activeTenantId);
    json(res, 200, body);
    return;
  }

  if (!allowedHost(req) || !allowedOrigin(req)) {
    json(res, 403, { ok: false, code: 'request_origin_denied' });
    return;
  }

  let tenantId: string;
  let principal: PrincipalAccess | null;

  if (config.sharedRouting) {
    const requestedTenant = sharedTenantFromPath(pathname);
    if (!requestedTenant || !runtimes.has(requestedTenant)) {
      json(res, 404, { ok: false, code: 'not_found' });
      return;
    }
    tenantId = requestedTenant;
    principal = principalRegistry.authenticate(req.headers.authorization);
  } else {
    if (pathname !== '/mcp') {
      json(res, 404, { ok: false, code: 'not_found' });
      return;
    }
    tenantId = config.activeTenantId;
    principal = legacyPrincipal(req);
  }

  if (principal === null) {
    const key = { principalId: 'anonymous', tenantId };
    usage.deny(key);
    audit(key.principalId, tenantId, 'denied', pathname);
    res.setHeader('WWW-Authenticate', 'Bearer');
    json(res, 401, { ok: false, code: 'authentication_failed' });
    return;
  }

  if (config.sharedRouting && !principalRegistry.canAccess(principal, tenantId)) {
    usage.deny({ principalId: principal.id, tenantId });
    audit(principal.id, tenantId, 'denied', pathname);
    json(res, 403, { ok: false, code: 'tenant_access_denied' });
    return;
  }

  if (!usage.admit({ principalId: principal.id, tenantId })) {
    audit(principal.id, tenantId, 'rate_limited', pathname);
    res.setHeader('Retry-After', '60');
    json(res, 429, { ok: false, code: 'rate_limited' });
    return;
  }

  if (!req.method) {
    json(res, 400, { ok: false, code: 'method_required' });
    return;
  }
  if (!req.url) {
    json(res, 400, { ok: false, code: 'url_required' });
    return;
  }

  const runtime = runtimes.get(tenantId);
  if (!runtime) {
    json(res, 404, { ok: false, code: 'not_found' });
    return;
  }

  audit(principal.id, tenantId, 'allowed', pathname);
  try {
    const mcpRequest = req as IncomingMessage & { method: string; url: string };
    const originalUrl = mcpRequest.url;
    mcpRequest.url = '/mcp';
    try {
      await runtime.handle(mcpRequest, res);
    } finally {
      mcpRequest.url = originalUrl;
    }
  } catch (error) {
    const message = error instanceof Error ? error.message : 'unhandled request error';
    console.error(`[mcp] request failed tenant=${tenantId}: ${message}`);
    if (!res.headersSent) json(res, 500, { ok: false, code: 'bridge_error' });
    else res.end();
  }
});

httpServer.listen(config.port, config.bindHost, () => {
  const target = config.sharedRouting ? 'shared-tenants' : config.activeTenantId;
  console.error(`[mcp] site-control bridge target=${target} listening on http://${config.bindHost}:${config.port}/mcp`);
});

async function shutdown(signal: string): Promise<void> {
  console.error(`[mcp] ${signal}; shutting down`);
  httpServer.close();
  await Promise.all([...runtimes.values()].map(async (runtime) => runtime.mcp.close()));
}

process.once('SIGINT', () => void shutdown('SIGINT'));
process.once('SIGTERM', () => void shutdown('SIGTERM'));
