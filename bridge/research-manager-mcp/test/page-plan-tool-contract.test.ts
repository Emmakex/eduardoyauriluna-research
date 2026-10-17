import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

test('page slot Plan is the only exposed Phase 2B.2 mutation-stage tool',()=>{
  const source=readFileSync(new URL('../src/server.ts',import.meta.url),'utf8');
  assert.match(source,/server\.registerTool\('site_page_slots_plan'[\s\S]*?withCapability\('pages\.write'/);
  assert.match(source,/site_page_slots_plan'[\s\S]*?readOnlyHint:false[\s\S]*?destructiveHint:false/);
  assert.match(source,/site_page_slots_plan'[\s\S]*?adapter\.planPageSlots/);
  assert.doesNotMatch(source,/server\.registerTool\('site_[^']*apply'/);
  assert.doesNotMatch(source,/server\.registerTool\('site_[^']*rollback'/);
  assert.match(source,/Apply, Verify and Rollback are not exposed yet/);
});
