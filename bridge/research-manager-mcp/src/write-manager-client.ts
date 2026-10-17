import { randomBytes, randomUUID } from 'node:crypto';
import type { TenantConfig } from './config.js';
import { ResearchManagerError } from './manager-client.js';
import type {
  SiteInsightApplyRequest,
  SiteInsightCreateData,
  SiteInsightCreatePlanRequest,
  SiteInsightPairPlanRequest,
  SiteInsightPairingApplyRequest,
  SiteInsightPairingRollbackRequest,
  SiteInsightPairingVerifyRequest,
  SiteInsightRollbackRequest,
  SiteInsightUnpairPlanRequest,
  SiteInsightUpdateChanges,
  SiteInsightUpdatePlanRequest,
  SiteInsightVerifyRequest,
  SitePageSlotsApplyRequest,
  SitePageSlotsPlanRequest,
  SitePageSlotsRollbackRequest,
  SitePageSlotsVerifyRequest,
  SitePageSlotValue,
} from './adapters/site-control.js';

type FetchLike = (input: string | URL | Request, init?: RequestInit) => Promise<Response>;
interface ManagerEnvelope { ok?: boolean; code?: string; message?: string; data?: unknown; }

type WriteClientConfig = Pick<TenantConfig, 'managerBaseUrl' | 'managerWriteToken' | 'managerTimeoutMs'>;
type WriteRoute =
  | '/operations/plan'
  | `/plans/${string}`
  | `/plans/${string}/apply`
  | `/operations/${string}/verify`
  | `/operations/${string}/rollback`
  | '/insights/pairing/plan'
  | `/insights/pairing/plans/${string}`
  | `/insights/pairing/plans/${string}/apply`
  | `/insights/pairing/operations/${string}`
  | `/insights/pairing/operations/${string}/verify`
  | `/insights/pairing/operations/${string}/rollback`;

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
    this.requireLanguage(request.language, 'Page language');
    this.requireSlots(request.slots);
    this.requireReason(request.reason, 'Page plan');

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
    this.requirePlanId(request.planId, 'Page slot');
    if (request.confirm !== true) {
      throw new Error('Page slot Apply requires explicit confirmation');
    }
    return this.get(
      `/plans/${encodeURIComponent(request.planId)}`,
      'page-slots-apply-preflight',
    ).then((plan) => {
      this.requireOperationType(plan, ['page-slots-update'], 'Page slot plan', 'a page-slots-update operation');
      return this.post(
        `/plans/${encodeURIComponent(request.planId)}/apply`,
        { confirm: true },
        'page-slots-apply',
      );
    });
  }

  verifyPageSlots(request: SitePageSlotsVerifyRequest): Promise<unknown> {
    this.requireOperationId(request.operationId, 'Page slot');
    if (request.rendered !== true) {
      throw new Error('Page slot Verify requires rendered verification');
    }
    return this.post(
      `/operations/${encodeURIComponent(request.operationId)}/verify`,
      { rendered: true },
      'page-slots-verify',
    );
  }

  rollbackPageSlots(request: SitePageSlotsRollbackRequest): Promise<unknown> {
    this.requireOperationId(request.operationId, 'Page slot');
    if (request.confirm !== true) {
      throw new Error('Page slot Rollback requires explicit confirmation');
    }
    return this.post(
      `/operations/${encodeURIComponent(request.operationId)}/rollback`,
      { confirm: true },
      'page-slots-rollback',
    );
  }

  planInsightCreate(request: SiteInsightCreatePlanRequest): Promise<unknown> {
    this.requireInsightCreateData(request.data);
    this.requireReason(request.reason, 'Insight create plan');
    return this.post(
      '/operations/plan',
      {
        operation: 'insight-create',
        payload: {
          data: request.data,
          ...(request.reason ? { reason: request.reason } : {}),
        },
      },
      'insight-create-plan',
    );
  }

  planInsightUpdate(request: SiteInsightUpdatePlanRequest): Promise<unknown> {
    this.requirePostId(request.postId, 'Insight');
    this.requireInsightUpdateChanges(request.changes);
    this.requireReason(request.reason, 'Insight update plan');
    return this.post(
      '/operations/plan',
      {
        operation: 'insight-update',
        payload: {
          post_id: request.postId,
          changes: request.changes,
          ...(request.reason ? { reason: request.reason } : {}),
        },
      },
      'insight-update-plan',
    );
  }

  applyInsight(request: SiteInsightApplyRequest): Promise<unknown> {
    this.requirePlanId(request.planId, 'Insight');
    if (request.confirm !== true) throw new Error('Insight Apply requires explicit confirmation');
    return this.get(
      `/plans/${encodeURIComponent(request.planId)}`,
      'insight-apply-preflight',
    ).then((plan) => {
      this.requireOperationType(plan, ['insight-create', 'insight-update'], 'Insight plan', 'an allowed operation');
      return this.post(
        `/plans/${encodeURIComponent(request.planId)}/apply`,
        { confirm: true },
        'insight-apply',
      );
    });
  }

  verifyInsight(request: SiteInsightVerifyRequest): Promise<unknown> {
    this.requireOperationId(request.operationId, 'Insight');
    if (request.rendered !== true) throw new Error('Insight Verify requires rendered verification');
    return this.post(
      `/operations/${encodeURIComponent(request.operationId)}/verify`,
      { rendered: true },
      'insight-verify',
    );
  }

  rollbackInsight(request: SiteInsightRollbackRequest): Promise<unknown> {
    this.requireOperationId(request.operationId, 'Insight');
    if (request.confirm !== true) throw new Error('Insight Rollback requires explicit confirmation');
    return this.post(
      `/operations/${encodeURIComponent(request.operationId)}/rollback`,
      { confirm: true },
      'insight-rollback',
    );
  }

  planInsightPair(request: SiteInsightPairPlanRequest): Promise<unknown> {
    this.requirePostId(request.firstId, 'First Insight');
    this.requirePostId(request.secondId, 'Second Insight');
    return this.post(
      '/insights/pairing/plan',
      { operation: 'insight-pair', payload: { first_id: request.firstId, second_id: request.secondId } },
      'insight-pair-plan',
    );
  }

  planInsightUnpair(request: SiteInsightUnpairPlanRequest): Promise<unknown> {
    this.requirePostId(request.postId, 'Insight');
    return this.post(
      '/insights/pairing/plan',
      { operation: 'insight-unpair', payload: { post_id: request.postId } },
      'insight-unpair-plan',
    );
  }

  applyInsightPairing(request: SiteInsightPairingApplyRequest): Promise<unknown> {
    this.requirePairingPlanId(request.planId);
    if (request.confirm !== true) throw new Error('Insight pairing Apply requires explicit confirmation');
    return this.get(
      `/insights/pairing/plans/${encodeURIComponent(request.planId)}`,
      'insight-pairing-apply-preflight',
    ).then((plan) => {
      this.requireOperationType(plan, ['insight-pair', 'insight-unpair'], 'Insight pairing plan', 'an allowed Insight pairing operation');
      return this.post(
        `/insights/pairing/plans/${encodeURIComponent(request.planId)}/apply`,
        { confirm: true },
        'insight-pairing-apply',
      );
    });
  }

  verifyInsightPairing(request: SiteInsightPairingVerifyRequest): Promise<unknown> {
    this.requirePairingOperationId(request.operationId);
    return this.get(
      `/insights/pairing/operations/${encodeURIComponent(request.operationId)}`,
      'insight-pairing-verify-preflight',
    ).then((operation) => {
      this.requireOperationType(operation, ['insight-pair', 'insight-unpair'], 'Insight pairing operation', 'an allowed Insight pairing operation');
      return this.post(
        `/insights/pairing/operations/${encodeURIComponent(request.operationId)}/verify`,
        { rendered: request.rendered },
        'insight-pairing-verify',
      );
    });
  }

  rollbackInsightPairing(request: SiteInsightPairingRollbackRequest): Promise<unknown> {
    this.requirePairingOperationId(request.operationId);
    if (request.confirm !== true) throw new Error('Insight pairing Rollback requires explicit confirmation');
    return this.get(
      `/insights/pairing/operations/${encodeURIComponent(request.operationId)}`,
      'insight-pairing-rollback-preflight',
    ).then((operation) => {
      this.requireOperationType(operation, ['insight-pair', 'insight-unpair'], 'Insight pairing operation', 'an allowed Insight pairing operation');
      return this.post(
        `/insights/pairing/operations/${encodeURIComponent(request.operationId)}/rollback`,
        { confirm: true },
        'insight-pairing-rollback',
      );
    });
  }

  private requireKey(key: string): void {
    if (!/^[a-z0-9][a-z0-9-]{0,62}$/.test(key)) {
      throw new Error('Page key must be a lowercase registered key');
    }
  }

  private requireLanguage(language: string, label: string): void {
    if (!/^[a-z][a-z0-9-]{1,15}$/.test(language)) {
      throw new Error(`${label} must be a lowercase language code`);
    }
  }

  private requirePostId(postId: number, label: string): void {
    if (!Number.isSafeInteger(postId) || postId <= 0) throw new Error(`${label} post ID must be a positive safe integer`);
  }

  private requirePlanId(planId: string, label: string): void {
    if (!/^erm-remote-plan-[a-f0-9]{20}-[a-f0-9]{8}$/.test(planId)) {
      throw new Error(`${label} plan ID has an invalid format`);
    }
  }

  private requireOperationId(operationId: string, label: string): void {
    if (!/^erm-operation-[a-f0-9]{32}$/.test(operationId)) {
      throw new Error(`${label} operation ID has an invalid format`);
    }
  }

  private requirePairingPlanId(planId: string): void {
    if (!/^erm-insight-pair-plan-[a-f0-9]{18}-[a-f0-9]{8}$/.test(planId)) {
      throw new Error('Insight pairing plan ID has an invalid format');
    }
  }

  private requirePairingOperationId(operationId: string): void {
    if (!/^erm-insight-pair-op-[a-f0-9]{32}$/.test(operationId)) {
      throw new Error('Insight pairing operation ID has an invalid format');
    }
  }

  private requireOperationType(value: unknown, allowed: readonly string[], label: string, expectedDescription: string): void {
    if (
      typeof value !== 'object'
      || value === null
      || Array.isArray(value)
      || typeof (value as Record<string, unknown>).operation !== 'string'
      || !allowed.includes((value as Record<string, unknown>).operation as string)
    ) {
      throw new Error(`${label} does not reference ${expectedDescription}`);
    }
  }

  private requireReason(reason: string | undefined, label: string): void {
    if (reason !== undefined && reason.length > 256) throw new Error(`${label} reason must be 256 characters or fewer`);
  }

  private requireInsightCreateData(data: SiteInsightCreateData): void {
    this.requireBoundedText(data.title, 1, 1_000, 'Insight title');
    if (data.slug !== undefined) this.requireBoundedText(data.slug, 1, 200, 'Insight slug');
    if (data.excerpt !== undefined) this.requireBoundedText(data.excerpt, 0, 12_000, 'Insight excerpt');
    if (data.content !== undefined) this.requireBoundedText(data.content, 0, 200_000, 'Insight content');
    this.requireLanguage(data.language, 'Insight language');
    if (!/^[a-z0-9][a-z0-9_-]{0,62}$/.test(data.insight_type)) throw new Error('Insight type must be a bounded lowercase key');
    if (!['draft', 'publish'].includes(data.status)) throw new Error('Insight create status must be draft or publish');
  }

  private requireInsightUpdateChanges(changes: SiteInsightUpdateChanges): void {
    const entries = Object.entries(changes).filter(([, value]) => value !== undefined);
    if (entries.length < 1 || entries.length > 8) throw new Error('Insight update must contain between 1 and 8 bounded changes');
    const allowed = new Set(['slug', 'title', 'excerpt', 'content', 'language', 'insight_type', 'status', 'scheduled_at', 'line_ids']);
    for (const [key] of entries) if (!allowed.has(key)) throw new Error(`Insight update field is not allowed: ${key}`);
    if (changes.status !== undefined && entries.length !== 1) throw new Error('Insight status must be changed in its own Preview');
    if (changes.scheduled_at !== undefined && entries.length !== 1) throw new Error('Insight scheduling must be changed in its own Preview');
    if (changes.slug !== undefined) this.requireBoundedText(changes.slug, 1, 200, 'Insight slug');
    if (changes.title !== undefined) this.requireBoundedText(changes.title, 1, 1_000, 'Insight title');
    if (changes.excerpt !== undefined) this.requireBoundedText(changes.excerpt, 0, 12_000, 'Insight excerpt');
    if (changes.content !== undefined) this.requireBoundedText(changes.content, 0, 200_000, 'Insight content');
    if (changes.language !== undefined) this.requireLanguage(changes.language, 'Insight language');
    if (changes.insight_type !== undefined && !/^[a-z0-9][a-z0-9_-]{0,62}$/.test(changes.insight_type)) throw new Error('Insight type must be a bounded lowercase key');
    if (changes.status !== undefined && !['draft', 'publish'].includes(changes.status)) throw new Error('Insight status must be draft or publish');
    if (changes.scheduled_at !== undefined) this.requireBoundedText(changes.scheduled_at, 1, 64, 'Insight scheduled_at');
    if (changes.line_ids !== undefined) {
      if (!Array.isArray(changes.line_ids) || changes.line_ids.length > 32) throw new Error('Insight line_ids must contain at most 32 Research Line IDs');
      const seen = new Set<number>();
      for (const id of changes.line_ids) {
        if (!Number.isSafeInteger(id) || id <= 0) throw new Error('Insight line_ids must contain positive safe integers');
        if (seen.has(id)) throw new Error('Insight line_ids must not contain duplicates');
        seen.add(id);
      }
    }
  }

  private requireBoundedText(value: string, min: number, max: number, label: string): void {
    if (typeof value !== 'string' || value.length < min || value.length > max) {
      throw new Error(`${label} must contain between ${min} and ${max} characters`);
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

  private get(route: WriteRoute, label: string): Promise<unknown> {
    return this.request(route, 'GET', undefined, label);
  }

  private post(route: WriteRoute, body: unknown, label: string): Promise<unknown> {
    return this.request(route, 'POST', body, label);
  }

  private async request(
    route: WriteRoute,
    method: 'GET' | 'POST',
    body: unknown | undefined,
    label: string,
  ): Promise<unknown> {
    if (!this.token) {
      throw new Error('Research Manager write credential is not configured for this tenant');
    }

    const endpoint = new URL(`/wp-json/research-manager/v1${route}`, this.baseUrl);
    const requestId = `mcp-${label}-${Date.now()}-${randomUUID()}`;
    const headers = new Headers({
      Accept: 'application/json',
      Authorization: `Bearer ${this.token}`,
      'User-Agent': 'Site-Control-MCP/0.10.0 ResearchManagerAdapter',
      'X-Research-Manager-Request-Id': requestId,
      'X-Research-Manager-Nonce': randomBytes(16).toString('hex'),
      'X-Research-Manager-Timestamp': Math.floor(Date.now() / 1000).toString(),
    });
    if (body !== undefined) headers.set('Content-Type', 'application/json');

    let response: Response;
    try {
      response = await this.fetchImpl(endpoint, {
        method,
        headers,
        ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
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
