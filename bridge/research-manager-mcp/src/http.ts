import { timingSafeEqual } from 'node:crypto';
import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';
import { toNodeHandler } from '@modelcontextprotocol/node';
import { createMcpHandler } from '@modelcontextprotocol/server';
import { loadConfig } from './config.js';
import { ResearchManagerClient } from './manager-client.js';
import { createBridgeServer } from './server.js';

const config = loadConfig();
const manager = new ResearchManagerClient(config);
const mcp = createMcpHandler(() => createBridgeServer(manager));
const handleMcp = toNodeHandler(mcp, {
  onerror(error) {
    const message = error instanceof Error ? error.message : 'unknown adapter error';
    console.error(`[mcp] adapter error: ${message}`);
  },
});

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

function inboundAuthorized(req: IncomingMessage): boolean {
  if (config.inboundBearerToken === null) return true;
  const header = req.headers.authorization ?? '';
  if (!header.startsWith('Bearer ')) return false;
  const supplied = Buffer.from(header.slice(7), 'utf8');
  const expected = Buffer.from(config.inboundBearerToken, 'utf8');
  return supplied.length === expected.length && timingSafeEqual(supplied, expected);
}

function json(res: ServerResponse, status: number, body: Record<string, unknown>): void {
  const payload = JSON.stringify(body);
  res.statusCode = status;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store');
  res.end(payload);
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
    json(res, 200, { ok: true, service: 'research-manager-mcp', version: '0.1.0', phase: 'read-only' });
    return;
  }

  if (pathname !== '/mcp') {
    json(res, 404, { ok: false, code: 'not_found' });
    return;
  }
  if (!allowedHost(req) || !allowedOrigin(req)) {
    json(res, 403, { ok: false, code: 'request_origin_denied' });
    return;
  }
  if (!inboundAuthorized(req)) {
    res.setHeader('WWW-Authenticate', 'Bearer');
    json(res, 401, { ok: false, code: 'authentication_failed' });
    return;
  }

  try {
    await handleMcp(req, res);
  } catch (error) {
    const message = error instanceof Error ? error.message : 'unhandled request error';
    console.error(`[mcp] request failed: ${message}`);
    if (!res.headersSent) json(res, 500, { ok: false, code: 'bridge_error' });
    else res.end();
  }
});

httpServer.listen(config.port, config.bindHost, () => {
  console.error(`[mcp] research-manager bridge listening on http://${config.bindHost}:${config.port}/mcp`);
});

async function shutdown(signal: string): Promise<void> {
  console.error(`[mcp] ${signal}; shutting down`);
  httpServer.close();
  await mcp.close();
}

process.once('SIGINT', () => void shutdown('SIGINT'));
process.once('SIGTERM', () => void shutdown('SIGTERM'));
