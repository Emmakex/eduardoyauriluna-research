import assert from 'node:assert/strict';
import test from 'node:test';
import {
  ELEVATED_COMMERCIAL_CAPABILITIES,
  EntitlementDeniedError,
  effectiveCapabilities,
  isElevatedCommercialCapability,
  parseCapabilityList,
  requireCapability,
  runWithAuthorizationContext,
} from '../src/entitlements.js';

test('effective commercial capabilities are the tenant/principal intersection', () => {
  assert.deepEqual(
    effectiveCapabilities(
      ['site.read', 'seo.read', 'media.read'],
      ['site.read', 'pages.read', 'seo.read'],
    ),
    ['site.read', 'seo.read'],
  );
});

test('elevated commercial capabilities stay explicitly classified away from normal write modules', () => {
  assert.deepEqual(ELEVATED_COMMERCIAL_CAPABILITIES, [
    'operations.rollback',
    'identity.write',
    'external_publish.execute',
    'software_update.execute',
    'destructive_delete.execute',
  ]);
  assert.equal(isElevatedCommercialCapability('operations.rollback'), true);
  assert.equal(isElevatedCommercialCapability('identity.write'), true);
  assert.equal(isElevatedCommercialCapability('external_publish.execute'), true);
  assert.equal(isElevatedCommercialCapability('destructive_delete.execute'), true);
  assert.equal(isElevatedCommercialCapability('pages.write'), false);
});

test('tool gate denies a capability the tenant has not contracted', async () => {
  const events: Array<{ reason: string; capability: string }> = [];
  await assert.rejects(
    () =>
      runWithAuthorizationContext(
        {
          principalId: 'agency-workspace',
          tenantId: 'client-one',
          tenantEntitlements: ['site.read'],
          principalCapabilities: ['site.read', 'seo.read'],
          onDenied: (event) => events.push({ reason: event.reason, capability: event.capability }),
        },
        async () => {
          await Promise.resolve();
          requireCapability('seo.read');
        },
      ),
    (error: unknown) => {
      assert.ok(error instanceof EntitlementDeniedError);
      assert.equal(error.reason, 'tenant_not_entitled');
      assert.equal(error.capability, 'seo.read');
      return true;
    },
  );
  assert.deepEqual(events, [{ reason: 'tenant_not_entitled', capability: 'seo.read' }]);
});

test('tool gate denies a capability not granted to the principal', () => {
  assert.throws(
    () =>
      runWithAuthorizationContext(
        {
          principalId: 'client-workspace',
          tenantId: 'client-one',
          tenantEntitlements: ['site.read', 'seo.read'],
          principalCapabilities: ['site.read'],
        },
        () => requireCapability('seo.read'),
      ),
    (error: unknown) => {
      assert.ok(error instanceof EntitlementDeniedError);
      assert.equal(error.reason, 'principal_not_granted');
      return true;
    },
  );
});

test('tool gate allows only when tenant and principal both grant the capability', () => {
  const context = runWithAuthorizationContext(
    {
      principalId: 'agency-workspace',
      tenantId: 'client-one',
      tenantEntitlements: ['site.read', 'seo.read'],
      principalCapabilities: ['site.read', 'seo.read'],
    },
    () => requireCapability('seo.read'),
  );
  assert.equal(context.principalId, 'agency-workspace');
  assert.equal(context.tenantId, 'client-one');
});

test('commercial capability configuration rejects unknown module names and defaults to site.read', () => {
  assert.deepEqual(parseCapabilityList(undefined, 'test'), ['site.read']);
  assert.throws(
    () => parseCapabilityList(['site.read', 'arbitrary.superpower'], 'test'),
    /not a supported commercial capability/,
  );
});

test('tool gate fails closed without request authorization context', () => {
  assert.throws(
    () => requireCapability('site.read'),
    (error: unknown) => {
      assert.ok(error instanceof EntitlementDeniedError);
      assert.equal(error.reason, 'context_missing');
      return true;
    },
  );
});
