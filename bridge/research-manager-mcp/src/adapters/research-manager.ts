import { ResearchManagerClient } from '../manager-client.js';
import type { TenantConfig } from '../config.js';
import type {
  SiteControlAdapter,
  SiteInsightInspectionRequest,
  SiteInsightListRequest,
  SitePageInspectionRequest,
} from './site-control.js';

export class ResearchManagerAdapter implements SiteControlAdapter {
  readonly kind = 'research-manager';
  readonly tenantId: string;
  readonly tenantLabel: string;
  private readonly languages: readonly string[];
  private readonly client: ResearchManagerClient;

  constructor(tenant: TenantConfig, fetchImpl: typeof fetch = fetch) {
    this.tenantId = tenant.id;
    this.tenantLabel = tenant.label;
    this.languages = tenant.languages;
    this.client = new ResearchManagerClient(tenant, fetchImpl);
  }

  status(): Promise<unknown> { return this.client.status(); }
  versions(): Promise<unknown> { return this.client.versions(); }
  capabilities(): Promise<unknown> { return this.client.capabilities(); }
  listPages(): Promise<unknown> { return this.client.pages(); }

  inspectPage(request: SitePageInspectionRequest): Promise<unknown> {
    if (!this.languages.includes(request.language)) throw new Error(`Page language is not enabled for tenant ${this.tenantId}`);
    return this.client.page(request.key, request.language);
  }

  listInsights(request: SiteInsightListRequest): Promise<unknown> {
    const language = request.language ?? '';
    if (language && !this.languages.includes(language)) {
      throw new Error(`Insight language is not enabled for tenant ${this.tenantId}`);
    }
    return this.client.insights(language);
  }

  inspectInsight(request: SiteInsightInspectionRequest): Promise<unknown> {
    return this.client.insight(request.postId);
  }
}
