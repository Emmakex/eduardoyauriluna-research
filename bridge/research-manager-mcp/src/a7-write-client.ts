import { randomBytes, randomUUID } from 'node:crypto';
import type { TenantConfig } from './config.js';
import { ResearchManagerError } from './manager-client.js';
import type {
  DesignPresetApplyRequest,
  DesignPresetChanges,
  DesignPresetPlanRequest,
  DesignPresetRollbackRequest,
  DesignPresetVerifyRequest,
  DesignVariantApplyRequest,
  DesignVariantChanges,
  DesignVariantPlanRequest,
  DesignVariantRollbackRequest,
  DesignVariantVerifyRequest,
} from './a7-contracts.js';

type FetchLike=(input:string|URL|Request,init?:RequestInit)=>Promise<Response>;
interface ManagerEnvelope { ok?:boolean; code?:string; message?:string; data?:unknown; }
type Config=Pick<TenantConfig,'managerBaseUrl'|'managerWriteToken'|'managerTimeoutMs'>;
type A7Route =
  | '/design/plan'
  | `/design/plans/${string}`
  | `/design/plans/${string}/apply`
  | `/design/operations/${string}`
  | `/design/operations/${string}/verify`
  | `/design/operations/${string}/rollback`
  | '/design/variants/plan'
  | `/design/variants/plans/${string}`
  | `/design/variants/plans/${string}/apply`
  | `/design/variants/operations/${string}`
  | `/design/variants/operations/${string}/verify`
  | `/design/variants/operations/${string}/rollback`;

const PALETTES=new Set(['researcher','ocean-lab','graphite-academic']);
const TYPOGRAPHIES=new Set(['modern-sans','academic-mixed','humanist-sans']);
const DENSITIES=new Set(['compact','balanced','airy']);
const HERO_VARIANTS=new Set(['orbit','editorial','compact']);
const SECTION_VARIANTS=new Set(['layered','plain','bands']);
const CARD_VARIANTS=new Set(['bordered','lifted','quiet']);
const COLLECTION_VARIANTS=new Set(['editorial-grid','compact-grid','list']);

export class ResearchManagerA7WriteClient {
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

  planPreset(request:DesignPresetPlanRequest):Promise<unknown>{
    const changes=this.validatePresetChanges(request.changes);
    this.validateIntent(request.intent);
    return this.post('/design/plan',{changes,...(request.intent?{intent:request.intent}:{})},'design-preset-plan');
  }
  applyPreset(request:DesignPresetApplyRequest):Promise<unknown>{
    this.requirePresetPlanId(request.planId);
    if(request.confirm!==true)throw new Error('Design preset Apply requires explicit confirmation');
    return this.get(`/design/plans/${encodeURIComponent(request.planId)}`,'design-preset-apply-preflight').then((plan)=>{
      this.requireOperation(plan,'design-preset-update','Design preset plan');
      return this.post(`/design/plans/${encodeURIComponent(request.planId)}/apply`,{confirm:true},'design-preset-apply');
    });
  }
  verifyPreset(request:DesignPresetVerifyRequest):Promise<unknown>{
    this.requirePresetOperationId(request.operationId);
    return this.get(`/design/operations/${encodeURIComponent(request.operationId)}`,'design-preset-verify-preflight').then((operation)=>{
      this.requireOperation(operation,'design-preset-update','Design preset operation');
      return this.post(`/design/operations/${encodeURIComponent(request.operationId)}/verify`,{},'design-preset-verify');
    });
  }
  rollbackPreset(request:DesignPresetRollbackRequest):Promise<unknown>{
    this.requirePresetOperationId(request.operationId);
    if(request.confirm!==true)throw new Error('Design preset Rollback requires explicit confirmation');
    return this.get(`/design/operations/${encodeURIComponent(request.operationId)}`,'design-preset-rollback-preflight').then((operation)=>{
      this.requireOperation(operation,'design-preset-update','Design preset operation');
      return this.post(`/design/operations/${encodeURIComponent(request.operationId)}/rollback`,{confirm:true},'design-preset-rollback');
    });
  }

  planVariant(request:DesignVariantPlanRequest):Promise<unknown>{
    const changes=this.validateVariantChanges(request.changes);
    this.validateIntent(request.intent);
    return this.post('/design/variants/plan',{changes,...(request.intent?{intent:request.intent}:{})},'design-variant-plan');
  }
  applyVariant(request:DesignVariantApplyRequest):Promise<unknown>{
    this.requireVariantPlanId(request.planId);
    if(request.confirm!==true)throw new Error('Design variant Apply requires explicit confirmation');
    return this.get(`/design/variants/plans/${encodeURIComponent(request.planId)}`,'design-variant-apply-preflight').then((plan)=>{
      this.requireOperation(plan,'design-variant-update','Design variant plan');
      return this.post(`/design/variants/plans/${encodeURIComponent(request.planId)}/apply`,{confirm:true},'design-variant-apply');
    });
  }
  verifyVariant(request:DesignVariantVerifyRequest):Promise<unknown>{
    this.requireVariantOperationId(request.operationId);
    return this.get(`/design/variants/operations/${encodeURIComponent(request.operationId)}`,'design-variant-verify-preflight').then((operation)=>{
      this.requireOperation(operation,'design-variant-update','Design variant operation');
      return this.post(`/design/variants/operations/${encodeURIComponent(request.operationId)}/verify`,{},'design-variant-verify');
    });
  }
  rollbackVariant(request:DesignVariantRollbackRequest):Promise<unknown>{
    this.requireVariantOperationId(request.operationId);
    if(request.confirm!==true)throw new Error('Design variant Rollback requires explicit confirmation');
    return this.get(`/design/variants/operations/${encodeURIComponent(request.operationId)}`,'design-variant-rollback-preflight').then((operation)=>{
      this.requireOperation(operation,'design-variant-update','Design variant operation');
      return this.post(`/design/variants/operations/${encodeURIComponent(request.operationId)}/rollback`,{confirm:true},'design-variant-rollback');
    });
  }

  private validatePresetChanges(changes:DesignPresetChanges):Record<string,string>{
    const clean:Record<string,string>={};
    if(changes.palette!==undefined){if(!PALETTES.has(changes.palette))throw new Error('Design palette is outside the registered Theme contract');clean.palette=changes.palette;}
    if(changes.typography!==undefined){if(!TYPOGRAPHIES.has(changes.typography))throw new Error('Design typography is outside the registered Theme contract');clean.typography=changes.typography;}
    if(changes.density!==undefined){if(!DENSITIES.has(changes.density))throw new Error('Design density is outside the registered Theme contract');clean.density=changes.density;}
    if(Object.keys(clean).length===0)throw new Error('Design preset plan requires at least one bounded change');
    return clean;
  }
  private validateVariantChanges(changes:DesignVariantChanges):Record<string,string>{
    const clean:Record<string,string>={};
    if(changes.hero!==undefined){if(!HERO_VARIANTS.has(changes.hero))throw new Error('Design hero variant is outside the registered Theme contract');clean.hero=changes.hero;}
    if(changes.sections!==undefined){if(!SECTION_VARIANTS.has(changes.sections))throw new Error('Design sections variant is outside the registered Theme contract');clean.sections=changes.sections;}
    if(changes.cards!==undefined){if(!CARD_VARIANTS.has(changes.cards))throw new Error('Design cards variant is outside the registered Theme contract');clean.cards=changes.cards;}
    if(changes.collections!==undefined){if(!COLLECTION_VARIANTS.has(changes.collections))throw new Error('Design collections variant is outside the registered Theme contract');clean.collections=changes.collections;}
    if(Object.keys(clean).length===0)throw new Error('Design variant plan requires at least one bounded change');
    return clean;
  }
  private validateIntent(intent:string|undefined):void{if(intent!==undefined&&intent.length>256)throw new Error('Design intent must be 256 characters or fewer');}
  private requirePresetPlanId(value:string):void{if(!/^erm-design-plan-[a-f0-9]{18}-[a-f0-9]{8}$/.test(value))throw new Error('Design preset plan ID has an invalid format');}
  private requirePresetOperationId(value:string):void{if(!/^erm-design-op-[a-f0-9]{32}$/.test(value))throw new Error('Design preset operation ID has an invalid format');}
  private requireVariantPlanId(value:string):void{if(!/^erm-design-variant-plan-[a-f0-9]{18}-[a-f0-9]{8}$/.test(value))throw new Error('Design variant plan ID has an invalid format');}
  private requireVariantOperationId(value:string):void{if(!/^erm-design-variant-op-[a-f0-9]{32}$/.test(value))throw new Error('Design variant operation ID has an invalid format');}
  private requireOperation(value:unknown,expected:string,label:string):void{
    if(typeof value!=='object'||value===null||Array.isArray(value)||(value as Record<string,unknown>).operation!==expected)throw new Error(`${label} does not reference ${expected}`);
  }
  private get(route:A7Route,label:string):Promise<unknown>{return this.request(route,'GET',undefined,label);}
  private post(route:A7Route,body:unknown,label:string):Promise<unknown>{return this.request(route,'POST',body,label);}
  private async request(route:A7Route,method:'GET'|'POST',body:unknown|undefined,label:string):Promise<unknown>{
    if(!this.token)throw new Error('Research Manager write credential is not configured for this tenant');
    const endpoint=new URL(`/wp-json/research-manager/v1${route}`,this.baseUrl);
    const requestId=`mcp-${label}-${Date.now()}-${randomUUID()}`;
    const headers=new Headers({Accept:'application/json',Authorization:`Bearer ${this.token}`,'User-Agent':'Site-Control-MCP/0.10.0 ResearchManagerAdapter','X-Research-Manager-Request-Id':requestId,'X-Research-Manager-Nonce':randomBytes(16).toString('hex'),'X-Research-Manager-Timestamp':Math.floor(Date.now()/1000).toString()});
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
