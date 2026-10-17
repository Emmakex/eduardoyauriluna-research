import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { TOOL_AUTHORIZATION } from '../src/tool-authorization.js';

function serverMappings(): Map<string,string> {
  const sources=[
    readFileSync(new URL('../src/server.ts',import.meta.url),'utf8'),
    readFileSync(new URL('../src/research-write-tools.ts',import.meta.url),'utf8'),
    readFileSync(new URL('../src/a3-write-tools.ts',import.meta.url),'utf8'),
    readFileSync(new URL('../src/a4-write-tools.ts',import.meta.url),'utf8'),
  ];
  const mappings=new Map<string,string>();
  for(const source of sources){
    for(const line of source.split('\n')){
      const tool=line.match(/server\.registerTool\('([^']+)'/);
      if(!tool)continue;
      const single=line.match(/withCapability\('([^']+)'/);
      const multiple=line.match(/withCapabilities\(\['([^']+)'/);
      const capability=single?.[1]??multiple?.[1];
      assert.ok(capability,`${tool[1]} must declare a primary commercial capability on its registration line`);
      mappings.set(tool[1]!,capability);
    }
  }
  return mappings;
}

test('every registered MCP tool has an authoritative primary commercial capability mapping',()=>{
  const actual=serverMappings();
  const catalogEntries=Object.entries(TOOL_AUTHORIZATION);
  assert.equal(catalogEntries.length,75);
  assert.equal(actual.size,75);
  assert.deepEqual([...actual.keys()].sort(),catalogEntries.map(([name])=>name).sort());
  const writeTools=new Set([
    'site_page_slots_plan','site_page_slots_apply','site_page_slots_verify','site_page_slots_rollback',
    'site_insight_create_plan','site_insight_update_plan','site_insight_apply','site_insight_verify','site_insight_rollback',
    'site_insight_pair_plan','site_insight_unpair_plan','site_insight_pairing_apply','site_insight_pairing_verify','site_insight_pairing_rollback',
    'site_research_line_create_plan','site_research_line_update_plan','site_research_line_apply','site_research_line_verify','site_research_line_rollback',
    'site_research_object_create_plan','site_research_object_update_plan','site_research_object_apply','site_research_object_verify','site_research_object_rollback',
    'site_translation_pair_plan','site_translation_unpair_plan','site_translation_apply','site_translation_verify','site_translation_rollback',
    'site_evidence_create_plan','site_evidence_update_plan','site_evidence_delete_plan','site_evidence_apply','site_evidence_verify','site_evidence_rollback',
    'site_identity_profile_update_plan','site_identity_profile_apply','site_identity_profile_verify','site_identity_profile_rollback',
    'site_seo_geo_remediation_plan','site_seo_geo_remediation_apply','site_seo_geo_remediation_verify','site_seo_geo_remediation_rollback',
  ]);
  for(const [name,contract] of catalogEntries){
    assert.equal(actual.get(name),contract.commercialCapability,`${name} primary commercial capability drifted`);
    assert.equal(contract.readOnly,!writeTools.has(name),`${name} read-only classification drifted`);
    assert.ok(contract.backendScopes.length>0,`${name} must declare backend scopes`);
  }
});

test('backend scope contracts preserve adapter security boundaries',()=>{
  assert.deepEqual(TOOL_AUTHORIZATION.site_pages_list.backendScopes,['site.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_page_slots_plan.backendScopes,['operations.apply']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_page_slots_verify.backendScopes,['site.read','site.diagnostics']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_page_slots_rollback.backendScopes,['site.read','operations.rollback']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_insight_create_plan.backendScopes,['operations.apply']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_insight_verify.backendScopes,['site.read','site.diagnostics']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_insight_pair_plan.backendScopes,['operations.apply','insights.write','translations.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_insight_pairing_rollback.backendScopes,['site.read','operations.rollback','insights.write','translations.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_research_line_create_plan.backendScopes,['operations.apply','research.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_research_line_apply.backendScopes,['research.read','operations.apply','research.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_research_line_verify.backendScopes,['research.read','site.diagnostics']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_research_line_rollback.backendScopes,['research.read','operations.rollback','research.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_research_object_create_plan.backendScopes,['operations.apply','research.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_research_object_apply.backendScopes,['research.read','operations.apply','research.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_research_object_verify.backendScopes,['research.read','site.diagnostics']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_research_object_rollback.backendScopes,['research.read','operations.rollback','research.write']);

  assert.equal(TOOL_AUTHORIZATION.site_translation_pair_plan.commercialCapability,'translations.write');
  assert.deepEqual(TOOL_AUTHORIZATION.site_translation_pair_plan.backendScopes,['operations.apply','translations.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_translation_apply.backendScopes,['translations.read','operations.apply','translations.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_translation_verify.backendScopes,['translations.read','site.diagnostics']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_translation_rollback.backendScopes,['translations.read','operations.rollback','translations.write']);

  assert.equal(TOOL_AUTHORIZATION.site_evidence_create_plan.commercialCapability,'evidence.write');
  assert.deepEqual(TOOL_AUTHORIZATION.site_evidence_create_plan.backendScopes,['operations.apply','evidence.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_evidence_apply.backendScopes,['evidence.read','operations.apply','evidence.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_evidence_verify.backendScopes,['evidence.read','site.diagnostics']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_evidence_rollback.backendScopes,['evidence.read','operations.rollback','evidence.write']);

  assert.equal(TOOL_AUTHORIZATION.site_identity_profile_update_plan.commercialCapability,'identity.write');
  assert.deepEqual(TOOL_AUTHORIZATION.site_identity_profile_update_plan.backendScopes,['operations.apply','evidence.write','identity.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_identity_profile_apply.backendScopes,['evidence.read','operations.apply','evidence.write','identity.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_identity_profile_verify.backendScopes,['evidence.read','site.diagnostics']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_identity_profile_rollback.backendScopes,['evidence.read','operations.rollback','evidence.write','identity.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_identity_profile_rollback.additionalCommercialCapabilities,['operations.rollback']);

  assert.equal(TOOL_AUTHORIZATION.site_seo_geo_remediation_plan.commercialCapability,'seo.write');
  assert.deepEqual(TOOL_AUTHORIZATION.site_seo_geo_remediation_plan.backendScopes,['operations.apply','seo.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_seo_geo_remediation_apply.backendScopes,['seo.read','operations.apply','seo.write']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_seo_geo_remediation_verify.backendScopes,['seo.read','site.diagnostics']);
  assert.equal(TOOL_AUTHORIZATION.site_seo_geo_remediation_rollback.commercialCapability,'operations.rollback');
  assert.deepEqual(TOOL_AUTHORIZATION.site_seo_geo_remediation_rollback.backendScopes,['seo.read','operations.rollback','seo.write']);

  assert.deepEqual(TOOL_AUTHORIZATION.site_navigation_inspect.backendScopes,['pages.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_media_diagnostics.backendScopes,['media.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_seo_geo_site.backendScopes,['site.diagnostics','seo.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_design_readiness.backendScopes,['design.read']);
});
