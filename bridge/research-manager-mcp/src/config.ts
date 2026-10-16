export type TenantAdapter = 'research-manager';

export interface TenantConfig {
  id: string;
  label: string;
  adapter: TenantAdapter;
  managerBaseUrl: URL;
  managerReadToken: string;
  managerTimeoutMs: number;
  languages: readonly string[];
}

export interface BridgeConfig {
  tenants: readonly TenantConfig[];
  activeTenantId: string;
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
  timeoutMs?: unknown;
  languages?: unknown;
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

function tenantId(raw: unknown, field: string): string {
  if (typeof raw !== 'string' || !/^[a-z0-9][a-z0-9-]{1,62}$/.test(raw)) {
    throw new Error(`${field} must be a lowercase tenant id (2-63 chars)`);
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

function tenantFromDefinition(definition: TenantDefinition, env: NodeJS.ProcessEnv, index: number): TenantConfig {
  const prefix = `MCP_TENANTS_JSON[${index}]`;
  const id = tenantId(definition.id, `${prefix}.id`);
  const label = typeof definition.label === 'string' && definition.label.trim() ? definition.label.trim() : id;
  if (definition.adapter !== 'research-manager') throw new Error(`${prefix}.adapter must be research-manager`);
  if (typeof definition.readTokenEnv !== 'string' || !definition.readTokenEnv.trim()) {
    throw new Error(`${prefix}.readTokenEnv is required`);
  }
  const tokenEnv = definition.readTokenEnv.trim();
  const timeoutMs =
    typeof definition.timeoutMs === 'number' && Number.isInteger(definition.timeoutMs) && definition.timeoutMs > 0
      ? definition.timeoutMs
      : 20_000;

  return {
    id,
    label,
    adapter: 'research-manager',
    managerBaseUrl: httpsUrl(definition.baseUrl, `${prefix}.baseUrl`),
    managerReadToken: managerToken(required(tokenEnv, env), tokenEnv),
    managerTimeoutMs: timeoutMs,
    languages: languages(definition.languages, `${prefix}.languages`),
  };
}

function configuredTenants(env: NodeJS.ProcessEnv): readonly TenantConfig[] {
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
      return tenantFromDefinition(item as TenantDefinition, env, index);
    });
  }

  const managerBaseUrl = httpsUrl(required('ERM_BASE_URL', env), 'ERM_BASE_URL');
  return [
    {
      id: tenantId(env.MCP_TENANT_ID?.trim() || 'eduardo-research', 'MCP_TENANT_ID'),
      label: (env.MCP_TENANT_LABEL ?? 'Eduardo Research').trim() || 'Eduardo Research',
      adapter: 'research-manager',
      managerBaseUrl,
      managerReadToken: managerToken(required('ERM_READ_TOKEN', env), 'ERM_READ_TOKEN'),
      managerTimeoutMs: positiveInt('ERM_TIMEOUT_MS', env.ERM_TIMEOUT_MS, 20_000),
      languages: csv(env.MCP_TENANT_LANGUAGES || 'en,es'),
    },
  ];
}

export function loadConfig(env: NodeJS.ProcessEnv = process.env): BridgeConfig {
  const tenants = configuredTenants(env);
  const requestedTenant = (env.MCP_TENANT_ID ?? '').trim();
  const activeTenantId = requestedTenant || tenants[0]!.id;
  if (!tenants.some((tenant) => tenant.id === activeTenantId)) {
    throw new Error(`MCP_TENANT_ID is not registered: ${activeTenantId}`);
  }

  const bindHost = (env.MCP_BIND_HOST ?? '127.0.0.1').trim();
  const inboundBearerToken = (env.MCP_BEARER_TOKEN ?? '').trim() || null;
  const isLoopback = ['127.0.0.1', '::1', 'localhost'].includes(bindHost.toLowerCase());
  if (!isLoopback && inboundBearerToken === null) {
    throw new Error('MCP_BEARER_TOKEN is required when MCP_BIND_HOST is not loopback');
  }

  return {
    tenants,
    activeTenantId,
    bindHost,
    port: positiveInt('MCP_PORT', env.MCP_PORT, 8787),
    inboundBearerToken,
    allowedHosts: csv(env.MCP_ALLOWED_HOSTS || 'localhost,127.0.0.1'),
    allowedOriginHosts: csv(env.MCP_ALLOWED_ORIGIN_HOSTS),
  };
}
