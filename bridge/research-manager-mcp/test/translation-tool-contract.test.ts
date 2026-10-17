import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

test('translation listing requires language at the MCP schema boundary',()=>{
  const source=readFileSync(new URL('../src/server.ts',import.meta.url),'utf8');
  assert.match(source,/site_translations_list'[\s\S]*?inputSchema:z\.object\(\{postType:translationTypeSchema,language:languageSchema\}\)/);
  assert.doesNotMatch(source,/site_translations_list'[\s\S]*?language:languageSchema\.optional\(\)/);
  assert.match(source,/site_translations_list'[\s\S]*?adapter\.listTranslations\(\{postType,language\}\)/);
});
