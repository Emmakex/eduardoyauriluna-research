import { randomBytes, randomUUID } from 'node:crypto';
import type { TenantConfig } from './config.js';
import { ResearchManagerError } from './manager-client.js';
import type {
  EvidenceApplyRequest,
  EvidenceContext,
  EvidenceCreatePlanRequest,
  EvidenceDeletePlanRequest,
  EvidenceRecordData,
  EvidenceRollbackRequest,
  EvidenceUpdatePlanRequest,
  EvidenceVerifyRequest,
  IdentityProfileApplyRequest,
  IdentityProfilePlanRequest,
  IdentityProfileRollbackRequest,
  IdentityProfileVerifyRequest,
  StructuredTranslationApplyRequest,
  StructuredTranslationPairPlanRequest,
  StructuredTranslationRollbackRequest,
  StructuredTranslationUnpairPlanRequest,
  StructuredTranslationVerifyRequest,
} from './a3-contracts.js';
import type { ResearchEvidenceGroup } from './adapters/site-control.js';

type FetchLike=(input:string|URL|Request,init?:RequestInit)=>Promise<Response>;
interface ManagerEnvelope { ok?:boolean; code?:string; message?:string; data?:unknown; }
type Config=Pick<TenantConfig,'managerBaseUrl'|'managerWriteToken'|'managerTimeoutMs'>;

type A3Route =
  | '/research-translations/plan'
  | `/research-translations/plans/${string}`
  | `/research-translations/plans/${string}/apply`
  | `/research-translations/operations/${string}`
  | `/research-translations/operations/${string}/verify`
  | `/research-translations/operations/${string}/rollback`
  | '/research-evidence/plan'
  | `/research-evidence/plans/${string}`
  | `/research-evidence/plans/${string}/apply`
  | `/research-evidence/operations/${string}`
  | `/research-evidence/operations/${string}/verify`
  | `/research-evidence/operations/${string}/rollback`
  | '/identity-profile/plan'
  | `/identity-profile/plans/${string}`
  | `/identity-profile/plans/${string}/apply`
  | `/identity-profile/operations/${string}`
  | `/identity-profile/operations/${string}/verify`
  | `/identity-profile/operations/${string}/rollback`;

const evidenceGroups=new Set<ResearchEvidenceGroup>(['profile','affiliations','identifiers','research_questions','methods','experience','education','awards','contact']);
const evidenceTextFields=new Set(['title','label','value','period','date','dates','start_date','end_date','organization','institution','company','affiliation']);
const evidenceAllowedFields=new Set([...evidenceTextFields,'status','summary','url','translations']);
const evidencePublicFields=['title','label','value','summary','url'] as const;

export class ResearchManagerA3WriteClient {
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

  planTranslationPair(request:StructuredTranslationPairPlanRequest):Promise<unknown>{
    this.requirePositiveId(request.firstId,'First Research translation');
    this.requirePositiveId(request.secondId,'Second Research translation');
    this.requireEvidenceContext(request);
    return this.post('/research-translations/plan',{operation:'translation-pair',payload:{first_id:request.firstId,second_id:request.secondId,...this.evidencePayload(request)}},'translation-pair-plan');
  }

  planTranslationUnpair(request:StructuredTranslationUnpairPlanRequest):Promise<unknown>{
    this.requirePositiveId(request.postId,'Research translation');
    this.requireEvidenceContext(request);
    return this.post('/research-translations/plan',{operation:'translation-unpair',payload:{post_id:request.postId,...this.evidencePayload(request)}},'translation-unpair-plan');
  }

  applyTranslation(request:StructuredTranslationApplyRequest):Promise<unknown>{
    this.requireTranslationPlanId(request.planId);
    if(request.confirm!==true)throw new Error('Research translation Apply requires explicit confirmation');
    return this.get(`/research-translations/plans/${encodeURIComponent(request.planId)}`,'translation-apply-preflight').then((plan)=>{
      this.requireOperation(plan,['translation-pair','translation-unpair'],'Research translation plan');
      return this.post(`/research-translations/plans/${encodeURIComponent(request.planId)}/apply`,{confirm:true},'translation-apply');
    });
  }

  verifyTranslation(request:StructuredTranslationVerifyRequest):Promise<unknown>{
    this.requireTranslationOperationId(request.operationId);
    return this.get(`/research-translations/operations/${encodeURIComponent(request.operationId)}`,'translation-verify-preflight').then((operation)=>{
      this.requireOperation(operation,['translation-pair','translation-unpair'],'Research translation operation');
      return this.post(`/research-translations/operations/${encodeURIComponent(request.operationId)}/verify`,{rendered:request.rendered},'translation-verify');
    });
  }

  rollbackTranslation(request:StructuredTranslationRollbackRequest):Promise<unknown>{
    this.requireTranslationOperationId(request.operationId);
    if(request.confirm!==true)throw new Error('Research translation Rollback requires explicit confirmation');
    return this.get(`/research-translations/operations/${encodeURIComponent(request.operationId)}`,'translation-rollback-preflight').then((operation)=>{
      this.requireOperation(operation,['translation-pair','translation-unpair'],'Research translation operation');
      return this.post(`/research-translations/operations/${encodeURIComponent(request.operationId)}/rollback`,{confirm:true},'translation-rollback');
    });
  }

  planEvidenceCreate(request:EvidenceCreatePlanRequest):Promise<unknown>{
    this.requireEvidenceGroup(request.group);
    this.requireEvidenceData(request.data,true);
    this.requireEvidenceContext(request);
    return this.post('/research-evidence/plan',{operation:'evidence-create',payload:{group:request.group,data:request.data,...this.evidencePayload(request)}},'evidence-create-plan');
  }

  planEvidenceUpdate(request:EvidenceUpdatePlanRequest):Promise<unknown>{
    this.requireEvidenceGroup(request.group);
    this.requireRecordId(request.recordId);
    this.requireEvidenceData(request.data,false);
    this.requireEvidenceContext(request);
    return this.post('/research-evidence/plan',{operation:'evidence-update',payload:{group:request.group,record_id:request.recordId,data:request.data,...this.evidencePayload(request)}},'evidence-update-plan');
  }

  planEvidenceDelete(request:EvidenceDeletePlanRequest):Promise<unknown>{
    this.requireEvidenceGroup(request.group);
    this.requireRecordId(request.recordId);
    this.requireEvidenceContext(request);
    return this.post('/research-evidence/plan',{operation:'evidence-delete',payload:{group:request.group,record_id:request.recordId,...this.evidencePayload(request)}},'evidence-delete-plan');
  }

  applyEvidence(request:EvidenceApplyRequest):Promise<unknown>{
    this.requireEvidencePlanId(request.planId);
    if(request.confirm!==true)throw new Error('Research evidence Apply requires explicit confirmation');
    return this.get(`/research-evidence/plans/${encodeURIComponent(request.planId)}`,'evidence-apply-preflight').then((plan)=>{
      this.requireOperation(plan,['evidence-create','evidence-update','evidence-delete'],'Research evidence plan');
      return this.post(`/research-evidence/plans/${encodeURIComponent(request.planId)}/apply`,{confirm:true},'evidence-apply');
    });
  }

  verifyEvidence(request:EvidenceVerifyRequest):Promise<unknown>{
    this.requireEvidenceOperationId(request.operationId);
    return this.get(`/research-evidence/operations/${encodeURIComponent(request.operationId)}`,'evidence-verify-preflight').then((operation)=>{
      this.requireOperation(operation,['evidence-create','evidence-update','evidence-delete'],'Research evidence operation');
      return this.post(`/research-evidence/operations/${encodeURIComponent(request.operationId)}/verify`,{},'evidence-verify');
    });
  }

  rollbackEvidence(request:EvidenceRollbackRequest):Promise<unknown>{
    this.requireEvidenceOperationId(request.operationId);
    if(request.confirm!==true)throw new Error('Research evidence Rollback requires explicit confirmation');
    return this.get(`/research-evidence/operations/${encodeURIComponent(request.operationId)}`,'evidence-rollback-preflight').then((operation)=>{
      this.requireOperation(operation,['evidence-create','evidence-update','evidence-delete'],'Research evidence operation');
      return this.post(`/research-evidence/operations/${encodeURIComponent(request.operationId)}/rollback`,{confirm:true},'evidence-rollback');
    });
  }

  planIdentityUpdate(request:IdentityProfilePlanRequest):Promise<unknown>{
    this.requireIdentityChanges(request.changes as unknown as Record<string,unknown>);
    this.requireEvidenceContext(request);
    return this.post('/identity-profile/plan',{changes:request.changes,...this.evidencePayload(request)},'identity-update-plan');
  }

  applyIdentity(request:IdentityProfileApplyRequest):Promise<unknown>{
    this.requireIdentityPlanId(request.planId);
    if(request.confirm!==true)throw new Error('Identity Profile Apply requires explicit confirmation');
    return this.get(`/identity-profile/plans/${encodeURIComponent(request.planId)}`,'identity-apply-preflight').then((plan)=>{
      this.requireOperation(plan,['identity-profile-update'],'Identity Profile plan');
      return this.post(`/identity-profile/plans/${encodeURIComponent(request.planId)}/apply`,{confirm:true},'identity-apply');
    });
  }

  verifyIdentity(request:IdentityProfileVerifyRequest):Promise<unknown>{
    this.requireIdentityOperationId(request.operationId);
    return this.get(`/identity-profile/operations/${encodeURIComponent(request.operationId)}`,'identity-verify-preflight').then((operation)=>{
      this.requireOperation(operation,['identity-profile-update'],'Identity Profile operation');
      return this.post(`/identity-profile/operations/${encodeURIComponent(request.operationId)}/verify`,{},'identity-verify');
    });
  }

  rollbackIdentity(request:IdentityProfileRollbackRequest):Promise<unknown>{
    this.requireIdentityOperationId(request.operationId);
    if(request.confirm!==true)throw new Error('Identity Profile Rollback requires explicit confirmation');
    return this.get(`/identity-profile/operations/${encodeURIComponent(request.operationId)}`,'identity-rollback-preflight').then((operation)=>{
      this.requireOperation(operation,['identity-profile-update'],'Identity Profile operation');
      return this.post(`/identity-profile/operations/${encodeURIComponent(request.operationId)}/rollback`,{confirm:true},'identity-rollback');
    });
  }

  private requireEvidenceContext(context:EvidenceContext):void{
    if(context.evidenceReference!==undefined&&context.evidenceReference.length>512)throw new Error('Evidence reference must be 512 characters or fewer');
    if(context.evidenceConfirmed===true&&!context.evidenceReference?.trim())throw new Error('Evidence confirmation requires a non-empty evidence reference');
  }

  private evidencePayload(context:EvidenceContext):Record<string,unknown>{
    return {evidence_confirmed:context.evidenceConfirmed===true,evidence_reference:context.evidenceReference??''};
  }

  private requireEvidenceData(data:EvidenceRecordData,creating:boolean):void{
    const entries=Object.entries(data).filter(([,value])=>value!==undefined);
    if(entries.length<1||entries.length>18)throw new Error('Evidence data must contain between 1 and 18 bounded fields');
    for(const [field,value] of entries){
      if(!evidenceAllowedFields.has(field))throw new Error(`Evidence field is not allowed: ${field}`);
      if(field==='status'){
        if(value!=='verified'&&value!=='unverified')throw new Error('Evidence status must be verified or unverified');
        continue;
      }
      if(field==='translations'){
        if(typeof value!=='object'||value===null||Array.isArray(value))throw new Error('Evidence translations must be a structured object');
        const translations=value as Record<string,unknown>;
        const languages=Object.keys(translations);
        if(languages.some((language)=>language!=='es'))throw new Error('Evidence translations currently support Spanish only');
        if(translations.es!==undefined)this.requireEvidenceTranslation(translations.es);
        continue;
      }
      if(typeof value!=='string')throw new Error(`Evidence ${field} must be text`);
      const max=field==='summary'?12_000:field==='url'?2_000:1_000;
      if(value.length>max)throw new Error(`Evidence ${field} exceeds ${max} characters`);
    }
    if(creating&&!evidencePublicFields.some((field)=>typeof data[field]==='string'&&data[field]!.trim()!=='')){
      throw new Error('Evidence creation requires at least a title, label, value, summary or URL');
    }
  }

  private requireEvidenceTranslation(value:unknown):void{
    if(typeof value!=='object'||value===null||Array.isArray(value))throw new Error('Evidence Spanish translation must be a structured object');
    for(const [field,entry] of Object.entries(value as Record<string,unknown>)){
      if(!evidenceTextFields.has(field)&&field!=='summary'&&field!=='url')throw new Error(`Evidence translation field is not allowed: ${field}`);
      if(typeof entry!=='string')throw new Error(`Evidence translation ${field} must be text`);
      const max=field==='summary'?12_000:field==='url'?2_000:1_000;
      if(entry.length>max)throw new Error(`Evidence translation ${field} exceeds ${max} characters`);
    }
  }

  private requireIdentityChanges(changes:Record<string,unknown>):void{
    const entries=Object.entries(changes).filter(([,value])=>value!==undefined);
    if(entries.length<1||entries.length>3)throw new Error('Identity Profile update requires between 1 and 3 bounded fields');
    for(const [field,value] of entries){
      if(!['name','headline','headline_es'].includes(field))throw new Error(`Identity Profile field is not allowed: ${field}`);
      if(typeof value!=='string'||value.trim()===''||value.length>1_000)throw new Error(`Identity Profile ${field} must be non-empty text up to 1000 characters`);
    }
  }

  private requireEvidenceGroup(group:ResearchEvidenceGroup):void{if(!evidenceGroups.has(group))throw new Error('Unsupported Research evidence group');}
  private requireRecordId(recordId:string):void{if(!/^[A-Za-z0-9._:-]{1,128}$/.test(recordId))throw new Error('Evidence record ID has an invalid format');}
  private requirePositiveId(value:number,label:string):void{if(!Number.isSafeInteger(value)||value<=0)throw new Error(`${label} ID must be a positive safe integer`);}
  private requireTranslationPlanId(value:string):void{if(!/^erm-translation-plan-[a-f0-9]{18}-[a-f0-9]{8}$/.test(value))throw new Error('Research translation plan ID has an invalid format');}
  private requireTranslationOperationId(value:string):void{if(!/^erm-translation-op-[a-f0-9]{32}$/.test(value))throw new Error('Research translation operation ID has an invalid format');}
  private requireEvidencePlanId(value:string):void{if(!/^erm-evidence-plan-[a-f0-9]{18}-[a-f0-9]{8}$/.test(value))throw new Error('Research evidence plan ID has an invalid format');}
  private requireEvidenceOperationId(value:string):void{if(!/^erm-evidence-op-[a-f0-9]{32}$/.test(value))throw new Error('Research evidence operation ID has an invalid format');}
  private requireIdentityPlanId(value:string):void{if(!/^erm-identity-plan-[a-f0-9]{24}$/.test(value))throw new Error('Identity Profile plan ID has an invalid format');}
  private requireIdentityOperationId(value:string):void{if(!/^erm-identity-op-[a-f0-9]{32}$/.test(value))throw new Error('Identity Profile operation ID has an invalid format');}

  private requireOperation(value:unknown,allowed:readonly string[],label:string):void{
    if(typeof value!=='object'||value===null||Array.isArray(value)||typeof (value as Record<string,unknown>).operation!=='string'||!allowed.includes((value as Record<string,unknown>).operation as string)){
      throw new Error(`${label} does not reference an allowed operation`);
    }
  }

  private get(route:A3Route,label:string):Promise<unknown>{return this.request(route,'GET',undefined,label);}
  private post(route:A3Route,body:unknown,label:string):Promise<unknown>{return this.request(route,'POST',body,label);}

  private async request(route:A3Route,method:'GET'|'POST',body:unknown|undefined,label:string):Promise<unknown>{
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
    try{
      response=await this.fetchImpl(endpoint,{method,headers,...(body!==undefined?{body:JSON.stringify(body)}:{}),redirect:'error',signal:AbortSignal.timeout(this.timeoutMs)});
    }catch(error){
      const detail=error instanceof Error?error.message:'network failure';
      throw new ResearchManagerError(`Research Manager request failed: ${detail}`,{status:null,code:'transport_error',requestId});
    }
    const raw=await response.text();let envelope:ManagerEnvelope={};
    try{const parsed:unknown=raw?JSON.parse(raw):{};if(typeof parsed==='object'&&parsed!==null)envelope=parsed as ManagerEnvelope;}
    catch{throw new ResearchManagerError(`Research Manager returned invalid JSON (HTTP ${response.status})`,{status:response.status,code:'invalid_json',requestId});}
    if(!response.ok||envelope.ok!==true||!('data'in envelope))throw new ResearchManagerError(`Research Manager rejected ${route} with HTTP ${response.status}: ${envelope.code??'request_failed'}`,{status:response.status,code:envelope.code??'request_failed',requestId});
    return envelope.data;
  }
}
