import type { McpServer } from '@modelcontextprotocol/server';
import * as z from 'zod/v4';
import type { SiteControlAdapter } from './adapters/site-control.js';
import { requireCapability, type CommercialCapability } from './entitlements.js';

function toolResult(data:unknown){return{content:[{type:'text' as const,text:JSON.stringify(data,null,2)}],structuredContent:{data}};}
async function withCapability(capability:CommercialCapability,operation:()=>Promise<unknown>){requireCapability(capability);return toolResult(await operation());}

const languageSchema=z.string().regex(/^[a-z][a-z0-9-]{1,15}$/);
const postIdSchema=z.number().int().positive().max(Number.MAX_SAFE_INTEGER);
const statusSchema=z.enum(['draft','publish']);
const textSchema=z.string().max(12000);
const titleSchema=z.string().min(1).max(200);
const slugSchema=z.string().min(1).max(200);
const excerptSchema=z.string().max(1000);
const contentSchema=z.string().max(60000);
const shortTextSchema=z.string().max(1000);
const urlTextSchema=z.string().max(2000);
const stringListSchema=z.array(z.string().max(1000)).max(64);
const lineIdsSchema=z.array(postIdSchema).max(32).refine((ids)=>new Set(ids).size===ids.length,{message:'Research Line IDs must not contain duplicates'});
const evidenceFields={evidenceConfirmed:z.boolean().optional(),evidenceReference:z.string().max(512).optional()};
function evidenceRefine(value:{evidenceConfirmed?:boolean|undefined;evidenceReference?:string|undefined},ctx:z.RefinementCtx){
  if(value.evidenceConfirmed===true && !value.evidenceReference?.trim()) ctx.addIssue({code:'custom',message:'Evidence confirmation requires a non-empty evidence reference',path:['evidenceReference']});
}

const lineDataShape={
  title:titleSchema,
  slug:slugSchema.optional(),
  excerpt:excerptSchema.optional(),
  content:contentSchema.optional(),
  language:languageSchema.optional(),
  status:statusSchema.optional(),
  evidence_status:shortTextSchema.optional(),
  research_status:shortTextSchema.optional(),
  central_question:textSchema.optional(),
  order:shortTextSchema.optional(),
  topics:stringListSchema.optional(),
  methods:stringListSchema.optional(),
};
const lineCreateDataSchema=z.object(lineDataShape).strict();
const lineUpdateChangesSchema=z.object({
  title:titleSchema.optional(),slug:slugSchema.optional(),excerpt:excerptSchema.optional(),content:contentSchema.optional(),
  language:languageSchema.optional(),status:statusSchema.optional(),evidence_status:shortTextSchema.optional(),research_status:shortTextSchema.optional(),
  central_question:textSchema.optional(),order:shortTextSchema.optional(),topics:stringListSchema.optional(),methods:stringListSchema.optional(),
}).strict().refine((changes)=>Object.values(changes).some((value)=>value!==undefined),{message:'At least one bounded Research Line change is required'});
const linePlanIdSchema=z.string().regex(/^erm-line-plan-[a-f0-9]{20}-[a-f0-9]{8}$/);
const lineOperationIdSchema=z.string().regex(/^erm-line-op-[a-f0-9]{32}$/);

const objectBaseCreateShape={
  title:titleSchema,
  slug:slugSchema.optional(),
  excerpt:excerptSchema.optional(),
  content:contentSchema.optional(),
  language:languageSchema.optional(),
  status:statusSchema.optional(),
};
const outputCreateSchema=z.object({...objectBaseCreateShape,output_type:shortTextSchema.optional(),review_status:shortTextSchema.optional(),publication_date:z.string().max(32).optional(),venue:textSchema.optional(),doi:shortTextSchema.optional(),authors:stringListSchema.optional(),line_ids:lineIdsSchema.optional()}).strict();
const projectCreateSchema=z.object({...objectBaseCreateShape,project_status:shortTextSchema.optional(),question:textSchema.optional(),role:shortTextSchema.optional(),start_date:z.string().max(32).optional(),end_date:z.string().max(32).optional(),partner:textSchema.optional(),funding:textSchema.optional(),project_url:urlTextSchema.optional(),methods:stringListSchema.optional(),line_ids:lineIdsSchema.optional()}).strict();
const softwareCreateSchema=z.object({...objectBaseCreateShape,software_status:shortTextSchema.optional(),version:shortTextSchema.optional(),release_date:z.string().max(32).optional(),repository_url:urlTextSchema.optional(),archive_url:urlTextSchema.optional(),license:shortTextSchema.optional(),documentation_url:urlTextSchema.optional(),doi:shortTextSchema.optional(),programming_languages:stringListSchema.optional(),line_ids:lineIdsSchema.optional()}).strict();
const datasetCreateSchema=z.object({...objectBaseCreateShape,version:shortTextSchema.optional(),publication_date:z.string().max(32).optional(),repository:textSchema.optional(),doi:shortTextSchema.optional(),license:shortTextSchema.optional(),access_level:shortTextSchema.optional(),methodology:textSchema.optional(),provenance:textSchema.optional(),size:shortTextSchema.optional(),documentation_url:urlTextSchema.optional(),ethics_notes:textSchema.optional(),formats:stringListSchema.optional(),line_ids:lineIdsSchema.optional()}).strict();

const objectCreateInputSchema=z.discriminatedUnion('kind',[
  z.object({kind:z.literal('output'),data:outputCreateSchema,...evidenceFields}).strict().superRefine(evidenceRefine),
  z.object({kind:z.literal('project'),data:projectCreateSchema,...evidenceFields}).strict().superRefine(evidenceRefine),
  z.object({kind:z.literal('software'),data:softwareCreateSchema,...evidenceFields}).strict().superRefine(evidenceRefine),
  z.object({kind:z.literal('dataset'),data:datasetCreateSchema,...evidenceFields}).strict().superRefine(evidenceRefine),
]);

const outputChangesSchema=z.object({title:titleSchema.optional(),excerpt:excerptSchema.optional(),content:contentSchema.optional(),language:languageSchema.optional(),output_type:shortTextSchema.optional(),review_status:shortTextSchema.optional(),publication_date:z.string().max(32).optional(),venue:textSchema.optional(),doi:shortTextSchema.optional(),authors:stringListSchema.optional(),line_ids:lineIdsSchema.optional()}).strict().refine(nonEmpty,{message:'At least one bounded Research Output change is required'});
const projectChangesSchema=z.object({title:titleSchema.optional(),excerpt:excerptSchema.optional(),content:contentSchema.optional(),language:languageSchema.optional(),project_status:shortTextSchema.optional(),question:textSchema.optional(),role:shortTextSchema.optional(),start_date:z.string().max(32).optional(),end_date:z.string().max(32).optional(),partner:textSchema.optional(),funding:textSchema.optional(),project_url:urlTextSchema.optional(),methods:stringListSchema.optional(),line_ids:lineIdsSchema.optional()}).strict().refine(nonEmpty,{message:'At least one bounded Research Project change is required'});
const softwareChangesSchema=z.object({title:titleSchema.optional(),excerpt:excerptSchema.optional(),content:contentSchema.optional(),language:languageSchema.optional(),software_status:shortTextSchema.optional(),version:shortTextSchema.optional(),release_date:z.string().max(32).optional(),repository_url:urlTextSchema.optional(),archive_url:urlTextSchema.optional(),license:shortTextSchema.optional(),documentation_url:urlTextSchema.optional(),doi:shortTextSchema.optional(),programming_languages:stringListSchema.optional(),line_ids:lineIdsSchema.optional()}).strict().refine(nonEmpty,{message:'At least one bounded Research Software change is required'});
const datasetChangesSchema=z.object({title:titleSchema.optional(),excerpt:excerptSchema.optional(),content:contentSchema.optional(),language:languageSchema.optional(),version:shortTextSchema.optional(),publication_date:z.string().max(32).optional(),repository:textSchema.optional(),doi:shortTextSchema.optional(),license:shortTextSchema.optional(),access_level:shortTextSchema.optional(),methodology:textSchema.optional(),provenance:textSchema.optional(),size:shortTextSchema.optional(),documentation_url:urlTextSchema.optional(),ethics_notes:textSchema.optional(),formats:stringListSchema.optional(),line_ids:lineIdsSchema.optional()}).strict().refine(nonEmpty,{message:'At least one bounded Research Dataset change is required'});
function nonEmpty(value:Record<string,unknown>){return Object.values(value).some((entry)=>entry!==undefined);}

const objectUpdateInputSchema=z.discriminatedUnion('kind',[
  z.object({kind:z.literal('output'),postId:postIdSchema,changes:outputChangesSchema,...evidenceFields}).strict().superRefine(evidenceRefine),
  z.object({kind:z.literal('project'),postId:postIdSchema,changes:projectChangesSchema,...evidenceFields}).strict().superRefine(evidenceRefine),
  z.object({kind:z.literal('software'),postId:postIdSchema,changes:softwareChangesSchema,...evidenceFields}).strict().superRefine(evidenceRefine),
  z.object({kind:z.literal('dataset'),postId:postIdSchema,changes:datasetChangesSchema,...evidenceFields}).strict().superRefine(evidenceRefine),
]);
const objectPlanIdSchema=z.string().regex(/^erm-object-plan-[a-f0-9]{18}-[a-f0-9]{8}$/);
const objectOperationIdSchema=z.string().regex(/^erm-object-op-[a-f0-9]{32}$/);

export function registerResearchWriteTools(server:McpServer,adapter:SiteControlAdapter):void{
  server.registerTool('site_research_line_create_plan',{title:'Plan Research Line creation',description:'Create an exact stored Preview/Plan for one first-class Research Line. Evidence-sensitive creation remains blocked unless evidence is explicitly confirmed with a reference.',inputSchema:z.object({data:lineCreateDataSchema,...evidenceFields}).strict().superRefine(evidenceRefine),annotations:{readOnlyHint:false,destructiveHint:false,openWorldHint:false}},async({data,evidenceConfirmed,evidenceReference})=>withCapability('research.write',()=>adapter.planResearchLineCreate({data,...(evidenceConfirmed!==undefined?{evidenceConfirmed}:{}),...(evidenceReference!==undefined?{evidenceReference}:{})})));
  server.registerTool('site_research_line_update_plan',{title:'Plan Research Line update',description:'Create an exact stored Preview/Plan for bounded Research Line fields, including status, slug, evidence/research status, question, order, topics and methods.',inputSchema:z.object({postId:postIdSchema,changes:lineUpdateChangesSchema,...evidenceFields}).strict().superRefine(evidenceRefine),annotations:{readOnlyHint:false,destructiveHint:false,openWorldHint:false}},async({postId,changes,evidenceConfirmed,evidenceReference})=>withCapability('research.write',()=>adapter.planResearchLineUpdate({postId,changes,...(evidenceConfirmed!==undefined?{evidenceConfirmed}:{}),...(evidenceReference!==undefined?{evidenceReference}:{})})));
  server.registerTool('site_research_line_apply',{title:'Apply exact Research Line plan',description:'Apply one stored line-create or line-update plan by exact domain plan ID. Payload mutation at Apply time is impossible.',inputSchema:z.object({planId:linePlanIdSchema,confirm:z.literal(true)}),annotations:{readOnlyHint:false,destructiveHint:false,idempotentHint:true,openWorldHint:false}},async({planId,confirm})=>withCapability('research.write',()=>adapter.applyResearchLine({planId,confirm})));
  server.registerTool('site_research_line_verify',{title:'Verify Research Line operation',description:'Verify one applied Research Line operation against stored semantic state and optionally rendered public output.',inputSchema:z.object({operationId:lineOperationIdSchema,rendered:z.boolean()}),annotations:{readOnlyHint:false,destructiveHint:false,idempotentHint:true,openWorldHint:false}},async({operationId,rendered})=>withCapability('research.write',()=>adapter.verifyResearchLine({operationId,rendered})));
  server.registerTool('site_research_line_rollback',{title:'Rollback Research Line operation',description:'Restore the snapshot for one bounded Research Line create/update operation. Explicit confirmation is mandatory.',inputSchema:z.object({operationId:lineOperationIdSchema,confirm:z.literal(true)}),annotations:{readOnlyHint:false,destructiveHint:true,idempotentHint:true,openWorldHint:false}},async({operationId,confirm})=>withCapability('operations.rollback',()=>adapter.rollbackResearchLine({operationId,confirm})));

  server.registerTool('site_research_object_create_plan',{title:'Plan Research Object creation',description:'Create an exact stored Preview/Plan for one Output/Publication, Project, Research Software or Dataset using only the fields registered for that kind. Evidence-sensitive creation remains gated.',inputSchema:objectCreateInputSchema,annotations:{readOnlyHint:false,destructiveHint:false,openWorldHint:false}},async({kind,data,evidenceConfirmed,evidenceReference})=>withCapability('research.write',()=>adapter.planResearchObjectCreate({kind,data,...(evidenceConfirmed!==undefined?{evidenceConfirmed}:{}),...(evidenceReference!==undefined?{evidenceReference}:{})})));
  server.registerTool('site_research_object_update_plan',{title:'Plan Research Object update',description:'Create an exact stored Preview/Plan for one bounded Output, Project, Software or Dataset update. Object update deliberately excludes status and slug because the Manager contract does.',inputSchema:objectUpdateInputSchema,annotations:{readOnlyHint:false,destructiveHint:false,openWorldHint:false}},async({kind,postId,changes,evidenceConfirmed,evidenceReference})=>withCapability('research.write',()=>adapter.planResearchObjectUpdate({kind,postId,changes,...(evidenceConfirmed!==undefined?{evidenceConfirmed}:{}),...(evidenceReference!==undefined?{evidenceReference}:{})})));
  server.registerTool('site_research_object_apply',{title:'Apply exact Research Object plan',description:'Apply one stored object-create or object-update plan by exact domain plan ID. Exact confirmation is mandatory.',inputSchema:z.object({planId:objectPlanIdSchema,confirm:z.literal(true)}),annotations:{readOnlyHint:false,destructiveHint:false,idempotentHint:true,openWorldHint:false}},async({planId,confirm})=>withCapability('research.write',()=>adapter.applyResearchObject({planId,confirm})));
  server.registerTool('site_research_object_verify',{title:'Verify Research Object operation',description:'Verify one applied Output, Project, Software or Dataset operation against semantic state and optionally rendered public output.',inputSchema:z.object({operationId:objectOperationIdSchema,rendered:z.boolean()}),annotations:{readOnlyHint:false,destructiveHint:false,idempotentHint:true,openWorldHint:false}},async({operationId,rendered})=>withCapability('research.write',()=>adapter.verifyResearchObject({operationId,rendered})));
  server.registerTool('site_research_object_rollback',{title:'Rollback Research Object operation',description:'Restore the snapshot for one bounded Research Object create/update operation and preserve baseline restoration evidence.',inputSchema:z.object({operationId:objectOperationIdSchema,confirm:z.literal(true)}),annotations:{readOnlyHint:false,destructiveHint:true,idempotentHint:true,openWorldHint:false}},async({operationId,confirm})=>withCapability('operations.rollback',()=>adapter.rollbackResearchObject({operationId,confirm})));
}
