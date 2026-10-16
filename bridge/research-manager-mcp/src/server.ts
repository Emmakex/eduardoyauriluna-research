import { McpServer } from '@modelcontextprotocol/server';
import type { SiteControlAdapter } from './adapters/site-control.js';

function toolResult(data: unknown) {
  return {
    content: [{ type: 'text' as const, text: JSON.stringify(data, null, 2) }],
    structuredContent: { data },
  };
}

export function createBridgeServer(adapter: SiteControlAdapter): McpServer {
  const server = new McpServer(
    { name: 'site-control-mcp', version: '0.3.0' },
    {
      instructions:
        `This MCP endpoint is bound to the registered tenant ${adapter.tenantId} (${adapter.tenantLabel}) ` +
        `through the ${adapter.kind} adapter. Use only registered bounded tools. ` +
        'Never infer or construct arbitrary WordPress, REST, database, filesystem or shell routes. ' +
        'The current surface is read-only.',
      capabilities: { tools: { listChanged: false } },
      maxToolInputElements: 32,
    },
  );

  server.registerTool(
    'research_status',
    {
      title: 'Site status',
      description: 'Read the authenticated status of the currently bound tenant through its registered adapter.',
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async () => toolResult(await adapter.status()),
  );

  server.registerTool(
    'research_versions',
    {
      title: 'Site versions',
      description: 'Read the active application, theme/runtime and API versions exposed by the registered adapter.',
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async () => toolResult(await adapter.versions()),
  );

  server.registerTool(
    'research_capabilities',
    {
      title: 'Site capabilities',
      description: 'Read the authenticated bounded capability and granted-scope inventory for the bound tenant.',
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async () => toolResult(await adapter.capabilities()),
  );

  return server;
}
