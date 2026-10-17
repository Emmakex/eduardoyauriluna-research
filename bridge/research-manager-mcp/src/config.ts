import type { PrincipalAccess } from './access-control.js';
import { parseCapabilityList, type CommercialCapability } from './entitlements.js';
import { EnvSecretResolver, type SecretResolver } from './secret-resolver.js';

export type TenantAdapter = 'research-manager';

export interface TenantConfig {
  id: string;
  label: string;
  adapter: TenantAdapter;
  managerBaseUrl: URL;
  managerReadToken: string;
  managerWriteToken: string | null;
  managerTimeoutMs: number;
  languages: readonly string[];
  entitlements: readonly CommercialCapability[];
}

export interface BridgeConfig {
  tenants: readonly TenantConfig[];
  principals: readonly PrincipalAccess[];
  activeTenantId: string;
  sharedRouting: boolean;
  requestsPerMinute: number;
  bindHost: string;
  port: number;
  inboundBearerToken: string | null;
  allowedHosts: string[];
  allowedOriginHosts: string[];
}

interface TenantDefinition {
  id?: unknown;
  label?: unknown;
  adapter?: unknown;
  baseUrl?: unknown;
  readTokenEnv?: unknown;
  writeTokenEnv?: unknown;
  timeoutMs?: unknown;
  languages?: unknown;
  entitlements?: unknown;
}

interface PrincipalDefinition {
  id?: unknown;
  bearerTokenEnv?: unknown;
  tenants?: unknown;
  capabilities?: unknown;
}

function required(name: string, env: NodeJS.ProcessEnv): string {
  const value = (env[name] ?? '').trim();
  if (!value) throw new Error(`Missing required environment variable: ${name}`);
  return value;
}

function csv(value: string | undefined): string[] {
  return (value ?? '')
    .split(',')
    .map((item) => item.trim().toLowerCase())
    .filter(Boolean);
}

function positiveInt(name: string, raw: string | undefined, fallback: number): number {
  if (!raw) return fallback;
  const parsed = Number.parseInt(raw, 10);
  if (!Number.isInteger(parsed) || parsed <= 0) throw new Error(`${name} must be a positive integer`);
  return parsed;
}

function enabled(name: string, raw: string | undefined): boolean {
  if (!raw) return false;
  const normalized = raw.trim().toLowerCase();
  if (['1', 'true', 'yes', 'on'].includes(normalized)) return true;
  if (['0', 'false', 'no', 'off'].includes(normalized)) return false;
  throw new Error(`${name} must be true/false`);
}

function tenantId(raw: unknown, field: string): string {
  if (typeof raw !== 'string' || !/^[a-z0-9][a-z0-9-]{1,62}$/.test(raw)) {
    throw new Error(`${field} must be a lowercase tenant id (2-63 chars)`);
  }
  return raw;
}

function principalId(raw: unknown, field: string): string {
  if (typeof raw !== 'string' || !/^[a-z0-9][a-z0-9._-]{1,62}$/.test(raw)) {
    throw new Error(`${field} must be a lowercase principal id (2-63 chars)`);
  }
  return raw;
}

function httpsUrl(raw: unknown, field: string): URL {
  if (typeof raw !== 'string' || !raw.trim()) throw new Error(`${field} is required`);
  const url = new URL(raw);
  if (url.protocol !== 'https:') throw new Error(`${field} must use HTTPS`);
  return url;
}

function managerToken(raw: string, field: string): string {
  if (!raw.startsWith('ermt1_') && !raw.startsWith('erm1_')) {
    throw new Error(`${field} does not look like a Research Manager credential`);
  }
  return raw;
}

function languages(raw: unknown, field: string): readonly string[] {
  if (raw === undefined) return ['en', 'es'];
  if (!Array.isArray(raw) || raw.length === 0 || raw.some((item) => typeof item !== 'string' || !item.trim())) {
    throw new Error(`${field} must be a non-empty string array`);
  }
  return [...new Set(raw.map((item) => (item as string).trim().toLowerCase()))];
}

function tenantFromDefinition(
  definition: TenantDefinition,
  resolver: SecretResolver,
  index: number,
): TenantConfig {
  const prefix = `MCP_TENANTS_JSON[${index}]`;
  const id = tenantId(definition.id, `${prefix}.id`);
  const label = typeof definition.label === 'string' && definition.label.trim() ? definition.label.trim() : id;
  if (definition.adapter !== 'research-manager') throw new Error(`${prefix}.adapter must be research-manager`);
  if (typeof definition.readTokenEnv !== 'string' || !definition.readTokenEnv.trim()) {
    throw new Error(`${prefix}.readTokenEnv is required`);
  }
  const readTokenReference = definition.readTokenEnv.trim();
  const writeTokenReference =
    typeof definition.writeTokenEnv === 'string' && definition.writeTokenEnv.trim()
      ? definition.writeTokenEnv.trim()
      : null;
  const timeoutMs =
    typeof definition.timeoutMs === 'number' && Number.isInteger(definition.timeoutMs) && definition.timeoutMs > 0
      ? definition.timeoutMs
      : 20_000;

  return {
    id,
    label,
    adapter: 'research-manager',
    managerBaseUrl: httpsUrl(definition.baseUrl, `${prefix}.baseUrl`),
    managerReadToken: managerToken(resolver.resolve(readTokenReference), readTokenReference),
    managerWriteToken: writeTokenReference
      ? managerToken(resolver.resolve(writeTokenReference), writeTokenReference)
      : null,
    managerTimeoutMs: timeoutMs,
    languages: languages(definition.languages, `${prefix}.languages`),
    entitlements: parseCapabilityList(definition.entitlements, `${prefix}.entitlements`),
  };
}

function configuredTenants(env: NodeJS.ProcessEnv, resolver: SecretResolver): readonly TenantConfig[] {
  const raw = (env.MCP_TENANTS_JSON ?? '').trim();
  if (raw) {
    let parsed: unknown;
    try {
      parsed = JSON.parse(raw);
    } catch {
      throw new Error('MCP_TENANTS_JSON must be valid JSON');
    }
    if (!Array.isArray(parsed) || parsed.length === 0) throw new Error('MCP_TENANTS_JSON must be a non-empty array');
    return parsed.map((item, index) => {
      if (typeof item !== 'object' || item === null) throw new Error(`MCP_TENANTS_JSON[${index}] must be an object`);
      return tenantFromDefinition(item as TenantDefinition, resolver, index);
    });
  }

  const managerBaseUrl = httpsUrl(required('ERM_BASE_URL', env), 'ERM_BASE_URL');
  const managerWriteToken = (env.ERM_WRITE_TOKEN ?? '').trim();
  return [
    {
      id: tenantId(env.MCP_TENANT_ID?.trim() || 'eduardo-research', 'MCP_TENANT_ID'),
      label: (env.MCP_TENANT_LABEL ?? 'Eduardo Research').trim() || 'Eduardo Research',
      adapter: 'research-manager',
      managerBaseUrl,
      managerReadToken: managerToken(resolver.resolve('ERM_READ_TOKEN'), 'ERM_READ_TOKEN'),
      managerWriteToken: managerWriteToken
        ? managerToken(resolver.resolve('ERM_WRITE_TOKEN'), 'ERM_WRITE_TOKEN')
        : null,
      managerTimeoutMs: positiveInt('ERM_TIMEOUT_MS', env.ERM_TIMEOUT_MS, 20_000),
      languages: csv(env.MCP_TENANT_LANGUAGES || 'en,es'),
      entitlements: parseCapabilityList(
        csv(env.MCP_TENANT_ENTITLEMENTS || 'site.read'),
        'MCP_TENANT_ENTITLEMENTS',
      ),
    },
  ];
}

function configuredPrincipals(
  env: NodeJS.ProcessEnv,
  resolver: SecretResolver,
  tenantIds: ReadonlySet<string>,
): readonly PrincipalAccess[] {
  const raw = (env.MCP_PRINCIPALS_JSON ?? '').trim();
  if (!raw) return [];

  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    throw new Error('MCP_PRINCIPALS_JSON must be valid JSON');
  }
  if (!Array.isArray(parsed) || parsed.length === 0) throw new Error('MCP_PRINCIPALS_JSON must be a non-empty array');

  return parsed.map((item, index) => {
    if (typeof item !== 'object' || item === null) throw new Error(`MCP_PRINCIPALS_JSON[${index}] must be an object`);
    const definition = item as PrincipalDefinition;
    const prefix = `MCP_PRINCIPALS_JSON[${index}]`;
    const id = principalId(definition.id, `${prefix}.id`);
    if (typeof definition.bearerTokenEnv !== 'string' || !definition.bearerTokenEnv.trim()) {
      throw new Error(`${prefix}.bearerTokenEnv is required`);
    }
    if (!Array.isArray(definition.tenants) || definition.tenants.length === 0) {
      throw new Error(`${prefix}.tenants must be a non-empty tenant id array`);
    }
    const allowedTenantIds = [...new Set(definition.tenants.map((entry, tenantIndex) => tenantId(entry, `${prefix}.tenants[${tenantIndex}]`)))];
    for (const allowedTenantId of allowedTenantIds) {
      if (!tenantIds.has(allowedTenantId)) {
        throw new Error(`${prefix} references unknown tenant id: ${allowedTenantId}`);
      }
    }
    return {
      id,
      bearerToken: resolver.resolve(definition.bearerTokenEnv.trim()),
      allowedTenantIds,
      capabilities: parseCapabilityList(definition.capabilities, `${prefix}.capabilities`),
    };
  });
}

export function loadConfig(
  env: NodeJS.ProcessEnv = process.env,
  resolver: SecretResolver = new EnvSecretResolver(env),
): BridgeConfig {
  const tenants = configuredTenants(env, resolver);
  const requestedTenant = (env.MCP_TENANT_ID ?? '').trim();
  const activeTenantId = requestedTenant || tenants[0]!.id;
  if (!tenants.some((tenant) => tenant.id === activeTenantId)) {
    throw new Error(`MCP_TENANT_ID is not registered: ${activeTenantId}`);
  }

  const tenantIds = new Set(tenants.map((tenant) => tenant.id));
  const principals = configuredPrincipals(env, resolver, tenantIds);
  const sharedRouting = enabled('MCP_SHARED_ROUTING', env.MCP_SHARED_ROUTING);
  const bindHost = (env.MCP_BIND_HOST ?? '127.0.0.1').trim();
  const inboundBearerToken = (env.MCP_BEARER_TOKEN ?? '').trim() || null;
  const isLoopback = ['127.0.0.1', '::1', 'localhost'].includes(bindHost.toLowerCase());

  if (sharedRouting && principals.length === 0) {
    throw new Error('MCP_SHARED_ROUTING requires MCP_PRINCIPALS_JSON');
  }
  if (sharedRouting && inboundBearerToken !== null) {
    throw new Error('MCP_BEARER_TOKEN is legacy single-tenant auth and cannot be used with MCP_SHARED_ROUTING');
  }
  if (!sharedRouting && !isLoopback && inboundBearerToken === null) {
    throw new Error('MCP_BEARER_TOKEN is required for non-loopback single-tenant mode');
  }

  return {
    tenants,
    principals,
    activeTenantId,
    sharedRouting,
    requestsPerMinute: positiveInt('MCP_REQUESTS_PER_MINUTE', env.MCP_REQUESTS_PER_MINUTE, 120),
    bindHost,
    port: positiveInt('MCP_PORT', env.MCP_PORT, 8787),
    inboundBearerToken,
    allowedHosts: csv(env.MCP_ALLOWED_HOSTS || 'localhost,127.0.0.1'),
    allowedOriginHosts: csv(env.MCP_ALLOWED_ORIGIN_HOSTS),
  };
}
