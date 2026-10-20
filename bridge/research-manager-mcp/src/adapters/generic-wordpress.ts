import type { TenantConfig } from '../config.js';
import { PortableManagerClient } from '../portable-manager-client.js';
import { ResearchManagerAdapter } from './research-manager.js';

/** Generic WordPress adapter: reuses accepted read transport but adds only portable diagnostics. */
export class GenericWordPressAdapter extends ResearchManagerAdapter {
  private readonly portableClient:PortableManagerClient;

  constructor(tenant:TenantConfig,fetchImpl:typeof fetch=fetch){
    super({...tenant,productProfile:'generic-wordpress'},fetchImpl);
    this.portableClient=new PortableManagerClient(tenant,fetchImpl);
  }

  baseline():Promise<unknown>{
    return this.portableClient.baseline();
  }
}
