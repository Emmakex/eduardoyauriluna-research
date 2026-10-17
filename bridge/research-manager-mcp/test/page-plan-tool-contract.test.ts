import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

test('Page slots expose only exact Plan and explicit Apply at this Phase 2B.2 stage',()=>{
  const source=readFileSync(new URL('../src/server.ts',import.meta.url),'utf8');
  assert.match(source,/server\.registerTool\('site_page_slots_plan'[\s\S]*?withCapability\('pages\.write'/);
  assert.match(source,/site_page_slots_plan'[\s\S]*?readOnlyHint:false[\s\S]*?destructiveHint:false/);
  assert.match(source,/site_page_slots_plan'[\s\S]*?adapter\.planPageSlots/);
  assert.match(source,/server\.registerTool\('site_page_slots_apply'[\s\S]*?withCapability\('pages\.write'/);
  assert.match(source,/site_page_slots_apply'[\s\S]*?confirm:z\.literal\(true\)/);
  assert.match(source,/site_page_slots_apply'[\s\S]*?readOnlyHint:false[\s\S]*?idempotentHint:true/);
  assert.match(source,/site_page_slots_apply'[\s\S]*?adapter\.applyPageSlots/);
  assert.doesNotMatch(source,/server\.registerTool\('site_[^']*verify'/);
  assert.doesNotMatch(source,/server\.registerTool\('site_[^']*rollback'/);
  assert.match(source,/Verify and Rollback are not exposed yet/);
});
