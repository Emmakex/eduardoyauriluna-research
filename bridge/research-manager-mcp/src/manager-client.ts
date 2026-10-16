import { randomBytes, randomUUID } from 'node:crypto';
import type { TenantConfig } from './config.js';

export type ResearchManagerStaticReadRoute = '/status' | '/versions' | '/capabilities' | '/pages';
type FetchLike = (input: string | URL | Request, init?: RequestInit) => Promise<Response>;

interface ManagerEnvelope {
  ok?: boolean;
  code?: string;
  message?: string;
  data?: unknown;
}

export class ResearchManagerError extends Error {
  readonly status: number | null;
  readonly code: string;
  readonly requestId: string;

  constructor(message: string, options: { status: number | null; code: string; requestId: string }) {
    super(message);
    this.name = 'ResearchManagerError';
    this.status = options.status;
    this.code = options.code;
    this.requestId = options.requestId;
  }
}

export class ResearchManagerClient {
  private readonly baseUrl: URL;
  private readonly token: string;
  private readonly timeoutMs: number;
  private readonly fetchImpl: FetchLike;

  constructor(
    config: Pick<TenantConfig, 'managerBaseUrl' | 'managerReadToken' | 'managerTimeoutMs'>,
    fetchImpl: FetchLike = fetch,
  ) {
    this.baseUrl = config.managerBaseUrl;
    this.token = config.managerReadToken;
    this.timeoutMs = config.managerTimeoutMs;
    this.fetchImpl = fetchImpl;
  }

  status(): Promise<unknown> {
    return this.getStatic('/status');
  }

  versions(): Promise<unknown> {
    return this.getStatic('/versions');
  }

  capabilities(): Promise<unknown> {
    return this.getStatic('/capabilities');
  }

  pages(): Promise<unknown> {
    return this.getStatic('/pages');
  }

  page(key: string, language: string): Promise<unknown> {
    if (!/^[a-z0-9][a-z0-9-]{0,62}$/.test(key)) {
      throw new Error('Page key must be a lowercase registered key');
    }
    if (!/^[a-z][a-z0-9-]{1,15}$/.test(language)) {
      throw new Error('Page language must be a lowercase language code');
    }
    const endpoint = new URL(`/wp-json/research-manager/v1/pages/${encodeURIComponent(key)}`, this.baseUrl);
    endpoint.searchParams.set('language', language);
    return this.getEndpoint(endpoint, `page-${key}-${language}`, `/pages/${key}`);
  }

  private getStatic(route: ResearchManagerStaticReadRoute): Promise<unknown> {
    const endpoint = new URL(`/wp-json/research-manager/v1${route}`, this.baseUrl);
    return this.getEndpoint(endpoint, route.slice(1).replaceAll('/', '-'), route);
  }

  private async getEndpoint(endpoint: URL, label: string, diagnosticRoute: string): Promise<unknown> {
    const requestId = `mcp-${label}-${Date.now()}-${randomUUID()}`;
    const headers = new Headers({
      Accept: 'application/json',
      Authorization: `Bearer ${this.token}`,
      'User-Agent': 'Site-Control-MCP/0.5.0 ResearchManagerAdapter',
      'X-Research-Manager-Request-Id': requestId,
      'X-Research-Manager-Nonce': randomBytes(16).toString('hex'),
      'X-Research-Manager-Timestamp': Math.floor(Date.now() / 1000).toString(),
    });

    let response: Response;
    try {
      response = await this.fetchImpl(endpoint, {
        method: 'GET',
        headers,
        redirect: 'error',
        signal: AbortSignal.timeout(this.timeoutMs),
      });
    } catch (error) {
      const detail = error instanceof Error ? error.message : 'network failure';
      throw new ResearchManagerError(`Research Manager request failed: ${detail}`, {
        status: null,
        code: 'transport_error',
        requestId,
      });
    }

    const raw = await response.text();
    let envelope: ManagerEnvelope = {};
    try {
      const parsed: unknown = raw ? JSON.parse(raw) : {};
      if (typeof parsed === 'object' && parsed !== null) envelope = parsed as ManagerEnvelope;
    } catch {
      throw new ResearchManagerError(`Research Manager returned invalid JSON (HTTP ${response.status})`, {
        status: response.status,
        code: 'invalid_json',
        requestId,
      });
    }

    if (!response.ok || envelope.ok !== true || !('data' in envelope)) {
      throw new ResearchManagerError(
        `Research Manager rejected ${diagnosticRoute} with HTTP ${response.status}: ${envelope.code ?? 'request_failed'}`,
        {
          status: response.status,
          code: envelope.code ?? 'request_failed',
          requestId,
        },
      );
    }

    return envelope.data;
  }
}
