import type { TenantConfig } from '../config.js';
import { PortableResearchManagerAdapter } from './portable-research-manager.js';

/** Generic WordPress adapter: portable baseline plus the minimal generic MCP tool inventory. */
export class GenericWordPressAdapter extends PortableResearchManagerAdapter {
  constructor(tenant:TenantConfig,fetchImpl:typeof fetch=fetch){
    super({...tenant,productProfile:'generic-wordpress'},fetchImpl);
  }
}
