import test from 'node:test';
import assert from 'node:assert/strict';
import { sanitizeObserved, TOOL_NAMES } from './site-architect-47523-sanitize-mcp.mjs';
import { validate } from './site-architect-47523-validate-mcp.mjs';

const sha = 'b'.repeat(40);
const wrap = value => ({
  result: { content: [{ type: 'text', text: JSON.stringify({ok:true, data: value}) }] }
});
function full() {
  return {
    research_status: wrap({
      manager_version:'0.11.1', theme_version:'1.1.3',
      runtime_url:'https://eduardoyauriluna.com/',
      preset:{id:'research',version:6,compatible:true},
      readiness:{ready:true},
      remote:{connection_id:'DO_NOT_EXPORT',token_hash:'DO_NOT_EXPORT'}
    }),
    research_versions: wrap({manager:'0.11.1',theme:'1.1.3',wordpress:'6.7.1',php:'8.2.10'}),
    research_capabilities: wrap({
      connection_id:'DO_NOT_EXPORT',
      granted_scopes:['site.read','pages.read','media.read','operations.apply'],
      writes_enabled:true, mutation_transport:{available:true}
    }),
    site_pages_list: wrap({total:24, page:1,total_pages:1,
      items:[{post_id:123, title:'PRIVATE WORDPRESS PAGE',status:'publish'},
             {post_id:456, title:'PRIVATE UNPUBLISHED PAGE',status:'draft'}]}),
    site_navigation_inspect: wrap({primary:[{title:'PRIVATE'}],footer:[{title:'PRIVATE'}]}),
    site_media_list: wrap({total:9,items:[{filename:'PRIVATE.JPG',status:'inherit'}]}),
  };
}

test('complete good data is redacted and never certified for cutover', () => {
  const report=sanitizeObserved(full(),sha,'2026-11-14T18:00:00Z');
  const result=validate(report);
  assert.equal(report.outcome,'MCP_PRIVATE_READBACK_OBSERVED');
  assert.equal(report.private_database_full_inventory,false);
  assert.equal(report.production_mutations_performed,false);
  assert.equal(result.total_tools_read,6);
  const output=JSON.stringify(report);
  for (const secret of ['DO_NOT_EXPORT','PRIVATE WORDPRESS PAGE',
    'PRIVATE UNPUBLISHED PAGE','PRIVATE.JPG','operations.apply','connection_id','post_id']) {
    assert.ok(!output.includes(secret),'No secret/private field can enter report: '+secret);
  }
  assert.equal(report.pages_summary.total,24);
  assert.equal(report.media_summary.total,9);
});

test('unreadable and failed live calls cannot be described as verified', () => {
  const report=sanitizeObserved({},sha);
  assert.equal(report.outcome,'READBACK_INCOMPLETE');
  assert.equal(validate(report).total_tools_read,0);
  for (const name of TOOL_NAMES) assert.equal(report.tool_call_states[name],'unverified');
});

test('attacker-controlled runtime host cannot count as Eduardo production', () => {
  const raw=full();
  raw.research_status=wrap({manager_version:'0.11.1',
    runtime_url:'https://attacker.invalid/',preset:{id:'research'}});
  const report=sanitizeObserved(raw,sha);
  assert.equal(report.outcome,'READBACK_INCOMPLETE');
  validate(report);
});

test('version drift must not be silently accepted', () => {
  const raw=full();
  raw.research_versions=wrap({manager:'0.11.0',theme:'1.1.3'});
  const report=sanitizeObserved(raw,sha);
  assert.equal(report.outcome,'READBACK_INCOMPLETE');
  validate(report);
});

test('unsafe release claim and leaked connection ID are rejected by closed schema', () => {
  const report=sanitizeObserved(full(),sha);
  report.release_certification='FULL_LIVE_CUTOVER';
  assert.throws(() => validate(report));
  report.release_certification='NOT_RELEASE_CERTIFIED';
  report.granted_capability_summary.connection_id='secret';
  assert.throws(() => validate(report));
});

test('tool error wrapper never counts as successful read', () => {
  const raw=full();
  raw.site_pages_list={result:{isError:true,content:[{type:'text',text:JSON.stringify({total:999})}]}};
  const report=sanitizeObserved(raw,sha);
  assert.equal(report.tool_call_states.site_pages_list,'unverified');
  assert.equal(report.outcome,'MCP_VERSION_CAPABILITIES_ONLY');
  validate(report);
});
