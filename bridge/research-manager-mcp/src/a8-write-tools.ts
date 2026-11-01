import type { McpServer } from '@modelcontextprotocol/server';
import * as z from 'zod/v4';
import { requireCapability } from './entitlements.js';
import type { A8WriteAdapter } from './a8-contracts.js';

function toolResult(data:unknown){return{content:[{type:'text' as const,text:JSON.stringify(data,null,2)}],structuredContent:{data}};}
async function withCapability(capability:'pages.write'|'operations.rollback',operation:()=>Promise<unknown>){requireCapability(capability);return toolResult(await operation());}

const keySchema=z.enum(['privacy-policy','legal-notice']);
const languageSchema=z.enum(['en','es']);
const sectionSchema=z.object({title:z.string().min(1).max(120).regex(/^[^<>]+$/),body:z.string().min(45).max(4000).regex(/^[^<>]+$/)}).strict();
const sectionsSchema=z.array(sectionSchema).min(2).max(6);
const planIdSchema=z.string().regex(/^erm-legal-plan-[a-f0-9]{20}-[a-f0-9]{8}$/);
const operationIdSchema=z.string().regex(/^erm-legal-op-[a-f0-9]{32}$/);

export function registerA8WriteTools(server:McpServer,adapter:A8WriteAdapter):void{
  server.registerTool('site_legal_content_plan',{title:'Plan legal Page content update',description:'Create an exact stored Preview for bounded EN/ES Privacy or Legal Notice sections. Plain text only; this tool never applies the change.',inputSchema:z.object({key:keySchema,language:languageSchema,sections:sectionsSchema,reason:z.string().max(256).optional()}).strict(),annotations:{readOnlyHint:false,destructiveHint:false,openWorldHint:false}},async({key,language,sections,reason})=>withCapability('pages.write',()=>adapter.planLegalContent({key,language,sections,...(reason?{reason}:{})})));
  server.registerTool('site_legal_content_apply',{title:'Apply exact legal content plan',description:'Apply one stored legal-content Preview by exact plan ID. Explicit confirmation is mandatory.',inputSchema:z.object({planId:planIdSchema,confirm:z.literal(true)}).strict(),annotations:{readOnlyHint:false,destructiveHint:false,idempotentHint:true,openWorldHint:false}},async({planId,confirm})=>withCapability('pages.write',()=>adapter.applyLegalContent({planId,confirm})));
  server.registerTool('site_legal_content_verify',{title:'Verify legal content operation',description:'Verify exact stored state and rendered public output for one applied legal-content operation.',inputSchema:z.object({operationId:operationIdSchema,rendered:z.literal(true)}).strict(),annotations:{readOnlyHint:false,destructiveHint:false,idempotentHint:true,openWorldHint:false}},async({operationId,rendered})=>withCapability('pages.write',()=>adapter.verifyLegalContent({operationId,rendered})));
  server.registerTool('site_legal_content_rollback',{title:'Rollback legal content operation',description:'Restore the exact pre-Apply Privacy or Legal Notice snapshot. Explicit confirmation is mandatory.',inputSchema:z.object({operationId:operationIdSchema,confirm:z.literal(true)}).strict(),annotations:{readOnlyHint:false,destructiveHint:true,idempotentHint:true,openWorldHint:false}},async({operationId,confirm})=>withCapability('operations.rollback',()=>adapter.rollbackLegalContent({operationId,confirm})));
}
