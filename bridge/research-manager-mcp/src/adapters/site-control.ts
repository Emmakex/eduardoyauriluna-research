export interface SitePageInspectionRequest {
  key: string;
  language: string;
}

export interface SiteInsightListRequest {
  language?: string;
}

export interface SiteInsightInspectionRequest {
  postId: number;
}

export type ResearchObjectKind = 'output' | 'project' | 'software' | 'dataset';

export interface SiteResearchObjectListRequest {
  kind?: ResearchObjectKind;
  language?: string;
}

export interface SiteResearchObjectInspectionRequest {
  kind: ResearchObjectKind;
  postId: number;
}

export interface SiteResearchLineListRequest {
  language?: string;
}

export interface SiteResearchLineInspectionRequest {
  postId: number;
}

export interface SiteControlAdapter {
  readonly kind: string;
  readonly tenantId: string;
  readonly tenantLabel: string;

  status(): Promise<unknown>;
  versions(): Promise<unknown>;
  capabilities(): Promise<unknown>;
  listPages(): Promise<unknown>;
  inspectPage(request: SitePageInspectionRequest): Promise<unknown>;
  listInsights(request: SiteInsightListRequest): Promise<unknown>;
  inspectInsight(request: SiteInsightInspectionRequest): Promise<unknown>;
  listResearchObjects(request: SiteResearchObjectListRequest): Promise<unknown>;
  inspectResearchObject(request: SiteResearchObjectInspectionRequest): Promise<unknown>;
  listResearchLines(request: SiteResearchLineListRequest): Promise<unknown>;
  inspectResearchLine(request: SiteResearchLineInspectionRequest): Promise<unknown>;
}
