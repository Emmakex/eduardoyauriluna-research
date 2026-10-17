export interface SitePageInspectionRequest { key: string; language: string; }
export type SitePageSlotValue = string | string[];
export interface SitePageSlotsPlanRequest {
  key: string;
  language: string;
  slots: Record<string, SitePageSlotValue>;
  reason?: string;
}
export interface SitePageSlotsApplyRequest { planId: string; confirm: true; }
export interface SitePageSlotsVerifyRequest { operationId: string; rendered: true; }
export interface SitePageSlotsRollbackRequest { operationId: string; confirm: true; }

export interface SiteInsightListRequest { language?: string; }
export interface SiteInsightInspectionRequest { postId: number; }
export type SiteInsightStatus = 'draft' | 'publish';
export interface SiteInsightCreateData {
  title: string;
  slug?: string | undefined;
  excerpt?: string | undefined;
  content?: string | undefined;
  language: string;
  insight_type: string;
  status: SiteInsightStatus;
}
export interface SiteInsightCreatePlanRequest { data: SiteInsightCreateData; reason?: string; }
export interface SiteInsightUpdateChanges {
  slug?: string | undefined;
  title?: string | undefined;
  excerpt?: string | undefined;
  content?: string | undefined;
  language?: string | undefined;
  insight_type?: string | undefined;
  status?: SiteInsightStatus | undefined;
  scheduled_at?: string | undefined;
  line_ids?: number[] | undefined;
}
export interface SiteInsightUpdatePlanRequest { postId: number; changes: SiteInsightUpdateChanges; reason?: string; }
export interface SiteInsightApplyRequest { planId: string; confirm: true; }
export interface SiteInsightVerifyRequest { operationId: string; rendered: true; }
export interface SiteInsightRollbackRequest { operationId: string; confirm: true; }
export interface SiteInsightPairPlanRequest { firstId: number; secondId: number; }
export interface SiteInsightUnpairPlanRequest { postId: number; }
export interface SiteInsightPairingApplyRequest { planId: string; confirm: true; }
export interface SiteInsightPairingVerifyRequest { operationId: string; rendered: boolean; }
export interface SiteInsightPairingRollbackRequest { operationId: string; confirm: true; }

export type ResearchObjectKind = 'output' | 'project' | 'software' | 'dataset';
export interface SiteResearchObjectListRequest { kind?: ResearchObjectKind; language?: string; }
export interface SiteResearchObjectInspectionRequest { kind: ResearchObjectKind; postId: number; }
export interface SiteResearchLineListRequest { language?: string; }
export interface SiteResearchLineInspectionRequest { postId: number; }

export type SiteResearchRecordStatus = 'draft' | 'publish';
type OptionalWithUndefined<T> = { [K in keyof T]?: T[K] | undefined };
export interface SiteResearchLineData {
  title: string;
  slug?: string | undefined;
  excerpt?: string | undefined;
  content?: string | undefined;
  language?: string | undefined;
  status?: SiteResearchRecordStatus | undefined;
  evidence_status?: string | undefined;
  research_status?: string | undefined;
  central_question?: string | undefined;
  order?: string | undefined;
  topics?: string[] | undefined;
  methods?: string[] | undefined;
}
export type SiteResearchLineChanges = OptionalWithUndefined<SiteResearchLineData>;
export interface SiteResearchLineCreatePlanRequest {
  data: SiteResearchLineData;
  evidenceConfirmed?: boolean | undefined;
  evidenceReference?: string | undefined;
}
export interface SiteResearchLineUpdatePlanRequest {
  postId: number;
  changes: SiteResearchLineChanges;
  evidenceConfirmed?: boolean | undefined;
  evidenceReference?: string | undefined;
}
export interface SiteResearchLineApplyRequest { planId: string; confirm: true; }
export interface SiteResearchLineVerifyRequest { operationId: string; rendered: boolean; }
export interface SiteResearchLineRollbackRequest { operationId: string; confirm: true; }

export interface SiteResearchObjectBaseCreateData {
  title: string;
  slug?: string | undefined;
  excerpt?: string | undefined;
  content?: string | undefined;
  language?: string | undefined;
  status?: SiteResearchRecordStatus | undefined;
}
export interface SiteResearchOutputCreateData extends SiteResearchObjectBaseCreateData {
  output_type?: string | undefined;
  review_status?: string | undefined;
  publication_date?: string | undefined;
  venue?: string | undefined;
  doi?: string | undefined;
  authors?: string[] | undefined;
  line_ids?: number[] | undefined;
}
export interface SiteResearchProjectCreateData extends SiteResearchObjectBaseCreateData {
  project_status?: string | undefined;
  question?: string | undefined;
  role?: string | undefined;
  start_date?: string | undefined;
  end_date?: string | undefined;
  partner?: string | undefined;
  funding?: string | undefined;
  project_url?: string | undefined;
  methods?: string[] | undefined;
  line_ids?: number[] | undefined;
}
export interface SiteResearchSoftwareCreateData extends SiteResearchObjectBaseCreateData {
  software_status?: string | undefined;
  version?: string | undefined;
  release_date?: string | undefined;
  repository_url?: string | undefined;
  archive_url?: string | undefined;
  license?: string | undefined;
  documentation_url?: string | undefined;
  doi?: string | undefined;
  programming_languages?: string[] | undefined;
  line_ids?: number[] | undefined;
}
export interface SiteResearchDatasetCreateData extends SiteResearchObjectBaseCreateData {
  version?: string | undefined;
  publication_date?: string | undefined;
  repository?: string | undefined;
  doi?: string | undefined;
  license?: string | undefined;
  access_level?: string | undefined;
  methodology?: string | undefined;
  provenance?: string | undefined;
  size?: string | undefined;
  documentation_url?: string | undefined;
  ethics_notes?: string | undefined;
  formats?: string[] | undefined;
  line_ids?: number[] | undefined;
}
export type SiteResearchObjectCreateData =
  | SiteResearchOutputCreateData
  | SiteResearchProjectCreateData
  | SiteResearchSoftwareCreateData
  | SiteResearchDatasetCreateData;
export type SiteResearchOutputChanges = Omit<OptionalWithUndefined<SiteResearchOutputCreateData>, 'status' | 'slug'>;
export type SiteResearchProjectChanges = Omit<OptionalWithUndefined<SiteResearchProjectCreateData>, 'status' | 'slug'>;
export type SiteResearchSoftwareChanges = Omit<OptionalWithUndefined<SiteResearchSoftwareCreateData>, 'status' | 'slug'>;
export type SiteResearchDatasetChanges = Omit<OptionalWithUndefined<SiteResearchDatasetCreateData>, 'status' | 'slug'>;
export type SiteResearchObjectChanges =
  | SiteResearchOutputChanges
  | SiteResearchProjectChanges
  | SiteResearchSoftwareChanges
  | SiteResearchDatasetChanges;
export interface SiteResearchObjectCreatePlanRequest {
  kind: ResearchObjectKind;
  data: SiteResearchObjectCreateData;
  evidenceConfirmed?: boolean | undefined;
  evidenceReference?: string | undefined;
}
export interface SiteResearchObjectUpdatePlanRequest {
  kind: ResearchObjectKind;
  postId: number;
  changes: SiteResearchObjectChanges;
  evidenceConfirmed?: boolean | undefined;
  evidenceReference?: string | undefined;
}
export interface SiteResearchObjectApplyRequest { planId: string; confirm: true; }
export interface SiteResearchObjectVerifyRequest { operationId: string; rendered: boolean; }
export interface SiteResearchObjectRollbackRequest { operationId: string; confirm: true; }

export type ResearchTranslationType =
  | 'research_line' | 'research_output' | 'research_project' | 'research_software' | 'research_dataset';
export interface SiteTranslationListRequest { postType: ResearchTranslationType; language?: string; }
export interface SiteTranslationInspectionRequest { postId: number; }

export type ResearchEvidenceGroup =
  | 'profile' | 'affiliations' | 'identifiers' | 'research_questions' | 'methods'
  | 'experience' | 'education' | 'awards' | 'contact';
export interface SiteEvidenceListRequest { group: ResearchEvidenceGroup; }
export interface SiteEvidenceInspectionRequest { group: ResearchEvidenceGroup; recordId: string; }

export type SiteMediaMimeFilter = 'image' | 'audio' | 'video' | 'pdf' | 'application/pdf';
export interface SiteMediaListRequest { page?: number; perPage?: number; search?: string; mimeType?: SiteMediaMimeFilter; }
export interface SiteMediaInspectionRequest { attachmentId: number; }
export interface SiteMediaDiagnosticsRequest { limit?: number; }
export interface SiteMediaDiagnosticInspectionRequest { attachmentId: number; }

export type SiteSeoResourceType = 'page' | 'insight' | 'line' | 'output' | 'project' | 'software' | 'dataset';
export type SiteSeoResourceRequest =
  | { type: 'page'; key: string; language: string }
  | { type: Exclude<SiteSeoResourceType, 'page'>; postId: number };
export interface SiteSeoInternalLinksRequest { limit?: number; }
export interface SiteSeoReadinessRequest { perTypeLimit?: number; }
export interface SiteDesignReadinessRequest { rendered?: boolean; }

export interface SiteControlAdapter {
  readonly kind: string;
  readonly tenantId: string;
  readonly tenantLabel: string;
  status(): Promise<unknown>;
  versions(): Promise<unknown>;
  capabilities(): Promise<unknown>;
  listPages(): Promise<unknown>;
  inspectPage(request: SitePageInspectionRequest): Promise<unknown>;
  planPageSlots(request: SitePageSlotsPlanRequest): Promise<unknown>;
  applyPageSlots(request: SitePageSlotsApplyRequest): Promise<unknown>;
  verifyPageSlots(request: SitePageSlotsVerifyRequest): Promise<unknown>;
  rollbackPageSlots(request: SitePageSlotsRollbackRequest): Promise<unknown>;
  listInsights(request: SiteInsightListRequest): Promise<unknown>;
  inspectInsight(request: SiteInsightInspectionRequest): Promise<unknown>;
  planInsightCreate(request: SiteInsightCreatePlanRequest): Promise<unknown>;
  planInsightUpdate(request: SiteInsightUpdatePlanRequest): Promise<unknown>;
  applyInsight(request: SiteInsightApplyRequest): Promise<unknown>;
  verifyInsight(request: SiteInsightVerifyRequest): Promise<unknown>;
  rollbackInsight(request: SiteInsightRollbackRequest): Promise<unknown>;
  planInsightPair(request: SiteInsightPairPlanRequest): Promise<unknown>;
  planInsightUnpair(request: SiteInsightUnpairPlanRequest): Promise<unknown>;
  applyInsightPairing(request: SiteInsightPairingApplyRequest): Promise<unknown>;
  verifyInsightPairing(request: SiteInsightPairingVerifyRequest): Promise<unknown>;
  rollbackInsightPairing(request: SiteInsightPairingRollbackRequest): Promise<unknown>;
  listResearchObjects(request: SiteResearchObjectListRequest): Promise<unknown>;
  inspectResearchObject(request: SiteResearchObjectInspectionRequest): Promise<unknown>;
  planResearchObjectCreate(request: SiteResearchObjectCreatePlanRequest): Promise<unknown>;
  planResearchObjectUpdate(request: SiteResearchObjectUpdatePlanRequest): Promise<unknown>;
  applyResearchObject(request: SiteResearchObjectApplyRequest): Promise<unknown>;
  verifyResearchObject(request: SiteResearchObjectVerifyRequest): Promise<unknown>;
  rollbackResearchObject(request: SiteResearchObjectRollbackRequest): Promise<unknown>;
  listResearchLines(request: SiteResearchLineListRequest): Promise<unknown>;
  inspectResearchLine(request: SiteResearchLineInspectionRequest): Promise<unknown>;
  planResearchLineCreate(request: SiteResearchLineCreatePlanRequest): Promise<unknown>;
  planResearchLineUpdate(request: SiteResearchLineUpdatePlanRequest): Promise<unknown>;
  applyResearchLine(request: SiteResearchLineApplyRequest): Promise<unknown>;
  verifyResearchLine(request: SiteResearchLineVerifyRequest): Promise<unknown>;
  rollbackResearchLine(request: SiteResearchLineRollbackRequest): Promise<unknown>;
  listTranslations(request: SiteTranslationListRequest): Promise<unknown>;
  inspectTranslation(request: SiteTranslationInspectionRequest): Promise<unknown>;
  evidenceInventory(): Promise<unknown>;
  evidenceIdentity(): Promise<unknown>;
  listEvidence(request: SiteEvidenceListRequest): Promise<unknown>;
  inspectEvidence(request: SiteEvidenceInspectionRequest): Promise<unknown>;
  inspectNavigation(): Promise<unknown>;
  listMedia(request: SiteMediaListRequest): Promise<unknown>;
  inspectMedia(request: SiteMediaInspectionRequest): Promise<unknown>;
  mediaDiagnostics(request: SiteMediaDiagnosticsRequest): Promise<unknown>;
  inspectMediaDiagnostic(request: SiteMediaDiagnosticInspectionRequest): Promise<unknown>;
  seoSite(): Promise<unknown>;
  seoResource(request: SiteSeoResourceRequest): Promise<unknown>;
  seoResourceActions(request: SiteSeoResourceRequest): Promise<unknown>;
  seoIndexability(): Promise<unknown>;
  seoResourceIndexability(request: SiteSeoResourceRequest): Promise<unknown>;
  seoInternalLinks(request: SiteSeoInternalLinksRequest): Promise<unknown>;
  seoReadiness(request: SiteSeoReadinessRequest): Promise<unknown>;
  designInspect(): Promise<unknown>;
  designVariants(): Promise<unknown>;
  designReadiness(request: SiteDesignReadinessRequest): Promise<unknown>;
}
