import type { McpServer } from '@modelcontextprotocol/server';
import * as z from 'zod/v4';
import type { A6WriteAdapter, NavigationTarget } from './a6-contracts.js';
import { requireCapability, type CommercialCapability } from './entitlements.js';

function toolResult(data:unknown){return{content:[{type:'text' as const,text:JSON.stringify(data,null,2)}],structuredContent:{data}};}
async function withCapability(capability:CommercialCapability,operation:()=>Promise<unknown>){requireCapability(capability);return toolResult(await operation());}

const locationSchema=z.enum(['primary','footer']);
const targetSchema=z.enum(['home','about','research','publications','projects','software','datasets','cv','insights','contact','privacy-policy','legal-notice']);
const itemsSchema=z.array(targetSchema).min(1).max(12).refine((items)=>new Set(items).size===items.length,'navigation targets must be unique');
const planIdSchema=z.string().regex(/^erm-nav-plan-[a-f0-9]{18}-[a-f0-9]{8}$/);
const operationIdSchema=z.string().regex(/^erm-nav-op-[a-f0-9]{32}$/);

export function registerA6WriteTools(server:McpServer,adapter:A6WriteAdapter):void{
  server.registerTool('site_navigation_update_plan',{title:'Plan Research Theme navigation update',description:'Plan an exact navigation state for a registered Research Theme location using only registered Theme page targets. Arbitrary URLs and generic WordPress menu payloads are not accepted.',inputSchema:z.object({location:locationSchema,items:itemsSchema,intent:z.string().max(256).optional()}).strict(),annotations:{readOnlyHint:false,destructiveHint:false,openWorldHint:false}},async({location,items,intent})=>withCapability('navigation.write',()=>adapter.planNavigationUpdate({location,items:items as NavigationTarget[],...(intent!==undefined?{intent}:{})})));
  server.registerTool('site_navigation_apply',{title:'Apply exact Research Theme navigation plan',description:'Apply one exact stored navigation-update plan after stale-revision and connection-ownership checks. The planned targets cannot be altered at Apply time.',inputSchema:z.object({planId:planIdSchema,confirm:z.literal(true)}).strict(),annotations:{readOnlyHint:false,destructiveHint:false,idempotentHint:true,openWorldHint:false}},async({planId,confirm})=>withCapability('navigation.write',()=>adapter.applyNavigation({planId,confirm})));
  server.registerTool('site_navigation_verify',{title:'Verify rendered Research Theme navigation',description:'Verify one navigation-update operation against exact stored state, revision and the rendered language-aware primary/footer links. Rendered verification is mandatory.',inputSchema:z.object({operationId:operationIdSchema,rendered:z.literal(true)}).strict(),annotations:{readOnlyHint:false,destructiveHint:false,idempotentHint:true,openWorldHint:false}},async({operationId,rendered})=>withCapability('navigation.write',()=>adapter.verifyNavigation({operationId,rendered})));
  server.registerTool('site_navigation_rollback',{title:'Rollback Research Theme navigation',description:'Restore the exact navigation baseline for one navigation-update operation, subject to connection ownership and stale-revision protection.',inputSchema:z.object({operationId:operationIdSchema,confirm:z.literal(true)}).strict(),annotations:{readOnlyHint:false,destructiveHint:true,idempotentHint:true,openWorldHint:false}},async({operationId,confirm})=>withCapability('operations.rollback',()=>adapter.rollbackNavigation({operationId,confirm})));
}
