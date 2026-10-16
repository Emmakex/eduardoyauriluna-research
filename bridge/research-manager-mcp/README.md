# Site Control MCP — commercial core

Commercial MCP control core with `eduardoyauriluna.com` as the first reference tenant and Research Manager as the first bounded adapter.

The product is designed so additional customers can be provisioned without forking the codebase.

## Current surface — v0.6.0 Pages + Insights read

The current MCP surface is read-only and exposes seven bounded tools:

- `research_status` — `site.read`;
- `research_versions` — `site.read`;
- `research_capabilities` — `site.read`;
- `site_pages_list` — `pages.read`;
- `site_page_inspect` — `pages.read`;
- `site_insights_list` — `insights.read`;
- `site_insight_inspect` — `insights.read`.

Research Manager remains the first adapter.

### Pages mapping

- `GET /research-manager/v1/pages`;
- `GET /research-manager/v1/pages/{registered-key}?language={enabled-language}`.

Page keys and languages are validated before transport. The Manager then independently validates the active Research preset and its own backend scope.

### Insights mapping

- `GET /research-manager/v1/insights` with optional `language`;
- `GET /research-manager/v1/insights/{positive-post-id}`.

The list endpoint only returns explicitly managed Research Insights. Detail inspection rejects missing or unmanaged WordPress posts. Language is constrained to the tenant language contract and IDs must be positive integers before transport.

The MCP caller cannot supply an arbitrary Manager path, URL, post type or WordPress REST endpoint.

## Commercial architecture

```text
MCP client / workspace
  → authenticated principal
  → authorized tenant
  → commercial entitlement gate
  → registered adapter
  → bounded customer-side manager/connector
  → customer system
```

The runtime has a closed tenant registry. A caller cannot provide an arbitrary backend URL or credential.

## Shared multi-tenant mode

Shared routing is opt-in with `MCP_SHARED_ROUTING=true`.

- tenants are provisioned through `MCP_TENANTS_JSON`;
- principals are provisioned through `MCP_PRINCIPALS_JSON`;
- backend and principal credentials are referenced by secret name rather than embedded in JSON;
- each principal has an explicit tenant allowlist;
- unknown principals, unknown tenants and cross-tenant access fail closed;
- the shared endpoint is `/mcp/{tenant-id}`;
- rate/usage counters are isolated by `principal × tenant`;
- access decisions emit structured non-secret audit events.

## Commercial entitlements

The effective permission is always:

`tenant entitlement ∩ principal capability`

A tool also remains subject to the connected backend's independent credential/scope checks.

Examples:

- `pages.read` in both layers → Pages tools commercially allowed;
- `insights.read` in both layers → Insights tools commercially allowed;
- missing tenant module → `tenant_not_entitled`;
- missing principal grant → `principal_not_granted`;
- missing request authorization context → fail closed.

Legacy configurations default to only `site.read`. Pages and Insights therefore require explicit product entitlement activation.

## Secret-provider boundary

Configuration resolves credentials through the `SecretResolver` interface. Tenant/principal definitions carry secret references rather than secret values, allowing a Vault/KMS/managed-secret resolver later without changing MCP tools or tenant IDs.

## Private/single-tenant mode

The Eduardo reference lab and private customer deployments remain supported:

```bash
export ERM_BASE_URL=https://eduardoyauriluna.com
export ERM_READ_TOKEN='...'
export MCP_TENANT_ID=eduardo-research
export MCP_TENANT_ENTITLEMENTS=site.read,pages.read,insights.read
npm run dev
```

Endpoint:

```text
http://127.0.0.1:8787/mcp
```

A non-loopback single-tenant deployment requires the legacy outer `MCP_BEARER_TOKEN`. Shared mode instead requires configured principals.

## Runtime and security boundary

- Node.js 22+;
- least-privilege backend credential per tenant;
- authenticated principal profile for shared-mode clients;
- closed tenant and capability catalogs;
- no caller-controlled Manager route;
- no arbitrary `wp/v2`, database, filesystem, shell or backend URL surface;
- Host/Origin allowlists remain enforced;
- rate/usage boundaries are isolated per principal/tenant;
- access and entitlement denials are auditable without token material.

Health:

```bash
curl -s http://127.0.0.1:8787/healthz
```

Use MCP Inspector against the relevant Streamable HTTP endpoint for protocol/tool acceptance.

## Roadmap

Phase 2B.1 continues domain-by-domain with Research objects/lines, translations/evidence, navigation/media and SEO/GEO/design inspection.

Broad writes remain behind the connected gateway lifecycle, including Research Manager:

`Preview → explicit Apply → Verify → Rollback`

Write credentials remain separate and least-privilege.

See:

- `docs/57-chatgpt-mcp-bridge.md`
- `docs/58-commercial-mcp-platform.md`
- issue #223
