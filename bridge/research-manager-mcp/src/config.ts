export interface BridgeConfig {
  managerBaseUrl: URL;
  managerReadToken: string;
  managerTimeoutMs: number;
  bindHost: string;
  port: number;
  inboundBearerToken: string | null;
  allowedHosts: string[];
  allowedOriginHosts: string[];
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

export function loadConfig(env: NodeJS.ProcessEnv = process.env): BridgeConfig {
  const managerBaseUrl = new URL(required('ERM_BASE_URL', env));
  if (managerBaseUrl.protocol !== 'https:') throw new Error('ERM_BASE_URL must use HTTPS');

  const managerReadToken = required('ERM_READ_TOKEN', env);
  if (!managerReadToken.startsWith('ermt1_') && !managerReadToken.startsWith('erm1_')) {
    throw new Error('ERM_READ_TOKEN does not look like a Research Manager credential');
  }

  const bindHost = (env.MCP_BIND_HOST ?? '127.0.0.1').trim();
  const inboundBearerToken = (env.MCP_BEARER_TOKEN ?? '').trim() || null;
  const isLoopback = ['127.0.0.1', '::1', 'localhost'].includes(bindHost.toLowerCase());
  if (!isLoopback && inboundBearerToken === null) {
    throw new Error('MCP_BEARER_TOKEN is required when MCP_BIND_HOST is not loopback');
  }

  return {
    managerBaseUrl,
    managerReadToken,
    managerTimeoutMs: positiveInt('ERM_TIMEOUT_MS', env.ERM_TIMEOUT_MS, 20_000),
    bindHost,
    port: positiveInt('MCP_PORT', env.MCP_PORT, 8787),
    inboundBearerToken,
    allowedHosts: csv(env.MCP_ALLOWED_HOSTS || 'localhost,127.0.0.1'),
    allowedOriginHosts: csv(env.MCP_ALLOWED_ORIGIN_HOSTS),
  };
}
