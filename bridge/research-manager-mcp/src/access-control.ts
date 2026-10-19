import { timingSafeEqual } from 'node:crypto';
import type { CommercialCapability } from './entitlements.js';

export interface PrincipalAccess {
  id: string;
  bearerToken: string;
  allowedTenantIds: readonly string[];
  capabilities: readonly CommercialCapability[];
}

export interface PrincipalRegistryOptions {
  phase2dGatewayGeneration?: string | null;
  requirePhase2dGatewayGeneration?: boolean;
}

const PHASE2D_GATEWAY_PRINCIPAL_ID = 'phase2d-github';
const GATEWAY_GENERATION_RE = /^[a-z0-9][a-z0-9._-]{0,63}$/;

function secureEqual(left: string, right: string): boolean {
  const a = Buffer.from(left, 'utf8');
  const b = Buffer.from(right, 'utf8');
  return a.length === b.length && timingSafeEqual(a, b);
}

function envFlag(name: string, raw: string | undefined): boolean {
  if (!raw) return false;
  const value = raw.trim().toLowerCase();
  if (['1', 'true', 'yes', 'on'].includes(value)) return true;
  if (['0', 'false', 'no', 'off'].includes(value)) return false;
  throw new Error(`${name} must be true/false`);
}

function defaultOptions(): PrincipalRegistryOptions {
  return {
    phase2dGatewayGeneration: (process.env.MCP_PHASE2D_GATEWAY_GENERATION ?? '').trim() || null,
    requirePhase2dGatewayGeneration: envFlag(
      'MCP_PHASE2D_REQUIRE_GATEWAY_GENERATION',
      process.env.MCP_PHASE2D_REQUIRE_GATEWAY_GENERATION,
    ),
  };
}

export class PrincipalRegistry {
  private readonly principals: readonly PrincipalAccess[];
  private readonly phase2dGatewayGeneration: string | null;
  private readonly requirePhase2dGatewayGeneration: boolean;

  constructor(principals: readonly PrincipalAccess[], options: PrincipalRegistryOptions = defaultOptions()) {
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

    const generation = options.phase2dGatewayGeneration?.trim() || null;
    if (generation !== null && !GATEWAY_GENERATION_RE.fullmatch(generation)) {
      throw new Error('MCP_PHASE2D_GATEWAY_GENERATION is invalid');
    }
    const required = options.requirePhase2dGatewayGeneration === true;
    if (required && generation === null) {
      throw new Error('MCP_PHASE2D_REQUIRE_GATEWAY_GENERATION requires MCP_PHASE2D_GATEWAY_GENERATION');
    }

    this.principals = principals;
    this.phase2dGatewayGeneration = generation;
    this.requirePhase2dGatewayGeneration = required;
  }

  get size(): number {
    return this.principals.length;
  }

  authenticate(authorizationHeader: string | undefined): PrincipalAccess | null {
    if (!authorizationHeader?.startsWith('Bearer ')) return null;
    const supplied = authorizationHeader.slice(7);
    let matched: PrincipalAccess | null = null;
    for (const principal of this.principals) {
      const bareMatch = secureEqual(supplied, principal.bearerToken);
      if (principal.id !== PHASE2D_GATEWAY_PRINCIPAL_ID || this.phase2dGatewayGeneration === null) {
        if (bareMatch) matched = principal;
        continue;
      }

      const contextualBearer = `${principal.bearerToken}.${this.phase2dGatewayGeneration}`;
      const contextualMatch = secureEqual(supplied, contextualBearer);
      if (contextualMatch || (!this.requirePhase2dGatewayGeneration && bareMatch)) {
        matched = principal;
      }
    }
    return matched;
  }

  canAccess(principal: PrincipalAccess, tenantId: string): boolean {
    return principal.allowedTenantIds.includes(tenantId);
  }
}
