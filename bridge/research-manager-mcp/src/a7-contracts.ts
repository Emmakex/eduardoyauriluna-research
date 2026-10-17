export type DesignPalette = 'researcher' | 'ocean-lab' | 'graphite-academic';
export type DesignTypography = 'modern-sans' | 'academic-mixed' | 'humanist-sans';
export type DesignDensity = 'compact' | 'balanced' | 'airy';

export interface DesignPresetChanges {
  palette?: DesignPalette | undefined;
  typography?: DesignTypography | undefined;
  density?: DesignDensity | undefined;
}
export interface DesignPresetPlanRequest { changes: DesignPresetChanges; intent?: string | undefined; }
export interface DesignPresetApplyRequest { planId: string; confirm: true; }
export interface DesignPresetVerifyRequest { operationId: string; }
export interface DesignPresetRollbackRequest { operationId: string; confirm: true; }

export type DesignHeroVariant = 'orbit' | 'editorial' | 'compact';
export type DesignSectionsVariant = 'layered' | 'plain' | 'bands';
export type DesignCardsVariant = 'bordered' | 'lifted' | 'quiet';
export type DesignCollectionsVariant = 'editorial-grid' | 'compact-grid' | 'list';

export interface DesignVariantChanges {
  hero?: DesignHeroVariant | undefined;
  sections?: DesignSectionsVariant | undefined;
  cards?: DesignCardsVariant | undefined;
  collections?: DesignCollectionsVariant | undefined;
}
export interface DesignVariantPlanRequest { changes: DesignVariantChanges; intent?: string | undefined; }
export interface DesignVariantApplyRequest { planId: string; confirm: true; }
export interface DesignVariantVerifyRequest { operationId: string; }
export interface DesignVariantRollbackRequest { operationId: string; confirm: true; }

export interface A7WriteAdapter {
  planDesignPresetUpdate(request: DesignPresetPlanRequest): Promise<unknown>;
  applyDesignPreset(request: DesignPresetApplyRequest): Promise<unknown>;
  verifyDesignPreset(request: DesignPresetVerifyRequest): Promise<unknown>;
  rollbackDesignPreset(request: DesignPresetRollbackRequest): Promise<unknown>;
  planDesignVariantUpdate(request: DesignVariantPlanRequest): Promise<unknown>;
  applyDesignVariant(request: DesignVariantApplyRequest): Promise<unknown>;
  verifyDesignVariant(request: DesignVariantVerifyRequest): Promise<unknown>;
  rollbackDesignVariant(request: DesignVariantRollbackRequest): Promise<unknown>;
}
