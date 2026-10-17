import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { TOOL_AUTHORIZATION } from '../src/tool-authorization.js';

function serverMappings(): Map<string,string> {
  const source=readFileSync(new URL('../src/server.ts',import.meta.url),'utf8');
  const mappings=new Map<string,string>();
  const pattern=/server\.registerTool\('([^']+)'[\s\S]*?withCapability\('([^']+)'/g;
  for(const match of source.matchAll(pattern)) mappings.set(match[1]!,match[2]!);
  return mappings;
}

test('every registered MCP tool has exactly one authoritative commercial capability mapping',()=>{
  const actual=serverMappings();
  const catalogEntries=Object.entries(TOOL_AUTHORIZATION);
  assert.equal(catalogEntries.length,41);
  assert.equal(actual.size,41);
  assert.deepEqual([...actual.keys()].sort(),catalogEntries.map(([name])=>name).sort());
  const writeTools=new Set([
    'site_page_slots_plan','site_page_slots_apply','site_page_slots_verify','site_page_slots_rollback',
    'site_insight_create_plan','site_insight_update_plan','site_insight_apply','site_insight_verify','site_insight_rollback',
  ]);
  for(const [name,contract] of catalogEntries){
    assert.equal(actual.get(name),contract.commercialCapability,`${name} commercial capability drifted`);
    assert.equal(contract.readOnly,!writeTools.has(name),`${name} read-only classification drifted`);
    assert.ok(contract.backendScopes.length>0,`${name} must declare backend scopes`);
  }
});

test('backend scope contracts preserve adapter security boundaries',()=>{
  assert.deepEqual(TOOL_AUTHORIZATION.site_pages_list.backendScopes,['site.read']);
  assert.equal(TOOL_AUTHORIZATION.site_page_slots_plan.commercialCapability,'pages.write');
  assert.deepEqual(TOOL_AUTHORIZATION.site_page_slots_plan.backendScopes,['operations.apply']);
  assert.equal(TOOL_AUTHORIZATION.site_page_slots_plan.readOnly,false);
  assert.equal(TOOL_AUTHORIZATION.site_page_slots_apply.commercialCapability,'pages.write');
  assert.deepEqual(TOOL_AUTHORIZATION.site_page_slots_apply.backendScopes,['operations.apply']);
  assert.equal(TOOL_AUTHORIZATION.site_page_slots_verify.commercialCapability,'pages.write');
  assert.deepEqual(TOOL_AUTHORIZATION.site_page_slots_verify.backendScopes,['site.read','site.diagnostics']);
  assert.equal(TOOL_AUTHORIZATION.site_page_slots_rollback.commercialCapability,'operations.rollback');
  assert.deepEqual(TOOL_AUTHORIZATION.site_page_slots_rollback.backendScopes,['site.read','operations.rollback']);
  assert.equal(TOOL_AUTHORIZATION.site_insight_create_plan.commercialCapability,'insights.write');
  assert.deepEqual(TOOL_AUTHORIZATION.site_insight_create_plan.backendScopes,['operations.apply']);
  assert.equal(TOOL_AUTHORIZATION.site_insight_update_plan.commercialCapability,'insights.write');
  assert.deepEqual(TOOL_AUTHORIZATION.site_insight_update_plan.backendScopes,['operations.apply']);
  assert.equal(TOOL_AUTHORIZATION.site_insight_apply.commercialCapability,'insights.write');
  assert.deepEqual(TOOL_AUTHORIZATION.site_insight_apply.backendScopes,['operations.apply']);
  assert.equal(TOOL_AUTHORIZATION.site_insight_verify.commercialCapability,'insights.write');
  assert.deepEqual(TOOL_AUTHORIZATION.site_insight_verify.backendScopes,['site.read','site.diagnostics']);
  assert.equal(TOOL_AUTHORIZATION.site_insight_rollback.commercialCapability,'operations.rollback');
  assert.deepEqual(TOOL_AUTHORIZATION.site_insight_rollback.backendScopes,['site.read','operations.rollback']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_insights_list.backendScopes,['site.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_navigation_inspect.backendScopes,['pages.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_research_objects_list.backendScopes,['research.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_translations_list.backendScopes,['translations.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_evidence_identity.backendScopes,['evidence.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_media_diagnostics.backendScopes,['media.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_seo_geo_site.backendScopes,['site.diagnostics','seo.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_design_readiness.backendScopes,['design.read']);
});
