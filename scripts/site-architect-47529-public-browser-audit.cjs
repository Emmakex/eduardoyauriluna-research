#!/usr/bin/env node
/**
 * #475.29: Eduardo public-browser observation only.
 * 24 closed Theme routes x desktop/mobile; no cookies/auth, POST or WP writes.
 * Screenshots have public page pixels; JSON has only counts/booleans.
 */
'use strict';
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require('playwright');
const AxeBuilder=require('@axe-core/playwright').default;

const HOST='eduardoyauriluna.com';
const SHA=process.env.SA47529_SOURCE_SHA||'';
const OUT=process.env.SA47529_OUTPUT_DIR||'';
if(!/^[0-9a-f]{40}$/.test(SHA)||!/^\/tmp\/sa47529(?:-[a-z0-9-]+)?$/.test(OUT)){
  throw Error('Invalid source SHA or output directory');
}
const ROUTES=Object.freeze([
 ['home','/','/es/'],
 ['about','/about/','/es/perfil/'],
 ['research','/research/','/es/investigacion/'],
 ['publications','/publications/','/es/publicaciones/'],
 ['projects','/projects/','/es/proyectos/'],
 ['software','/software/','/es/software/'],
 ['datasets','/datasets/','/es/datos/'],
 ['cv','/cv/','/es/cv/'],
 ['insights','/insights/','/es/notas/'],
 ['contact','/contact/','/es/contacto/'],
 ['privacy-policy','/privacy-policy/','/es/privacidad/'],
 ['legal-notice','/legal-notice/','/es/aviso-legal/'],
]);
const EXPECTED_EMPTY=new Set(['publications','projects','software','datasets']);
const APPROVED_PATHS=new Set(ROUTES.flatMap(x=>[x[1],x[2]]));
if(APPROVED_PATHS.size!==24)throw Error('Duplicate Theme routes');
const VIEWPORTS=Object.freeze([
 {name:'desktop',width:1440,height:900,mobile:false},
 {name:'mobile',width:390,height:844,mobile:true},
]);

async function check(browser,view,key,lang,routePath){
 if(!APPROVED_PATHS.has(routePath))throw Error('Route outside exact Theme contract');
 const context=await browser.newContext({
  viewport:{width:view.width,height:view.height},
  screen:{width:view.width,height:view.height},
  deviceScaleFactor:1,isMobile:view.mobile,hasTouch:view.mobile,
  reducedMotion:'reduce',colorScheme:'light',
  locale:lang==='en'?'en-US':'es-ES'
 });
 let blocked=0;
 await context.route('**/*',r=>{
  let u;
  try{u=new URL(r.request().url());}catch{blocked++;return r.abort();}
  if(u.protocol==='https:'&&u.hostname===HOST&&(u.port===''||u.port==='443'))return r.continue();
  if(u.protocol==='blob:'||u.protocol==='data:')return r.continue();
  blocked++;return r.abort();
 });
 const page=await context.newPage();
 let pageErrors=0;
 page.on('pageerror',()=>pageErrors++);
 const item={
   key,language:lang,viewport:view.name,http_status:null,
   expected_empty_collection:EXPECTED_EMPTY.has(key),
   transport:'not_observed',host_ok:false,
   theme_dom:null,screenshot:false,mobile_menu:null,
   axe:null,blocked_off_host_requests:0,page_errors:0,checks_complete:false,
 };
 try{
  let response;
  try{
   response=await page.goto('https://'+HOST+routePath,{
     waitUntil:'domcontentloaded',timeout:23000
   });
   item.http_status=response?.status()??null;
   const dest=new URL(page.url());
   item.host_ok=dest.hostname===HOST&&dest.protocol==='https:'&&
      (!dest.port||dest.port==='443');
   item.transport=item.host_ok?'ok':'unapproved_redirect';
  }catch{item.transport='navigation_unverified';}
  if(item.http_status===200&&item.host_ok){
   await page.evaluate(()=>Promise.race([
     document.fonts?.ready??Promise.resolve(),
     new Promise(resolve=>setTimeout(resolve,2000))
   ]));
   item.theme_dom=await page.evaluate(()=>{
     const main=document.querySelector('main#main');
     const box=main?.getBoundingClientRect();
     const navLinks=[...document.querySelectorAll(
       '#research-primary-nav a[href],.research-footer-nav a[href]'
     )];
     const badHosts=navLinks.filter(a=>{
       try{return new URL(a.href,location.href).hostname!=='eduardoyauriluna.com';}
       catch{return true;}
     }).length;
     const width=Math.max(document.documentElement.scrollWidth,document.body?.scrollWidth||0);
     return{
       lang:(document.documentElement.lang||'').split('-')[0],
       header:!!document.querySelector('.research-site-header'),
       main:!!box&&box.width>0&&box.height>0,
       primary_nav:!!document.querySelector('#research-primary-nav'),
       footer:!!document.querySelector('.research-site-footer'),
       h1:document.querySelectorAll('h1').length,
       horizontal_overflow_px:Math.max(0,width-innerWidth),
       unsafe_nav_hosts:badHosts,
       primary_links:document.querySelectorAll('#research-primary-nav a[href]').length,
       footer_links:document.querySelectorAll('.research-footer-nav a[href]').length,
       skip_link:!!document.querySelector('a[href="#main"]'),
       images_missing_alt:[...document.images].filter(x=>!x.hasAttribute('alt')).length,
     };
   });
   await page.screenshot({
    path:path.join(OUT,'screenshots',key+'-'+lang+'-'+view.name+'.png'),
    fullPage:false,animations:'disabled',timeout:20000
   });
   item.screenshot=true;
   if(view.mobile&&await page.locator('[data-research-menu-toggle]').isVisible()){
    const button=page.locator('[data-research-menu-toggle]');
    const before=await button.getAttribute('aria-expanded');
    await button.click({timeout:6000});
    const after=await button.getAttribute('aria-expanded');
    const visible=await page.locator('#research-primary-nav').isVisible();
    item.mobile_menu={began_closed:before==='false',opened:after==='true',nav_visible:visible};
   }
   try{
    const ax=await new AxeBuilder({page})
      .withTags(['wcag2a','wcag2aa','wcag21a','wcag21aa'])
      .analyze();
    item.axe={
      scanned:true,violations:ax.violations.length,
      serious:ax.violations.filter(v=>v.impact==='serious').length,
      critical:ax.violations.filter(v=>v.impact==='critical').length,
      rule_ids:ax.violations.map(v=>v.id).sort().slice(0,24)
    };
   }catch{item.axe={scanned:false,violations:null,serious:null,critical:null,rule_ids:[]};}
   item.checks_complete=true;
  }
 }catch{
  item.transport=item.transport==='ok'?'inspection_unverified':item.transport;
 }finally{
  item.blocked_off_host_requests=blocked;
  item.page_errors=pageErrors;
  await context.close();
 }
 return item;
}

async function main(){
 fs.mkdirSync(path.join(OUT,'screenshots'),{recursive:true,mode:0o700});
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 const observations=[];
 try{
  for(const [key,en,es] of ROUTES){
   for(const [lang,pathpart] of [['en',en],['es',es]]){
    for(const view of VIEWPORTS){
     const row=await check(browser,view,key,lang,pathpart);
     observations.push(row);
     console.log('EDUARDO_BROWSER '+key+'/'+lang+'/'+view.name+
       ' HTTP='+(row.http_status??'unverified')+
       ' layout='+(row.theme_dom?.horizontal_overflow_px??'unverified'));
    }
   }
  }
 }finally{await browser.close();}
 const good=observations.filter(x=>x.http_status===200&&x.checks_complete);
 const unexpected=observations.filter(x=>!x.expected_empty_collection&&
   (x.http_status!==200||!x.checks_complete));
 const layout=good.filter(x=>x.theme_dom.horizontal_overflow_px>4||
   !x.theme_dom.header||!x.theme_dom.main||!x.theme_dom.primary_nav||
   !x.theme_dom.footer||x.theme_dom.lang!==x.language||
   x.theme_dom.unsafe_nav_hosts!==0||x.theme_dom.h1!==1||
   x.mobile_menu?.opened===false);
 const axe=good.filter(x=>x.axe?.serious>0||x.axe?.critical>0);
 const report={
   schema_version:1,mode:'EDUARDO_PUBLIC_BROWSER_VISUAL_READ_ONLY',
   source_commit:SHA,site:HOST,observed_at_utc:new Date().toISOString(),
   viewports:VIEWPORTS.map(x=>({name:x.name,width:x.width,height:x.height})),
   routes_expected:24,observations_expected:48,observations,
   totals:{
    audited:observations.length,
    public_views_rendered:good.length,
    expected_public_unverified:unexpected.length,
    layout_flagged:layout.length,
    serious_or_critical_a11y_flagged:axe.length,
    screenshot_count:observations.filter(x=>x.screenshot).length
   },
   manual_visual_review_required:true,
   wordpress_private_inventory_verified:false,
   actual_production_mutations_performed:false,
   release_certification:'NOT_RELEASE_CERTIFIED'
 };
 fs.writeFileSync(path.join(OUT,'site-architect-47529-public-browser-report.json'),
  JSON.stringify(report,null,2)+'\n',{mode:0o600});
 console.log('EDUARDO_BROWSER_AUDIT='+report.totals.audited+'/48'+
  ' screenshots='+report.totals.screenshot_count+
  ' unexpected='+report.totals.expected_public_unverified+
  ' layout_issues='+report.totals.layout_flagged+
  ' a11y_issues='+report.totals.serious_or_critical_a11y_flagged);
 if(observations.length!==48||good.length===0)process.exitCode=1;
}
main().catch(e=>{
 console.error('EDUARDO_BROWSER_TECHNICAL_ERROR: '+(e instanceof Error?e.message:'unknown'));
 process.exitCode=1;
});
