#!/usr/bin/env node
/* Strict schema/consistency gate for the public-only #475.29 browser report. */
'use strict';
const fs=require('node:fs');
const ROUTE_KEYS=['home','about','research','publications','projects','software',
 'datasets','cv','insights','contact','privacy-policy','legal-notice'];
const EMPTY=new Set(['publications','projects','software','datasets']);
const VIEWS=['desktop','mobile'],LANGS=['en','es'];
const eq=(a,b)=>Array.isArray(a)&&Array.isArray(b)&&
 a.length===b.length&&JSON.stringify([...a].sort())===JSON.stringify([...b].sort());
const rec=x=>x!==null&&typeof x==='object'&&!Array.isArray(x);
const nat=x=>Number.isSafeInteger(x)&&x>=0;
const val=(c,m)=>{if(!c)throw Error('Invalid Eduardo browser evidence: '+m);};
const AXE_ID=/^[a-z][a-z0-9-]{0,80}$/;
const DOM=[
 'lang','header','main','primary_nav','footer','h1',
 'horizontal_overflow_px','unsafe_nav_hosts','primary_links','footer_links',
 'skip_link','images_missing_alt'
];
const ENTRY=[
 'key','language','viewport','http_status','expected_empty_collection',
 'transport','host_ok','theme_dom','screenshot','mobile_menu','axe',
 'blocked_off_host_requests','page_errors','checks_complete'
];
function validate(report){
 const ROOT=[
  'schema_version','mode','source_commit','site','observed_at_utc',
  'viewports','routes_expected','observations_expected','observations','totals',
  'manual_visual_review_required','wordpress_private_inventory_verified',
  'actual_production_mutations_performed','release_certification'
 ];
 val(rec(report)&&eq(Object.keys(report),ROOT),'envelope');
 val(report.schema_version===1&&report.mode==='EDUARDO_PUBLIC_BROWSER_VISUAL_READ_ONLY'
  &&report.site==='eduardoyauriluna.com'&&/^[0-9a-f]{40}$/.test(report.source_commit)
  &&report.manual_visual_review_required===true
  &&report.wordpress_private_inventory_verified===false
  &&report.actual_production_mutations_performed===false
  &&report.release_certification==='NOT_RELEASE_CERTIFIED','identity/release');
 val(Array.isArray(report.viewports)&&report.viewports.length===2&&
  report.viewports[0].name==='desktop'&&report.viewports[0].width===1440&&
  report.viewports[0].height===900&&report.viewports[1].name==='mobile'&&
  report.viewports[1].width===390&&report.viewports[1].height===844,'viewports');
 val(report.routes_expected===24&&report.observations_expected===48
  &&Array.isArray(report.observations)&&report.observations.length===48,'route counts');
 const pairs=new Set();
 for(const x of report.observations){
  val(rec(x)&&eq(Object.keys(x),ENTRY),'observation fields');
  val(ROUTE_KEYS.includes(x.key)&&LANGS.includes(x.language)&&
    VIEWS.includes(x.viewport),'route');
  const pair=x.key+'/'+x.language+'/'+x.viewport;
  val(!pairs.has(pair),'duplicate');pairs.add(pair);
  val(x.expected_empty_collection===EMPTY.has(x.key),'collection status');
  val(x.http_status===null||nat(x.http_status)&&x.http_status>=100&&x.http_status<=599,'HTTP');
  val(['ok','not_observed','navigation_unverified','unapproved_redirect','inspection_unverified']
    .includes(x.transport),'transport label');
  val(typeof x.host_ok==='boolean'&&typeof x.screenshot==='boolean'&&
    typeof x.checks_complete==='boolean'&&nat(x.blocked_off_host_requests)
    &&nat(x.page_errors),'boolean/count');
  if(x.checks_complete){
   val(x.http_status===200&&x.host_ok===true&&x.screenshot===true&&rec(x.theme_dom),
      'false successful inspection');
   val(eq(Object.keys(x.theme_dom),DOM),'DOM schema');
   for(const f of ['header','main','primary_nav','footer','skip_link'])
    val(typeof x.theme_dom[f]==='boolean','DOM flag');
   val(x.theme_dom.lang===x.language||x.theme_dom.lang===null||
      typeof x.theme_dom.lang==='string'&&x.theme_dom.lang.length<=8,'lang');
   for(const f of ['h1','horizontal_overflow_px','unsafe_nav_hosts','primary_links',
     'footer_links','images_missing_alt'])val(nat(x.theme_dom[f])&&x.theme_dom[f]<200000,'DOM number');
  }else{
   val(x.theme_dom===null||rec(x.theme_dom),'incomplete DOM');
  }
  if(x.mobile_menu!==null){
   val(x.viewport==='mobile'&&rec(x.mobile_menu)&&
     eq(Object.keys(x.mobile_menu),['began_closed','opened','nav_visible'])&&
     Object.values(x.mobile_menu).every(v=>typeof v==='boolean'),'mobile menu');
  }
  if(x.axe!==null){
   val(rec(x.axe)&&eq(Object.keys(x.axe),
    ['scanned','violations','serious','critical','rule_ids']),'axe fields');
   val(typeof x.axe.scanned==='boolean','axe flag');
   if(x.axe.scanned){
    val(nat(x.axe.violations)&&nat(x.axe.serious)&&nat(x.axe.critical)&&
      x.axe.violations>=x.axe.serious+x.axe.critical,'axe counts');
   }else val(x.axe.violations===null&&x.axe.serious===null&&
      x.axe.critical===null,'unverified axe');
   val(Array.isArray(x.axe.rule_ids)&&x.axe.rule_ids.length<=24&&
     x.axe.rule_ids.every(s=>typeof s==='string'&&AXE_ID.test(s)),'axe rule IDs');
  }
 }
 val(pairs.size===48,'route coverage');
 const good=report.observations.filter(x=>x.http_status===200&&x.checks_complete);
 const unexpected=report.observations.filter(x=>!x.expected_empty_collection&&
    (x.http_status!==200||!x.checks_complete));
 const layout=good.filter(x=>x.theme_dom.horizontal_overflow_px>4||
    !x.theme_dom.header||!x.theme_dom.main||!x.theme_dom.primary_nav||
    !x.theme_dom.footer||x.theme_dom.lang!==x.language||
    x.theme_dom.unsafe_nav_hosts!==0||x.theme_dom.h1!==1||
    x.mobile_menu?.opened===false);
 const axe=good.filter(x=>x.axe?.serious>0||x.axe?.critical>0);
 const totals={
  audited:48,
  public_views_rendered:good.length,
  expected_public_unverified:unexpected.length,
  layout_flagged:layout.length,
  serious_or_critical_a11y_flagged:axe.length,
  screenshot_count:report.observations.filter(x=>x.screenshot).length
 };
 val(rec(report.totals)&&eq(Object.keys(report.totals),Object.keys(totals))&&
  Object.keys(totals).every(k=>report.totals[k]===totals[k]),'forged totals');
 const serialized=JSON.stringify(report);
 val(!/(?:Bearer\s+|ermt1_|erm1_|token_hash|post_id|post_content|post_title|password|api_key|client_secret|wp-admin|wp-json)/i.test(serialized),'private data leak');
 return totals;
}
module.exports={validate};
if(require.main===module){
 const p=process.argv[2];
 if(!p||!/\/site-architect-47529-public-browser-report\.json$/.test(p))
  throw Error('Expected sanitized browser report');
 const summary=validate(JSON.parse(fs.readFileSync(p,'utf8')));
 console.log('PASS: Eduardo browser JSON closed schema and all 48 route/viewport records; screenshots='+summary.screenshot_count);
}
