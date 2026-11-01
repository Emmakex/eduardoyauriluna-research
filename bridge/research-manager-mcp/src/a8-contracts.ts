export type LegalContentKey='privacy-policy'|'legal-notice';
export type LegalContentLanguage='en'|'es';
export interface LegalContentSection { title:string; body:string; }
export interface LegalContentPlanRequest { key:LegalContentKey; language:LegalContentLanguage; sections:LegalContentSection[]; reason?:string|undefined; }
export interface LegalContentApplyRequest { planId:string; confirm:true; }
export interface LegalContentVerifyRequest { operationId:string; rendered:true; }
export interface LegalContentRollbackRequest { operationId:string; confirm:true; }

export interface A8WriteAdapter {
  planLegalContent(request:LegalContentPlanRequest):Promise<unknown>;
  applyLegalContent(request:LegalContentApplyRequest):Promise<unknown>;
  verifyLegalContent(request:LegalContentVerifyRequest):Promise<unknown>;
  rollbackLegalContent(request:LegalContentRollbackRequest):Promise<unknown>;
}
