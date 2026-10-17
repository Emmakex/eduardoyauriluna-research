import { ResearchManagerClient } from '../manager-client.js';
import { ResearchManagerWriteClient } from '../write-manager-client.js';
import { ResearchManagerA3WriteClient } from '../a3-write-client.js';
import { ResearchManagerA4WriteClient } from '../a4-write-client.js';
import { ResearchManagerA5WriteClient } from '../a5-write-client.js';
import type { TenantConfig } from '../config.js';
import type {
  EvidenceApplyRequest,
  EvidenceCreatePlanRequest,
  EvidenceDeletePlanRequest,
  EvidenceRollbackRequest,
  EvidenceUpdatePlanRequest,
  EvidenceVerifyRequest,
  IdentityProfileApplyRequest,
  IdentityProfilePlanRequest,
  IdentityProfileRollbackRequest,
  IdentityProfileVerifyRequest,
  StructuredTranslationApplyRequest,
  StructuredTranslationPairPlanRequest,
  StructuredTranslationRollbackRequest,
  StructuredTranslationUnpairPlanRequest,
  StructuredTranslationVerifyRequest,
} from '../a3-contracts.js';
import type {
  SeoGeoRemediationApplyRequest,
  SeoGeoRemediationPlanRequest,
  SeoGeoRemediationRollbackRequest,
  SeoGeoRemediationVerifyRequest,
} from '../a4-contracts.js';
import type {
  MediaIngestApplyRequest,
  MediaIngestCancelRequest,
  MediaIngestPlanRequest,
  MediaIngestRollbackRequest,
  MediaIngestVerifyRequest,
  MediaMetadataApplyRequest,
  MediaMetadataRollbackRequest,
  MediaMetadataUpdatePlanRequest,
  MediaMetadataVerifyRequest,
} from '../a5-contracts.js';
import type {
  SiteControlAdapter,
  SiteDesignReadinessRequest,
  SiteEvidenceInspectionRequest,
  SiteEvidenceListRequest,
  SiteInsightApplyRequest,
  SiteInsightCreatePlanRequest,
  SiteInsightInspectionRequest,
  SiteInsightListRequest,
  SiteInsightPairPlanRequest,
  SiteInsightPairingApplyRequest,
  SiteInsightPairingRollbackRequest,
  SiteInsightPairingVerifyRequest,
  SiteInsightRollbackRequest,
  SiteInsightUnpairPlanRequest,
  SiteInsightUpdatePlanRequest,
  SiteInsightVerifyRequest,
  SiteMediaDiagnosticInspectionRequest,
  SiteMediaDiagnosticsRequest,
  SiteMediaInspectionRequest,
  SiteMediaListRequest,
  SitePageInspectionRequest,
  SitePageSlotsApplyRequest,
  SitePageSlotsPlanRequest,
  SitePageSlotsRollbackRequest,
  SitePageSlotsVerifyRequest,
  SiteResearchLineApplyRequest,
  SiteResearchLineCreatePlanRequest,
  SiteResearchLineInspectionRequest,
  SiteResearchLineListRequest,
  SiteResearchLineRollbackRequest,
  SiteResearchLineUpdatePlanRequest,
  SiteResearchLineVerifyRequest,
  SiteResearchObjectApplyRequest,
  SiteResearchObjectCreatePlanRequest,
  SiteResearchObjectInspectionRequest,
  SiteResearchObjectListRequest,
  SiteResearchObjectRollbackRequest,
  SiteResearchObjectUpdatePlanRequest,
  SiteResearchObjectVerifyRequest,
  SiteSeoInternalLinksRequest,
  SiteSeoReadinessRequest,
  SiteSeoResourceRequest,
  SiteTranslationInspectionRequest,
  SiteTranslationListRequest,
} from './site-control.js';

export class ResearchManagerAdapter implements SiteControlAdapter {
  readonly kind='research-manager'; readonly tenantId:string; readonly tenantLabel:string;
  private readonly languages:readonly string[];
  private readonly client:ResearchManagerClient;
  private readonly writeClient:ResearchManagerWriteClient;
  private readonly a3WriteClient:ResearchManagerA3WriteClient;
  private readonly a4WriteClient:ResearchManagerA4WriteClient;
  private readonly a5WriteClient:ResearchManagerA5WriteClient;
  constructor(tenant:TenantConfig,fetchImpl:typeof fetch=fetch){
    this.tenantId=tenant.id;
    this.tenantLabel=tenant.label;
    this.languages=tenant.languages;
    this.client=new ResearchManagerClient(tenant,fetchImpl);
    this.writeClient=new ResearchManagerWriteClient(tenant,fetchImpl);
    this.a3WriteClient=new ResearchManagerA3WriteClient(tenant,fetchImpl);
    this.a4WriteClient=new ResearchManagerA4WriteClient(tenant,fetchImpl);
    this.a5WriteClient=new ResearchManagerA5WriteClient(tenant,fetchImpl);
  }
  status():Promise<unknown>{return this.client.status();}
  versions():Promise<unknown>{return this.client.versions();}
  capabilities():Promise<unknown>{return this.client.capabilities();}
  listPages():Promise<unknown>{return this.client.pages();}
  inspectPage(request:SitePageInspectionRequest):Promise<unknown>{this.requireLanguage(request.language,'Page');return this.client.page(request.key,request.language);}
  planPageSlots(request:SitePageSlotsPlanRequest):Promise<unknown>{this.requireLanguage(request.language,'Page');return this.writeClient.planPageSlots(request);}
  applyPageSlots(request:SitePageSlotsApplyRequest):Promise<unknown>{return this.writeClient.applyPageSlots(request);}
  async verifyPageSlots(request:SitePageSlotsVerifyRequest):Promise<unknown>{const operation=await this.client.operation(request.operationId);this.requireOperation(operation,['page-slots-update'],'Page slot Verify','a page-slots-update operation');return this.writeClient.verifyPageSlots(request);}
  async rollbackPageSlots(request:SitePageSlotsRollbackRequest):Promise<unknown>{const operation=await this.client.operation(request.operationId);this.requireOperation(operation,['page-slots-update'],'Page slot Rollback','a page-slots-update operation');return this.writeClient.rollbackPageSlots(request);}
  listInsights(request:SiteInsightListRequest):Promise<unknown>{const language=request.language??'';if(language)this.requireLanguage(language,'Insight');return this.client.insights(language);}
  inspectInsight(request:SiteInsightInspectionRequest):Promise<unknown>{return this.client.insight(request.postId);}
  planInsightCreate(request:SiteInsightCreatePlanRequest):Promise<unknown>{this.requireLanguage(request.data.language,'Insight');return this.writeClient.planInsightCreate(request);}
  planInsightUpdate(request:SiteInsightUpdatePlanRequest):Promise<unknown>{if(request.changes.language)this.requireLanguage(request.changes.language,'Insight');return this.writeClient.planInsightUpdate(request);}
  applyInsight(request:SiteInsightApplyRequest):Promise<unknown>{return this.writeClient.applyInsight(request);}
  async verifyInsight(request:SiteInsightVerifyRequest):Promise<unknown>{const operation=await this.client.operation(request.operationId);this.requireOperation(operation,['insight-create','insight-update'],'Insight Verify','an allowed Insight operation');return this.writeClient.verifyInsight(request);}
  async rollbackInsight(request:SiteInsightRollbackRequest):Promise<unknown>{const operation=await this.client.operation(request.operationId);this.requireOperation(operation,['insight-create','insight-update'],'Insight Rollback','an allowed Insight operation');return this.writeClient.rollbackInsight(request);}
  planInsightPair(request:SiteInsightPairPlanRequest):Promise<unknown>{return this.writeClient.planInsightPair(request);}
  planInsightUnpair(request:SiteInsightUnpairPlanRequest):Promise<unknown>{return this.writeClient.planInsightUnpair(request);}
  applyInsightPairing(request:SiteInsightPairingApplyRequest):Promise<unknown>{return this.writeClient.applyInsightPairing(request);}
  verifyInsightPairing(request:SiteInsightPairingVerifyRequest):Promise<unknown>{return this.writeClient.verifyInsightPairing(request);}
  rollbackInsightPairing(request:SiteInsightPairingRollbackRequest):Promise<unknown>{return this.writeClient.rollbackInsightPairing(request);}
  listResearchObjects(request:SiteResearchObjectListRequest):Promise<unknown>{const language=request.language??'';if(language)this.requireLanguage(language,'Research Object');return this.client.researchObjects(request.kind,language);}
  inspectResearchObject(request:SiteResearchObjectInspectionRequest):Promise<unknown>{return this.client.researchObject(request.kind,request.postId);}
  planResearchObjectCreate(request:SiteResearchObjectCreatePlanRequest):Promise<unknown>{const language=request.data.language??'';if(language)this.requireLanguage(language,'Research Object');return this.writeClient.planResearchObjectCreate(request);}
  planResearchObjectUpdate(request:SiteResearchObjectUpdatePlanRequest):Promise<unknown>{const language=request.changes.language??'';if(language)this.requireLanguage(language,'Research Object');return this.writeClient.planResearchObjectUpdate(request);}
  applyResearchObject(request:SiteResearchObjectApplyRequest):Promise<unknown>{return this.writeClient.applyResearchObject(request);}
  verifyResearchObject(request:SiteResearchObjectVerifyRequest):Promise<unknown>{return this.writeClient.verifyResearchObject(request);}
  rollbackResearchObject(request:SiteResearchObjectRollbackRequest):Promise<unknown>{return this.writeClient.rollbackResearchObject(request);}
  listResearchLines(request:SiteResearchLineListRequest):Promise<unknown>{const language=request.language??'';if(language)this.requireLanguage(language,'Research Line');return this.client.researchLines(language);}
  inspectResearchLine(request:SiteResearchLineInspectionRequest):Promise<unknown>{return this.client.researchLine(request.postId);}
  planResearchLineCreate(request:SiteResearchLineCreatePlanRequest):Promise<unknown>{const language=request.data.language??'';if(language)this.requireLanguage(language,'Research Line');return this.writeClient.planResearchLineCreate(request);}
  planResearchLineUpdate(request:SiteResearchLineUpdatePlanRequest):Promise<unknown>{const language=request.changes.language??'';if(language)this.requireLanguage(language,'Research Line');return this.writeClient.planResearchLineUpdate(request);}
  applyResearchLine(request:SiteResearchLineApplyRequest):Promise<unknown>{return this.writeClient.applyResearchLine(request);}
  verifyResearchLine(request:SiteResearchLineVerifyRequest):Promise<unknown>{return this.writeClient.verifyResearchLine(request);}
  rollbackResearchLine(request:SiteResearchLineRollbackRequest):Promise<unknown>{return this.writeClient.rollbackResearchLine(request);}
  listTranslations(request:SiteTranslationListRequest):Promise<unknown>{const language=request.language??'';if(language)this.requireLanguage(language,'Translation');return this.client.translations(request.postType,language);}
  inspectTranslation(request:SiteTranslationInspectionRequest):Promise<unknown>{return this.client.translation(request.postId);}
  planStructuredTranslationPair(request:StructuredTranslationPairPlanRequest):Promise<unknown>{return this.a3WriteClient.planTranslationPair(request);}
  planStructuredTranslationUnpair(request:StructuredTranslationUnpairPlanRequest):Promise<unknown>{return this.a3WriteClient.planTranslationUnpair(request);}
  applyStructuredTranslation(request:StructuredTranslationApplyRequest):Promise<unknown>{return this.a3WriteClient.applyTranslation(request);}
  verifyStructuredTranslation(request:StructuredTranslationVerifyRequest):Promise<unknown>{return this.a3WriteClient.verifyTranslation(request);}
  rollbackStructuredTranslation(request:StructuredTranslationRollbackRequest):Promise<unknown>{return this.a3WriteClient.rollbackTranslation(request);}
  evidenceInventory():Promise<unknown>{return this.client.evidenceInventory();}
  evidenceIdentity():Promise<unknown>{return this.client.evidenceIdentity();}
  listEvidence(request:SiteEvidenceListRequest):Promise<unknown>{return this.client.evidenceRecords(request.group);}
  inspectEvidence(request:SiteEvidenceInspectionRequest):Promise<unknown>{return this.client.evidenceRecord(request.group,request.recordId);}
  planEvidenceCreate(request:EvidenceCreatePlanRequest):Promise<unknown>{return this.a3WriteClient.planEvidenceCreate(request);}
  planEvidenceUpdate(request:EvidenceUpdatePlanRequest):Promise<unknown>{return this.a3WriteClient.planEvidenceUpdate(request);}
  planEvidenceDelete(request:EvidenceDeletePlanRequest):Promise<unknown>{return this.a3WriteClient.planEvidenceDelete(request);}
  applyEvidence(request:EvidenceApplyRequest):Promise<unknown>{return this.a3WriteClient.applyEvidence(request);}
  verifyEvidence(request:EvidenceVerifyRequest):Promise<unknown>{return this.a3WriteClient.verifyEvidence(request);}
  rollbackEvidence(request:EvidenceRollbackRequest):Promise<unknown>{return this.a3WriteClient.rollbackEvidence(request);}
  planIdentityProfileUpdate(request:IdentityProfilePlanRequest):Promise<unknown>{return this.a3WriteClient.planIdentityUpdate(request);}
  applyIdentityProfile(request:IdentityProfileApplyRequest):Promise<unknown>{return this.a3WriteClient.applyIdentity(request);}
  verifyIdentityProfile(request:IdentityProfileVerifyRequest):Promise<unknown>{return this.a3WriteClient.verifyIdentity(request);}
  rollbackIdentityProfile(request:IdentityProfileRollbackRequest):Promise<unknown>{return this.a3WriteClient.rollbackIdentity(request);}
  inspectNavigation():Promise<unknown>{return this.client.navigation();}
  listMedia(request:SiteMediaListRequest):Promise<unknown>{return this.client.mediaInventory(request);}
  inspectMedia(request:SiteMediaInspectionRequest):Promise<unknown>{return this.client.mediaItem(request.attachmentId);}
  mediaDiagnostics(request:SiteMediaDiagnosticsRequest):Promise<unknown>{return this.client.mediaDiagnostics(request.limit);}
  inspectMediaDiagnostic(request:SiteMediaDiagnosticInspectionRequest):Promise<unknown>{return this.client.mediaDiagnosticItem(request.attachmentId);}
  planMediaIngest(request:MediaIngestPlanRequest):Promise<unknown>{return this.a5WriteClient.planIngest(request);}
  cancelMediaIngest(request:MediaIngestCancelRequest):Promise<unknown>{return this.a5WriteClient.cancelIngest(request);}
  applyMediaIngest(request:MediaIngestApplyRequest):Promise<unknown>{return this.a5WriteClient.applyIngest(request);}
  verifyMediaIngest(request:MediaIngestVerifyRequest):Promise<unknown>{return this.a5WriteClient.verifyIngest(request);}
  rollbackMediaIngest(request:MediaIngestRollbackRequest):Promise<unknown>{return this.a5WriteClient.rollbackIngest(request);}
  planMediaMetadataUpdate(request:MediaMetadataUpdatePlanRequest):Promise<unknown>{return this.a5WriteClient.planMetadataUpdate(request);}
  applyMediaMetadata(request:MediaMetadataApplyRequest):Promise<unknown>{return this.a5WriteClient.applyMetadata(request);}
  verifyMediaMetadata(request:MediaMetadataVerifyRequest):Promise<unknown>{return this.a5WriteClient.verifyMetadata(request);}
  rollbackMediaMetadata(request:MediaMetadataRollbackRequest):Promise<unknown>{return this.a5WriteClient.rollbackMetadata(request);}
  seoSite():Promise<unknown>{return this.client.seoSite();}
  seoResource(request:SiteSeoResourceRequest):Promise<unknown>{this.requireSeoLanguage(request);return this.client.seoResource(request);}
  seoResourceActions(request:SiteSeoResourceRequest):Promise<unknown>{this.requireSeoLanguage(request);return this.client.seoResourceActions(request);}
  seoIndexability():Promise<unknown>{return this.client.seoIndexability();}
  seoResourceIndexability(request:SiteSeoResourceRequest):Promise<unknown>{this.requireSeoLanguage(request);return this.client.seoResourceIndexability(request);}
  seoInternalLinks(request:SiteSeoInternalLinksRequest):Promise<unknown>{return this.client.seoInternalLinks(request.limit);}
  seoReadiness(request:SiteSeoReadinessRequest):Promise<unknown>{return this.client.seoReadiness(request.perTypeLimit);}
  planSeoGeoRemediation(request:SeoGeoRemediationPlanRequest):Promise<unknown>{return this.a4WriteClient.plan(request);}
  applySeoGeoRemediation(request:SeoGeoRemediationApplyRequest):Promise<unknown>{return this.a4WriteClient.apply(request);}
  verifySeoGeoRemediation(request:SeoGeoRemediationVerifyRequest):Promise<unknown>{return this.a4WriteClient.verify(request);}
  rollbackSeoGeoRemediation(request:SeoGeoRemediationRollbackRequest):Promise<unknown>{return this.a4WriteClient.rollback(request);}
  designInspect():Promise<unknown>{return this.client.design();}
  designVariants():Promise<unknown>{return this.client.designVariants();}
  designReadiness(request:SiteDesignReadinessRequest):Promise<unknown>{return this.client.designReadiness(request.rendered);}
  private requireLanguage(language:string,label:string):void{if(!this.languages.includes(language))throw new Error(`${label} language is not enabled for tenant ${this.tenantId}`);}
  private requireOperation(value:unknown,allowed:readonly string[],label:string,expectedDescription:string):void{if(typeof value!=='object'||value===null||Array.isArray(value)||typeof (value as Record<string,unknown>).operation!=='string'||!allowed.includes((value as Record<string,unknown>).operation as string))throw new Error(`${label} does not reference ${expectedDescription}`);}
  private requireSeoLanguage(request:SiteSeoResourceRequest):void{if(request.type==='page')this.requireLanguage(request.language,'SEO Page');}
}
