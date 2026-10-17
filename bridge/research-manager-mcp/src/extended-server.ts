import type { McpServer } from '@modelcontextprotocol/server';
import type { A3WriteAdapter } from './a3-contracts.js';
import { registerA3WriteTools } from './a3-write-tools.js';
import type { A4WriteAdapter } from './a4-contracts.js';
import { registerA4WriteTools } from './a4-write-tools.js';
import type { A5WriteAdapter } from './a5-contracts.js';
import { registerA5WriteTools } from './a5-write-tools.js';
import type { SiteControlAdapter } from './adapters/site-control.js';
import { registerResearchWriteTools } from './research-write-tools.js';
import { createBridgeServer as createBaseBridgeServer } from './server.js';

export function createBridgeServer(adapter:SiteControlAdapter):McpServer{
  const server=createBaseBridgeServer(adapter);
  registerResearchWriteTools(server,adapter);
  registerA3WriteTools(server,adapter as SiteControlAdapter&A3WriteAdapter);
  registerA4WriteTools(server,adapter as SiteControlAdapter&A4WriteAdapter);
  registerA5WriteTools(server,adapter as SiteControlAdapter&A5WriteAdapter);
  return server;
}
