export interface SitePageInspectionRequest { key: string; language: string; }
export interface SiteInsightListRequest { language?: string; }
export interface SiteInsightInspectionRequest { postId: number; }

export type ResearchObjectKind = 'output' | 'project' | 'software' | 'dataset';
export interface SiteResearchObjectListRequest { kind?: ResearchObjectKind; language?: string; }
export interface SiteResearchObjectInspectionRequest { kind: ResearchObjectKind; postId: number; }
export interface SiteResearchLineListRequest { language?: string; }
export interface SiteResearchLineInspectionRequest { postId: number; }

export type ResearchTranslationType =
  | 'research_line'
  | 'research_output'
  | 'research_project'
  | 'research_software'
  | 'research_dataset';
export interface SiteTranslationListRequest { postType: ResearchTranslationType; language?: string; }
export interface SiteTranslationInspectionRequest { postId: number; }

export type ResearchEvidenceGroup =
  | 'profile'
  | 'affiliations'
  | 'identifiers'
  | 'research_questions'
  | 'methods'
  | 'experience'
  | 'education'
  | 'awards'
  | 'contact';
export interface SiteEvidenceListRequest { group: ResearchEvidenceGroup; }
export interface SiteEvidenceInspectionRequest { group: ResearchEvidenceGroup; recordId: string; }

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
  listTranslations(request: SiteTranslationListRequest): Promise<unknown>;
  inspectTranslation(request: SiteTranslationInspectionRequest): Promise<unknown>;
  evidenceInventory(): Promise<unknown>;
  evidenceIdentity(): Promise<unknown>;
  listEvidence(request: SiteEvidenceListRequest): Promise<unknown>;
  inspectEvidence(request: SiteEvidenceInspectionRequest): Promise<unknown>;
}
