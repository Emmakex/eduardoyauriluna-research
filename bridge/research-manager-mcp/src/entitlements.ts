import { AsyncLocalStorage } from 'node:async_hooks';

export const COMMERCIAL_CAPABILITIES = [
  'site.read',
  'diagnostics.read',
  'pages.read',
  'pages.write',
  'insights.read',
  'insights.write',
  'research.read',
  'research.write',
  'translations.read',
  'translations.write',
  'evidence.read',
  'evidence.write',
  'seo.read',
  'seo.write',
  'media.read',
  'media.write',
  'navigation.read',
  'navigation.write',
  'design.read',
  'design.write',
  'connections.read',
  'connections.write',
  'operations.apply',
  'operations.rollback',
  'identity.write',
  'external_publish.execute',
  'software_update.execute',
  'destructive_delete.execute',
] as const;

export type CommercialCapability = (typeof COMMERCIAL_CAPABILITIES)[number];

export const ELEVATED_COMMERCIAL_CAPABILITIES = [
  'identity.write',
  'external_publish.execute',
  'software_update.execute',
  'destructive_delete.execute',
] as const satisfies readonly CommercialCapability[];

export type ElevatedCommercialCapability = (typeof ELEVATED_COMMERCIAL_CAPABILITIES)[number];
export type EntitlementDenialReason = 'context_missing' | 'tenant_not_entitled' | 'principal_not_granted';

const capabilitySet = new Set<string>(COMMERCIAL_CAPABILITIES);
const elevatedCapabilitySet = new Set<string>(ELEVATED_COMMERCIAL_CAPABILITIES);

export function isCommercialCapability(value: string): value is CommercialCapability {
  return capabilitySet.has(value);
}

export function isElevatedCommercialCapability(value: CommercialCapability): value is ElevatedCommercialCapability {
  return elevatedCapabilitySet.has(value);
}

export function parseCapabilityList(
  raw: unknown,
  field: string,
  fallback: readonly CommercialCapability[] = ['site.read'],
): readonly CommercialCapability[] {
  if (raw === undefined) return [...fallback];
  if (!Array.isArray(raw) || raw.length === 0) throw new Error(`${field} must be a non-empty capability array`);
  const parsed = raw.map((entry, index) => {
    if (typeof entry !== 'string' || !isCommercialCapability(entry)) {
      throw new Error(`${field}[${index}] is not a supported commercial capability`);
    }
    return entry;
  });
  return [...new Set(parsed)];
}

export interface AuthorizationContext {
  principalId: string;
  tenantId: string;
  tenantEntitlements: readonly CommercialCapability[];
  principalCapabilities: readonly CommercialCapability[];
  onDenied?: (event: {
    capability: CommercialCapability;
    reason: EntitlementDenialReason;
    principalId: string;
    tenantId: string;
  }) => void;
}

const authorizationStorage = new AsyncLocalStorage<AuthorizationContext>();

export class EntitlementDeniedError extends Error {
  readonly code = 'entitlement_denied';

  constructor(
    readonly capability: CommercialCapability,
    readonly reason: EntitlementDenialReason,
  ) {
    super(`Commercial entitlement denied: ${capability} (${reason})`);
    this.name = 'EntitlementDeniedError';
  }
}

export function runWithAuthorizationContext<T>(context: AuthorizationContext, callback: () => T): T {
  return authorizationStorage.run(context, callback);
}

export function currentAuthorizationContext(): AuthorizationContext | null {
  return authorizationStorage.getStore() ?? null;
}

export function requireCapability(capability: CommercialCapability): AuthorizationContext {
  const context = currentAuthorizationContext();
  if (!context) throw new EntitlementDeniedError(capability, 'context_missing');

  let reason: EntitlementDenialReason | null = null;
  if (!context.tenantEntitlements.includes(capability)) reason = 'tenant_not_entitled';
  else if (!context.principalCapabilities.includes(capability)) reason = 'principal_not_granted';

  if (reason) {
    context.onDenied?.({
      capability,
      reason,
      principalId: context.principalId,
      tenantId: context.tenantId,
    });
    throw new EntitlementDeniedError(capability, reason);
  }
  return context;
}

export function effectiveCapabilities(
  tenantEntitlements: readonly CommercialCapability[],
  principalCapabilities: readonly CommercialCapability[],
): readonly CommercialCapability[] {
  const granted = new Set(principalCapabilities);
  return tenantEntitlements.filter((capability) => granted.has(capability));
}
