import assert from 'node:assert/strict';
import test from 'node:test';
import { PrincipalRegistry, type PrincipalAccess } from '../src/access-control.js';

const gateway: PrincipalAccess = {
  id: 'phase2d-github',
  bearerToken: 'gateway-secret',
  allowedTenantIds: ['eduardo-research'],
  capabilities: ['site.read'],
};

const other: PrincipalAccess = {
  id: 'agency-workspace',
  bearerToken: 'agency-secret',
  allowedTenantIds: ['eduardo-research'],
  capabilities: ['site.read'],
};

const generation = 'phase2d-v2-20261019';

test('gateway generation compatibility mode accepts bare and contextual bearer', () => {
  const registry = new PrincipalRegistry([gateway, other], {
    phase2dGatewayGeneration: generation,
    requirePhase2dGatewayGeneration: false,
  });

  assert.equal(registry.authenticate('Bearer gateway-secret')?.id, gateway.id);
  assert.equal(registry.authenticate(`Bearer gateway-secret.${generation}`)?.id, gateway.id);
  assert.equal(registry.authenticate('Bearer gateway-secret.phase2d-old'), null);
  assert.equal(registry.authenticate('Bearer agency-secret')?.id, other.id);
});

test('gateway generation strict mode rejects historical bare bearer shape', () => {
  const registry = new PrincipalRegistry([gateway, other], {
    phase2dGatewayGeneration: generation,
    requirePhase2dGatewayGeneration: true,
  });

  assert.equal(registry.authenticate('Bearer gateway-secret'), null);
  assert.equal(registry.authenticate(`Bearer gateway-secret.${generation}`)?.id, gateway.id);
  assert.equal(registry.authenticate('Bearer gateway-secret.phase2d-old'), null);
  assert.equal(registry.authenticate('Bearer agency-secret')?.id, other.id);
});

test('generation policy fails closed on invalid or incomplete configuration', () => {
  assert.throws(
    () =>
      new PrincipalRegistry([gateway], {
        phase2dGatewayGeneration: '../unsafe',
        requirePhase2dGatewayGeneration: true,
      }),
    /MCP_PHASE2D_GATEWAY_GENERATION is invalid/,
  );
  assert.throws(
    () =>
      new PrincipalRegistry([gateway], {
        requirePhase2dGatewayGeneration: true,
      }),
    /requires MCP_PHASE2D_GATEWAY_GENERATION/,
  );
});
