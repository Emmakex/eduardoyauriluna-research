import { McpServer } from '@modelcontextprotocol/server';
import type { SiteControlAdapter } from './adapters/site-control.js';
import { requireCapability } from './entitlements.js';
import { registerPortableTools } from './portable-tools.js';

function toolResult(data:unknown){return {content:[{type:'text' as const,text:JSON.stringify(data,null,2)}],structuredContent:{data}};}
async function withCapability(capability:'site.read',operation:()=>Promise<unknown>){requireCapability(capability);return toolResult(await operation());}

export function createGenericBridgeServer(adapter:SiteControlAdapter):McpServer{
  const server=new McpServer(
    {name:'site-control-mcp',version:'0.10.0'},
    {
      instructions:`This MCP endpoint is bound to the registered generic WordPress tenant ${adapter.tenantId} (${adapter.tenantLabel}) through the ${adapter.kind} adapter. The generic profile is read-only. Use only the registered status, versions, capabilities and Site Baseline Audit tools. Never infer or construct arbitrary WordPress, REST, database, filesystem or shell routes.`,
      capabilities:{tools:{listChanged:false}},
      maxToolInputElements:32,
    },
  );

  server.registerTool('site_status',{title:'Site status',description:'Read authenticated generic WordPress tenant status.',annotations:{readOnlyHint:true,openWorldHint:false}},async()=>withCapability('site.read',()=>adapter.status()));
  server.registerTool('site_versions',{title:'Site versions',description:'Read WordPress, PHP, Manager and active theme versions.',annotations:{readOnlyHint:true,openWorldHint:false}},async()=>withCapability('site.read',()=>adapter.versions()));
  server.registerTool('site_capabilities',{title:'Site capabilities',description:'Read the portable Manager capability inventory for this tenant.',annotations:{readOnlyHint:true,openWorldHint:false}},async()=>withCapability('site.read',()=>adapter.capabilities()));
  registerPortableTools(server,adapter);
  return server;
}
