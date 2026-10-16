export interface SitePageInspectionRequest {
  key: string;
  language: string;
}

export interface SiteControlAdapter {
  readonly kind: string;
  readonly tenantId: string;
  readonly tenantLabel: string;

  status(): Promise<unknown>;
  versions(): Promise<unknown>;
  capabilities(): Promise<unknown>;
  listPages(): Promise<unknown>;
  inspectPage(request: SitePageInspectionRequest): Promise<unknown>;
}
