export type MediaIngestMimeType = 'image/jpeg' | 'image/png' | 'image/webp' | 'application/pdf';

export interface MediaIngestPlanRequest {
  fileName: string;
  mimeType: MediaIngestMimeType;
  contentBase64: string;
  title?: string | undefined;
  altText?: string | undefined;
  caption?: string | undefined;
  description?: string | undefined;
}
export interface MediaIngestCancelRequest { planId: string; }
export interface MediaIngestApplyRequest { planId: string; confirm: true; }
export interface MediaIngestVerifyRequest { operationId: string; }
export interface MediaIngestRollbackRequest { operationId: string; confirm: true; }

export interface MediaMetadataChanges {
  title?: string | undefined;
  altText?: string | undefined;
  caption?: string | undefined;
  description?: string | undefined;
}
export interface MediaMetadataUpdatePlanRequest { attachmentId: number; changes: MediaMetadataChanges; }
export interface MediaMetadataApplyRequest { planId: string; confirm: true; }
export interface MediaMetadataVerifyRequest { operationId: string; rendered: boolean; }
export interface MediaMetadataRollbackRequest { operationId: string; confirm: true; }

export interface A5WriteAdapter {
  planMediaIngest(request: MediaIngestPlanRequest): Promise<unknown>;
  cancelMediaIngest(request: MediaIngestCancelRequest): Promise<unknown>;
  applyMediaIngest(request: MediaIngestApplyRequest): Promise<unknown>;
  verifyMediaIngest(request: MediaIngestVerifyRequest): Promise<unknown>;
  rollbackMediaIngest(request: MediaIngestRollbackRequest): Promise<unknown>;
  planMediaMetadataUpdate(request: MediaMetadataUpdatePlanRequest): Promise<unknown>;
  applyMediaMetadata(request: MediaMetadataApplyRequest): Promise<unknown>;
  verifyMediaMetadata(request: MediaMetadataVerifyRequest): Promise<unknown>;
  rollbackMediaMetadata(request: MediaMetadataRollbackRequest): Promise<unknown>;
}
