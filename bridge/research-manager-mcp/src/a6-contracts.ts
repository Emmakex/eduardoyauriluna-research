export type NavigationLocation = 'primary' | 'footer';
export type NavigationTarget =
  | 'home'
  | 'about'
  | 'research'
  | 'publications'
  | 'projects'
  | 'software'
  | 'datasets'
  | 'cv'
  | 'insights'
  | 'contact'
  | 'privacy-policy'
  | 'legal-notice';

export interface NavigationUpdatePlanRequest {
  location: NavigationLocation;
  items: NavigationTarget[];
  intent?: string | undefined;
}
export interface NavigationApplyRequest { planId: string; confirm: true; }
export interface NavigationVerifyRequest { operationId: string; rendered: true; }
export interface NavigationRollbackRequest { operationId: string; confirm: true; }

export interface A6WriteAdapter {
  planNavigationUpdate(request: NavigationUpdatePlanRequest): Promise<unknown>;
  applyNavigation(request: NavigationApplyRequest): Promise<unknown>;
  verifyNavigation(request: NavigationVerifyRequest): Promise<unknown>;
  rollbackNavigation(request: NavigationRollbackRequest): Promise<unknown>;
}
