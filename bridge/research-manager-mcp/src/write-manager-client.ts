import { randomBytes, randomUUID } from 'node:crypto';
import type { TenantConfig } from './config.js';
import { ResearchManagerError } from './manager-client.js';
import type {
  SitePageSlotsApplyRequest,
  SitePageSlotsPlanRequest,
  SitePageSlotValue,
} from './adapters/site-control.js';

type FetchLike = (input: string | URL | Request, init?: RequestInit) => Promise<Response>;
interface ManagerEnvelope { ok?: boolean; code?: string; message?: string; data?: unknown; }

type WriteClientConfig = Pick<TenantConfig, 'managerBaseUrl' | 'managerWriteToken' | 'managerTimeoutMs'>;
type WriteRoute = '/operations/plan' | `/plans/${string}/apply`;

export class ResearchManagerWriteClient {
  private readonly baseUrl: URL;
  private readonly token: string | null;
  private readonly timeoutMs: number;
  private readonly fetchImpl: FetchLike;

  constructor(config: WriteClientConfig, fetchImpl: FetchLike = fetch) {
    this.baseUrl = config.managerBaseUrl;
    this.token = config.managerWriteToken;
    this.timeoutMs = config.managerTimeoutMs;
    this.fetchImpl = fetchImpl;
  }

  planPageSlots(request: SitePageSlotsPlanRequest): Promise<unknown> {
    this.requireKey(request.key);
    this.requireLanguage(request.language);
    this.requireSlots(request.slots);
    if (request.reason !== undefined && request.reason.length > 256) {
      throw new Error('Page plan reason must be 256 characters or fewer');
    }

    return this.post(
      '/operations/plan',
      {
        operation: 'page-slots-update',
        payload: {
          key: request.key,
          language: request.language,
          slots: request.slots,
          ...(request.reason ? { reason: request.reason } : {}),
        },
      },
      'page-slots-plan',
    );
  }

  applyPageSlots(request: SitePageSlotsApplyRequest): Promise<unknown> {
    this.requirePlanId(request.planId);
    if (request.confirm !== true) {
      throw new Error('Page slot Apply requires explicit confirmation');
    }
    return this.post(
      `/plans/${encodeURIComponent(request.planId)}/apply`,
      { confirm: true },
      'page-slots-apply',
    );
  }

  private requireKey(key: string): void {
    if (!/^[a-z0-9][a-z0-9-]{0,62}$/.test(key)) {
      throw new Error('Page key must be a lowercase registered key');
    }
  }

  private requireLanguage(language: string): void {
    if (!/^[a-z][a-z0-9-]{1,15}$/.test(language)) {
      throw new Error('Page language must be a lowercase language code');
    }
  }

  private requirePlanId(planId: string): void {
    if (!/^erm-remote-plan-[a-f0-9]{20}-[a-f0-9]{8}$/.test(planId)) {
      throw new Error('Page slot plan ID has an invalid format');
    }
  }

  private requireSlots(slots: Record<string, SitePageSlotValue>): void {
    const entries = Object.entries(slots);
    if (entries.length < 1 || entries.length > 32) {
      throw new Error('Page slot plan must contain between 1 and 32 Theme-owned slots');
    }
    for (const [name, value] of entries) {
      if (!/^[a-z0-9][a-z0-9-]{0,62}$/.test(name)) {
        throw new Error(`Page slot name is invalid: ${name}`);
      }
      if (typeof value === 'string') {
        if (value.length > 12_000) throw new Error(`Page slot ${name} exceeds 12000 characters`);
        continue;
      }
      if (!Array.isArray(value) || value.length < 1 || value.length > 32) {
        throw new Error(`Page slot ${name} must be scalar text or an array with 1-32 items`);
      }
      for (const item of value) {
        if (typeof item !== 'string' || item.length > 1_000) {
          throw new Error(`Page slot ${name} array items must be text up to 1000 characters`);
        }
      }
    }
  }

  private async post(route: WriteRoute, body: unknown, label: string): Promise<unknown> {
    if (!this.token) {
      throw new Error('Research Manager write credential is not configured for this tenant');
    }

    const endpoint = new URL(`/wp-json/research-manager/v1${route}`, this.baseUrl);
    const requestId = `mcp-${label}-${Date.now()}-${randomUUID()}`;
    const headers = new Headers({
      Accept: 'application/json',
      Authorization: `Bearer ${this.token}`,
      'Content-Type': 'application/json',
      'User-Agent': 'Site-Control-MCP/0.10.0 ResearchManagerAdapter',
      'X-Research-Manager-Request-Id': requestId,
      'X-Research-Manager-Nonce': randomBytes(16).toString('hex'),
      'X-Research-Manager-Timestamp': Math.floor(Date.now() / 1000).toString(),
    });

    let response: Response;
    try {
      response = await this.fetchImpl(endpoint, {
        method: 'POST',
        headers,
        body: JSON.stringify(body),
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
        `Research Manager rejected ${route} with HTTP ${response.status}: ${envelope.code ?? 'request_failed'}`,
        { status: response.status, code: envelope.code ?? 'request_failed', requestId },
      );
    }

    return envelope.data;
  }
}
