import assert from 'node:assert/strict';
import test from 'node:test';
import { loadConfig } from '../src/config.js';

const managerToken='ermt1_generic-site-not-a-secret';
const principalToken='outer-generic-not-a-secret';

function baseEnv():NodeJS.ProcessEnv{
  return {
    MCP_TENANTS_JSON:JSON.stringify([{
      id:'generic-site',
      label:'Generic Site',
      adapter:'research-manager',
      profile:'generic-wordpress',
      baseUrl:'https://example.test',
      readTokenEnv:'GENERIC_READ_TOKEN',
      languages:['es'],
      entitlements:['site.read','diagnostics.read'],
    }]),
    GENERIC_READ_TOKEN:managerToken,
    MCP_SHARED_ROUTING:'true',
    MCP_PRINCIPALS_JSON:JSON.stringify([{
      id:'generic-chatgpt',
      bearerTokenEnv:'GENERIC_PRINCIPAL_TOKEN',
      tenants:['generic-site'],
      capabilities:['site.read','diagnostics.read'],
    }]),
    GENERIC_PRINCIPAL_TOKEN:principalToken,
    MCP_BIND_HOST:'127.0.0.1',
  };
}

test('shared tenant config accepts explicit generic-wordpress profile',()=>{
  const config=loadConfig(baseEnv());
  assert.equal(config.tenants.length,1);
  assert.equal(config.tenants[0]?.productProfile,'generic-wordpress');
  assert.deepEqual(config.tenants[0]?.entitlements,['site.read','diagnostics.read']);
});

test('tenant profile defaults to research-theme for backward compatibility',()=>{
  const env=baseEnv();
  const definitions=JSON.parse(env.MCP_TENANTS_JSON??'[]') as Array<Record<string,unknown>>;
  delete definitions[0]?.profile;
  env.MCP_TENANTS_JSON=JSON.stringify(definitions);
  const config=loadConfig(env);
  assert.equal(config.tenants[0]?.productProfile,'research-theme');
});

test('unsupported tenant product profile fails closed',()=>{
  const env=baseEnv();
  const definitions=JSON.parse(env.MCP_TENANTS_JSON??'[]') as Array<Record<string,unknown>>;
  definitions[0]!.profile='anything-goes';
  env.MCP_TENANTS_JSON=JSON.stringify(definitions);
  assert.throws(()=>loadConfig(env),/must be research-theme or generic-wordpress/);
});
