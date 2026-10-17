# Commercial MCP platform architecture

## Product principle

The MCP work is a reusable commercial product, not a one-off controller for `eduardoyauriluna.com`.

The Eduardo Research installation remains the reference tenant and production acceptance lab, but every capability must be reusable for the next customer without forking the repository or creating a customer-specific MCP implementation.

Target operating model:

```text
Customer / operator
  → ChatGPT / OpenAI / compatible MCP client
  → authenticated principal / workspace
  → Commercial MCP control platform
  → authorized tenant boundary
  → commercial entitlement gate
  → registered adapter
  → bounded customer-side manager/connector
  → WordPress / ecommerce / ERP / CRM / custom software
  → verification / audit
```

## Architecture decision — one product, shared or private deployment

The commercial MCP has one codebase, one capability model, one adapter model and one release line. Deployment isolation is configuration/infrastructure, not a source-code fork.

The initial managed production target is our OVH infrastructure, containerized and exposed only through an authenticated HTTPS MCP endpoint behind the platform ingress/reverse proxy.

The same product supports three deployment classes:

1. **Managed shared / multi-tenant** — one MCP platform serves multiple isolated customers/tenants.
2. **Private managed** — the same product image is dedicated to one customer, normally in our OVH infrastructure.
3. **Enterprise / customer infrastructure** — the same product image runs in customer-controlled infrastructure when required.

No deployment class may weaken the adapter, authorization, capability, verification or audit contracts.

## Commercial invariant

A model or MCP caller may choose an allowed operation, but it must never supply an arbitrary backend URL, credential, REST route, database target, filesystem path or shell command.

A customer system exists in the platform only after it has been provisioned as a tenant and connected through an approved adapter.

Commercial entitlement is an additional product gate, never a replacement for the authorization enforced by the customer-side manager/connector.

## Tenant model

Each tenant has a stable internal ID and a closed server-side configuration:

- tenant ID;
- commercial/customer label;
- adapter type;
- approved backend origin;
- language contract;
- credential profile references;
- contracted capability entitlements;
- audit/billing identity;
- deployment class.

Credentials are not stored inside the tenant registry payload. The registry references a secret identifier/environment variable and the runtime resolves the secret server-side.

The LLM never receives tenant secrets.

### Shared-mode isolation

In managed shared mode, every customer is a separate tenant. At minimum, each tenant has independent:

- backend origin and adapter registration;
- read/write/elevated backend credential references;
- entitlement set;
- principal/workspace allowlist;
- rate/usage accounting boundary;
- audit correlation;
- language and capability contract.

Knowing another tenant ID never grants access to it.

### Private-mode isolation

In private managed mode, the same product image is bound to one active customer/tenant. Private mode does not use a different codebase and does not gain arbitrary backend access; it keeps the same typed tools, scopes and Preview/Apply/Verify/Rollback rules.

## Principal model

A tenant ID is never authorization.

Shared SaaS mode introduces authenticated principals/workspaces. Each principal has:

- a stable principal ID;
- a server-side bearer/OAuth credential reference;
- an explicit allowlist of tenant IDs;
- an explicit capability grant set/role.

The authorization order is fixed:

`authenticate principal → resolve registered tenant → verify principal-to-tenant grant → rate/usage admission → resolve effective commercial capabilities → tool gate → adapter/backend authorization`

A principal that knows another customer's tenant ID is denied unless that tenant is explicitly present in its allowlist.

## Commercial entitlement model

The platform separates what the customer has purchased from what a user/workspace is allowed to do.

- **Tenant entitlements**: modules/capabilities enabled for that customer/site/system.
- **Principal capabilities**: actions allowed for the authenticated user/workspace/role.
- **Effective capability**: intersection of those two sets.

A tool is executable only when its required capability is present in both layers.

Examples:

- tenant has `seo.read` + principal has `seo.read` → commercial gate allows the tool;
- tenant lacks `seo.read` → deny with `tenant_not_entitled`;
- tenant has `seo.read` but principal lacks it → deny with `principal_not_granted`;
- request has no authorization context → fail closed with `context_missing`.

The backend then independently evaluates its own credential/scopes. Passing the commercial gate does not grant backend permission.

The closed capability catalog covers:

- `site.read`, diagnostics;
- Pages read/write;
- Insights read/write;
- Research read/write;
- translations and evidence;
- SEO/GEO read/write;
- Media read/write;
- Navigation read/write;
- Design read/write;
- Connections read/write;
- Apply/Rollback;
- elevated identity, external publication, software update and destructive-delete actions.

Unknown capability names are rejected during configuration loading.

## Adapter model

The commercial MCP core depends on adapter contracts rather than WordPress-specific implementation details.

Initial adapter:

- `research-manager`: bounded control of the accepted Research Manager APIs.

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

### Managed shared / multi-tenant on OVH

One managed MCP control platform in our OVH infrastructure serves multiple isolated tenants.

Commercial unit: connected site/system plus enabled capability package and usage.

Shared routing uses a tenant-scoped MCP endpoint such as `/mcp/{tenant-id}` plus authenticated principal authorization. The URL identifies the target; the principal grant authorizes it; entitlements determine which product modules are usable.

Requirements before shared write access is generally enabled:

- authenticated principals/workspaces;
- deny-by-default principal → tenant mapping;
- per-tenant backend credential profiles;
- per-principal/per-tenant rate and usage boundaries;
- persistent tenant-safe audit correlation;
- server-side secret resolution;
- HTTPS-only external endpoint;
- no tenant enumeration from public health/status surfaces.

### Private managed instance on OVH

The same product image is deployed in dedicated infrastructure/runtime for one customer requiring stronger isolation.

Commercial unit: private instance + connected systems + support/SLA.

Private mode may expose a single authenticated `/mcp` endpoint bound to one configured tenant. It keeps the same tools and security lifecycle as shared mode.

### Enterprise / customer infrastructure

The same product image and adapter contracts run in the customer's infrastructure. Secrets and customer data remain inside the customer's environment where required.

Commercial unit: licence/subscription + deployment/onboarding + support/SLA.

These are deployment variants of one product, not separate codebases.

## OVH runtime target

The managed runtime target is a containerized service in our OVH infrastructure with:

- Docker-managed MCP runtime;
- HTTPS ingress/reverse proxy;
- remote MCP endpoint and `/healthz`;
- outer client/principal authentication separated from backend Manager credentials;
- server-side secret references only;
- shared multi-tenant or private single-tenant configuration selected without code changes;
- persistent audit/usage telemetry suitable for production;
- controlled deployment/rollback of the MCP service itself;
- no direct exposure of WordPress, database, shell or filesystem administration through MCP.

Local `127.0.0.1:8787` + MCP Inspector remains an engineering/acceptance path, not the steady-state production topology.

## Capability packaging

Commercial capability groups remain independently grantable and billable. Candidate product packages can combine the typed capabilities into offers such as:

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

Pricing packages may change without changing the authorization semantics: every MCP tool still maps to one or more exact typed capabilities.

Write access must always preserve the connected gateway's lifecycle. For Research Manager this is:

`Preview/Plan → explicit Apply → Verify → Rollback`

Elevated identity, destructive deletion, software update and external publication actions remain separately permissioned.

## Current accepted baseline

The commercial foundation, tenant isolation, entitlement gate and read surfaces have been accepted. Page-slot mutation is the first production-accepted write lifecycle through MCP.

Accepted Page lifecycle:

`Plan → Apply → stored/rendered Verify → Rollback → baseline readback → credential revocation`

This acceptance is the template for every additional write domain; it is not permission to bypass domain-specific Manager contracts.

## Phase 2C — full bounded write surface

Objective: expose through MCP the normal bounded write capabilities already provided by Research Manager so the accepted Research website can be operated end-to-end from an approved MCP client without routine WordPress Admin, SSH, WP-CLI, direct REST, database or filesystem access.

Implementation order:

1. **Insights** — draft/create, edit, relationships, translation pairing, scheduling/status and reversible verification lifecycle.
2. **Research objects** — Lines, Outputs/Publications, Projects, Software and Datasets.
3. **Translations + Evidence** — structured pair/unpair and bounded evidence management; identity-sensitive operations remain elevated.
4. **SEO/GEO** — deterministic remediation writes only where the Manager exposes a legitimate bounded target.
5. **Media** — bounded ingest/update lifecycle through accepted Manager contracts.
6. **Navigation** — bounded registered Theme navigation changes.
7. **Design** — palette, typography, density and registered component variants; no arbitrary CSS/HTML/page-builder control.

Each domain must ship with:

- exact typed MCP tools;
- commercial capability mapping;
- exact backend-scope mapping;
- least-privilege read/write/elevated credential use;
- immutable Preview/Plan where the Manager contract provides it;
- explicit Apply/confirmation;
- stored/rendered Verify as applicable;
- safe Rollback/exact restoration where supported;
- idempotency/stale-plan/domain-boundary tests;
- production acceptance with reversible fixtures or legitimate natural targets;
- credential revocation proof after acceptance.

A domain is not complete merely because Research Manager already supports it; it is complete when the MCP adapter exposes and production-accepts the bounded lifecycle without weakening the Manager boundary.

## Phase 2D — managed OVH production deployment

After the required Phase 2C write surface is complete:

1. package the MCP runtime as the production image;
2. deploy to our OVH infrastructure;
3. configure HTTPS ingress and health checks;
4. configure outer MCP client/principal authentication;
5. provision the Eduardo reference tenant with least-privilege backend credentials;
6. prove remote read/write lifecycle through the HTTPS MCP endpoint;
7. validate secret rotation/revocation without service restart where supported;
8. validate audit/usage persistence;
9. validate shared-routing isolation with at least two test tenants before general multi-client write enablement;
10. retain private single-tenant deployment as the dedicated-customer option using the same image.

## Commercial onboarding target

A normal new customer must not require a source-code fork.

Target onboarding:

1. create tenant;
2. select adapter;
3. install/configure the bounded customer-side connector/manager;
4. register backend origin;
5. provision least-privilege secrets;
6. choose tenant capability package/entitlements;
7. create principal/workspace grants/roles;
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
- request-scoped commercial entitlement checks;
- immutable/persistent audit correlation for production;
- secret-manager-compatible credential resolution;
- tenant-safe logs/metrics;
- billing/usage hooks that never weaken authorization.

Tenant IDs are identifiers, not authorization. Knowing another tenant ID must never grant access.

## Secret provider contract

The commercial core resolves credentials through a `SecretResolver` interface rather than reading arbitrary secret material inside tenant logic.

The current implementation ships `EnvSecretResolver` first. The tenant/principal configuration only stores secret references, so a Vault, cloud KMS/secret manager or customer-managed resolver can replace the environment implementation without changing MCP tools, tenant IDs or adapter contracts.

## Usage and audit boundary

The commercial guard is keyed by:

`principal ID × tenant ID`

This provides independent per-minute admission counters, denied/rate-limited accounting and structured access events that contain principal ID, tenant ID, request path and outcome but never bearer material.

Entitlement denials add structured reason codes (`tenant_not_entitled`, `principal_not_granted`) plus the denied capability without exposing secrets.

Before external commercial launch, usage/audit persistence must use durable storage/telemetry with retention and tamper-resistance appropriate to the deployment class.

## Product naming

`site-control-mcp` is an internal technical service identifier, not the final commercial brand.

The final customer-facing name should be selected with the same commercial rule used for other products: understandable value proposition, ES/EN suitability, search/market evidence and competitive differentiation before branding is frozen.

## North-star rule

Every engineering decision should answer:

> Can this capability be provisioned securely for the next customer without copying the repository or creating a customer-specific MCP implementation?

If the answer is no, the design needs another abstraction boundary before it becomes part of the commercial core.
