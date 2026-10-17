import { randomBytes, randomUUID } from 'node:crypto';
import { Buffer } from 'node:buffer';
import type { TenantConfig } from './config.js';
import { ResearchManagerError } from './manager-client.js';
import type {
  MediaIngestApplyRequest,
  MediaIngestCancelRequest,
  MediaIngestPlanRequest,
  MediaIngestRollbackRequest,
  MediaIngestVerifyRequest,
  MediaMetadataApplyRequest,
  MediaMetadataChanges,
  MediaMetadataRollbackRequest,
  MediaMetadataUpdatePlanRequest,
  MediaMetadataVerifyRequest,
} from './a5-contracts.js';

type FetchLike=(input:string|URL|Request,init?:RequestInit)=>Promise<Response>;
interface ManagerEnvelope { ok?:boolean; code?:string; message?:string; data?:unknown; }
type Config=Pick<TenantConfig,'managerBaseUrl'|'managerWriteToken'|'managerTimeoutMs'>;
type A5Route =
  | '/media/ingest/plan'
  | `/media/ingest/plans/${string}`
  | `/media/ingest/plans/${string}/cancel`
  | `/media/ingest/plans/${string}/apply`
  | `/media/ingest/operations/${string}`
  | `/media/ingest/operations/${string}/verify`
  | `/media/ingest/operations/${string}/rollback`
  | '/media/metadata/plan'
  | `/media/metadata/plans/${string}`
  | `/media/metadata/plans/${string}/apply`
  | `/media/metadata/operations/${string}`
  | `/media/metadata/operations/${string}/verify`
  | `/media/metadata/operations/${string}/rollback`;

const MAX_MEDIA_BYTES=10*1024*1024;
const ALLOWED_MIME_TYPES=new Set(['image/jpeg','image/png','image/webp','application/pdf']);
const BASE64_PATTERN=/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/;

export class ResearchManagerA5WriteClient {
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

  planIngest(request:MediaIngestPlanRequest):Promise<unknown>{
    const bytes=this.validateIngest(request);
    const form=new FormData();
    const buffer=Uint8Array.from(bytes).buffer as ArrayBuffer;
    form.append('file',new Blob([buffer],{type:request.mimeType}),request.fileName);
    if(request.title!==undefined)form.append('title',request.title);
    if(request.altText!==undefined)form.append('alt_text',request.altText);
    if(request.caption!==undefined)form.append('caption',request.caption);
    if(request.description!==undefined)form.append('description',request.description);
    return this.request('/media/ingest/plan','POST',form,'media-ingest-plan');
  }

  cancelIngest(request:MediaIngestCancelRequest):Promise<unknown>{
    this.requireIngestPlanId(request.planId);
    return this.get(`/media/ingest/plans/${encodeURIComponent(request.planId)}`,'media-ingest-cancel-preflight').then((plan)=>{
      this.requireOperation(plan,['media-ingest'],'Media ingest plan');
      return this.post(`/media/ingest/plans/${encodeURIComponent(request.planId)}/cancel`,{},'media-ingest-cancel');
    });
  }

  applyIngest(request:MediaIngestApplyRequest):Promise<unknown>{
    this.requireIngestPlanId(request.planId);
    if(request.confirm!==true)throw new Error('Media ingest Apply requires explicit confirmation');
    return this.get(`/media/ingest/plans/${encodeURIComponent(request.planId)}`,'media-ingest-apply-preflight').then((plan)=>{
      this.requireOperation(plan,['media-ingest'],'Media ingest plan');
      return this.post(`/media/ingest/plans/${encodeURIComponent(request.planId)}/apply`,{confirm:true},'media-ingest-apply');
    });
  }

  verifyIngest(request:MediaIngestVerifyRequest):Promise<unknown>{
    this.requireIngestOperationId(request.operationId);
    return this.get(`/media/ingest/operations/${encodeURIComponent(request.operationId)}`,'media-ingest-verify-preflight').then((operation)=>{
      this.requireOperation(operation,['media-ingest'],'Media ingest operation');
      return this.post(`/media/ingest/operations/${encodeURIComponent(request.operationId)}/verify`,{},'media-ingest-verify');
    });
  }

  rollbackIngest(request:MediaIngestRollbackRequest):Promise<unknown>{
    this.requireIngestOperationId(request.operationId);
    if(request.confirm!==true)throw new Error('Media ingest Rollback requires explicit confirmation');
    return this.get(`/media/ingest/operations/${encodeURIComponent(request.operationId)}`,'media-ingest-rollback-preflight').then((operation)=>{
      this.requireOperation(operation,['media-ingest'],'Media ingest operation');
      return this.post(`/media/ingest/operations/${encodeURIComponent(request.operationId)}/rollback`,{confirm:true},'media-ingest-rollback');
    });
  }

  planMetadataUpdate(request:MediaMetadataUpdatePlanRequest):Promise<unknown>{
    if(!Number.isSafeInteger(request.attachmentId)||request.attachmentId<=0)throw new Error('Media attachment ID must be a positive integer');
    const changes=this.metadataChanges(request.changes);
    return this.post('/media/metadata/plan',{operation:'media-metadata-update',payload:{attachment_id:request.attachmentId,changes}},'media-metadata-plan');
  }

  applyMetadata(request:MediaMetadataApplyRequest):Promise<unknown>{
    this.requireMetadataPlanId(request.planId);
    if(request.confirm!==true)throw new Error('Media metadata Apply requires explicit confirmation');
    return this.get(`/media/metadata/plans/${encodeURIComponent(request.planId)}`,'media-metadata-apply-preflight').then((plan)=>{
      this.requireOperation(plan,['media-metadata-update'],'Media metadata plan');
      return this.post(`/media/metadata/plans/${encodeURIComponent(request.planId)}/apply`,{confirm:true},'media-metadata-apply');
    });
  }

  verifyMetadata(request:MediaMetadataVerifyRequest):Promise<unknown>{
    this.requireMetadataOperationId(request.operationId);
    return this.get(`/media/metadata/operations/${encodeURIComponent(request.operationId)}`,'media-metadata-verify-preflight').then((operation)=>{
      this.requireOperation(operation,['media-metadata-update'],'Media metadata operation');
      return this.post(`/media/metadata/operations/${encodeURIComponent(request.operationId)}/verify`,{rendered:request.rendered},'media-metadata-verify');
    });
  }

  rollbackMetadata(request:MediaMetadataRollbackRequest):Promise<unknown>{
    this.requireMetadataOperationId(request.operationId);
    if(request.confirm!==true)throw new Error('Media metadata Rollback requires explicit confirmation');
    return this.get(`/media/metadata/operations/${encodeURIComponent(request.operationId)}`,'media-metadata-rollback-preflight').then((operation)=>{
      this.requireOperation(operation,['media-metadata-update'],'Media metadata operation');
      return this.post(`/media/metadata/operations/${encodeURIComponent(request.operationId)}/rollback`,{confirm:true},'media-metadata-rollback');
    });
  }

  private validateIngest(request:MediaIngestPlanRequest):Uint8Array{
    const name=request.fileName.trim();
    if(name.length===0||name.length>200||name==='.'||name==='..'||/[\\/\0]/.test(name))throw new Error('Media ingest filename must be a bounded basename');
    if(!ALLOWED_MIME_TYPES.has(request.mimeType))throw new Error('Media ingest MIME type is not allowed');
    if(request.contentBase64.length===0||request.contentBase64.length>14_000_000||!BASE64_PATTERN.test(request.contentBase64))throw new Error('Media ingest content must be canonical base64');
    const bytes=Buffer.from(request.contentBase64,'base64');
    if(bytes.length===0||bytes.length>MAX_MEDIA_BYTES)throw new Error('Media ingest file must be between 1 byte and 10 MB');
    if(request.mimeType.startsWith('image/')&&!(request.altText??'').trim())throw new Error('Media ingest image alt text is required');
    this.validateText(request.title,'Media title',200,true);
    this.validateText(request.altText,'Media alt text',500,false);
    this.validateText(request.caption,'Media caption',2000,false);
    this.validateText(request.description,'Media description',12000,false);
    return Uint8Array.from(bytes);
  }

  private metadataChanges(changes:MediaMetadataChanges):Record<string,string>{
    const result:Record<string,string>={};
    if(changes.title!==undefined){this.validateText(changes.title,'Media title',200,true);result.title=changes.title;}
    if(changes.altText!==undefined){this.validateText(changes.altText,'Media alt text',500,false);result.alt_text=changes.altText;}
    if(changes.caption!==undefined){this.validateText(changes.caption,'Media caption',2000,false);result.caption=changes.caption;}
    if(changes.description!==undefined){this.validateText(changes.description,'Media description',12000,false);result.description=changes.description;}
    if(Object.keys(result).length===0)throw new Error('Media metadata update requires at least one bounded change');
    return result;
  }

  private validateText(value:string|undefined,label:string,max:number,nonEmpty:boolean):void{
    if(value===undefined)return;
    if(value.length>max)throw new Error(`${label} must be ${max} characters or fewer`);
    if(nonEmpty&&value.trim().length===0)throw new Error(`${label} cannot be empty`);
  }

  private requireIngestPlanId(value:string):void{if(!/^erm-media-ingest-plan-[a-f0-9]{20}-[a-f0-9]{8}$/.test(value))throw new Error('Media ingest plan ID has an invalid format');}
  private requireIngestOperationId(value:string):void{if(!/^erm-media-ingest-op-[a-f0-9]{32}$/.test(value))throw new Error('Media ingest operation ID has an invalid format');}
  private requireMetadataPlanId(value:string):void{if(!/^erm-media-plan-[a-f0-9]{20}-[a-f0-9]{8}$/.test(value))throw new Error('Media metadata plan ID has an invalid format');}
  private requireMetadataOperationId(value:string):void{if(!/^erm-media-op-[a-f0-9]{32}$/.test(value))throw new Error('Media metadata operation ID has an invalid format');}
  private requireOperation(value:unknown,allowed:readonly string[],label:string):void{
    if(typeof value!=='object'||value===null||Array.isArray(value)||typeof (value as Record<string,unknown>).operation!=='string'||!allowed.includes((value as Record<string,unknown>).operation as string))throw new Error(`${label} does not reference an allowed operation`);
  }

  private get(route:A5Route,label:string):Promise<unknown>{return this.request(route,'GET',undefined,label);}
  private post(route:A5Route,body:unknown,label:string):Promise<unknown>{return this.request(route,'POST',JSON.stringify(body),label,true);}
  private async request(route:A5Route,method:'GET'|'POST',body:BodyInit|undefined,label:string,json=false):Promise<unknown>{
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
    if(json)headers.set('Content-Type','application/json');
    let response:Response;
    try{response=await this.fetchImpl(endpoint,{method,headers,...(body!==undefined?{body}:{}),redirect:'error',signal:AbortSignal.timeout(this.timeoutMs)});}
    catch(error){const detail=error instanceof Error?error.message:'network failure';throw new ResearchManagerError(`Research Manager request failed: ${detail}`,{status:null,code:'transport_error',requestId});}
    const raw=await response.text();let envelope:ManagerEnvelope={};
    try{const parsed:unknown=raw?JSON.parse(raw):{};if(typeof parsed==='object'&&parsed!==null)envelope=parsed as ManagerEnvelope;}
    catch{throw new ResearchManagerError(`Research Manager returned invalid JSON (HTTP ${response.status})`,{status:response.status,code:'invalid_json',requestId});}
    if(!response.ok||envelope.ok!==true||!('data'in envelope))throw new ResearchManagerError(`Research Manager rejected ${route} with HTTP ${response.status}: ${envelope.code??'request_failed'}`,{status:response.status,code:envelope.code??'request_failed',requestId});
    return envelope.data;
  }
}
