# Commercial MCP platform architecture

## Product principle

The MCP work must be built as a reusable commercial product, not as a one-off controller for `eduardoyauriluna.com`.

The Eduardo Research installation remains the reference tenant and acceptance lab, but every new bridge capability should be reusable across customers without forking the codebase.

Target operating model:

```text
Customer / operator
  → ChatGPT / OpenAI / compatible MCP client
  → authenticated principal / workspace
  → Commercial MCP control platform
  → authorized tenant boundary
  → registered adapter
  → bounded customer-side manager/connector
  → WordPress / ecommerce / ERP / CRM / custom software
  → verification / audit
```

## Commercial invariant

A model or MCP caller may choose an allowed operation, but it must never supply an arbitrary backend URL, credential, REST route, database target, filesystem path or shell command.

A customer system exists in the platform only after it has been provisioned as a tenant and connected through an approved adapter.

## Tenant model

Each tenant has a stable internal ID and a closed server-side configuration:

- tenant ID;
- commercial/customer label;
- adapter type;
- approved backend origin;
- language contract;
- credential profile references;
- enabled capability groups;
- audit/billing identity;
- deployment class.

Credentials are not stored inside the tenant registry payload. The registry references a secret identifier/environment variable and the runtime resolves the secret server-side.

The LLM never receives tenant secrets.

## Principal model

A tenant ID is never authorization.

Shared SaaS mode introduces authenticated principals/workspaces. Each principal has:

- a stable principal ID;
- a server-side bearer/OAuth credential reference;
- an explicit allowlist of tenant IDs;
- later, capability/entitlement and commercial-plan metadata.

The authorization order is fixed:

`authenticate principal → resolve requested registered tenant → verify principal-to-tenant grant → rate/usage admission → adapter execution`

A principal that knows another customer's tenant ID is denied unless that tenant is explicitly present in its allowlist.

## Adapter model

The commercial MCP core depends on adapter contracts rather than WordPress-specific implementation details.

Initial adapter:

- `research-manager`: bounded control of the accepted Research Manager v1 APIs.

Planned commercial adapters can include, when a bounded customer-side gateway exists:

- WordPress content/site manager;
- WooCommerce;
- PrestaShop;
- ecommerce/catalog connectors;
- ERP;
- CRM;
- proprietary business systems.

An adapter is not an arbitrary HTTP proxy. It exposes typed commercial capabilities and translates them into the bounded gateway contract of the connected system.

## Commercial deployment models

### Managed SaaS

One managed control platform serves multiple isolated tenants. Recommended for SMEs and recurring subscriptions.

Commercial unit: connected site/system plus enabled capability package and usage.

Shared routing uses a tenant-scoped MCP URL (`/mcp/{tenant-id}`) plus authenticated principal authorization. The URL identifies the target; the principal grant authorizes it.

### Private managed instance

The same product image is deployed in dedicated infrastructure managed by us for a customer that needs stronger isolation.

Commercial unit: private instance + connected systems + support/SLA.

Private mode can remain bound to one active tenant at `/mcp`.

### Enterprise / customer infrastructure

The same product image and adapter contracts run in the customer's infrastructure. Secrets and customer data remain inside the customer's environment where required.

Commercial unit: licence/subscription + deployment/onboarding + support/SLA.

These are deployment variants of one product, not separate codebases.

## Capability packaging

Commercial capability groups should remain independently grantable and billable. Candidate groups:

- Read / diagnostics;
- Content & Pages;
- Editorial / Insights;
- Research / structured knowledge;
- SEO/GEO;
- Media;
- Navigation;
- Design;
- Ecommerce/catalog;
- Integrations;
- Automation / scheduled operations;
- elevated enterprise actions.

Write access must always preserve the connected gateway's lifecycle. For Research Manager this is:

`Preview/Plan → explicit Apply → Verify → Rollback`

Elevated identity, destructive deletion, software update and external publication actions remain separately permissioned.

## Commercial onboarding target

A normal new customer must not require a source-code fork.

Target onboarding:

1. create tenant;
2. select adapter;
3. install/configure the bounded customer-side connector/manager;
4. register backend origin;
5. provision least-privilege secrets;
6. create principal/workspace grants;
7. choose enabled capability package;
8. run readiness/compatibility acceptance;
9. connect approved MCP client/workspace;
10. activate audit and usage metering.

## Multi-tenant security requirements

Before a shared public multi-tenant endpoint can expose write tools, the platform must enforce:

- authenticated principals/workspaces;
- principal → allowed tenant mapping;
- deny-by-default tenant selection;
- per-tenant credential profiles;
- per-principal/per-tenant rate limits and usage counters;
- immutable/persistent audit correlation for production;
- secret-manager-compatible credential resolution;
- tenant-safe logs/metrics;
- explicit capability entitlement checks;
- billing/usage hooks that never weaken authorization.

Tenant IDs are identifiers, not authorization. Knowing another tenant ID must never grant access.

## Secret provider contract

The commercial core resolves credentials through a `SecretResolver` interface rather than reading arbitrary secret material inside tenant logic.

v0.3 ships `EnvSecretResolver` as the first implementation. The tenant/principal configuration only stores secret references, so a Vault, cloud KMS/secret manager or customer-managed resolver can replace the environment implementation without changing MCP tools, tenant IDs or adapter contracts.

## Usage and audit boundary

v0.3 introduces a first in-process commercial guard keyed by:

`principal ID × tenant ID`

This provides independent per-minute admission counters, denied/rate-limited accounting and structured access events that contain principal ID, tenant ID, request path and outcome but never bearer material.

This is the enforcement boundary for the current prototype, not the final billing ledger. Before external commercial launch, usage/audit persistence must move to durable storage/telemetry with retention and tamper-resistance appropriate to the deployment class.

## Current implementation — v0.3 tenant isolation

`bridge/research-manager-mcp` now contains the reusable commercial core while keeping the accepted Research Manager read-only surface compatible.

Implemented:

- adapter interface separated from MCP server;
- `research-manager` as the first adapter;
- closed multi-tenant registry;
- tenant configuration references backend secrets instead of embedding bearer credentials;
- authenticated principal registry with explicit allowed tenant IDs;
- shared routing only when `MCP_SHARED_ROUTING=true`;
- shared tenant endpoints at `/mcp/{tenant-id}`;
- deny-by-default unknown/cross-tenant behavior;
- independent `principal × tenant` rate/usage counters;
- structured non-secret access audit events;
- `SecretResolver` abstraction with environment-backed implementation;
- health response does not enumerate tenants in shared mode;
- private/single-tenant mode remains backward compatible for the Eduardo reference lab.

The next commercial slice after v0.3 acceptance is capability entitlement/read-surface expansion, followed by bounded Preview/Apply/Verify/Rollback write workflows.

## Product naming

`site-control-mcp` is an internal technical service identifier, not the final commercial brand.

The final customer-facing name should be selected with the same commercial rule used for other products: understandable value proposition, ES/EN suitability, search/market evidence and competitive differentiation before branding is frozen.

## North-star rule

Every engineering decision should answer:

> Can this capability be provisioned securely for the next customer without copying the repository or creating a customer-specific MCP implementation?

If the answer is no, the design needs another abstraction boundary before it becomes part of the commercial core.
