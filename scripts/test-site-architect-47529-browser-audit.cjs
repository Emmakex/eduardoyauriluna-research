const test=require('node:test');
const assert=require('node:assert/strict');
const {validate}=require('./site-architect-47529-validate-browser-audit.cjs');
const KEYS=['home','about','research','publications','projects','software',
 'datasets','cv','insights','contact','privacy-policy','legal-notice'];
const EMPTY=new Set(['publications','projects','software','datasets']);
function sample(){
 const observations=[];
 for(const key of KEYS)for(const lang of ['en','es'])
   for(const viewport of ['desktop','mobile']){
    const empty=EMPTY.has(key);
    observations.push({
     key,language:lang,viewport,http_status:empty?404:200,
     expected_empty_collection:empty,transport:'ok',host_ok:true,
     theme_dom:empty?null:{
       lang,header:true,main:true,primary_nav:true,footer:true,h1:1,
       horizontal_overflow_px:0,unsafe_nav_hosts:0,primary_links:5,
       footer_links:2,skip_link:true,images_missing_alt:0,
     },
     screenshot:!empty,mobile_menu:empty?null:viewport==='mobile'?{
       began_closed:true,opened:true,nav_visible:true,
     }:null,
     axe:empty?null:{
       scanned:true,violations:0,serious:0,critical:0,rule_ids:[],
     },
     blocked_off_host_requests:0,page_errors:0,checks_complete:!empty,
    });
   }
 return{
  schema_version:1,mode:'EDUARDO_PUBLIC_BROWSER_VISUAL_READ_ONLY',
  source_commit:'f'.repeat(40),site:'eduardoyauriluna.com',
  observed_at_utc:'2026-11-14T00:00:00Z',
  viewports:[{name:'desktop',width:1440,height:900},{name:'mobile',width:390,height:844}],
  routes_expected:24,observations_expected:48,observations,
  totals:{
    audited:48,public_views_rendered:32,expected_public_unverified:0,
    layout_flagged:0,serious_or_critical_a11y_flagged:0,screenshot_count:32,
  },
  manual_visual_review_required:true,wordpress_private_inventory_verified:false,
  actual_production_mutations_performed:false,release_certification:'NOT_RELEASE_CERTIFIED',
 };
}

test('16 public EN/ES routes x 2 viewports and 8 empty collection routes are accounted for',()=>{
 const report=sample();
 assert.deepEqual(validate(report),report.totals);
 assert.equal(report.observations.filter(x=>x.http_status===404).length,16);
});

test('missing mobile navigation is flagged, never silently certified',()=>{
 const report=sample();
 report.observations[1].mobile_menu.opened=false;
 report.totals.layout_flagged=1;
 assert.equal(validate(report).layout_flagged,1);
});

test('missing publicly expected page is a blocker counted against the visual survey',()=>{
 const r=sample(),row=r.observations[0];
 row.http_status=404;row.screenshot=false;row.checks_complete=false;
 row.theme_dom=null;row.axe=null;
 r.totals.public_views_rendered-=1;
 r.totals.screenshot_count-=1;
 r.totals.expected_public_unverified+=1;
 assert.equal(validate(r).expected_public_unverified,1);
});

test('falsified success counters, missing routes and unapproved host are rejected',()=>{
 const a=sample();
 a.totals.layout_flagged=3;
 assert.throws(()=>validate(a));
 const b=sample();
 b.observations.pop();
 assert.throws(()=>validate(b));
 const c=sample();
 c.observations[0].key='other-client';
 assert.throws(()=>validate(c));
});

test('private payload fields and false cutover certification are rejected',()=>{
 const a=sample();
 a.observations[0].post_content='PRIVATE';
 assert.throws(()=>validate(a));
 const b=sample();
 b.release_certification='PRODUCTION_READY';
 assert.throws(()=>validate(b));
});

test('axe critical/serious flags are retained as open quality findings, not erased',()=>{
 const r=sample();
 r.observations[0].axe={
   scanned:true,violations:2,serious:1,critical:1,rule_ids:['color-contrast','image-alt']
 };
 r.totals.serious_or_critical_a11y_flagged=1;
 assert.equal(validate(r).serious_or_critical_a11y_flagged,1);
});
