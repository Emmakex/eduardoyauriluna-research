import { resolve4, resolve6 } from 'node:dns/promises';
import { connect as tlsConnect, type PeerCertificate } from 'node:tls';

type FetchLike=(input:string|URL|Request,init?:RequestInit)=>Promise<Response>;

interface BoundedHttpResult {
  ok:boolean;
  status:number|null;
  url:string;
  redirects:Array<{url:string;status:number;location:string|null}>;
  headers:Record<string,string>;
  body:string;
  bytes:number;
  headersMs:number|null;
  totalMs:number|null;
  error?:string;
}

const HEADER_NAMES=[
  'content-type','content-length','cache-control','etag','last-modified','content-encoding',
  'strict-transport-security','content-security-policy','x-content-type-options','x-frame-options',
  'referrer-policy','permissions-policy','cross-origin-opener-policy','cross-origin-resource-policy',
] as const;
const REDIRECT_STATUSES=new Set([301,302,303,307,308]);

function boundedText(value:string,max=240):string{return value.trim().replace(/\s+/g,' ').slice(0,max);}
function firstMatch(html:string,pattern:RegExp):string{return boundedText(pattern.exec(html)?.[1]??'',500);}
function countMatches(value:string,pattern:RegExp):number{return [...value.matchAll(pattern)].length;}
function attrFromTag(tag:string,name:string):string{
  const escaped=name.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
  const match=new RegExp(`\\s${escaped}\\s*=\\s*["']([^"']*)["']`,'i').exec(tag);
  return boundedText(match?.[1]??'',1000);
}
function metaContent(html:string,name:string,attribute='name'):string{
  const tags=html.match(/<meta\b[^>]*>/gi)??[];
  for(const tag of tags){if(attrFromTag(tag,attribute).toLowerCase()===name.toLowerCase())return attrFromTag(tag,'content');}
  return '';
}
function linkHref(html:string,rel:string):string{
  const tags=html.match(/<link\b[^>]*>/gi)??[];
  for(const tag of tags){const relValue=attrFromTag(tag,'rel').toLowerCase().split(/\s+/);if(relValue.includes(rel.toLowerCase()))return attrFromTag(tag,'href');}
  return '';
}

export function analyseHtml(html:string):Record<string,unknown>{
  const htmlTag=/<html\b[^>]*>/i.exec(html)?.[0]??'';
  const hreflangTags=(html.match(/<link\b[^>]*\bhreflang\s*=\s*["'][^"']+["'][^>]*>/gi)??[]).map((tag)=>({hreflang:attrFromTag(tag,'hreflang'),href:attrFromTag(tag,'href')})).slice(0,50);
  return {
    title:firstMatch(html,/<title[^>]*>([\s\S]*?)<\/title>/i),
    meta_description:metaContent(html,'description'),
    meta_robots:metaContent(html,'robots'),
    canonical:linkHref(html,'canonical'),
    html_lang:attrFromTag(htmlTag,'lang'),
    h1_count:countMatches(html,/<h1\b[^>]*>/gi),
    json_ld_count:countMatches(html,/<script\b[^>]*type\s*=\s*["']application\/ld\+json["'][^>]*>/gi),
    hreflang_count:hreflangTags.length,
    hreflang:hreflangTags,
    og_url:metaContent(html,'og:url','property'),
    og_title:metaContent(html,'og:title','property'),
  };
}

async function bodyWithLimit(response:Response,maxBytes:number):Promise<{body:string;bytes:number}>{
  const declared=Number.parseInt(response.headers.get('content-length')??'',10);
  if(Number.isFinite(declared)&&declared>maxBytes)throw new Error(`response_too_large:${declared}`);
  if(!response.body){return{body:'',bytes:0};}
  const reader=response.body.getReader();
  const chunks:Uint8Array[]=[];
  let total=0;
  try{
    while(true){
      const {done,value}=await reader.read();
      if(done)break;
      if(value){total+=value.byteLength;if(total>maxBytes){await reader.cancel();throw new Error(`response_too_large:${total}`);}chunks.push(value);}
    }
  }finally{reader.releaseLock();}
  const merged=new Uint8Array(total);let offset=0;for(const chunk of chunks){merged.set(chunk,offset);offset+=chunk.byteLength;}
  return{body:new TextDecoder().decode(merged),bytes:total};
}

async function boundedFetch(root:URL,path:string,fetchImpl:FetchLike,maxBytes:number):Promise<BoundedHttpResult>{
  const started=Date.now();let current=new URL(path,root);const redirects:BoundedHttpResult['redirects']=[];
  try{
    for(let hop=0;hop<=5;hop+=1){
      const response=await fetchImpl(current,{method:'GET',redirect:'manual',headers:{Accept:'text/html,application/xml,text/plain;q=0.9,*/*;q=0.1','User-Agent':'Kairoseth-Site-Baseline/0.11'},signal:AbortSignal.timeout(8_000)});
      const headersMs=Date.now()-started;
      const location=response.headers.get('location');
      if(REDIRECT_STATUSES.has(response.status)&&location){
        const next=new URL(location,current);
        redirects.push({url:current.toString(),status:response.status,location:next.toString()});
        if(next.hostname!==root.hostname||next.protocol!=='https:')throw new Error('redirect_outside_configured_https_host');
        current=next;continue;
      }
      const selected:Record<string,string>={};for(const name of HEADER_NAMES){const value=response.headers.get(name);if(value)selected[name]=boundedText(value,1000);}
      const {body,bytes}=await bodyWithLimit(response,maxBytes);
      return{ok:response.ok,status:response.status,url:current.toString(),redirects,headers:selected,body,bytes,headersMs,totalMs:Date.now()-started};
    }
    throw new Error('too_many_redirects');
  }catch(error){return{ok:false,status:null,url:current.toString(),redirects,headers:{},body:'',bytes:0,headersMs:null,totalMs:Date.now()-started,error:error instanceof Error?error.message:'network_error'};}
}

async function dnsProbe(hostname:string):Promise<Record<string,unknown>>{
  const [v4,v6]=await Promise.allSettled([resolve4(hostname),resolve6(hostname)]);
  const a=v4.status==='fulfilled'?v4.value:[];const aaaa=v6.status==='fulfilled'?v6.value:[];
  return {status:(a.length||aaaa.length)?'measured':'error',hostname,a:a.slice(0,16),aaaa:aaaa.slice(0,16),ipv4_error:v4.status==='rejected'?boundedText(String(v4.reason),240):'',ipv6_error:v6.status==='rejected'?boundedText(String(v6.reason),240):''};
}

function certSummary(cert:PeerCertificate):Record<string,unknown>{
  return {subject_cn:cert.subject?.CN??'',issuer_cn:cert.issuer?.CN??'',valid_from:cert.valid_from??'',valid_to:cert.valid_to??'',fingerprint256:cert.fingerprint256??'',subject_alt_name:boundedText(cert.subjectaltname??'',1000)};
}
async function tlsProbe(hostname:string,port:number):Promise<Record<string,unknown>>{
  return new Promise((resolve)=>{
    let settled=false;
    const socket=tlsConnect({host:hostname,port,servername:hostname,rejectUnauthorized:true},()=>{
      if(settled)return;settled=true;
      const cert=socket.getPeerCertificate();
      const result={status:'measured',authorized:socket.authorized,authorization_error:socket.authorizationError?String(socket.authorizationError):'',protocol:socket.getProtocol()??'',cipher:socket.getCipher(),alpn:socket.alpnProtocol||'',certificate:certSummary(cert)};
      socket.end();resolve(result);
    });
    socket.setTimeout(8_000,()=>{if(settled)return;settled=true;socket.destroy();resolve({status:'error',error:'tls_timeout'});});
    socket.once('error',(error)=>{if(settled)return;settled=true;resolve({status:'error',error:boundedText(error.message,240)});});
  });
}

function headerAssessment(headers:Record<string,string>):Record<string,unknown>{
  const expected=['strict-transport-security','content-security-policy','x-content-type-options','referrer-policy','permissions-policy'];
  const present=expected.filter((name)=>Boolean(headers[name]));
  return {present,count:present.length,expected_count:expected.length,headers};
}
function robotsAnalysis(result:BoundedHttpResult):Record<string,unknown>{
  const lines=result.body.split(/\r?\n/).map((line)=>line.trim());
  return {status:result.error?'error':'measured',http_status:result.status,url:result.url,bytes:result.bytes,error:result.error??'',user_agent_rules:lines.filter((line)=>/^user-agent\s*:/i.test(line)).length,disallow_rules:lines.filter((line)=>/^disallow\s*:/i.test(line)).length,allow_rules:lines.filter((line)=>/^allow\s*:/i.test(line)).length,sitemaps:lines.filter((line)=>/^sitemap\s*:/i.test(line)).map((line)=>boundedText(line.replace(/^sitemap\s*:/i,''),1000)).slice(0,20)};
}
function sitemapAnalysis(result:BoundedHttpResult):Record<string,unknown>{
  return {status:result.error?'error':'measured',http_status:result.status,url:result.url,bytes:result.bytes,error:result.error??'',kind:/<sitemapindex\b/i.test(result.body)?'sitemapindex':/<urlset\b/i.test(result.body)?'urlset':'unknown',loc_count:Math.min(countMatches(result.body,/<loc\b[^>]*>/gi),100000)};
}

export class ExternalSiteProbe {
  private readonly root:URL;private readonly fetchImpl:FetchLike;
  constructor(baseUrl:URL,fetchImpl:FetchLike=fetch){this.root=new URL(baseUrl.origin);this.fetchImpl=fetchImpl;}

  async run():Promise<Record<string,unknown>>{
    const port=this.root.port?Number.parseInt(this.root.port,10):443;
    const [dns,tls,home,robots,sitemap]=await Promise.all([
      dnsProbe(this.root.hostname),
      tlsProbe(this.root.hostname,Number.isFinite(port)?port:443),
      boundedFetch(this.root,'/',this.fetchImpl,2_000_000),
      boundedFetch(this.root,'/robots.txt',this.fetchImpl,256_000),
      boundedFetch(this.root,'/wp-sitemap.xml',this.fetchImpl,2_000_000),
    ]);
    const html=/text\/html/i.test(home.headers['content-type']??'')?analyseHtml(home.body):{};
    return {
      status:'measured',
      measured_at:new Date().toISOString(),
      configured_origin:this.root.origin,
      dns,
      tls,
      http:{status:home.error?'error':'measured',http_status:home.status,url:home.url,redirects:home.redirects,bytes:home.bytes,error:home.error??'',headers:headerAssessment(home.headers)},
      html:{status:Object.keys(html).length?'measured':'not_html',...html},
      robots:robotsAnalysis(robots),
      sitemap:sitemapAnalysis(sitemap),
      transport_performance:{status:home.error?'error':'measured',response_headers_ms:home.headersMs,total_download_ms:home.totalMs,note:'Synthetic HTTP transport timing only; not a Core Web Vitals or browser-render performance measurement.'},
    };
  }
}
