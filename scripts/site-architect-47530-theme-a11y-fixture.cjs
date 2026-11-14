#!/usr/bin/env node
/**
 * Eduardo Theme #475.30: actual Chromium/axe fixture for the two defects
 * observed on production 2026-11-14. This is source-level proof ONLY:
 * not an assertion that the new styles have reached public production.
 */
'use strict';
const fs=require('node:fs');
const {chromium}=require('playwright');
const AxeBuilder=require('@axe-core/playwright').default;
const root='wordpress/themes/eduardo-research/';
const base=fs.readFileSync(root+'style.css','utf8');
const nav=fs.readFileSync(root+'assets/research-navigation.css','utf8');
const polish=fs.readFileSync(root+'assets/visual-polish.css','utf8');

const lum=h=>{
 const rgb=[1,3,5].map(i=>parseInt(h.slice(i,i+2),16)/255);
 const linear=rgb.map(c=>c<=0.04045?c/12.92:((c+0.055)/1.055)**2.4);
 return 0.2126*linear[0]+0.7152*linear[1]+0.0722*linear[2];
};
function contrast(a,b){
 const x=lum(a),y=lum(b);
 return (Math.max(x,y)+0.05)/(Math.min(x,y)+0.05);
}
const val=(cond,msg)=>{if(!cond)throw Error('EDUARDO_THEME_A11Y_SOURCE_FAILED: '+msg);};
async function main(){
 val(!/@media\(max-width:520px\)[^\n]*\.research-menu-toggle-label\{display:none\}/.test(nav),
   'Mobile label is still display:none');
 const color=(polish.match(/\.research-section-number\{color:(#[0-9A-Fa-f]{6});/)||[])[1];
 val(!!color,'Missing Theme-owned small section number color');
 for(const surface of ['#FCFCFA','#F5F5F0','#FFFFFF']){
   val(contrast(color,surface)>=4.5,'Insufficient section-number contrast on Theme surface');
 }
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 const result=[];
 try{
  for(const width of [390,480]){
   const context=await browser.newContext({viewport:{width,height:844},reducedMotion:'reduce'});
   const page=await context.newPage();
   await page.setContent('<!doctype html><html lang="es"><head><title>Theme accessibility source smoke</title></head>'+
     '<body><header class="research-site-header research-nav-enhanced" data-menu-open="false">'+
     '<button class="research-menu-toggle" type="button" aria-expanded="false" '+
     'aria-controls="research-header-actions" data-research-menu-toggle>'+
     '<span class="research-menu-toggle-icon" aria-hidden="true"><span></span></span>'+
     '<span class="research-menu-toggle-label">Abrir menú</span></button>'+
     '<div id="research-header-actions"></div></header>'+
     '<main id="main"><h1>Prueba de navegación</h1>'+
     '<section class="research-legal-section"><span class="research-section-number">01</span></section>'+
     '</main></body></html>');
   await page.addStyleTag({content:base+'\n'+polish+'\n'+nav});
   val(await page.getByRole('button',{name:'Abrir menú'}).isVisible(),
     'Mobile menu lost accessible name');
   const label=await page.locator('.research-menu-toggle-label').evaluate(el=>({
     display:getComputedStyle(el).display,
     clip:getComputedStyle(el).clip,
   }));
   val(label.display!=='none'&&label.clip!=='auto',
     'The visually-hidden menu text must remain in the accessibility tree');
   const actualColor=await page.locator('.research-section-number')
      .evaluate(el=>getComputedStyle(el).color);
   const ax=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa']).analyze();
   const blockers=ax.violations.filter(v=>['button-name','color-contrast'].includes(v.id));
   val(blockers.length===0,
     'One of the previously confirmed browser a11y rules still fails');
   result.push({width,accessible_button:true,section_number_computed_color:actualColor,
      axe_previous_rule_blockers:blockers.length});
   await context.close();
  }
 }finally{await browser.close();}
 console.log('PASS: EDUARDO_THEME_A11Y_SOURCE_FIX='+result.length+'/2'+
   ' menu accessible; contrast on three Theme backgrounds >=4.5; no button-name/color-contrast violations');
}
main().catch(e=>{
 console.error(e instanceof Error?e.message:'Theme a11y fixture failed');
 process.exitCode=1;
});
