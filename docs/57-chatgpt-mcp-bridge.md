# ChatGPT / OpenAI MCP bridge

## Purpose

Research Manager v1 remains the accepted execution boundary for `eduardoyauriluna.com`. The MCP bridge does **not** replace it and does not add a second WordPress control plane.

The Eduardo installation is now the first reference tenant of a reusable commercial MCP control core. The commercial architecture is documented in `docs/58-commercial-mcp-platform.md`.

Reference operating path:

```text
Eduardo / customer operator
  → ChatGPT / OpenAI / compatible MCP client
  → Site Control MCP core
  → authenticated tenant boundary
  → registered adapter
  → bounded manager/connector
  → application state
  → rendered/semantic verification
```

For the first tenant, the registered adapter is `research-manager` and the bounded gateway is Research Manager v1.

## Architectural rules

1. The connected customer-side Manager/connector remains the only application execution gateway.
2. The MCP server exposes named tools, never a generic `path`, `route`, SQL, shell, PHP, filesystem or arbitrary REST proxy.
3. Backend credentials live only in the MCP runtime/secret store.
4. Read and write credentials are separate profiles.
5. The MCP client never receives backend bearer credentials.
6. Tenant configuration is closed and server-controlled; the model cannot invent a backend URL or tenant connection.
7. Tenant IDs are identifiers, not authorization.
8. Tool results may include request IDs, operation IDs and non-secret evidence needed for audit/continuation.
9. Language contracts remain explicit for content-bearing capabilities.
10. Elevated identity, destructive deletion, external publication and software-update permissions remain separate elevated surfaces.

## Phase 1 — accepted Research Manager read-only bridge

Implementation root:

`bridge/research-manager-mcp`

Protocol/runtime:

- Node.js 22+
- MCP TypeScript SDK v2
- MCP revision 2026-07-28 through Streamable HTTP
- endpoint `/mcp`
- health endpoint `/healthz`

Accepted tools:

| MCP tool | Research Manager call | Manager scope | Mutation |
| --- | --- | --- | --- |
| `research_status` | `GET /research-manager/v1/status` | `site.read` | no |
| `research_versions` | `GET /research-manager/v1/versions` | `site.read` | no |
| `research_capabilities` | `GET /research-manager/v1/capabilities` | `site.read` | no |

The Research Manager client retains a compile-time route union for those three endpoints; callers cannot supply an arbitrary path.

Phase 1 production acceptance completed with a temporary `site.read` credential and the credential was revoked afterwards.

## v0.2 commercial foundation

Before broadening write tools, the bridge is being refactored into a commercial reusable core:

- MCP server depends on an adapter contract rather than directly on Research Manager;
- `research-manager` is the first adapter;
- a closed tenant registry supports multiple provisioned tenant definitions;
- tenant registry payloads reference secret environment-variable names instead of embedding bearer credentials;
- runtime selects only a registered tenant through `MCP_TENANT_ID`;
- the legacy `ERM_*` single-tenant path remains supported for the Eduardo reference lab;
- the next gate is authenticated shared principal → allowed tenant routing.

This ordering avoids multiplying customer-specific tools before the commercial isolation boundary exists.

## Authentication layers

There are distinct credentials:

1. **MCP client → platform**: outer authentication. A write-capable shared commercial deployment requires production authentication and principal-to-tenant authorization.
2. **Platform → customer-side adapter/gateway**: per-tenant backend credential, stored server-side only and scoped to the enabled capabilities.

These credentials must never be the same secret.

For the local/reference deployment, a bridge bearer remains supported when binding outside loopback. It is not the final commercial multi-tenant authorization model.

## Deployment models

### Managed SaaS

Shared commercial platform with isolated tenants, authenticated principals/workspaces, per-tenant entitlements, audit and metering.

### Private managed instance

Same product image in dedicated infrastructure for a customer requiring stronger isolation.

### Enterprise / customer infrastructure

Same product image and adapter contracts in customer-controlled infrastructure.

### Secure MCP Tunnel / OpenAI API

The same MCP server can be exposed through a secure tunnel or supplied as a remote MCP server to an eligible OpenAI client/API path. This does not alter the adapter or tenant architecture.

## Current ChatGPT product availability note

OpenAI product-plan support for direct custom MCP connections can change independently of this architecture. Product availability must be rechecked against current OpenAI documentation before customer deployment decisions.

Official references previously used for this bridge:

- https://help.openai.com/en/articles/12584461-connecting-custom-mcp-servers-in-chatgpt
- https://developers.openai.com/api/docs/guides/tools-connectors-mcp

## Phase 2 — commercial controlled operations

The order is now:

1. commercial tenant + adapter foundation;
2. authenticated principal → allowed tenant routing and deny-by-default isolation;
3. inspection/list surfaces for enabled domains;
4. Preview/Plan tools that return immutable plan IDs, expected revisions and a human-readable summary;
5. explicit Apply tools that accept the returned plan ID and required revision/idempotency data;
6. Verify tools that return stored/rendered proof;
7. Rollback tools for reversible contracts;
8. separately permissioned elevated tools;
9. usage/audit hooks suitable for commercial operation.

The MCP layer must never weaken the connected gateway contract. If Research Manager requires evidence confirmation, verified Research-Line state, fixed Theme targets, published objects or other preconditions, the MCP adapter preserves them.

## Acceptance gates

### Commercial foundation

- strict TypeScript typecheck;
- tests for closed tenant resolution;
- registry contains no embedded backend secrets;
- unknown tenants denied;
- adapter interface contains no arbitrary URL/route escape hatch;
- previous Research Manager read-only tools remain compatible;
- bounded-surface CI remains green.

### Shared multi-tenant routing

Before one public shared endpoint can serve several customers:

- authenticated principals/workspaces;
- principal → allowed tenant mapping;
- deny-by-default tenant selection;
- per-tenant rate/usage boundary;
- tenant-safe logs and audit correlation;
- secret-manager-compatible credential resolution;
- proof that knowing another tenant ID cannot cross the boundary.

### Write domains

Each write domain requires:

- least-privilege credential profile;
- Preview proving no mutation;
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

Tracking: #223. Commercial architecture: `docs/58-commercial-mcp-platform.md`.
