import { randomBytes, randomUUID } from 'node:crypto';
import type { TenantConfig } from './config.js';
import { ResearchManagerError } from './manager-client.js';
import type {
  NavigationApplyRequest,
  NavigationRollbackRequest,
  NavigationUpdatePlanRequest,
  NavigationVerifyRequest,
} from './a6-contracts.js';

type FetchLike=(input:string|URL|Request,init?:RequestInit)=>Promise<Response>;
interface ManagerEnvelope { ok?:boolean; code?:string; message?:string; data?:unknown; }
type Config=Pick<TenantConfig,'managerBaseUrl'|'managerWriteToken'|'managerTimeoutMs'>;
type A6Route =
  | '/navigation/plan'
  | `/navigation/plans/${string}`
  | `/navigation/plans/${string}/apply`
  | `/navigation/operations/${string}`
  | `/navigation/operations/${string}/verify`
  | `/navigation/operations/${string}/rollback`;

const LOCATIONS=new Set(['primary','footer']);
const TARGETS=new Set(['home','about','research','publications','projects','software','datasets','cv','insights','contact','privacy-policy','legal-notice']);

export class ResearchManagerA6WriteClient {
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

  plan(request:NavigationUpdatePlanRequest):Promise<unknown>{
    if(!LOCATIONS.has(request.location))throw new Error('Navigation location is outside the registered Theme contract');
    if(request.items.length<1||request.items.length>TARGETS.size)throw new Error('Navigation items must contain between 1 and 12 registered Theme targets');
    const unique=new Set<string>();
    for(const item of request.items){
      if(!TARGETS.has(item))throw new Error('Navigation may only target registered Research Theme pages');
      if(unique.has(item))throw new Error('Navigation targets must be unique');
      unique.add(item);
    }
    if(request.intent!==undefined&&request.intent.length>256)throw new Error('Navigation intent must be 256 characters or fewer');
    return this.post('/navigation/plan',{location:request.location,items:request.items,...(request.intent?{intent:request.intent}:{})},'navigation-plan');
  }

  apply(request:NavigationApplyRequest):Promise<unknown>{
    this.requirePlanId(request.planId);
    if(request.confirm!==true)throw new Error('Navigation Apply requires explicit confirmation');
    return this.get(`/navigation/plans/${encodeURIComponent(request.planId)}`,'navigation-apply-preflight').then((plan)=>{
      this.requireOperation(plan,'Navigation plan');
      return this.post(`/navigation/plans/${encodeURIComponent(request.planId)}/apply`,{confirm:true},'navigation-apply');
    });
  }

  verify(request:NavigationVerifyRequest):Promise<unknown>{
    this.requireOperationId(request.operationId);
    if(request.rendered!==true)throw new Error('Navigation Verify requires rendered verification');
    return this.get(`/navigation/operations/${encodeURIComponent(request.operationId)}`,'navigation-verify-preflight').then((operation)=>{
      this.requireOperation(operation,'Navigation operation');
      return this.post(`/navigation/operations/${encodeURIComponent(request.operationId)}/verify`,{rendered:true},'navigation-verify');
    });
  }

  rollback(request:NavigationRollbackRequest):Promise<unknown>{
    this.requireOperationId(request.operationId);
    if(request.confirm!==true)throw new Error('Navigation Rollback requires explicit confirmation');
    return this.get(`/navigation/operations/${encodeURIComponent(request.operationId)}`,'navigation-rollback-preflight').then((operation)=>{
      this.requireOperation(operation,'Navigation operation');
      return this.post(`/navigation/operations/${encodeURIComponent(request.operationId)}/rollback`,{confirm:true},'navigation-rollback');
    });
  }

  private requirePlanId(value:string):void{if(!/^erm-nav-plan-[a-f0-9]{18}-[a-f0-9]{8}$/.test(value))throw new Error('Navigation plan ID has an invalid format');}
  private requireOperationId(value:string):void{if(!/^erm-nav-op-[a-f0-9]{32}$/.test(value))throw new Error('Navigation operation ID has an invalid format');}
  private requireOperation(value:unknown,label:string):void{
    if(typeof value!=='object'||value===null||Array.isArray(value)||(value as Record<string,unknown>).operation!=='navigation-update')throw new Error(`${label} does not reference navigation-update`);
  }
  private get(route:A6Route,label:string):Promise<unknown>{return this.request(route,'GET',undefined,label);}
  private post(route:A6Route,body:unknown,label:string):Promise<unknown>{return this.request(route,'POST',body,label);}
  private async request(route:A6Route,method:'GET'|'POST',body:unknown|undefined,label:string):Promise<unknown>{
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
