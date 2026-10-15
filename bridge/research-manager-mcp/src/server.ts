import { McpServer } from '@modelcontextprotocol/server';
import type { ResearchManagerClient } from './manager-client.js';

function toolResult(data: unknown) {
  return {
    content: [{ type: 'text' as const, text: JSON.stringify(data, null, 2) }],
    structuredContent: { data },
  };
}

export function createBridgeServer(client: ResearchManagerClient): McpServer {
  const server = new McpServer(
    { name: 'eduardo-research-manager', version: '0.1.0' },
    {
      instructions:
        'This server controls the single-site Research Manager for eduardoyauriluna.com. ' +
        'Use only the registered bounded tools. Never infer or construct arbitrary WordPress or REST routes. ' +
        'Phase 1 is read-only.',
      capabilities: { tools: { listChanged: false } },
      maxToolInputElements: 32,
    },
  );

  server.registerTool(
    'research_status',
    {
      title: 'Research site status',
      description: 'Read the authenticated Research Manager site status for eduardoyauriluna.com.',
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async () => toolResult(await client.status()),
  );

  server.registerTool(
    'research_versions',
    {
      title: 'Research site versions',
      description: 'Read the active Research Manager, Research Theme, and API versions.',
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async () => toolResult(await client.versions()),
  );

  server.registerTool(
    'research_capabilities',
    {
      title: 'Research Manager capabilities',
      description: 'Read the authenticated bounded capability and granted-scope inventory.',
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async () => toolResult(await client.capabilities()),
  );

  return server;
}
