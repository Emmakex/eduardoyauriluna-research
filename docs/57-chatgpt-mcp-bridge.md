# ChatGPT / OpenAI MCP bridge

## Purpose

Research Manager remains the accepted execution boundary for `eduardoyauriluna.com`. The MCP bridge does **not** replace it and does not add a second WordPress control plane.

The Eduardo installation is the first reference tenant of a reusable commercial MCP control core. The commercial architecture is documented in `docs/58-commercial-mcp-platform.md`.

Reference operating path:

```text
Eduardo / customer operator
  → ChatGPT / OpenAI / compatible MCP client
  → authenticated HTTPS MCP endpoint
  → Site Control MCP core
  → authenticated principal / tenant boundary
  → commercial entitlement gate
  → registered adapter
  → bounded Research Manager / customer-side connector
  → application state
  → rendered / semantic verification
```

For the first tenant, the registered adapter is `research-manager` and the bounded gateway is Research Manager.

## Architectural rules

1. The connected customer-side Manager/connector remains the only application execution gateway.
2. The MCP server exposes named typed tools, never a generic `path`, `route`, SQL, shell, PHP, filesystem or arbitrary REST proxy.
3. Backend credentials live only in the MCP runtime/secret store.
4. Read, normal-write and genuinely elevated credential/capability boundaries remain explicit.
5. The MCP client never receives backend bearer credentials.
6. Tenant configuration is closed and server-controlled; the model cannot invent a backend URL or tenant connection.
7. Tenant IDs are identifiers, not authorization.
8. Tool results may include request IDs, operation IDs and non-secret evidence needed for audit/continuation.
9. Language contracts remain explicit for content-bearing capabilities.
10. Elevated identity, destructive deletion, external publication and software-update permissions remain separate elevated surfaces.
11. The same MCP product image must support shared multi-tenant and private single-tenant deployment without source-code forks.

## Accepted baseline

Implementation root:

`bridge/research-manager-mcp`

Protocol/runtime:

- Node.js 22+
- MCP TypeScript SDK v2
- Streamable HTTP MCP endpoint;
- `/mcp` in private/single-tenant mode;
- `/mcp/{tenant-id}` in shared routing mode;
- `/healthz` health endpoint.

Accepted commercial foundation:

- adapter abstraction separated from MCP server;
- `research-manager` first adapter;
- closed tenant registry;
- authenticated principal / tenant allowlist model;
- commercial tenant entitlements × principal capability intersection;
- deny-by-default unknown/cross-tenant behavior;
- per-principal/per-tenant rate and usage boundary;
- secret references resolved server-side;
- structured non-secret audit events;
- bounded read/list/inspect surfaces across the accepted Research Manager domains.

Accepted production write reference lifecycle:

`Page slots Plan → Apply → stored/rendered Verify → Rollback → baseline readback → credential revocation`

Phase 2B production acceptance is complete. Temporary acceptance credentials were revoked and both read/write revocation paths were proven live with backend `HTTP 401 authentication_failed` while the local bridge still retained the revoked credentials.

## Current MCP surface boundary

The MCP currently exposes broad read/list/inspect operations across:

- site status/versions/capabilities;
- Pages;
- Insights;
- Research Lines/Objects;
- Translations;
- Evidence;
- Navigation;
- Media;
- SEO/GEO;
- Design.

The first complete write lifecycle exposed and accepted through MCP is Theme-owned Page slots.

Research Manager itself already contains broader accepted write capabilities. Those capabilities are **not automatically considered MCP-complete** until the adapter exposes the exact bounded lifecycle and that lifecycle passes production acceptance.

## Deployment decision — managed OVH remote MCP

The steady-state managed deployment target is our OVH infrastructure, not a developer laptop.

Target topology:

```text
ChatGPT / OpenAI / compatible MCP client
  → authenticated HTTPS
  → MCP runtime in OVH
  → tenant/principal/entitlement gate
  → registered adapter
  → customer-side Manager/connector
  → WordPress / other bounded system
```

The runtime will be containerized and placed behind HTTPS ingress/reverse proxy. Backend Manager credentials remain server-side and separate from outer MCP client authentication.

Local `127.0.0.1:8787` + MCP Inspector remains an engineering and acceptance path only.

## Deployment modes

### Managed shared / multi-tenant

One MCP platform in OVH serves multiple isolated customers.

Each customer has a separate tenant with independent:

- backend origin/adapter;
- secret references;
- capability entitlements;
- principal/workspace grants;
- rate/usage boundary;
- audit correlation.

Shared routing identifies the tenant, but only principal authorization permits access to it.

### Private managed

The same MCP product image is deployed/bound for a single customer in dedicated runtime/infrastructure, normally on our OVH platform.

Private mode keeps the same typed tools and security lifecycle. It is not a privileged generic admin mode.

### Enterprise / customer infrastructure

The same image and adapter contracts may run in customer-controlled infrastructure when required.

No deployment mode creates a customer-specific code fork.

## Authentication layers

There are distinct credentials:

1. **MCP client → platform**: outer authentication and principal/workspace authorization.
2. **Platform → customer-side adapter/gateway**: per-tenant backend credentials scoped to enabled capabilities.

These credentials must never be the same secret.

## Phase 2C — Full bounded write surface

Objective: make the accepted Research site operable end-to-end from an approved MCP client by exposing the normal Research Manager write capabilities without weakening the Manager security model.

Implementation order:

1. **Insights** — create/draft, edit, relationships, translations, schedule/status, Verify/Rollback.
2. **Research objects** — Lines, Outputs/Publications, Projects, Software, Datasets.
3. **Translations + Evidence** — structured pair/unpair and bounded evidence writes; identity-sensitive writes remain elevated.
4. **SEO/GEO** — deterministic remediation writes only for legitimate bounded targets.
5. **Media** — bounded media ingest/update lifecycle.
6. **Navigation** — bounded registered Theme navigation lifecycle.
7. **Design** — palette, typography, density and registered component variants.

Each write domain requires:

- exact typed tool schemas;
- commercial capability mapping;
- exact backend-scope mapping;
- least-privilege credential selection;
- Preview/Plan where the Manager contract provides it;
- explicit Apply/confirmation;
- stored/rendered Verify where applicable;
- safe Rollback/exact baseline restoration where supported;
- domain/stale/idempotency regression tests;
- production acceptance with reversible fixtures or legitimate natural targets;
- credential revocation evidence after acceptance.

The reference Page-slots lifecycle is the template, not a shortcut around each domain's own Manager contract.

## Phase 2D — OVH production deployment

After the required Phase 2C surface is complete:

1. package the production MCP runtime image;
2. deploy it to our OVH infrastructure;
3. configure HTTPS ingress and `/healthz`;
4. configure outer MCP principal/client authentication;
5. provision the Eduardo reference tenant with least-privilege backend credentials;
6. run remote read/write acceptance through the HTTPS endpoint;
7. verify rotation/revocation behavior;
8. enable persistent audit/usage telemetry;
9. validate two-tenant isolation before enabling shared multi-client writes generally;
10. preserve private single-tenant deployment as the dedicated-customer option using the same image.

## Acceptance gates

### Shared multi-tenant routing

Before one public shared endpoint can serve several customers with write capability:

- authenticated principals/workspaces;
- principal → allowed tenant mapping;
- deny-by-default tenant selection;
- per-tenant credential profiles;
- per-principal/per-tenant rate/usage boundary;
- tenant-safe logs and persistent audit correlation;
- secret-manager-compatible credential resolution;
- proof that knowing another tenant ID cannot cross the boundary.

### Write domains

Each write domain requires:

- least-privilege credential profile;
- Preview proving no mutation when applicable;
- explicit Apply;
- stored/rendered Verify where applicable;
- Rollback and exact baseline restoration where supported;
- revocation/rotation evidence;
- no new normal-operation bypass.

## Non-goals

- generic WordPress administration;
- arbitrary REST proxying;
- SSH/WP-CLI tunnelling through MCP;
- direct database/filesystem access;
- generic page-builder control;
- weakening a customer-side Manager/connector security boundary;
- customer-specific source-code forks as the normal onboarding model.

Commercial architecture: `docs/58-commercial-mcp-platform.md`.
