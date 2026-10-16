import { McpServer } from '@modelcontextprotocol/server';
import * as z from 'zod/v4';
import type { SiteControlAdapter } from './adapters/site-control.js';
import { requireCapability, type CommercialCapability } from './entitlements.js';

function toolResult(data: unknown) {
  return {
    content: [{ type: 'text' as const, text: JSON.stringify(data, null, 2) }],
    structuredContent: { data },
  };
}

async function withCapability(capability: CommercialCapability, operation: () => Promise<unknown>) {
  requireCapability(capability);
  return toolResult(await operation());
}

const languageSchema = z.string().regex(/^[a-z][a-z0-9-]{1,15}$/);

export function createBridgeServer(adapter: SiteControlAdapter): McpServer {
  const server = new McpServer(
    { name: 'site-control-mcp', version: '0.6.0' },
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
    async () => withCapability('site.read', () => adapter.status()),
  );

  server.registerTool(
    'research_versions',
    {
      title: 'Site versions',
      description: 'Read the active application, theme/runtime and API versions exposed by the registered adapter.',
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async () => withCapability('site.read', () => adapter.versions()),
  );

  server.registerTool(
    'research_capabilities',
    {
      title: 'Site capabilities',
      description: 'Read the authenticated bounded capability and granted-scope inventory for the bound tenant.',
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async () => withCapability('site.read', () => adapter.capabilities()),
  );

  server.registerTool(
    'site_pages_list',
    {
      title: 'List managed pages',
      description:
        'List the registered Theme-owned page inventory and language-specific structured-slot availability for the bound tenant.',
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async () => withCapability('pages.read', () => adapter.listPages()),
  );

  server.registerTool(
    'site_page_inspect',
    {
      title: 'Inspect managed page',
      description:
        'Inspect one registered Theme-owned page in one enabled language. The page key and language must belong to the tenant contract.',
      inputSchema: z.object({
        key: z.string().regex(/^[a-z0-9][a-z0-9-]{0,62}$/).describe('Registered page key, for example home or about'),
        language: languageSchema.describe('Enabled lowercase language code, for example en or es'),
      }),
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async ({ key, language }) => withCapability('pages.read', () => adapter.inspectPage({ key, language })),
  );

  server.registerTool(
    'site_insights_list',
    {
      title: 'List managed insights',
      description:
        'List Research Insights managed by the bound tenant. Optionally restrict the inventory to one enabled language.',
      inputSchema: z.object({
        language: languageSchema.optional().describe('Optional enabled lowercase language code, for example en or es'),
      }),
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async ({ language }) =>
      withCapability('insights.read', () => adapter.listInsights(language ? { language } : {})),
  );

  server.registerTool(
    'site_insight_inspect',
    {
      title: 'Inspect managed insight',
      description:
        'Inspect one explicitly managed Research Insight by its positive WordPress post ID. Unmanaged posts are rejected by the adapter backend.',
      inputSchema: z.object({
        postId: z.number().int().positive().max(Number.MAX_SAFE_INTEGER).describe('Positive managed Insight post ID'),
      }),
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async ({ postId }) => withCapability('insights.read', () => adapter.inspectInsight({ postId })),
  );

  return server;
}
