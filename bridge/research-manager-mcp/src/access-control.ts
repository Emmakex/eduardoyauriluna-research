import { timingSafeEqual } from 'node:crypto';
import type { CommercialCapability } from './entitlements.js';

export interface PrincipalAccess {
  id: string;
  bearerToken: string;
  allowedTenantIds: readonly string[];
  capabilities: readonly CommercialCapability[];
}

function secureEqual(left: string, right: string): boolean {
  const a = Buffer.from(left, 'utf8');
  const b = Buffer.from(right, 'utf8');
  return a.length === b.length && timingSafeEqual(a, b);
}

export class PrincipalRegistry {
  private readonly principals: readonly PrincipalAccess[];

  constructor(principals: readonly PrincipalAccess[]) {
    const ids = new Set<string>();
    const bearerTokens = new Set<string>();
    for (const principal of principals) {
      if (ids.has(principal.id)) throw new Error(`Duplicate principal id: ${principal.id}`);
      ids.add(principal.id);
      if (!principal.bearerToken) throw new Error(`Principal ${principal.id} is missing bearer token material`);
      if (bearerTokens.has(principal.bearerToken)) {
        throw new Error(`Duplicate bearer credential assigned to multiple principals`);
      }
      bearerTokens.add(principal.bearerToken);
      if (principal.allowedTenantIds.length === 0) {
        throw new Error(`Principal ${principal.id} must be allowed at least one tenant`);
      }
      if (principal.capabilities.length === 0) {
        throw new Error(`Principal ${principal.id} must be granted at least one commercial capability`);
      }
    }
    this.principals = principals;
  }

  get size(): number {
    return this.principals.length;
  }

  authenticate(authorizationHeader: string | undefined): PrincipalAccess | null {
    if (!authorizationHeader?.startsWith('Bearer ')) return null;
    const supplied = authorizationHeader.slice(7);
    let matched: PrincipalAccess | null = null;
    for (const principal of this.principals) {
      if (secureEqual(supplied, principal.bearerToken)) matched = principal;
    }
    return matched;
  }

  canAccess(principal: PrincipalAccess, tenantId: string): boolean {
    return principal.allowedTenantIds.includes(tenantId);
  }
}
