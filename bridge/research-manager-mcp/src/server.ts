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
const postIdSchema = z.number().int().positive().max(Number.MAX_SAFE_INTEGER);
const researchObjectKindSchema = z.enum(['output', 'project', 'software', 'dataset']);

export function createBridgeServer(adapter: SiteControlAdapter): McpServer {
  const server = new McpServer(
    { name: 'site-control-mcp', version: '0.7.0' },
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
    { title: 'Site status', description: 'Read the authenticated status of the currently bound tenant through its registered adapter.', annotations: { readOnlyHint: true, openWorldHint: false } },
    async () => withCapability('site.read', () => adapter.status()),
  );

  server.registerTool(
    'research_versions',
    { title: 'Site versions', description: 'Read the active application, theme/runtime and API versions exposed by the registered adapter.', annotations: { readOnlyHint: true, openWorldHint: false } },
    async () => withCapability('site.read', () => adapter.versions()),
  );

  server.registerTool(
    'research_capabilities',
    { title: 'Site capabilities', description: 'Read the authenticated bounded capability and granted-scope inventory for the bound tenant.', annotations: { readOnlyHint: true, openWorldHint: false } },
    async () => withCapability('site.read', () => adapter.capabilities()),
  );

  server.registerTool(
    'site_pages_list',
    { title: 'List managed pages', description: 'List the registered Theme-owned page inventory and language-specific structured-slot availability for the bound tenant.', annotations: { readOnlyHint: true, openWorldHint: false } },
    async () => withCapability('pages.read', () => adapter.listPages()),
  );

  server.registerTool(
    'site_page_inspect',
    {
      title: 'Inspect managed page',
      description: 'Inspect one registered Theme-owned page in one enabled language. The page key and language must belong to the tenant contract.',
      inputSchema: z.object({ key: z.string().regex(/^[a-z0-9][a-z0-9-]{0,62}$/), language: languageSchema }),
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async ({ key, language }) => withCapability('pages.read', () => adapter.inspectPage({ key, language })),
  );

  server.registerTool(
    'site_insights_list',
    {
      title: 'List managed insights',
      description: 'List Research Insights managed by the bound tenant. Optionally restrict the inventory to one enabled language.',
      inputSchema: z.object({ language: languageSchema.optional() }),
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async ({ language }) => withCapability('insights.read', () => adapter.listInsights(language ? { language } : {})),
  );

  server.registerTool(
    'site_insight_inspect',
    {
      title: 'Inspect managed insight',
      description: 'Inspect one explicitly managed Research Insight by positive post ID.',
      inputSchema: z.object({ postId: postIdSchema }),
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async ({ postId }) => withCapability('insights.read', () => adapter.inspectInsight({ postId })),
  );

  server.registerTool(
    'site_research_objects_list',
    {
      title: 'List research objects',
      description: 'List managed Research Outputs, Projects, Software and Datasets, optionally filtered by one closed kind and/or enabled language.',
      inputSchema: z.object({ kind: researchObjectKindSchema.optional(), language: languageSchema.optional() }),
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async ({ kind, language }) =>
      withCapability('research.read', () => adapter.listResearchObjects({ ...(kind ? { kind } : {}), ...(language ? { language } : {}) })),
  );

  server.registerTool(
    'site_research_object_inspect',
    {
      title: 'Inspect research object',
      description: 'Inspect one managed Research Output, Project, Software or Dataset by closed kind and positive post ID.',
      inputSchema: z.object({ kind: researchObjectKindSchema, postId: postIdSchema }),
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async ({ kind, postId }) => withCapability('research.read', () => adapter.inspectResearchObject({ kind, postId })),
  );

  server.registerTool(
    'site_research_lines_list',
    {
      title: 'List research lines',
      description: 'List first-class Research Lines, optionally restricted to one enabled tenant language.',
      inputSchema: z.object({ language: languageSchema.optional() }),
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async ({ language }) => withCapability('research.read', () => adapter.listResearchLines(language ? { language } : {})),
  );

  server.registerTool(
    'site_research_line_inspect',
    {
      title: 'Inspect research line',
      description: 'Inspect one first-class Research Line by positive post ID.',
      inputSchema: z.object({ postId: postIdSchema }),
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async ({ postId }) => withCapability('research.read', () => adapter.inspectResearchLine({ postId })),
  );

  return server;
}
