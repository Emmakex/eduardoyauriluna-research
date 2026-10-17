import { ResearchManagerClient } from '../manager-client.js';
import { ResearchManagerWriteClient } from '../write-manager-client.js';
import type { TenantConfig } from '../config.js';
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
  SiteResearchLineInspectionRequest,
  SiteResearchLineListRequest,
  SiteResearchObjectInspectionRequest,
  SiteResearchObjectListRequest,
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
  constructor(tenant:TenantConfig,fetchImpl:typeof fetch=fetch){
    this.tenantId=tenant.id;
    this.tenantLabel=tenant.label;
    this.languages=tenant.languages;
    this.client=new ResearchManagerClient(tenant,fetchImpl);
    this.writeClient=new ResearchManagerWriteClient(tenant,fetchImpl);
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
  listResearchLines(request:SiteResearchLineListRequest):Promise<unknown>{const language=request.language??'';if(language)this.requireLanguage(language,'Research Line');return this.client.researchLines(language);}
  inspectResearchLine(request:SiteResearchLineInspectionRequest):Promise<unknown>{return this.client.researchLine(request.postId);}
  listTranslations(request:SiteTranslationListRequest):Promise<unknown>{const language=request.language??'';if(language)this.requireLanguage(language,'Translation');return this.client.translations(request.postType,language);}
  inspectTranslation(request:SiteTranslationInspectionRequest):Promise<unknown>{return this.client.translation(request.postId);}
  evidenceInventory():Promise<unknown>{return this.client.evidenceInventory();}
  evidenceIdentity():Promise<unknown>{return this.client.evidenceIdentity();}
  listEvidence(request:SiteEvidenceListRequest):Promise<unknown>{return this.client.evidenceRecords(request.group);}
  inspectEvidence(request:SiteEvidenceInspectionRequest):Promise<unknown>{return this.client.evidenceRecord(request.group,request.recordId);}
  inspectNavigation():Promise<unknown>{return this.client.navigation();}
  listMedia(request:SiteMediaListRequest):Promise<unknown>{return this.client.mediaInventory(request);}
  inspectMedia(request:SiteMediaInspectionRequest):Promise<unknown>{return this.client.mediaItem(request.attachmentId);}
  mediaDiagnostics(request:SiteMediaDiagnosticsRequest):Promise<unknown>{return this.client.mediaDiagnostics(request.limit);}
  inspectMediaDiagnostic(request:SiteMediaDiagnosticInspectionRequest):Promise<unknown>{return this.client.mediaDiagnosticItem(request.attachmentId);}
  seoSite():Promise<unknown>{return this.client.seoSite();}
  seoResource(request:SiteSeoResourceRequest):Promise<unknown>{this.requireSeoLanguage(request);return this.client.seoResource(request);}
  seoResourceActions(request:SiteSeoResourceRequest):Promise<unknown>{this.requireSeoLanguage(request);return this.client.seoResourceActions(request);}
  seoIndexability():Promise<unknown>{return this.client.seoIndexability();}
  seoResourceIndexability(request:SiteSeoResourceRequest):Promise<unknown>{this.requireSeoLanguage(request);return this.client.seoResourceIndexability(request);}
  seoInternalLinks(request:SiteSeoInternalLinksRequest):Promise<unknown>{return this.client.seoInternalLinks(request.limit);}
  seoReadiness(request:SiteSeoReadinessRequest):Promise<unknown>{return this.client.seoReadiness(request.perTypeLimit);}
  designInspect():Promise<unknown>{return this.client.design();}
  designVariants():Promise<unknown>{return this.client.designVariants();}
  designReadiness(request:SiteDesignReadinessRequest):Promise<unknown>{return this.client.designReadiness(request.rendered);}
  private requireLanguage(language:string,label:string):void{if(!this.languages.includes(language))throw new Error(`${label} language is not enabled for tenant ${this.tenantId}`);}
  private requireOperation(value:unknown,allowed:readonly string[],label:string,expectedDescription:string):void{if(typeof value!=='object'||value===null||Array.isArray(value)||typeof (value as Record<string,unknown>).operation!=='string'||!allowed.includes((value as Record<string,unknown>).operation as string))throw new Error(`${label} does not reference ${expectedDescription}`);}
  private requireSeoLanguage(request:SiteSeoResourceRequest):void{if(request.type==='page')this.requireLanguage(request.language,'SEO Page');}
}
