import { randomBytes, randomUUID } from 'node:crypto';
import type { TenantConfig } from './config.js';
import { ResearchManagerError } from './manager-client.js';

type FetchLike = (input:string|URL|Request,init?:RequestInit)=>Promise<Response>;
interface ManagerEnvelope { ok?:boolean; code?:string; message?:string; data?:unknown; }

/** Dedicated diagnostics client. Keeps Site Baseline Audit off the site.read capability surface. */
export class PortableManagerClient {
  private readonly baseUrl:URL;
  private readonly token:string;
  private readonly timeoutMs:number;
  private readonly fetchImpl:FetchLike;

  constructor(config:Pick<TenantConfig,'managerBaseUrl'|'managerReadToken'|'managerTimeoutMs'>,fetchImpl:FetchLike=fetch){
    this.baseUrl=config.managerBaseUrl;
    this.token=config.managerReadToken;
    this.timeoutMs=config.managerTimeoutMs;
    this.fetchImpl=fetchImpl;
  }

  baseline():Promise<unknown>{
    return this.get(new URL('/wp-json/research-manager/v1/audit/baseline',this.baseUrl));
  }

  private async get(endpoint:URL):Promise<unknown>{
    const requestId=`mcp-site-baseline-${Date.now()}-${randomUUID()}`;
    const headers=new Headers({
      Accept:'application/json',
      Authorization:`Bearer ${this.token}`,
      'User-Agent':'Site-Control-MCP/0.10.0 PortableManagerAdapter',
      'X-Research-Manager-Request-Id':requestId,
      'X-Research-Manager-Nonce':randomBytes(16).toString('hex'),
      'X-Research-Manager-Timestamp':Math.floor(Date.now()/1000).toString(),
    });

    let response:Response;
    try{
      response=await this.fetchImpl(endpoint,{method:'GET',headers,redirect:'error',signal:AbortSignal.timeout(this.timeoutMs)});
    }catch(error){
      const detail=error instanceof Error?error.message:'network failure';
      throw new ResearchManagerError(`Research Manager baseline request failed: ${detail}`,{status:null,code:'transport_error',requestId});
    }

    const raw=await response.text();
    let envelope:ManagerEnvelope={};
    try{
      const parsed:unknown=raw?JSON.parse(raw):{};
      if(typeof parsed==='object'&&parsed!==null)envelope=parsed as ManagerEnvelope;
    }catch{
      throw new ResearchManagerError(`Research Manager returned invalid JSON (HTTP ${response.status})`,{status:response.status,code:'invalid_json',requestId});
    }

    if(!response.ok||envelope.ok!==true||!('data'in envelope)){
      throw new ResearchManagerError(`Research Manager rejected /audit/baseline with HTTP ${response.status}: ${envelope.code??'request_failed'}`,{status:response.status,code:envelope.code??'request_failed',requestId});
    }
    return envelope.data;
  }
}
