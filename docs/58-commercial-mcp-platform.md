# Commercial MCP platform architecture

## Product principle

The MCP work must be built as a reusable commercial product, not as a one-off controller for `eduardoyauriluna.com`.

The Eduardo Research installation remains the reference tenant and acceptance lab, but every new bridge capability should be reusable across customers without forking the codebase.

Target operating model:

```text
Customer / operator
  → ChatGPT / OpenAI / compatible MCP client
  → Commercial MCP control platform
  → authenticated tenant boundary
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

### Private managed instance

The same product image is deployed in dedicated infrastructure managed by us for a customer that needs stronger isolation.

Commercial unit: private instance + connected systems + support/SLA.

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
6. choose enabled capability package;
7. run readiness/compatibility acceptance;
8. connect approved MCP client/workspace;
9. activate audit and usage metering.

## Multi-tenant security requirements

Before a shared public multi-tenant endpoint can expose write tools, the platform must add:

- authenticated principals/workspaces;
- principal → allowed tenant mapping;
- deny-by-default tenant selection;
- per-tenant credential profiles;
- per-tenant rate limits/quotas;
- immutable audit correlation;
- secret-manager integration;
- tenant-safe logs/metrics;
- explicit capability entitlement checks;
- billing/usage hooks that never weaken authorization.

Tenant IDs are identifiers, not authorization. Knowing another tenant ID must never grant access.

## Current implementation — v0.2 commercial foundation

`bridge/research-manager-mcp` is being refactored to establish the commercial core while keeping the accepted Research Manager read-only surface compatible.

Current foundation:

- adapter interface separated from MCP server;
- `research-manager` as the first adapter;
- closed tenant registry;
- tenant configuration supports multiple registered tenants;
- registry references secret environment variables instead of embedding bearer credentials;
- runtime binds to one registered tenant through `MCP_TENANT_ID` while shared principal-to-tenant routing is built and accepted;
- legacy single-tenant `ERM_*` configuration remains supported for the Eduardo reference lab.

The next commercial slice is authenticated shared tenant routing. Only after that gate is accepted should broad Phase 2 write tools be multiplied across domains.

## Product naming

`site-control-mcp` is an internal technical service identifier, not the final commercial brand.

The final customer-facing name should be selected with the same commercial rule used for other products: understandable value proposition, ES/EN suitability, search/market evidence and competitive differentiation before branding is frozen.

## North-star rule

Every engineering decision should answer:

> Can this capability be provisioned securely for the next customer without copying the repository or creating a customer-specific MCP implementation?

If the answer is no, the design needs another abstraction boundary before it becomes part of the commercial core.
