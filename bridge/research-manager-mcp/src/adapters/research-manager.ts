import { ResearchManagerClient } from '../manager-client.js';
import type { TenantConfig } from '../config.js';
import type { SiteControlAdapter } from './site-control.js';

export class ResearchManagerAdapter implements SiteControlAdapter {
  readonly kind = 'research-manager';
  readonly tenantId: string;
  readonly tenantLabel: string;
  private readonly client: ResearchManagerClient;

  constructor(tenant: TenantConfig, fetchImpl: typeof fetch = fetch) {
    this.tenantId = tenant.id;
    this.tenantLabel = tenant.label;
    this.client = new ResearchManagerClient(tenant, fetchImpl);
  }

  status(): Promise<unknown> {
    return this.client.status();
  }

  versions(): Promise<unknown> {
    return this.client.versions();
  }

  capabilities(): Promise<unknown> {
    return this.client.capabilities();
  }
}
