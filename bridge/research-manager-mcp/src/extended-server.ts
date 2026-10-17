import type { McpServer } from '@modelcontextprotocol/server';
import type { SiteControlAdapter } from './adapters/site-control.js';
import { registerResearchWriteTools } from './research-write-tools.js';
import { createBridgeServer as createBaseBridgeServer } from './server.js';

export function createBridgeServer(adapter:SiteControlAdapter):McpServer{
  const server=createBaseBridgeServer(adapter);
  registerResearchWriteTools(server,adapter);
  return server;
}
