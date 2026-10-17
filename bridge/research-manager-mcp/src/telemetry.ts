import { appendFileSync, chmodSync, closeSync, mkdirSync, openSync } from 'node:fs';
import { dirname, isAbsolute } from 'node:path';

export interface UsageTelemetry {
  window_started_at: number;
  allowed: number;
  denied: number;
  rate_limited: number;
}

export interface AccessTelemetryEvent {
  event: 'mcp_access';
  timestamp: string;
  request_id: string;
  principal_id: string;
  tenant_id: string;
  outcome: 'allowed' | 'denied' | 'rate_limited';
  request_path: string;
  usage: UsageTelemetry;
}

export interface EntitlementTelemetryEvent {
  event: 'mcp_entitlement_denied';
  timestamp: string;
  request_id: string;
  principal_id: string;
  tenant_id: string;
  capability: string;
  reason: string;
}

export type TelemetryEvent = AccessTelemetryEvent | EntitlementTelemetryEvent;

export interface TelemetrySink {
  readonly persistent: boolean;
  write(event: TelemetryEvent): void;
}

class NullTelemetrySink implements TelemetrySink {
  readonly persistent = false;
  write(_event: TelemetryEvent): void {}
}

export class JsonlTelemetrySink implements TelemetrySink {
  readonly persistent = true;
  readonly path: string;

  constructor(path: string) {
    const normalized = path.trim();
    if (!normalized || !isAbsolute(normalized) || normalized.includes('\0') || normalized.length > 4096) {
      throw new Error('MCP telemetry path must be a bounded absolute filesystem path');
    }
    this.path = normalized;
    mkdirSync(dirname(this.path), { recursive: true, mode: 0o700 });
    const fd = openSync(this.path, 'a', 0o600);
    closeSync(fd);
    chmodSync(this.path, 0o600);
  }

  write(event: TelemetryEvent): void {
    appendFileSync(this.path, `${JSON.stringify(event)}\n`, { encoding: 'utf8', mode: 0o600 });
  }
}

export function createTelemetrySink(path: string | null, required: boolean): TelemetrySink {
  if (path === null) {
    if (required) throw new Error('Persistent MCP telemetry is required but MCP_TELEMETRY_PATH is not configured');
    return new NullTelemetrySink();
  }
  return new JsonlTelemetrySink(path);
}
