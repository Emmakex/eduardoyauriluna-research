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
  assert.equal(catalogEntries.length,32);
  assert.equal(actual.size,32);
  assert.deepEqual([...actual.keys()].sort(),catalogEntries.map(([name])=>name).sort());
  for(const [name,contract] of catalogEntries){
    assert.equal(actual.get(name),contract.commercialCapability,`${name} commercial capability drifted`);
    assert.equal(contract.readOnly,true);
    assert.ok(contract.backendScopes.length>0,`${name} must declare backend scopes`);
  }
});

test('backend scope contracts preserve adapter security boundaries',()=>{
  assert.deepEqual(TOOL_AUTHORIZATION.site_pages_list.backendScopes,['site.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_insights_list.backendScopes,['site.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_navigation_inspect.backendScopes,['pages.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_research_objects_list.backendScopes,['research.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_translations_list.backendScopes,['translations.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_evidence_identity.backendScopes,['evidence.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_media_diagnostics.backendScopes,['media.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_seo_geo_site.backendScopes,['site.diagnostics','seo.read']);
  assert.deepEqual(TOOL_AUTHORIZATION.site_design_readiness.backendScopes,['design.read']);
});
