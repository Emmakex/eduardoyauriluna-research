import type { AccessTelemetryEvent } from './telemetry.js';

export type AccessOutcome = 'allowed' | 'denied' | 'rate_limited';

export interface UsageKey {
  principalId: string;
  tenantId: string;
}

export interface UsageSnapshot {
  principalId: string;
  tenantId: string;
  windowStartedAt: number;
  allowed: number;
  denied: number;
  rateLimited: number;
}

interface Counter {
  windowStartedAt: number;
  allowed: number;
  denied: number;
  rateLimited: number;
}

export class UsageBoundary {
  private readonly counters = new Map<string, Counter>();

  constructor(
    private readonly limitPerMinute: number,
    private readonly now: () => number = Date.now,
  ) {
    if (!Number.isInteger(limitPerMinute) || limitPerMinute <= 0) {
      throw new Error('limitPerMinute must be a positive integer');
    }
  }

  admit(key: UsageKey): boolean {
    const counter = this.counter(key);
    if (counter.allowed >= this.limitPerMinute) {
      counter.rateLimited += 1;
      return false;
    }
    counter.allowed += 1;
    return true;
  }

  deny(key: UsageKey): void {
    this.counter(key).denied += 1;
  }

  snapshot(key: UsageKey): UsageSnapshot {
    const counter = this.counter(key);
    return {
      principalId: key.principalId,
      tenantId: key.tenantId,
      windowStartedAt: counter.windowStartedAt,
      allowed: counter.allowed,
      denied: counter.denied,
      rateLimited: counter.rateLimited,
    };
  }

  private counter(key: UsageKey): Counter {
    const minute = Math.floor(this.now() / 60_000) * 60_000;
    const mapKey = `${key.principalId}\u0000${key.tenantId}`;
    const current = this.counters.get(mapKey);
    if (!current || current.windowStartedAt !== minute) {
      const fresh: Counter = { windowStartedAt: minute, allowed: 0, denied: 0, rateLimited: 0 };
      this.counters.set(mapKey, fresh);
      return fresh;
    }
    return current;
  }
}

export function accessAuditRecord(input: {
  requestId: string;
  principalId: string;
  tenantId: string;
  outcome: AccessOutcome;
  requestPath: string;
  usage: UsageSnapshot;
  timestamp?: string;
}): AccessTelemetryEvent {
  return {
    event: 'mcp_access',
    timestamp: input.timestamp ?? new Date().toISOString(),
    request_id: input.requestId,
    principal_id: input.principalId,
    tenant_id: input.tenantId,
    outcome: input.outcome,
    request_path: input.requestPath,
    usage: {
      window_started_at: input.usage.windowStartedAt,
      allowed: input.usage.allowed,
      denied: input.usage.denied,
      rate_limited: input.usage.rateLimited,
    },
  };
}

export function accessAuditEvent(input: {
  requestId?: string;
  principalId: string;
  tenantId: string;
  outcome: AccessOutcome;
  requestPath: string;
  usage?: UsageSnapshot;
}): string {
  const usage = input.usage ?? {
    principalId: input.principalId,
    tenantId: input.tenantId,
    windowStartedAt: 0,
    allowed: 0,
    denied: 0,
    rateLimited: 0,
  };
  return JSON.stringify(accessAuditRecord({
    requestId: input.requestId ?? 'legacy-unscoped',
    principalId: input.principalId,
    tenantId: input.tenantId,
    outcome: input.outcome,
    requestPath: input.requestPath,
    usage,
  }));
}
