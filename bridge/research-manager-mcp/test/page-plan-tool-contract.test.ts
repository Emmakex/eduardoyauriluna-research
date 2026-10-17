import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

test('Page slots expose exact Plan, explicit Apply, rendered Verify and explicit Rollback',()=>{
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
  assert.match(source,/server\.registerTool\('site_page_slots_rollback'[\s\S]*?withCapability\('operations\.rollback'/);
  assert.match(source,/site_page_slots_rollback'[\s\S]*?confirm:z\.literal\(true\)/);
  assert.match(source,/site_page_slots_rollback'[\s\S]*?readOnlyHint:false[\s\S]*?destructiveHint:true[\s\S]*?idempotentHint:true/);
  assert.match(source,/site_page_slots_rollback'[\s\S]*?adapter\.rollbackPageSlots/);
  assert.match(source,/complete bounded lifecycle/);
});
