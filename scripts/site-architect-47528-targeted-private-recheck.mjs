#!/usr/bin/env node
/**
 * #475.28: independent read-only verification of one intermittently unreadable
 * Eduardo Page/language, using only pre-existing authenticated MCP READ tools.
 * Raw WordPress content, slots, post IDs, URLs, and credentials never exported.
 */
import fs from 'node:fs';
import path from 'node:path';
import {decodeResult} from './site-architect-47523-sanitize-mcp.mjs';

const permittedKeys=Object.freeze([
  'schema_version','mode','target','source_commit','observed_at_utc',
  'page_key','language','identity_verified','inspect_observed',
  'status','contract_aligned','allowed_slot_count','stored_slot_count',
  'outcome','release_certification','raw_content_exported',
  'production_mutations_performed'
]);
const SAFE_STATES=new Set(['publish','draft','private','future','pending','trash']);
const eq=(a,b)=>JSON.stringify([...a].sort())===JSON.stringify([...b].sort());
const count=v=>Array.isArray(v)?v.length:(v!==null&&typeof v==='object'&&!Array.isArray(v))?
  Object.keys(v).length:null;
const isObj=v=>v!==null&&typeof v==='object'&&!Array.isArray(v);
const ident=raw=>{
 const obj=decodeResult(raw);
 if(!obj||obj.manager_version!=='0.11.1'||obj.theme_version!=='1.1.3'
   ||obj.preset?.compatible!==true)return false;
 try{
  const url=new URL(obj.runtime_url);
  return url.protocol==='https:'&&url.hostname==='eduardoyauriluna.com'&&
    (!url.port||url.port==='443');
 }catch{return false;}
};
export function reduceTargeted(statusRaw,pageRaw,sha,at='2026-11-14T00:00:00Z'){
 if(!/^[0-9a-f]{40}$/.test(sha))throw Error('Invalid source SHA');
 const identity=ident(statusRaw);
 const page=decodeResult(pageRaw);
 const pageObserved=isObj(page)&&page.key==='privacy-policy'&&page.language==='en'&&
   SAFE_STATES.has(page.status)&&typeof page.contract_aligned==='boolean'&&
   count(page.allowed_slots)!==null&&count(page.stored_slots)!==null;
 const allowed=pageObserved?count(page.allowed_slots):null;
 const stored=pageObserved?count(page.stored_slots):null;
 const aligned=pageObserved?page.contract_aligned:null;
 const status=pageObserved?page.status:null;
 const outcome=!identity||!pageObserved?'READBACK_UNVERIFIED'
    :aligned!==true?'PAGE_CONTRACT_DRIFT_REVIEW'
    :status!=='publish'?'PAGE_PUBLICATION_STATE_REVIEW'
    :'TARGET_PAGE_READBACK_CONFIRMED';
 return{
   schema_version:1,
   mode:'EDUARDO_SINGLE_PRIVACY_PAGE_READONLY',
   target:'eduardoyauriluna.com',
   source_commit:sha,observed_at_utc:at,
   page_key:'privacy-policy',
   language:'en',
   identity_verified:identity,
   inspect_observed:!!pageObserved,
   status,contract_aligned:aligned,
   allowed_slot_count:allowed,stored_slot_count:stored,
   outcome,
   release_certification:'NOT_RELEASE_CERTIFIED',
   raw_content_exported:false,
   production_mutations_performed:false,
 };
}
export function validateTargeted(r){
 if(!isObj(r)||!eq(Object.keys(r),permittedKeys))throw Error('Unsafe redacted keys');
 if(r.schema_version!==1||r.mode!=='EDUARDO_SINGLE_PRIVACY_PAGE_READONLY'
   ||r.target!=='eduardoyauriluna.com'||!/^[0-9a-f]{40}$/.test(r.source_commit)
   ||r.page_key!=='privacy-policy'||r.language!=='en'
   ||typeof r.identity_verified!=='boolean'
   ||typeof r.inspect_observed!=='boolean'
   ||r.release_certification!=='NOT_RELEASE_CERTIFIED'
   ||r.raw_content_exported!==false||r.production_mutations_performed!==false)throw Error('Unsafe identity or release claim');
 const counts=[r.allowed_slot_count,r.stored_slot_count];
 if(counts.some(v=>v!==null&&(!Number.isInteger(v)||v<0||v>10000))
   ||![true,false,null].includes(r.contract_aligned)
   ||!new Set([...SAFE_STATES,null]).has(r.status))throw Error('Unsafe slot/state');
 if(!r.inspect_observed&&(r.contract_aligned!==null||r.status!==null
   ||counts.some(v=>v!==null)))throw Error('Unobserved data fields');
 const expected=!r.identity_verified||!r.inspect_observed?'READBACK_UNVERIFIED'
    :r.contract_aligned!==true?'PAGE_CONTRACT_DRIFT_REVIEW'
    :r.status!=='publish'?'PAGE_PUBLICATION_STATE_REVIEW'
    :'TARGET_PAGE_READBACK_CONFIRMED';
 if(r.outcome!==expected)throw Error('Forged readback result');
 if(/(?:Bearer\s+|ermt1_|erm1_|token_hash|post_id|post_content|title|secret|password|email|\/wp-json\/)/i.test(JSON.stringify(r)))
   throw Error('Private data leak');
 return r.outcome;
}
function main(){
 const args=process.argv.slice(2),opt=k=>args[args.indexOf(k)+1];
 const status=opt('--status'),page=opt('--page'),output=opt('--output'),sha=opt('--source-sha');
 if(!status||!page||!output||!sha||!output.endsWith('/sa47528-privacy-readback.json'))
   throw Error('Invalid bounded readback paths');
 const read=p=>{try{return JSON.parse(fs.readFileSync(p,'utf8'));}catch{return null;}};
 const report=reduceTargeted(read(status),read(page),sha,new Date().toISOString());
 validateTargeted(report);
 fs.writeFileSync(output,JSON.stringify(report,null,2)+'\n',{mode:0o600});
 console.log('EDUARDO_PRIVACY_EN_READBACK='+report.outcome+
  ' identity='+report.identity_verified+' observed='+report.inspect_observed);
}
if(path.basename(process.argv[1]||'')==='site-architect-47528-targeted-private-recheck.mjs')main();
