import type { ResearchEvidenceGroup } from './adapters/site-control.js';

export interface EvidenceContext {
  evidenceConfirmed?: boolean | undefined;
  evidenceReference?: string | undefined;
}

export interface StructuredTranslationPairPlanRequest extends EvidenceContext {
  firstId: number;
  secondId: number;
}
export interface StructuredTranslationUnpairPlanRequest extends EvidenceContext { postId: number; }
export interface StructuredTranslationApplyRequest { planId: string; confirm: true; }
export interface StructuredTranslationVerifyRequest { operationId: string; rendered: boolean; }
export interface StructuredTranslationRollbackRequest { operationId: string; confirm: true; }

export type EvidenceStatus = 'verified' | 'unverified';
export interface EvidenceTranslationData {
  title?: string | undefined;
  label?: string | undefined;
  value?: string | undefined;
  period?: string | undefined;
  date?: string | undefined;
  dates?: string | undefined;
  start_date?: string | undefined;
  end_date?: string | undefined;
  organization?: string | undefined;
  institution?: string | undefined;
  company?: string | undefined;
  affiliation?: string | undefined;
  summary?: string | undefined;
  url?: string | undefined;
}
export interface EvidenceRecordData extends EvidenceTranslationData {
  status?: EvidenceStatus | undefined;
  translations?: { es?: EvidenceTranslationData | undefined } | undefined;
}
export interface EvidenceCreatePlanRequest extends EvidenceContext {
  group: ResearchEvidenceGroup;
  data: EvidenceRecordData;
}
export interface EvidenceUpdatePlanRequest extends EvidenceContext {
  group: ResearchEvidenceGroup;
  recordId: string;
  data: EvidenceRecordData;
}
export interface EvidenceDeletePlanRequest extends EvidenceContext {
  group: ResearchEvidenceGroup;
  recordId: string;
}
export interface EvidenceApplyRequest { planId: string; confirm: true; }
export interface EvidenceVerifyRequest { operationId: string; }
export interface EvidenceRollbackRequest { operationId: string; confirm: true; }

export interface IdentityProfileChanges {
  name?: string | undefined;
  headline?: string | undefined;
  headline_es?: string | undefined;
}
export interface IdentityProfilePlanRequest extends EvidenceContext { changes: IdentityProfileChanges; }
export interface IdentityProfileApplyRequest { planId: string; confirm: true; }
export interface IdentityProfileVerifyRequest { operationId: string; }
export interface IdentityProfileRollbackRequest { operationId: string; confirm: true; }

export interface A3WriteAdapter {
  planStructuredTranslationPair(request: StructuredTranslationPairPlanRequest): Promise<unknown>;
  planStructuredTranslationUnpair(request: StructuredTranslationUnpairPlanRequest): Promise<unknown>;
  applyStructuredTranslation(request: StructuredTranslationApplyRequest): Promise<unknown>;
  verifyStructuredTranslation(request: StructuredTranslationVerifyRequest): Promise<unknown>;
  rollbackStructuredTranslation(request: StructuredTranslationRollbackRequest): Promise<unknown>;
  planEvidenceCreate(request: EvidenceCreatePlanRequest): Promise<unknown>;
  planEvidenceUpdate(request: EvidenceUpdatePlanRequest): Promise<unknown>;
  planEvidenceDelete(request: EvidenceDeletePlanRequest): Promise<unknown>;
  applyEvidence(request: EvidenceApplyRequest): Promise<unknown>;
  verifyEvidence(request: EvidenceVerifyRequest): Promise<unknown>;
  rollbackEvidence(request: EvidenceRollbackRequest): Promise<unknown>;
  planIdentityProfileUpdate(request: IdentityProfilePlanRequest): Promise<unknown>;
  applyIdentityProfile(request: IdentityProfileApplyRequest): Promise<unknown>;
  verifyIdentityProfile(request: IdentityProfileVerifyRequest): Promise<unknown>;
  rollbackIdentityProfile(request: IdentityProfileRollbackRequest): Promise<unknown>;
}
