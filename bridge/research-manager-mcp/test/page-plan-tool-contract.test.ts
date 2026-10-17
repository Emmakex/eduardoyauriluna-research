import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

test('Page slots expose exact Plan, explicit Apply and rendered Verify while Rollback remains closed',()=>{
  const source=readFileSync(new URL('../src/server.ts',import.meta.url),'utf8');
  assert.match(source,/server\.registerTool\('site_page_slots_plan'[\s\S]*?withCapability\('pages\.write'/);
  assert.match(source,/site_page_slots_plan'[\s\S]*?readOnlyHint:false[\s\S]*?destructiveHint:false/);
  assert.match(source,/site_page_slots_plan'[\s\S]*?adapter\.planPageSlots/);
  assert.match(source,/server\.registerTool\('site_page_slots_apply'[\s\S]*?withCapability\('pages\.write'/);
  assert.match(source,/site_page_slots_apply'[\s\S]*?confirm:z\.literal\(true\)/);
  assert.match(source,/site_page_slots_apply'[\s\S]*?readOnlyHint:false[\s\S]*?idempotentHint:true/);
  assert.match(source,/site_page_slots_apply'[\s\S]*?adapter\.applyPageSlots/);
  assert.match(source,/server\.registerTool\('site_page_slots_verify'[\s\S]*?withCapability\('pages\.write'/);
  assert.match(source,/site_page_slots_verify'[\s\S]*?rendered:z\.literal\(true\)/);
  assert.match(source,/site_page_slots_verify'[\s\S]*?readOnlyHint:false[\s\S]*?idempotentHint:true/);
  assert.match(source,/site_page_slots_verify'[\s\S]*?adapter\.verifyPageSlots/);
  assert.doesNotMatch(source,/server\.registerTool\('site_[^']*rollback'/);
  assert.match(source,/Rollback is not exposed yet/);
});
