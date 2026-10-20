import type { McpServer } from '@modelcontextprotocol/server';
import type { SiteControlAdapter } from './adapters/site-control.js';
import { requireCapability } from './entitlements.js';

function toolResult(data:unknown){
  return {content:[{type:'text' as const,text:JSON.stringify(data,null,2)}],structuredContent:{data}};
}

function record(value:unknown):Record<string,unknown>|null{
  return typeof value==='object'&&value!==null&&!Array.isArray(value)?value as Record<string,unknown>:null;
}

export function registerPortableTools(server:McpServer,adapter:SiteControlAdapter):void{
  server.registerTool(
    'site_baseline_audit',
    {
      title:'Site Baseline Audit',
      description:'Run the portable read-only WordPress baseline exposed by Manager 0.11. Returns internal WordPress evidence and explicitly marks external network/performance/accessibility/search measurements as not_measured until their probes are connected.',
      annotations:{readOnlyHint:true,openWorldHint:false},
    },
    async()=>{
      requireCapability('diagnostics.read');
      const capabilities=record(await adapter.capabilities());
      const baseline=capabilities?.site_baseline;
      if(baseline===undefined){
        throw new Error('Site Baseline Audit is unavailable. The Manager tenant credential must grant site.diagnostics and run a portable-capable Manager version.');
      }
      return toolResult(baseline);
    },
  );
}
