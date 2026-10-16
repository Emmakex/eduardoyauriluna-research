import { ResearchManagerClient } from '../manager-client.js';
import type { TenantConfig } from '../config.js';
import type {
  SiteControlAdapter,
  SiteInsightInspectionRequest,
  SiteInsightListRequest,
  SitePageInspectionRequest,
  SiteResearchLineInspectionRequest,
  SiteResearchLineListRequest,
  SiteResearchObjectInspectionRequest,
  SiteResearchObjectListRequest,
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
    this.requireLanguage(request.language, 'Page');
    return this.client.page(request.key, request.language);
  }

  listInsights(request: SiteInsightListRequest): Promise<unknown> {
    const language = request.language ?? '';
    if (language) this.requireLanguage(language, 'Insight');
    return this.client.insights(language);
  }

  inspectInsight(request: SiteInsightInspectionRequest): Promise<unknown> {
    return this.client.insight(request.postId);
  }

  listResearchObjects(request: SiteResearchObjectListRequest): Promise<unknown> {
    const language = request.language ?? '';
    if (language) this.requireLanguage(language, 'Research Object');
    return this.client.researchObjects(request.kind, language);
  }

  inspectResearchObject(request: SiteResearchObjectInspectionRequest): Promise<unknown> {
    return this.client.researchObject(request.kind, request.postId);
  }

  listResearchLines(request: SiteResearchLineListRequest): Promise<unknown> {
    const language = request.language ?? '';
    if (language) this.requireLanguage(language, 'Research Line');
    return this.client.researchLines(language);
  }

  inspectResearchLine(request: SiteResearchLineInspectionRequest): Promise<unknown> {
    return this.client.researchLine(request.postId);
  }

  private requireLanguage(language: string, label: string): void {
    if (!this.languages.includes(language)) throw new Error(`${label} language is not enabled for tenant ${this.tenantId}`);
  }
}
