# ChatGPT / OpenAI MCP bridge for Research Manager

## Purpose

The Research Manager v1 is already the accepted execution boundary for `eduardoyauriluna.com`. This bridge does **not** replace it and does not add a second WordPress control plane.

The target operating path is:

```text
Eduardo
  → ChatGPT / OpenAI client
  → Research Manager MCP Bridge
  → authenticated Research Manager v1
  → shared Manager services
  → WordPress state
  → Research Theme
  → rendered verification
```

The bridge translates MCP tools into the typed Manager capabilities that already enforce scopes, stale-state protection, audit correlation and reversible workflows.

## Architectural rules

1. Research Manager remains the only WordPress execution gateway.
2. The MCP server exposes named tools, never a generic `path`, `route`, SQL, shell, PHP, filesystem or `wp/v2` proxy.
3. Manager credentials live only in the bridge runtime.
4. Read and write Manager credentials are separate profiles.
5. The MCP client never receives the Manager bearer token.
6. Tool results may include Manager request IDs, operation IDs and non-secret evidence needed for audit/continuation.
7. ES/EN remains part of every content-bearing capability contract.
8. Elevated identity, destructive deletion, external publication and software-update permissions remain separate future/elevated surfaces.

## Phase 1 — read-only bridge

Implementation root:

`bridge/research-manager-mcp`

Protocol/runtime:

- Node.js 22+
- MCP TypeScript SDK v2
- MCP revision 2026-07-28 through Streamable HTTP
- endpoint `/mcp`
- health endpoint `/healthz`

Exposed tools:

| MCP tool | Manager call | Manager scope | Mutation |
| --- | --- | --- | --- |
| `research_status` | `GET /research-manager/v1/status` | `site.read` | no |
| `research_versions` | `GET /research-manager/v1/versions` | `site.read` | no |
| `research_capabilities` | `GET /research-manager/v1/capabilities` | `site.read` | no |

The Manager client has a compile-time route union for those three endpoints; callers cannot supply an arbitrary path.

### Authentication layers

There are two distinct credentials:

1. **MCP client → bridge**: Phase 1 supports an optional bridge bearer. It becomes mandatory whenever the listener is bound outside loopback.
2. **Bridge → Research Manager**: `ERM_READ_TOKEN`, kept only in the bridge process and scoped to `site.read` for Phase 1.

These tokens must never be the same credential.

A write-capable public remote MCP deployment should move the outer bridge layer to a production OAuth flow before exposing mutation tools.

## Deployment options

### A. Secure MCP Tunnel — preferred initial path

Run the bridge on loopback/private infrastructure and connect it using Secure MCP Tunnel. This avoids exposing the MCP listener directly to the public Internet while preserving a standard MCP endpoint internally.

### B. Public remote MCP

Run behind HTTPS/reverse proxy, set:

- non-loopback `MCP_BIND_HOST` only when needed;
- strong `MCP_BEARER_TOKEN` for the Phase 1 read-only prototype;
- exact `MCP_ALLOWED_HOSTS`;
- exact `MCP_ALLOWED_ORIGIN_HOSTS` if browser-origin traffic is expected.

Before write tools, replace the prototype outer bearer with production OAuth/authorization appropriate for the MCP client/workspace.

### C. OpenAI Responses API

The same MCP server can be supplied to the OpenAI Responses API as a remote MCP server (`server_url`) or through Secure MCP Tunnel (`tunnel_id`). No bridge rewrite is required.

## Current ChatGPT product availability (2026-10-15)

OpenAI currently documents full custom MCP server connections in ChatGPT for Business and Enterprise/Edu workspaces on web. Remote MCP servers and Secure MCP Tunnel are also supported through the OpenAI API. This product-plan availability is outside the Research Manager architecture and may change independently.

Official references:

- https://help.openai.com/en/articles/12584461-connecting-custom-mcp-servers-in-chatgpt
- https://developers.openai.com/api/docs/guides/tools-connectors-mcp

## Phase 2 — bounded write control

Write capability is added by domain, never through a generic proxy. Proposed order:

1. Read/list/inspect surfaces for Pages, Insights, Research Objects, translations, evidence, navigation, media, SEO/GEO and design.
2. Preview/plan tools that return immutable plan IDs, expected revisions and a human-readable summary.
3. Explicit Apply tools that accept the returned plan ID and required expected revision/idempotency data.
4. Verify tools that return stored/rendered proof.
5. Rollback tools for contracts that are reversible.
6. Separate elevated tools only where the existing Manager defines an elevated scope.

The MCP layer must not weaken the Manager contract. If a Manager workflow requires evidence confirmation, verified Research-Line state, a fixed Theme target, a published object, or another domain precondition, the MCP tool must preserve it.

## Acceptance gates

### Phase 1

- TypeScript strict typecheck.
- Unit tests for exact Manager route/header contract.
- Unit test that Manager errors do not expose bearer material.
- Configuration test: non-loopback bind without inbound auth must fail.
- Local MCP Inspector can list exactly the three expected tools and call each successfully.
- Production smoke uses a temporary `site.read` Manager credential; credential is revoked afterwards.

### Phase 2

Each write domain requires:

- least-privilege credential profile;
- Preview proving no mutation;
- explicit Apply;
- stored/rendered Verify where applicable;
- Rollback and exact baseline restoration where the Manager contract supports it;
- revocation/rotation evidence;
- no new normal-operation bypass.

## Non-goals

- generic WordPress administration;
- arbitrary REST proxying;
- SSH/WP-CLI tunnelling through MCP;
- direct database/filesystem access;
- generic page-builder control;
- changing the completed Research Manager v1 product boundary;
- introducing multi-client SaaS tenancy into the Eduardo single-site bridge.

Tracking: #223. Product vision: #94.
