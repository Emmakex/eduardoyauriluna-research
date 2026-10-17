import { ResearchManagerClient } from '../manager-client.js';
import { ResearchManagerWriteClient } from '../write-manager-client.js';
import { ResearchManagerA3WriteClient } from '../a3-write-client.js';
import { ResearchManagerA4WriteClient } from '../a4-write-client.js';
import { ResearchManagerA5WriteClient } from '../a5-write-client.js';
import { ResearchManagerA6WriteClient } from '../a6-write-client.js';
import { ResearchManagerA7WriteClient } from '../a7-write-client.js';
import type { TenantConfig } from '../config.js';
import type * as A3 from '../a3-contracts.js';
import type * as A4 from '../a4-contracts.js';
import type * as A5 from '../a5-contracts.js';
import type * as A6 from '../a6-contracts.js';
import type * as A7 from '../a7-contracts.js';
import type * as Site from './site-control.js';

export class ResearchManagerAdapter implements Site.SiteControlAdapter {
  readonly kind='research-manager'; readonly tenantId:string; readonly tenantLabel:string;
  private readonly languages:readonly string[];
  private readonly client:ResearchManagerClient;
  private readonly writeClient:ResearchManagerWriteClient;
  private readonly a3WriteClient:ResearchManagerA3WriteClient;
  private readonly a4WriteClient:ResearchManagerA4WriteClient;
  private readonly a5WriteClient:ResearchManagerA5WriteClient;
  private readonly a6WriteClient:ResearchManagerA6WriteClient;
  private readonly a7WriteClient:ResearchManagerA7WriteClient;
  constructor(tenant:TenantConfig,fetchImpl:typeof fetch=fetch){
    this.tenantId=tenant.id; this.tenantLabel=tenant.label; this.languages=tenant.languages;
    this.client=new ResearchManagerClient(tenant,fetchImpl);
    this.writeClient=new ResearchManagerWriteClient(tenant,fetchImpl);
    this.a3WriteClient=new ResearchManagerA3WriteClient(tenant,fetchImpl);
    this.a4WriteClient=new ResearchManagerA4WriteClient(tenant,fetchImpl);
    this.a5WriteClient=new ResearchManagerA5WriteClient(tenant,fetchImpl);
    this.a6WriteClient=new ResearchManagerA6WriteClient(tenant,fetchImpl);
    this.a7WriteClient=new ResearchManagerA7WriteClient(tenant,fetchImpl);
  }
  status():Promise<unknown>{return this.client.status();}
  versions():Promise<unknown>{return this.client.versions();}
  capabilities():Promise<unknown>{return this.client.capabilities();}
  listPages():Promise<unknown>{return this.client.pages();}
  inspectPage(request:Site.SitePageInspectionRequest):Promise<unknown>{this.requireLanguage(request.language,'Page');return this.client.page(request.key,request.language);}
  planPageSlots(request:Site.SitePageSlotsPlanRequest):Promise<unknown>{this.requireLanguage(request.language,'Page');return this.writeClient.planPageSlots(request);}
  applyPageSlots(request:Site.SitePageSlotsApplyRequest):Promise<unknown>{return this.writeClient.applyPageSlots(request);}
  async verifyPageSlots(request:Site.SitePageSlotsVerifyRequest):Promise<unknown>{const operation=await this.client.operation(request.operationId);this.requireOperation(operation,['page-slots-update'],'Page slot Verify','a page-slots-update operation');return this.writeClient.verifyPageSlots(request);}
  async rollbackPageSlots(request:Site.SitePageSlotsRollbackRequest):Promise<unknown>{const operation=await this.client.operation(request.operationId);this.requireOperation(operation,['page-slots-update'],'Page slot Rollback','a page-slots-update operation');return this.writeClient.rollbackPageSlots(request);}
  listInsights(request:Site.SiteInsightListRequest):Promise<unknown>{const language=request.language??'';if(language)this.requireLanguage(language,'Insight');return this.client.insights(language);}
  inspectInsight(request:Site.SiteInsightInspectionRequest):Promise<unknown>{return this.client.insight(request.postId);}
  planInsightCreate(request:Site.SiteInsightCreatePlanRequest):Promise<unknown>{this.requireLanguage(request.data.language,'Insight');return this.writeClient.planInsightCreate(request);}
  planInsightUpdate(request:Site.SiteInsightUpdatePlanRequest):Promise<unknown>{if(request.changes.language)this.requireLanguage(request.changes.language,'Insight');return this.writeClient.planInsightUpdate(request);}
  applyInsight(request:Site.SiteInsightApplyRequest):Promise<unknown>{return this.writeClient.applyInsight(request);}
  async verifyInsight(request:Site.SiteInsightVerifyRequest):Promise<unknown>{const operation=await this.client.operation(request.operationId);this.requireOperation(operation,['insight-create','insight-update'],'Insight Verify','an allowed Insight operation');return this.writeClient.verifyInsight(request);}
  async rollbackInsight(request:Site.SiteInsightRollbackRequest):Promise<unknown>{const operation=await this.client.operation(request.operationId);this.requireOperation(operation,['insight-create','insight-update'],'Insight Rollback','an allowed Insight operation');return this.writeClient.rollbackInsight(request);}
  planInsightPair(request:Site.SiteInsightPairPlanRequest):Promise<unknown>{return this.writeClient.planInsightPair(request);}
  planInsightUnpair(request:Site.SiteInsightUnpairPlanRequest):Promise<unknown>{return this.writeClient.planInsightUnpair(request);}
  applyInsightPairing(request:Site.SiteInsightPairingApplyRequest):Promise<unknown>{return this.writeClient.applyInsightPairing(request);}
  verifyInsightPairing(request:Site.SiteInsightPairingVerifyRequest):Promise<unknown>{return this.writeClient.verifyInsightPairing(request);}
  rollbackInsightPairing(request:Site.SiteInsightPairingRollbackRequest):Promise<unknown>{return this.writeClient.rollbackInsightPairing(request);}
  listResearchObjects(request:Site.SiteResearchObjectListRequest):Promise<unknown>{const language=request.language??'';if(language)this.requireLanguage(language,'Research Object');return this.client.researchObjects(request.kind,language);}
  inspectResearchObject(request:Site.SiteResearchObjectInspectionRequest):Promise<unknown>{return this.client.researchObject(request.kind,request.postId);}
  planResearchObjectCreate(request:Site.SiteResearchObjectCreatePlanRequest):Promise<unknown>{const language=request.data.language??'';if(language)this.requireLanguage(language,'Research Object');return this.writeClient.planResearchObjectCreate(request);}
  planResearchObjectUpdate(request:Site.SiteResearchObjectUpdatePlanRequest):Promise<unknown>{const language=request.changes.language??'';if(language)this.requireLanguage(language,'Research Object');return this.writeClient.planResearchObjectUpdate(request);}
  applyResearchObject(request:Site.SiteResearchObjectApplyRequest):Promise<unknown>{return this.writeClient.applyResearchObject(request);}
  verifyResearchObject(request:Site.SiteResearchObjectVerifyRequest):Promise<unknown>{return this.writeClient.verifyResearchObject(request);}
  rollbackResearchObject(request:Site.SiteResearchObjectRollbackRequest):Promise<unknown>{return this.writeClient.rollbackResearchObject(request);}
  listResearchLines(request:Site.SiteResearchLineListRequest):Promise<unknown>{const language=request.language??'';if(language)this.requireLanguage(language,'Research Line');return this.client.researchLines(language);}
  inspectResearchLine(request:Site.SiteResearchLineInspectionRequest):Promise<unknown>{return this.client.researchLine(request.postId);}
  planResearchLineCreate(request:Site.SiteResearchLineCreatePlanRequest):Promise<unknown>{const language=request.data.language??'';if(language)this.requireLanguage(language,'Research Line');return this.writeClient.planResearchLineCreate(request);}
  planResearchLineUpdate(request:Site.SiteResearchLineUpdatePlanRequest):Promise<unknown>{const language=request.changes.language??'';if(language)this.requireLanguage(language,'Research Line');return this.writeClient.planResearchLineUpdate(request);}
  applyResearchLine(request:Site.SiteResearchLineApplyRequest):Promise<unknown>{return this.writeClient.applyResearchLine(request);}
  verifyResearchLine(request:Site.SiteResearchLineVerifyRequest):Promise<unknown>{return this.writeClient.verifyResearchLine(request);}
  rollbackResearchLine(request:Site.SiteResearchLineRollbackRequest):Promise<unknown>{return this.writeClient.rollbackResearchLine(request);}
  listTranslations(request:Site.SiteTranslationListRequest):Promise<unknown>{const language=request.language??'';if(language)this.requireLanguage(language,'Translation');return this.client.translations(request.postType,language);}
  inspectTranslation(request:Site.SiteTranslationInspectionRequest):Promise<unknown>{return this.client.translation(request.postId);}
  planStructuredTranslationPair(request:A3.StructuredTranslationPairPlanRequest):Promise<unknown>{return this.a3WriteClient.planTranslationPair(request);}
  planStructuredTranslationUnpair(request:A3.StructuredTranslationUnpairPlanRequest):Promise<unknown>{return this.a3WriteClient.planTranslationUnpair(request);}
  applyStructuredTranslation(request:A3.StructuredTranslationApplyRequest):Promise<unknown>{return this.a3WriteClient.applyTranslation(request);}
  verifyStructuredTranslation(request:A3.StructuredTranslationVerifyRequest):Promise<unknown>{return this.a3WriteClient.verifyTranslation(request);}
  rollbackStructuredTranslation(request:A3.StructuredTranslationRollbackRequest):Promise<unknown>{return this.a3WriteClient.rollbackTranslation(request);}
  evidenceInventory():Promise<unknown>{return this.client.evidenceInventory();}
  evidenceIdentity():Promise<unknown>{return this.client.evidenceIdentity();}
  listEvidence(request:Site.SiteEvidenceListRequest):Promise<unknown>{return this.client.evidenceRecords(request.group);}
  inspectEvidence(request:Site.SiteEvidenceInspectionRequest):Promise<unknown>{return this.client.evidenceRecord(request.group,request.recordId);}
  planEvidenceCreate(request:A3.EvidenceCreatePlanRequest):Promise<unknown>{return this.a3WriteClient.planEvidenceCreate(request);}
  planEvidenceUpdate(request:A3.EvidenceUpdatePlanRequest):Promise<unknown>{return this.a3WriteClient.planEvidenceUpdate(request);}
  planEvidenceDelete(request:A3.EvidenceDeletePlanRequest):Promise<unknown>{return this.a3WriteClient.planEvidenceDelete(request);}
  applyEvidence(request:A3.EvidenceApplyRequest):Promise<unknown>{return this.a3WriteClient.applyEvidence(request);}
  verifyEvidence(request:A3.EvidenceVerifyRequest):Promise<unknown>{return this.a3WriteClient.verifyEvidence(request);}
  rollbackEvidence(request:A3.EvidenceRollbackRequest):Promise<unknown>{return this.a3WriteClient.rollbackEvidence(request);}
  planIdentityProfileUpdate(request:A3.IdentityProfilePlanRequest):Promise<unknown>{return this.a3WriteClient.planIdentityUpdate(request);}
  applyIdentityProfile(request:A3.IdentityProfileApplyRequest):Promise<unknown>{return this.a3WriteClient.applyIdentity(request);}
  verifyIdentityProfile(request:A3.IdentityProfileVerifyRequest):Promise<unknown>{return this.a3WriteClient.verifyIdentity(request);}
  rollbackIdentityProfile(request:A3.IdentityProfileRollbackRequest):Promise<unknown>{return this.a3WriteClient.rollbackIdentity(request);}
  inspectNavigation():Promise<unknown>{return this.client.navigation();}
  planNavigationUpdate(request:A6.NavigationUpdatePlanRequest):Promise<unknown>{return this.a6WriteClient.plan(request);}
  applyNavigation(request:A6.NavigationApplyRequest):Promise<unknown>{return this.a6WriteClient.apply(request);}
  verifyNavigation(request:A6.NavigationVerifyRequest):Promise<unknown>{return this.a6WriteClient.verify(request);}
  rollbackNavigation(request:A6.NavigationRollbackRequest):Promise<unknown>{return this.a6WriteClient.rollback(request);}
  listMedia(request:Site.SiteMediaListRequest):Promise<unknown>{return this.client.mediaInventory(request);}
  inspectMedia(request:Site.SiteMediaInspectionRequest):Promise<unknown>{return this.client.mediaItem(request.attachmentId);}
  mediaDiagnostics(request:Site.SiteMediaDiagnosticsRequest):Promise<unknown>{return this.client.mediaDiagnostics(request.limit);}
  inspectMediaDiagnostic(request:Site.SiteMediaDiagnosticInspectionRequest):Promise<unknown>{return this.client.mediaDiagnosticItem(request.attachmentId);}
  planMediaIngest(request:A5.MediaIngestPlanRequest):Promise<unknown>{return this.a5WriteClient.planIngest(request);}
  cancelMediaIngest(request:A5.MediaIngestCancelRequest):Promise<unknown>{return this.a5WriteClient.cancelIngest(request);}
  applyMediaIngest(request:A5.MediaIngestApplyRequest):Promise<unknown>{return this.a5WriteClient.applyIngest(request);}
  verifyMediaIngest(request:A5.MediaIngestVerifyRequest):Promise<unknown>{return this.a5WriteClient.verifyIngest(request);}
  rollbackMediaIngest(request:A5.MediaIngestRollbackRequest):Promise<unknown>{return this.a5WriteClient.rollbackIngest(request);}
  planMediaMetadataUpdate(request:A5.MediaMetadataUpdatePlanRequest):Promise<unknown>{return this.a5WriteClient.planMetadataUpdate(request);}
  applyMediaMetadata(request:A5.MediaMetadataApplyRequest):Promise<unknown>{return this.a5WriteClient.applyMetadata(request);}
  verifyMediaMetadata(request:A5.MediaMetadataVerifyRequest):Promise<unknown>{return this.a5WriteClient.verifyMetadata(request);}
  rollbackMediaMetadata(request:A5.MediaMetadataRollbackRequest):Promise<unknown>{return this.a5WriteClient.rollbackMetadata(request);}
  seoSite():Promise<unknown>{return this.client.seoSite();}
  seoResource(request:Site.SiteSeoResourceRequest):Promise<unknown>{this.requireSeoLanguage(request);return this.client.seoResource(request);}
  seoResourceActions(request:Site.SiteSeoResourceRequest):Promise<unknown>{this.requireSeoLanguage(request);return this.client.seoResourceActions(request);}
  seoIndexability():Promise<unknown>{return this.client.seoIndexability();}
  seoResourceIndexability(request:Site.SiteSeoResourceRequest):Promise<unknown>{this.requireSeoLanguage(request);return this.client.seoResourceIndexability(request);}
  seoInternalLinks(request:Site.SiteSeoInternalLinksRequest):Promise<unknown>{return this.client.seoInternalLinks(request.limit);}
  seoReadiness(request:Site.SiteSeoReadinessRequest):Promise<unknown>{return this.client.seoReadiness(request.perTypeLimit);}
  planSeoGeoRemediation(request:A4.SeoGeoRemediationPlanRequest):Promise<unknown>{return this.a4WriteClient.plan(request);}
  applySeoGeoRemediation(request:A4.SeoGeoRemediationApplyRequest):Promise<unknown>{return this.a4WriteClient.apply(request);}
  verifySeoGeoRemediation(request:A4.SeoGeoRemediationVerifyRequest):Promise<unknown>{return this.a4WriteClient.verify(request);}
  rollbackSeoGeoRemediation(request:A4.SeoGeoRemediationRollbackRequest):Promise<unknown>{return this.a4WriteClient.rollback(request);}
  designInspect():Promise<unknown>{return this.client.design();}
  designVariants():Promise<unknown>{return this.client.designVariants();}
  designReadiness(request:Site.SiteDesignReadinessRequest):Promise<unknown>{return this.client.designReadiness(request.rendered);}
  planDesignPresetUpdate(request:A7.DesignPresetPlanRequest):Promise<unknown>{return this.a7WriteClient.planPreset(request);}
  applyDesignPreset(request:A7.DesignPresetApplyRequest):Promise<unknown>{return this.a7WriteClient.applyPreset(request);}
  verifyDesignPreset(request:A7.DesignPresetVerifyRequest):Promise<unknown>{return this.a7WriteClient.verifyPreset(request);}
  rollbackDesignPreset(request:A7.DesignPresetRollbackRequest):Promise<unknown>{return this.a7WriteClient.rollbackPreset(request);}
  planDesignVariantUpdate(request:A7.DesignVariantPlanRequest):Promise<unknown>{return this.a7WriteClient.planVariant(request);}
  applyDesignVariant(request:A7.DesignVariantApplyRequest):Promise<unknown>{return this.a7WriteClient.applyVariant(request);}
  verifyDesignVariant(request:A7.DesignVariantVerifyRequest):Promise<unknown>{return this.a7WriteClient.verifyVariant(request);}
  rollbackDesignVariant(request:A7.DesignVariantRollbackRequest):Promise<unknown>{return this.a7WriteClient.rollbackVariant(request);}
  private requireLanguage(language:string,label:string):void{if(!this.languages.includes(language))throw new Error(`${label} language is not enabled for tenant ${this.tenantId}`);}
  private requireOperation(value:unknown,allowed:readonly string[],label:string,expectedDescription:string):void{if(typeof value!=='object'||value===null||Array.isArray(value)||typeof (value as Record<string,unknown>).operation!=='string'||!allowed.includes((value as Record<string,unknown>).operation as string))throw new Error(`${label} does not reference ${expectedDescription}`);}
  private requireSeoLanguage(request:Site.SiteSeoResourceRequest):void{if(request.type==='page')this.requireLanguage(request.language,'SEO Page');}
}
