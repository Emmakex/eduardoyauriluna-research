import { randomBytes, randomUUID } from 'node:crypto';
import type { TenantConfig } from './config.js';
import { ResearchManagerError } from './manager-client.js';
import type {
  SeoGeoRemediationApplyRequest,
  SeoGeoRemediationPlanRequest,
  SeoGeoRemediationRollbackRequest,
  SeoGeoRemediationVerifyRequest,
} from './a4-contracts.js';

type FetchLike=(input:string|URL|Request,init?:RequestInit)=>Promise<Response>;
interface ManagerEnvelope { ok?:boolean; code?:string; message?:string; data?:unknown; }
type Config=Pick<TenantConfig,'managerBaseUrl'|'managerWriteToken'|'managerTimeoutMs'>;
type A4Route =
  | '/seo-geo/remediation/plan'
  | `/seo-geo/plans/${string}`
  | `/seo-geo/plans/${string}/apply`
  | `/seo-geo/operations/${string}`
  | `/seo-geo/operations/${string}/verify`
  | `/seo-geo/operations/${string}/rollback`;

export class ResearchManagerA4WriteClient {
  private readonly baseUrl:URL;
  private readonly token:string|null;
  private readonly timeoutMs:number;
  private readonly fetchImpl:FetchLike;

  constructor(config:Config,fetchImpl:FetchLike=fetch){
    this.baseUrl=config.managerBaseUrl;
    this.token=config.managerWriteToken;
    this.timeoutMs=config.managerTimeoutMs;
    this.fetchImpl=fetchImpl;
  }

  plan(request:SeoGeoRemediationPlanRequest):Promise<unknown>{
    this.requireCheckId(request.checkId);
    if(request.intent!==undefined&&request.intent.length>256)throw new Error('SEO/GEO remediation intent must be 256 characters or fewer');
    return this.post('/seo-geo/remediation/plan',{check_id:request.checkId,...(request.intent?{intent:request.intent}:{})},'seo-geo-remediation-plan');
  }

  apply(request:SeoGeoRemediationApplyRequest):Promise<unknown>{
    this.requirePlanId(request.planId);
    if(request.confirm!==true)throw new Error('SEO/GEO remediation Apply requires explicit confirmation');
    return this.get(`/seo-geo/plans/${encodeURIComponent(request.planId)}`,'seo-geo-remediation-apply-preflight').then((plan)=>{
      this.requireOperation(plan,['seo-remediate','search-indexability'],'SEO/GEO remediation plan');
      return this.post(`/seo-geo/plans/${encodeURIComponent(request.planId)}/apply`,{confirm:true},'seo-geo-remediation-apply');
    });
  }

  verify(request:SeoGeoRemediationVerifyRequest):Promise<unknown>{
    this.requireOperationId(request.operationId);
    return this.get(`/seo-geo/operations/${encodeURIComponent(request.operationId)}`,'seo-geo-remediation-verify-preflight').then((operation)=>{
      this.requireOperation(operation,['seo-remediate','search-indexability'],'SEO/GEO remediation operation');
      return this.post(`/seo-geo/operations/${encodeURIComponent(request.operationId)}/verify`,{rendered:request.rendered},'seo-geo-remediation-verify');
    });
  }

  rollback(request:SeoGeoRemediationRollbackRequest):Promise<unknown>{
    this.requireOperationId(request.operationId);
    if(request.confirm!==true)throw new Error('SEO/GEO remediation Rollback requires explicit confirmation');
    return this.get(`/seo-geo/operations/${encodeURIComponent(request.operationId)}`,'seo-geo-remediation-rollback-preflight').then((operation)=>{
      this.requireOperation(operation,['seo-remediate','search-indexability'],'SEO/GEO remediation operation');
      return this.post(`/seo-geo/operations/${encodeURIComponent(request.operationId)}/rollback`,{confirm:true},'seo-geo-remediation-rollback');
    });
  }

  private requireCheckId(value:string):void{
    if(!/^[a-z0-9][a-z0-9_-]{0,127}$/.test(value))throw new Error('SEO/GEO remediation check ID must be a bounded lowercase diagnostic ID');
  }
  private requirePlanId(value:string):void{
    if(!/^erm-(?:seo-plan-[a-f0-9]{24}|indexability-plan-[a-f0-9]{18}-[a-f0-9]{8})$/.test(value))throw new Error('SEO/GEO remediation plan ID has an invalid format');
  }
  private requireOperationId(value:string):void{
    if(!/^erm-(?:seo-op|indexability-op)-[a-f0-9]{32}$/.test(value))throw new Error('SEO/GEO remediation operation ID has an invalid format');
  }
  private requireOperation(value:unknown,allowed:readonly string[],label:string):void{
    if(typeof value!=='object'||value===null||Array.isArray(value)||typeof (value as Record<string,unknown>).operation!=='string'||!allowed.includes((value as Record<string,unknown>).operation as string)){
      throw new Error(`${label} does not reference an allowed operation`);
    }
  }

  private get(route:A4Route,label:string):Promise<unknown>{return this.request(route,'GET',undefined,label);}
  private post(route:A4Route,body:unknown,label:string):Promise<unknown>{return this.request(route,'POST',body,label);}
  private async request(route:A4Route,method:'GET'|'POST',body:unknown|undefined,label:string):Promise<unknown>{
    if(!this.token)throw new Error('Research Manager write credential is not configured for this tenant');
    const endpoint=new URL(`/wp-json/research-manager/v1${route}`,this.baseUrl);
    const requestId=`mcp-${label}-${Date.now()}-${randomUUID()}`;
    const headers=new Headers({
      Accept:'application/json',Authorization:`Bearer ${this.token}`,
      'User-Agent':'Site-Control-MCP/0.10.0 ResearchManagerAdapter',
      'X-Research-Manager-Request-Id':requestId,
      'X-Research-Manager-Nonce':randomBytes(16).toString('hex'),
      'X-Research-Manager-Timestamp':Math.floor(Date.now()/1000).toString(),
    });
    if(body!==undefined)headers.set('Content-Type','application/json');
    let response:Response;
    try{response=await this.fetchImpl(endpoint,{method,headers,...(body!==undefined?{body:JSON.stringify(body)}:{}),redirect:'error',signal:AbortSignal.timeout(this.timeoutMs)});}
    catch(error){const detail=error instanceof Error?error.message:'network failure';throw new ResearchManagerError(`Research Manager request failed: ${detail}`,{status:null,code:'transport_error',requestId});}
    const raw=await response.text();let envelope:ManagerEnvelope={};
    try{const parsed:unknown=raw?JSON.parse(raw):{};if(typeof parsed==='object'&&parsed!==null)envelope=parsed as ManagerEnvelope;}
    catch{throw new ResearchManagerError(`Research Manager returned invalid JSON (HTTP ${response.status})`,{status:response.status,code:'invalid_json',requestId});}
    if(!response.ok||envelope.ok!==true||!('data'in envelope))throw new ResearchManagerError(`Research Manager rejected ${route} with HTTP ${response.status}: ${envelope.code??'request_failed'}`,{status:response.status,code:envelope.code??'request_failed',requestId});
    return envelope.data;
  }
}
