import type { CommercialCapability } from './entitlements.js';

export interface ToolAuthorizationContract {
  commercialCapability: CommercialCapability;
  backendScopes: readonly string[];
  readOnly: boolean;
}

export const TOOL_AUTHORIZATION = {
  research_status: { commercialCapability:'site.read', backendScopes:['site.read'], readOnly:true },
  research_versions: { commercialCapability:'site.read', backendScopes:['site.read'], readOnly:true },
  research_capabilities: { commercialCapability:'site.read', backendScopes:['site.read'], readOnly:true },

  site_pages_list: { commercialCapability:'pages.read', backendScopes:['site.read'], readOnly:true },
  site_page_inspect: { commercialCapability:'pages.read', backendScopes:['site.read'], readOnly:true },
  site_page_slots_plan: { commercialCapability:'pages.write', backendScopes:['operations.apply'], readOnly:false },
  site_page_slots_apply: { commercialCapability:'pages.write', backendScopes:['operations.apply'], readOnly:false },
  site_page_slots_verify: { commercialCapability:'pages.write', backendScopes:['site.read','site.diagnostics'], readOnly:false },
  site_page_slots_rollback: { commercialCapability:'operations.rollback', backendScopes:['site.read','operations.rollback'], readOnly:false },

  site_insights_list: { commercialCapability:'insights.read', backendScopes:['site.read'], readOnly:true },
  site_insight_inspect: { commercialCapability:'insights.read', backendScopes:['site.read'], readOnly:true },
  site_insight_create_plan: { commercialCapability:'insights.write', backendScopes:['operations.apply'], readOnly:false },
  site_insight_update_plan: { commercialCapability:'insights.write', backendScopes:['operations.apply'], readOnly:false },
  site_insight_apply: { commercialCapability:'insights.write', backendScopes:['operations.apply'], readOnly:false },
  site_insight_verify: { commercialCapability:'insights.write', backendScopes:['site.read','site.diagnostics'], readOnly:false },
  site_insight_rollback: { commercialCapability:'operations.rollback', backendScopes:['site.read','operations.rollback'], readOnly:false },
  site_insight_pair_plan: { commercialCapability:'translations.write', backendScopes:['operations.apply','insights.write','translations.write'], readOnly:false },
  site_insight_unpair_plan: { commercialCapability:'translations.write', backendScopes:['operations.apply','insights.write','translations.write'], readOnly:false },
  site_insight_pairing_apply: { commercialCapability:'translations.write', backendScopes:['operations.apply','insights.write','translations.write'], readOnly:false },
  site_insight_pairing_verify: { commercialCapability:'translations.write', backendScopes:['site.read','site.diagnostics'], readOnly:false },
  site_insight_pairing_rollback: { commercialCapability:'operations.rollback', backendScopes:['site.read','operations.rollback','insights.write','translations.write'], readOnly:false },

  site_research_objects_list: { commercialCapability:'research.read', backendScopes:['research.read'], readOnly:true },
  site_research_object_inspect: { commercialCapability:'research.read', backendScopes:['research.read'], readOnly:true },
  site_research_lines_list: { commercialCapability:'research.read', backendScopes:['research.read'], readOnly:true },
  site_research_line_inspect: { commercialCapability:'research.read', backendScopes:['research.read'], readOnly:true },

  site_translations_list: { commercialCapability:'translations.read', backendScopes:['translations.read'], readOnly:true },
  site_translation_inspect: { commercialCapability:'translations.read', backendScopes:['translations.read'], readOnly:true },

  site_evidence_inventory: { commercialCapability:'evidence.read', backendScopes:['evidence.read'], readOnly:true },
  site_evidence_identity: { commercialCapability:'evidence.read', backendScopes:['evidence.read'], readOnly:true },
  site_evidence_list: { commercialCapability:'evidence.read', backendScopes:['evidence.read'], readOnly:true },
  site_evidence_inspect: { commercialCapability:'evidence.read', backendScopes:['evidence.read'], readOnly:true },

  site_navigation_inspect: { commercialCapability:'navigation.read', backendScopes:['pages.read'], readOnly:true },

  site_media_list: { commercialCapability:'media.read', backendScopes:['media.read'], readOnly:true },
  site_media_inspect: { commercialCapability:'media.read', backendScopes:['media.read'], readOnly:true },
  site_media_diagnostics: { commercialCapability:'media.read', backendScopes:['media.read'], readOnly:true },
  site_media_diagnostic_inspect: { commercialCapability:'media.read', backendScopes:['media.read'], readOnly:true },

  site_seo_geo_site: { commercialCapability:'seo.read', backendScopes:['site.diagnostics','seo.read'], readOnly:true },
  site_seo_geo_resource: { commercialCapability:'seo.read', backendScopes:['site.diagnostics','seo.read'], readOnly:true },
  site_seo_geo_resource_actions: { commercialCapability:'seo.read', backendScopes:['site.diagnostics','seo.read'], readOnly:true },
  site_seo_geo_indexability: { commercialCapability:'seo.read', backendScopes:['site.diagnostics','seo.read'], readOnly:true },
  site_seo_geo_resource_indexability: { commercialCapability:'seo.read', backendScopes:['site.diagnostics','seo.read'], readOnly:true },
  site_seo_geo_internal_links: { commercialCapability:'seo.read', backendScopes:['site.diagnostics','seo.read'], readOnly:true },
  site_seo_geo_readiness: { commercialCapability:'seo.read', backendScopes:['site.diagnostics','seo.read'], readOnly:true },

  site_design_inspect: { commercialCapability:'design.read', backendScopes:['design.read'], readOnly:true },
  site_design_variants: { commercialCapability:'design.read', backendScopes:['design.read'], readOnly:true },
  site_design_readiness: { commercialCapability:'design.read', backendScopes:['design.read'], readOnly:true },
} as const satisfies Record<string, ToolAuthorizationContract>;

export type SiteControlToolName = keyof typeof TOOL_AUTHORIZATION;

export function toolAuthorization(name: SiteControlToolName): ToolAuthorizationContract {
  return TOOL_AUTHORIZATION[name];
}
