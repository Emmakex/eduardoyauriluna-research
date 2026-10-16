# Site Control MCP — commercial core

Commercial MCP control core with `eduardoyauriluna.com` as the first reference tenant and Research Manager as the first bounded adapter.

The product is designed so additional customers can be provisioned without forking the codebase.

## Current surface — v0.2.0 foundation

The accepted read-only surface remains compatible and exposes exactly three MCP tools:

- `research_status`
- `research_versions`
- `research_capabilities`

There is no arbitrary REST/path proxy. The Research Manager adapter only calls the fixed Research Manager v1 routes `/status`, `/versions`, and `/capabilities`.

## Commercial architecture

```text
MCP client
  → Site Control MCP core
  → registered tenant
  → registered adapter
  → bounded customer-side manager/connector
  → customer system
```

The runtime has a closed tenant registry. A caller cannot provide an arbitrary backend URL or credential.

### Preferred tenant registry mode

`MCP_TENANTS_JSON` contains non-secret tenant definitions. Each definition references the environment-variable name holding its Manager credential.

Example:

```bash
export MCP_TENANTS_JSON='[{"id":"eduardo-research","label":"Eduardo Research","adapter":"research-manager","baseUrl":"https://eduardoyauriluna.com","readTokenEnv":"TENANT_EDUARDO_READ_TOKEN","languages":["en","es"]}]'
export TENANT_EDUARDO_READ_TOKEN='...'
export MCP_TENANT_ID='eduardo-research'
npm run dev
```

The registry JSON contains no bearer secret.

### Legacy/reference single-tenant mode

For the Eduardo reference lab the previous variables remain supported:

```bash
export ERM_BASE_URL=https://eduardoyauriluna.com
export ERM_READ_TOKEN='...'
export MCP_TENANT_ID=eduardo-research
npm run dev
```

## Runtime

Requirements:

- Node.js 22+
- a least-privilege backend credential for the selected tenant

Health check:

```bash
curl -s http://127.0.0.1:8787/healthz
```

MCP endpoint:

```text
http://127.0.0.1:8787/mcp
```

Use MCP Inspector against the Streamable HTTP endpoint for protocol/tool acceptance.

## Security boundary

- backend credentials are server-side only;
- tenant configuration is closed and server-controlled;
- secrets are referenced outside the registry definition;
- the MCP client cannot supply arbitrary Manager/backend routes;
- loopback is the default bind address;
- binding outside loopback requires a distinct `MCP_BEARER_TOKEN`;
- Host and Origin allowlists remain enforced;
- adapter errors expose typed non-secret diagnostics only;
- tenant IDs are identifiers, not authorization.

A shared write-capable commercial endpoint will additionally require authenticated principal → allowed tenant mapping before multi-tenant routing is enabled.

## Write roadmap

Broad write tools are intentionally deferred until the commercial tenant/auth boundary is accepted. Phase 2 preserves the connected gateway semantics, including for Research Manager:

`Preview → explicit Apply → Verify → Rollback`

Write credentials remain separate and least-privilege.

See:

- `docs/57-chatgpt-mcp-bridge.md`
- `docs/58-commercial-mcp-platform.md`
- issue #223
