import { McpServer } from '@modelcontextprotocol/server';
import type { SiteControlAdapter } from './adapters/site-control.js';
import { requireCapability } from './entitlements.js';

function toolResult(data: unknown) {
  return {
    content: [{ type: 'text' as const, text: JSON.stringify(data, null, 2) }],
    structuredContent: { data },
  };
}

async function withSiteRead(operation: () => Promise<unknown>) {
  requireCapability('site.read');
  return toolResult(await operation());
}

export function createBridgeServer(adapter: SiteControlAdapter): McpServer {
  const server = new McpServer(
    { name: 'site-control-mcp', version: '0.4.0' },
    {
      instructions:
        `This MCP endpoint is bound to the registered tenant ${adapter.tenantId} (${adapter.tenantLabel}) ` +
        `through the ${adapter.kind} adapter. Use only registered bounded tools. ` +
        'Every tool invocation is subject to tenant and principal commercial entitlements in addition to backend scopes. ' +
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
    async () => withSiteRead(() => adapter.status()),
  );

  server.registerTool(
    'research_versions',
    {
      title: 'Site versions',
      description: 'Read the active application, theme/runtime and API versions exposed by the registered adapter.',
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async () => withSiteRead(() => adapter.versions()),
  );

  server.registerTool(
    'research_capabilities',
    {
      title: 'Site capabilities',
      description: 'Read the authenticated bounded capability and granted-scope inventory for the bound tenant.',
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async () => withSiteRead(() => adapter.capabilities()),
  );

  return server;
}
