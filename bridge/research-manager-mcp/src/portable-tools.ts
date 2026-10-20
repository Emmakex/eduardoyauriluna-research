import type { McpServer } from '@modelcontextprotocol/server';
import type { SiteControlAdapter } from './adapters/site-control.js';
import { requireCapability } from './entitlements.js';

function toolResult(data:unknown){
  return {content:[{type:'text' as const,text:JSON.stringify(data,null,2)}],structuredContent:{data}};
}

type PortableSiteControlAdapter=SiteControlAdapter&{baseline?:()=>Promise<unknown>};

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
      const portable=adapter as PortableSiteControlAdapter;
      if(typeof portable.baseline!=='function'){
        throw new Error('Site Baseline Audit is unavailable for this adapter.');
      }
      return toolResult(await portable.baseline());
    },
  );
}
