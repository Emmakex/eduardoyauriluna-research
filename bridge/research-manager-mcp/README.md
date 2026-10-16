# Site Control MCP — commercial core

Commercial MCP control core with `eduardoyauriluna.com` as the first reference tenant and Research Manager as the first bounded adapter.

The product is designed so additional customers can be provisioned without forking the codebase.

## Current surface — v0.5.0 Pages read

The accepted foundation remains read-only. The MCP surface now exposes five bounded tools:

- `research_status` — requires `site.read`;
- `research_versions` — requires `site.read`;
- `research_capabilities` — requires `site.read`;
- `site_pages_list` — requires `pages.read`;
- `site_page_inspect` — requires `pages.read`.

Research Manager remains the first adapter. Its Pages implementation maps only to the real Manager routes:

- `GET /research-manager/v1/pages`;
- `GET /research-manager/v1/pages/{registered-key}?language={enabled-language}`.

The page key and language are validated before transport; the MCP caller cannot supply a Manager path, URL or WordPress endpoint. The Manager independently enforces its backend scope (`site.read` for these two routes) and active Research preset contract.

The Pages inventory returns the bounded Theme-owned page catalog, language availability and structured-slot state. Page inspection returns contract/state metadata and Theme-owned structured slots rather than arbitrary WordPress HTML or REST data.

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
- tenant backend credentials and principal bearer credentials are referenced by secret name rather than embedded in JSON;
- each principal has an explicit allowlist of tenant IDs;
- unknown principals, unknown tenants and cross-tenant access fail closed;
- the shared endpoint is `/mcp/{tenant-id}`;
- rate/usage counters are isolated by `principal × tenant`;
- access decisions emit structured audit events with principal ID, tenant ID and outcome, never bearer material.

## Commercial entitlements

A tenant being reachable does not mean every product module is available.

Each tenant declares `entitlements`, representing modules/capabilities it has contracted. Each principal declares `capabilities`, representing the permissions/role granted to that user/workspace.

The effective permission is always:

`tenant entitlement ∩ principal capability`

Examples:

- tenant has `pages.read`, principal has `pages.read` → Pages tools are commercially allowed;
- tenant does not have `pages.read` → `tenant_not_entitled`;
- tenant has `pages.read`, principal lacks it → `principal_not_granted`;
- no request authorization context → fail closed.

If old configuration omits entitlement fields, the default is only `site.read`, so the new Pages tools remain unavailable until `pages.read` is explicitly enabled.

Commercial entitlements do **not** replace Research Manager scopes or any future backend permission model. Both layers must permit the operation.

## Secret-provider boundary

Configuration resolves credentials through the `SecretResolver` interface. The tenant/principal contracts depend on secret references, so Vault/KMS/managed secret-store resolvers can be added without changing tenant definitions or MCP tools.

## Private/single-tenant mode

For private deployments and the Eduardo reference lab, the previous variables remain supported:

```bash
export ERM_BASE_URL=https://eduardoyauriluna.com
export ERM_READ_TOKEN='...'
export MCP_TENANT_ID=eduardo-research
export MCP_TENANT_ENTITLEMENTS=site.read,pages.read
npm run dev
```

The endpoint remains:

```text
http://127.0.0.1:8787/mcp
```

A non-loopback single-tenant deployment requires the legacy outer `MCP_BEARER_TOKEN`. Shared mode does not use that variable; it requires configured principals.

## Runtime

Requirements:

- Node.js 22+
- a least-privilege backend credential for each provisioned tenant
- an authenticated principal profile for every shared-mode MCP workspace/client

Health check:

```bash
curl -s http://127.0.0.1:8787/healthz
```

In shared mode the health response does not enumerate tenant metadata.

Use MCP Inspector against the relevant Streamable HTTP endpoint for protocol/tool acceptance.

## Security boundary

- backend credentials are server-side only;
- tenant configuration is closed and server-controlled;
- principal-to-tenant access is deny-by-default;
- commercial capabilities are typed and closed;
- every tool invocation requires its capability in both tenant and principal layers;
- Page keys/languages are validated and cannot become arbitrary routes;
- secrets are referenced outside tenant/principal definitions;
- the MCP client cannot supply arbitrary Manager/backend routes;
- Host and Origin allowlists remain enforced;
- adapter errors expose typed non-secret diagnostics only;
- tenant IDs are identifiers, not authorization;
- rate/usage counters are independent for each principal and tenant;
- access and entitlement denials emit structured non-secret audit events.

## Roadmap

Phase 2B continues by adding bounded read/list/inspect surfaces domain-by-domain. Broad writes remain behind the connected gateway lifecycle, including Research Manager:

`Preview → explicit Apply → Verify → Rollback`

Write credentials remain separate and least-privilege.

See:

- `docs/57-chatgpt-mcp-bridge.md`
- `docs/58-commercial-mcp-platform.md`
- issue #223
