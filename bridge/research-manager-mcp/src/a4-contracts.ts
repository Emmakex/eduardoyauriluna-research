export interface SeoGeoRemediationPlanRequest {
  checkId: string;
  intent?: string | undefined;
}
export interface SeoGeoRemediationApplyRequest { planId: string; confirm: true; }
export interface SeoGeoRemediationVerifyRequest { operationId: string; rendered: boolean; }
export interface SeoGeoRemediationRollbackRequest { operationId: string; confirm: true; }

export interface A4WriteAdapter {
  planSeoGeoRemediation(request: SeoGeoRemediationPlanRequest): Promise<unknown>;
  applySeoGeoRemediation(request: SeoGeoRemediationApplyRequest): Promise<unknown>;
  verifySeoGeoRemediation(request: SeoGeoRemediationVerifyRequest): Promise<unknown>;
  rollbackSeoGeoRemediation(request: SeoGeoRemediationRollbackRequest): Promise<unknown>;
}
