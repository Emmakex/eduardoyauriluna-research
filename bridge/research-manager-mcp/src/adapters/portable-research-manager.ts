import type { TenantConfig } from '../config.js';
import { ExternalSiteProbe } from '../external-site-probe.js';
import { PortableManagerClient } from '../portable-manager-client.js';
import { ResearchManagerAdapter } from './research-manager.js';

function record(value:unknown):Record<string,unknown>|null{return typeof value==='object'&&value!==null&&!Array.isArray(value)?value as Record<string,unknown>:null;}

/** Adds the universal Site Baseline Audit to the accepted Research Manager adapter. */
export class PortableResearchManagerAdapter extends ResearchManagerAdapter {
  private readonly portableClient:PortableManagerClient;
  private readonly externalProbe:ExternalSiteProbe;

  constructor(tenant:TenantConfig,fetchImpl:typeof fetch=fetch){
    super(tenant,fetchImpl);
    this.portableClient=new PortableManagerClient(tenant,fetchImpl);
    this.externalProbe=new ExternalSiteProbe(tenant.managerBaseUrl,fetchImpl);
  }

  async baseline():Promise<unknown>{
    const [internal,external]=await Promise.all([this.portableClient.baseline(),this.externalProbe.run()]);
    const baseline=record(internal);
    if(!baseline)return {internal,external_observability:{external_probe:external}};
    const existing=record(baseline.external_observability)??{};
    return {
      ...baseline,
      external_observability:{
        ...existing,
        dns_tls_http_headers:external,
        robots_and_sitemap_crawl:{status:'measured',robots:record(external)?.robots??{},sitemap:record(external)?.sitemap??{}},
        html_seo:record(external)?.html??{},
        transport_performance:record(external)?.transport_performance??{},
      },
    };
  }
}
