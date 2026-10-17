import type { McpServer } from '@modelcontextprotocol/server';
import * as z from 'zod/v4';
import type { A4WriteAdapter } from './a4-contracts.js';
import { requireCapability, type CommercialCapability } from './entitlements.js';

function toolResult(data:unknown){return{content:[{type:'text' as const,text:JSON.stringify(data,null,2)}],structuredContent:{data}};}
async function withCapability(capability:CommercialCapability,operation:()=>Promise<unknown>){requireCapability(capability);return toolResult(await operation());}

const checkIdSchema=z.string().regex(/^[a-z0-9][a-z0-9_-]{0,127}$/);
const remediationPlanIdSchema=z.string().regex(/^erm-(?:seo-plan-[a-f0-9]{24}|indexability-plan-[a-f0-9]{18}-[a-f0-9]{8})$/);
const remediationOperationIdSchema=z.string().regex(/^erm-(?:seo-op|indexability-op)-[a-f0-9]{32}$/);

export function registerA4WriteTools(server:McpServer,adapter:A4WriteAdapter):void{
  server.registerTool('site_seo_geo_remediation_plan',{title:'Plan SEO/GEO remediation',description:'Create an exact stored remediation plan only for a diagnostic check that currently exists and is classified auto-remediable by Research Manager. This never exposes arbitrary SEO metadata, HTML or WordPress options.',inputSchema:z.object({checkId:checkIdSchema,intent:z.string().max(256).optional()}).strict(),annotations:{readOnlyHint:false,destructiveHint:false,openWorldHint:false}},async({checkId,intent})=>withCapability('seo.write',()=>adapter.planSeoGeoRemediation({checkId,...(intent!==undefined?{intent}:{})})));
  server.registerTool('site_seo_geo_remediation_apply',{title:'Apply exact SEO/GEO remediation',description:'Apply one previously stored seo-remediate or search-indexability plan by exact domain plan ID. The plan payload cannot be changed at Apply time.',inputSchema:z.object({planId:remediationPlanIdSchema,confirm:z.literal(true)}).strict(),annotations:{readOnlyHint:false,destructiveHint:false,idempotentHint:true,openWorldHint:false}},async({planId,confirm})=>withCapability('seo.write',()=>adapter.applySeoGeoRemediation({planId,confirm})));
  server.registerTool('site_seo_geo_remediation_verify',{title:'Verify SEO/GEO remediation',description:'Verify one applied bounded remediation against stored state and optionally rendered SEO/GEO output. The operation must belong to seo-remediate or search-indexability.',inputSchema:z.object({operationId:remediationOperationIdSchema,rendered:z.boolean()}).strict(),annotations:{readOnlyHint:false,destructiveHint:false,idempotentHint:true,openWorldHint:false}},async({operationId,rendered})=>withCapability('seo.write',()=>adapter.verifySeoGeoRemediation({operationId,rendered})));
  server.registerTool('site_seo_geo_remediation_rollback',{title:'Rollback SEO/GEO remediation',description:'Restore the exact baseline for one bounded seo-remediate or search-indexability operation. Explicit confirmation is mandatory.',inputSchema:z.object({operationId:remediationOperationIdSchema,confirm:z.literal(true)}).strict(),annotations:{readOnlyHint:false,destructiveHint:true,idempotentHint:true,openWorldHint:false}},async({operationId,confirm})=>withCapability('operations.rollback',()=>adapter.rollbackSeoGeoRemediation({operationId,confirm})));
}
