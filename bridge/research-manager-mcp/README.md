# Site Control MCP — commercial core

Commercial MCP control core with `eduardoyauriluna.com` as the first reference tenant and Research Manager as the first bounded adapter.

The product is designed so additional customers can be provisioned without forking the codebase.

## Current surface — v0.3.0 tenant isolation

The accepted read-only surface remains compatible and exposes exactly three MCP tools:

- `research_status`
- `research_versions`
- `research_capabilities`

There is no arbitrary REST/path proxy. The Research Manager adapter only calls the fixed Research Manager v1 routes `/status`, `/versions`, and `/capabilities`.

## Commercial architecture

```text
MCP client / workspace
  → authenticated principal
  → Site Control MCP core
  → allowed tenant
  → registered adapter
  → bounded customer-side manager/connector
  → customer system
```

The runtime has a closed tenant registry. A caller cannot provide an arbitrary backend URL or credential.

## Shared multi-tenant mode

Shared routing is opt-in with `MCP_SHARED_ROUTING=true`.

- tenants are provisioned through `MCP_TENANTS_JSON`;
- principals are provisioned through `MCP_PRINCIPALS_JSON`;
- both tenant backend credentials and principal bearer credentials are referenced by secret name rather than embedded in JSON;
- each principal has an explicit allowlist of tenant IDs;
- unknown principals, unknown tenants and cross-tenant access fail closed;
- the shared endpoint is `/mcp/{tenant-id}`;
- rate/usage counters are isolated by `principal × tenant`;
- access decisions emit structured audit events with principal ID, tenant ID and outcome, never bearer material.

Example:

```bash
export MCP_TENANTS_JSON='[{"id":"client-one","label":"Client One","adapter":"research-manager","baseUrl":"https://client-one.example","readTokenEnv":"CLIENT_ONE_MANAGER_TOKEN","languages":["es","en"]},{"id":"client-two","label":"Client Two","adapter":"research-manager","baseUrl":"https://client-two.example","readTokenEnv":"CLIENT_TWO_MANAGER_TOKEN","languages":["es","en"]}]'
export CLIENT_ONE_MANAGER_TOKEN='...'
export CLIENT_TWO_MANAGER_TOKEN='...'

export MCP_PRINCIPALS_JSON='[{"id":"agency-workspace","bearerTokenEnv":"AGENCY_BEARER_TOKEN","tenants":["client-one","client-two"]},{"id":"client-one-workspace","bearerTokenEnv":"CLIENT_ONE_BEARER_TOKEN","tenants":["client-one"]}]'
export AGENCY_BEARER_TOKEN='...'
export CLIENT_ONE_BEARER_TOKEN='...'
export MCP_SHARED_ROUTING=true
export MCP_REQUESTS_PER_MINUTE=120
npm run dev
```

The agency principal can connect to either registered tenant endpoint. The client-one principal is denied if it tries `/mcp/client-two` even when it knows that tenant ID.

## Secret-provider boundary

Configuration resolves credentials through the `SecretResolver` interface. v0.3.0 ships an environment-backed implementation, but the tenant/principal contracts depend on secret references, so Vault/KMS/managed secret-store resolvers can be added without changing tenant definitions or MCP tools.

## Private/single-tenant mode

For private deployments and the Eduardo reference lab, the previous variables remain supported:

```bash
export ERM_BASE_URL=https://eduardoyauriluna.com
export ERM_READ_TOKEN='...'
export MCP_TENANT_ID=eduardo-research
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
- secrets are referenced outside tenant/principal definitions;
- the MCP client cannot supply arbitrary Manager/backend routes;
- Host and Origin allowlists remain enforced;
- adapter errors expose typed non-secret diagnostics only;
- tenant IDs are identifiers, not authorization;
- rate/usage counters are independent for each principal and tenant;
- structured access audit events contain identifiers/outcomes but no tokens.

## Write roadmap

Broad write tools remain deferred until this tenant-isolation slice is accepted in CI and then proven against the Eduardo reference tenant. Phase 2B will preserve the connected gateway semantics, including for Research Manager:

`Preview → explicit Apply → Verify → Rollback`

Write credentials remain separate and least-privilege.

See:

- `docs/57-chatgpt-mcp-bridge.md`
- `docs/58-commercial-mcp-platform.md`
- issue #223
