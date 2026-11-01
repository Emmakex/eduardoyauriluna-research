import { randomBytes, randomUUID } from 'node:crypto';
import type { TenantConfig } from './config.js';
import { ResearchManagerError } from './manager-client.js';
import type { LegalContentApplyRequest,LegalContentPlanRequest,LegalContentRollbackRequest,LegalContentVerifyRequest } from './a8-contracts.js';

type FetchLike=(input:string|URL|Request,init?:RequestInit)=>Promise<Response>;
interface ManagerEnvelope { ok?:boolean; code?:string; message?:string; data?:unknown; }
type Config=Pick<TenantConfig,'managerBaseUrl'|'managerWriteToken'|'managerTimeoutMs'>;
type A8Route =
  | `/legal/${string}/${string}/plan`
  | `/legal/plans/${string}`
  | `/legal/plans/${string}/apply`
  | `/legal/operations/${string}`
  | `/legal/operations/${string}/verify`
  | `/legal/operations/${string}/rollback`;

export class ResearchManagerA8WriteClient {
  private readonly baseUrl:URL;
  private readonly token:string|null;
  private readonly timeoutMs:number;
  private readonly fetchImpl:FetchLike;
  constructor(config:Config,fetchImpl:FetchLike=fetch){this.baseUrl=config.managerBaseUrl;this.token=config.managerWriteToken;this.timeoutMs=config.managerTimeoutMs;this.fetchImpl=fetchImpl;}

  plan(request:LegalContentPlanRequest):Promise<unknown>{
    this.requireKey(request.key);this.requireLanguage(request.language);this.requireSections(request.sections);this.requireReason(request.reason);
    return this.post(`/legal/${request.key}/${request.language}/plan`,{sections:request.sections,...(request.reason?{reason:request.reason}:{})},'legal-content-plan');
  }
  apply(request:LegalContentApplyRequest):Promise<unknown>{
    this.requirePlanId(request.planId);if(request.confirm!==true)throw new Error('Legal content Apply requires explicit confirmation');
    return this.get(`/legal/plans/${encodeURIComponent(request.planId)}`,'legal-content-apply-preflight').then((plan)=>{this.requireOperation(plan,'legal-content-update','Legal content plan');return this.post(`/legal/plans/${encodeURIComponent(request.planId)}/apply`,{confirm:true},'legal-content-apply');});
  }
  verify(request:LegalContentVerifyRequest):Promise<unknown>{
    this.requireOperationId(request.operationId);if(request.rendered!==true)throw new Error('Legal content Verify requires rendered verification');
    return this.get(`/legal/operations/${encodeURIComponent(request.operationId)}`,'legal-content-verify-preflight').then((operation)=>{this.requireOperation(operation,'legal-content-update','Legal content operation');return this.post(`/legal/operations/${encodeURIComponent(request.operationId)}/verify`,{rendered:true},'legal-content-verify');});
  }
  rollback(request:LegalContentRollbackRequest):Promise<unknown>{
    this.requireOperationId(request.operationId);if(request.confirm!==true)throw new Error('Legal content Rollback requires explicit confirmation');
    return this.get(`/legal/operations/${encodeURIComponent(request.operationId)}`,'legal-content-rollback-preflight').then((operation)=>{this.requireOperation(operation,'legal-content-update','Legal content operation');return this.post(`/legal/operations/${encodeURIComponent(request.operationId)}/rollback`,{confirm:true},'legal-content-rollback');});
  }

  private requireKey(value:string):void{if(value!=='privacy-policy'&&value!=='legal-notice')throw new Error('Legal content key is outside the bounded contract');}
  private requireLanguage(value:string):void{if(value!=='en'&&value!=='es')throw new Error('Legal content language must be en or es');}
  private requireSections(sections:{title:string;body:string}[]):void{
    if(!Array.isArray(sections)||sections.length<2||sections.length>6)throw new Error('Legal content requires between 2 and 6 sections');
    for(const section of sections){if(!section||typeof section.title!=='string'||typeof section.body!=='string')throw new Error('Legal sections require title and body');if(!section.title.trim()||section.title.length>120)throw new Error('Legal section title is outside bounds');if(section.body.trim().length<45||section.body.length>4000)throw new Error('Legal section body is outside bounds');if(/[<>]/.test(section.title)||/[<>]/.test(section.body))throw new Error('Legal content accepts plain text only');}
  }
  private requireReason(value:string|undefined):void{if(value!==undefined&&value.length>256)throw new Error('Legal content reason must be 256 characters or fewer');}
  private requirePlanId(value:string):void{if(!/^erm-legal-plan-[a-f0-9]{20}-[a-f0-9]{8}$/.test(value))throw new Error('Legal content plan ID has an invalid format');}
  private requireOperationId(value:string):void{if(!/^erm-legal-op-[a-f0-9]{32}$/.test(value))throw new Error('Legal content operation ID has an invalid format');}
  private requireOperation(value:unknown,expected:string,label:string):void{if(typeof value!=='object'||value===null||Array.isArray(value)||(value as Record<string,unknown>).operation!==expected)throw new Error(`${label} does not reference ${expected}`);}
  private get(route:A8Route,label:string):Promise<unknown>{return this.request(route,'GET',undefined,label);}
  private post(route:A8Route,body:unknown,label:string):Promise<unknown>{return this.request(route,'POST',body,label);}
  private async request(route:A8Route,method:'GET'|'POST',body:unknown|undefined,label:string):Promise<unknown>{
    if(!this.token)throw new Error('Research Manager write credential is not configured for this tenant');
    const endpoint=new URL(`/wp-json/research-manager/v1${route}`,this.baseUrl);const requestId=`mcp-${label}-${Date.now()}-${randomUUID()}`;
    const headers=new Headers({Accept:'application/json',Authorization:`Bearer ${this.token}`,'User-Agent':'Site-Control-MCP/0.10.0 ResearchManagerAdapter','X-Research-Manager-Request-Id':requestId,'X-Research-Manager-Nonce':randomBytes(16).toString('hex'),'X-Research-Manager-Timestamp':Math.floor(Date.now()/1000).toString()});
    if(body!==undefined)headers.set('Content-Type','application/json');let response:Response;
    try{response=await this.fetchImpl(endpoint,{method,headers,...(body!==undefined?{body:JSON.stringify(body)}:{}),redirect:'error',signal:AbortSignal.timeout(this.timeoutMs)});}
    catch(error){const detail=error instanceof Error?error.message:'network failure';throw new ResearchManagerError(`Research Manager request failed: ${detail}`,{status:null,code:'transport_error',requestId});}
    const raw=await response.text();let envelope:ManagerEnvelope={};try{const parsed:unknown=raw?JSON.parse(raw):{};if(typeof parsed==='object'&&parsed!==null)envelope=parsed as ManagerEnvelope;}catch{throw new ResearchManagerError(`Research Manager returned invalid JSON (HTTP ${response.status})`,{status:response.status,code:'invalid_json',requestId});}
    if(!response.ok||envelope.ok!==true||!('data'in envelope))throw new ResearchManagerError(`Research Manager rejected ${route} with HTTP ${response.status}: ${envelope.code??'request_failed'}`,{status:response.status,code:envelope.code??'request_failed',requestId});
    return envelope.data;
  }
}
