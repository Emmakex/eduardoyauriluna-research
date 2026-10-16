import type { TenantConfig } from './config.js';

export interface TenantMetadata {
  id: string;
  label: string;
  adapter: TenantConfig['adapter'];
  languages: readonly string[];
}

export class TenantRegistry {
  private readonly tenants = new Map<string, TenantConfig>();

  constructor(configs: readonly TenantConfig[]) {
    for (const tenant of configs) {
      if (this.tenants.has(tenant.id)) throw new Error(`Duplicate tenant id: ${tenant.id}`);
      this.tenants.set(tenant.id, tenant);
    }
    if (this.tenants.size === 0) throw new Error('At least one tenant must be configured');
  }

  require(id: string): TenantConfig {
    const tenant = this.tenants.get(id);
    if (!tenant) throw new Error(`Unknown tenant id: ${id}`);
    return tenant;
  }

  metadata(id: string): TenantMetadata {
    const tenant = this.require(id);
    return {
      id: tenant.id,
      label: tenant.label,
      adapter: tenant.adapter,
      languages: tenant.languages,
    };
  }
}
