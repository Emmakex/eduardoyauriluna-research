# Canonical contract — Chat-governed Research Manager

## Status

**Accepted product direction for `eduardoyauriluna.com`.**

This document is the canonical source of truth for how the Research Manager is meant to be operated. If another roadmap, backlog or design note conflicts with this document, this document wins until an explicit new ADR supersedes it.

The immediate scope is the real Greenfield WordPress site `eduardoyauriluna.com` using the operational Research Theme and the Research Manager. The same architecture may later be reused in the generic SEO/GEO Manager, but work in this repository must stay focused on proving this operating model on the Eduardo Research site.

---

## 1. Product intent

We do **not** want to manage `eduardoyauriluna.com` primarily by opening WordPress screens and manually editing pages one by one.

We want to govern the website from our normal ChatGPT working surface, in the same way we currently direct work on repositories and custom applications:

```text
Eduardo gives an instruction in ChatGPT
              ↓
ChatGPT interprets the website intent
              ↓
ChatGPT calls the authenticated Research Manager connector
              ↓
Research Manager reads the actual WordPress/Theme state
              ↓
Research Manager prepares a bounded operation plan
              ↓
Preview / policy / capability gates
              ↓
Apply through shared Manager services
              ↓
Stored-state verification
              ↓
Rendered-site verification
              ↓
ChatGPT reports the real result back to Eduardo
```

The Manager is therefore the **authoritative bridge/gateway between ChatGPT and WordPress**.

The Manager is not merely a WordPress dashboard. Its most important architectural responsibility is to expose safe, structured, auditable website operations that can be invoked locally from WordPress Admin and remotely through an authenticated connector.

---

## 2. Operating-surface hierarchy

For the managed workflow used by us on `eduardoyauriluna.com`, the intended hierarchy is:

1. **Primary operating surface — ChatGPT conversation**
   - Eduardo gives business/content/design/SEO/GEO instructions here.
   - ChatGPT reads current state through the Manager before making material assumptions.
   - ChatGPT prepares and executes bounded Manager operations.
   - ChatGPT returns the actual operation result and verification status.

2. **Authoritative execution gateway — Research Manager**
   - validates identity, capability and operation scope;
   - resolves Theme contracts and structured resources;
   - creates operation plans;
   - enforces Preview → Apply → Verify → Rollback semantics;
   - writes only through approved service-layer operations;
   - records audit information;
   - exposes current state back to ChatGPT.

3. **Local administrative fallback — WordPress Admin / Manager UI**
   - must remain fully usable without ChatGPT or external connectivity;
   - exposes the same underlying Manager services for an administrator;
   - provides emergency/manual operation, inspection, credential revocation and troubleshooting;
   - is not the preferred day-to-day surface for our managed workflow.

4. **Automation/support transport — WP-CLI**
   - may call the same shared Manager services;
   - is useful for installation, CI/CD, recovery and bulk operations;
   - must not contain exclusive business capabilities.

5. **Infrastructure transport — SSH**
   - optional for bootstrap, hosting operations, recovery and infrastructure maintenance;
   - never required for normal website operation through the Manager.

The architectural rule is:

> **ChatGPT expresses intent; the Manager authorises and executes; the Theme renders; WordPress stores/runtime-hosts the result.**

---

## 3. Responsibility boundaries

### 3.1 ChatGPT owns intent orchestration

ChatGPT may:

- interpret Eduardo's natural-language instruction;
- inspect current Manager state;
- request inventories, diagnostics and rendered verification;
- prepare a bounded operation request;
- explain a Preview before mutation when required;
- invoke an authorised Apply;
- invoke Verify;
- invoke Rollback when needed and permitted;
- sequence multiple Manager operations into one higher-level task;
- report exactly what changed and what did not change.

ChatGPT must **not** bypass the Manager for normal website writes.

ChatGPT must not require direct database access, arbitrary PHP execution, FTP or SSH in order to create/edit normal site content.

### 3.2 Research Manager owns website control

The Manager owns:

- WordPress resource inventory;
- Theme/preset contract resolution;
- structured Page operations;
- Posts / Insights operations;
- Research Lines;
- Publications;
- Projects;
- Software;
- Datasets;
- Researcher Profile and academic identity fields;
- navigation and internal relationships;
- translation relationships;
- media metadata and future bounded media assignment;
- SEO configuration and remediation;
- GEO/machine-discoverability configuration and remediation;
- academic discoverability;
- diagnostics/readiness;
- external academic connection previews;
- operation planning;
- mutation safety;
- audit trail;
- stored-state verification;
- rendered verification;
- rollback boundaries.

### 3.3 Research Theme owns public rendering

The Theme owns:

- layout;
- components;
- responsive behaviour;
- design tokens and permitted variants;
- semantic HTML;
- accessibility behaviour;
- canonical/hreflang/Schema rendering;
- SEO/GEO frontend rendering;
- archive/single/page presentation;
- deterministic rendering of structured Manager state.

The Manager may choose from Theme-defined variants and tokens but must not become an unrestricted page builder.

### 3.4 WordPress owns CMS/runtime storage

WordPress remains the content/runtime platform. It stores content, metadata, users, media and relationships and executes Theme/Plugin code.

WordPress native screens are implementation/runtime surfaces, not the primary operating model for our managed workflow.

---

## 4. Mandatory control rule

All normal instructions for the managed Eduardo site must be expressible as Manager capabilities.

Examples:

- "Create a new Research Software page in English and Spanish."
- "Change the homepage researcher headline."
- "Create today's Insight and optimise it for SEO/GEO."
- "Add this publication and relate it to these research lines."
- "Improve the internal linking of the Research section."
- "Check the whole site for missing metadata and fix deterministic issues."
- "Change the permitted visual variant of this section."
- "Translate this page to Spanish and create the hreflang relationship."
- "Verify that every public research object exposes the correct Schema."

If a routine website outcome can only be completed by manually editing PHP, using SSH, directly updating the database or navigating unrelated WordPress screens, the Manager capability is incomplete.

---

## 5. Command model

Remote control must not expose arbitrary commands. ChatGPT sends **typed Manager operations**.

A logical command envelope should contain at least:

```json
{
  "request_id": "globally-unique-id",
  "operation": "page.update",
  "target": {
    "resource_type": "page",
    "resource_id": "research",
    "language": "en"
  },
  "input": {},
  "mode": "preview",
  "expected_revision": "optional-current-revision",
  "reason": "Human-readable operator intent"
}
```

Required properties:

- **request_id** — prevents accidental duplicate execution and allows audit correlation;
- **operation** — fixed allowlisted operation name;
- **target** — explicit target, never implicit arbitrary SQL/resource selection;
- **input** — schema-validated structured payload;
- **mode** — status/read/preview/apply/verify/rollback depending on operation;
- **expected_revision** — optional optimistic-concurrency guard;
- **reason** — traceable human intent.

The Manager must reject unknown operation names and fields outside the operation schema.

---

## 6. Operation lifecycle

Material mutations follow this state machine:

```text
REQUESTED
   ↓
VALIDATED
   ↓
PLANNED
   ↓
PREVIEWED
   ↓
AUTHORIZED
   ↓
APPLIED
   ↓
STORED_VERIFIED
   ↓
RENDERED_VERIFIED
   ↓
COMPLETED
```

Possible alternate terminal states:

```text
BLOCKED
FAILED
ROLLED_BACK
PARTIALLY_VERIFIED
REQUIRES_HUMAN_REVIEW
```

### 6.1 Read operations

Read-only operations may execute immediately after authentication/capability validation.

Examples:

- status;
- resource inventory;
- page inspection;
- readiness report;
- rendered verification report;
- connection status;
- operation history.

### 6.2 Safe deterministic mutations

Normal controlled writes should use:

```text
Preview → Apply → stored Verify → rendered Verify
```

The Apply call should reference a specific `plan_id` generated during Preview. The Manager must not silently recompute a materially different plan between Preview and Apply.

### 6.3 High-impact operations

Operations involving identity, destructive deletion, credentials, external publishing, large bulk changes, Theme/plugin updates or irreversible actions need stronger confirmation/policy gates.

The Manager must expose that an operation requires elevated confirmation rather than simply failing with a generic error.

---

## 7. Initial remote API contract

The API is a first-class part of the operating architecture because it is the transport through which ChatGPT governs the Manager.

Use a versioned namespace such as:

```text
/wp-json/research-manager/v1/
```

The exact URL may change, but versioning and bounded operations are mandatory.

### 7.1 System/read endpoints

Minimum target capabilities:

```text
GET  /status
GET  /capabilities
GET  /versions
GET  /readiness
GET  /diagnostics
GET  /operations/{operation_id}
GET  /resources
GET  /resources/{type}
GET  /resources/{type}/{id}
```

`/status` should expose only non-secret operational information, including:

- Manager version;
- Theme version;
- active preset;
- site mode (`greenfield` here);
- canonical domain;
- languages;
- readiness summary;
- connector/auth capability state;
- whether writes are enabled;
- last verified operation where appropriate.

### 7.2 Planning endpoints

```text
POST /plans
GET  /plans/{plan_id}
```

A plan response should include:

- plan ID;
- operation type;
- target resources;
- current revision/fingerprint;
- intended changes;
- validation results;
- warnings;
- risk class;
- apply allowed/blocked;
- required confirmation class;
- rollback support;
- plan expiry.

### 7.3 Apply and verification endpoints

```text
POST /plans/{plan_id}/apply
POST /operations/{operation_id}/verify
POST /operations/{operation_id}/rollback
```

Apply must be idempotent against the same request/plan identity where feasible.

Verify should distinguish:

- stored-state verification;
- rendered HTTP verification;
- SEO/GEO verification;
- language/canonical/hreflang verification;
- Schema verification;
- evidence-policy verification when applicable.

### 7.4 Domain-operation examples

Do not implement every action as a unique transport if the same generic plan service can safely express it, but the capability model must ultimately cover operations equivalent to:

```text
page.create
page.update
page.publish
page.archive
page.set_variant

insight.create
insight.update
insight.schedule
insight.publish
insight.archive

research_line.create
research_line.update
research_line.relate

publication.create
publication.update
project.create
project.update
software.create
software.update
dataset.create
dataset.update

translation.create
translation.update
translation.pair

navigation.update
relationship.update

seo.inspect
seo.optimise
geo.inspect
geo.optimise
site.optimise

render.verify
readiness.remediate
```

These are capability names, not permission to execute arbitrary WordPress actions.

---

## 8. Authentication and trust boundary

Remote access is privileged and must be treated as a production control-plane connection.

Mandatory properties:

- site-specific credential;
- no shared global secret across customers/sites;
- revocable from local WordPress Admin;
- rotatable;
- stored hashed/encrypted as appropriate rather than committed to Git;
- explicit scopes/capabilities;
- separate read and write authority;
- request expiry/replay protection;
- rate limiting;
- TLS only;
- audit correlation by credential/client identity;
- no WordPress administrator password transmitted to ChatGPT;
- no SSH credential required;
- no arbitrary code/SQL execution endpoint.

Preferred direction:

- Manager creates a site-specific connection identity;
- administrator explicitly enables remote management;
- administrator chooses read/write scopes;
- credential can be disabled immediately from WordPress;
- credential has a visible last-used timestamp and label;
- every remote mutation records which connection executed it.

The local Manager must continue functioning if the remote connection is disabled.

---

## 9. Capability/scoping model

Do not use one monolithic `manage_everything` remote permission.

Target scopes should be decomposed, for example:

```text
site.read
site.diagnostics
pages.read
pages.write
insights.read
insights.write
research.read
research.write
translations.read
translations.write
seo.read
seo.write
media.read
media.write
connections.read
connections.write
design.read
design.write
operations.apply
operations.rollback
```

Some scopes should remain separately elevated:

```text
identity.write
credentials.manage
external_publish.execute
software_update.execute
destructive_delete.execute
```

The API should expose `/capabilities` so ChatGPT knows exactly what it may and may not request for the current site connection.

---

## 10. Concurrency and stale-state protection

Because WordPress may still be edited locally, remote mutation must protect against stale state.

For mutable resources, the Manager should expose a revision/fingerprint based on relevant state.

A Preview records the expected source revision. Apply must fail with a typed conflict if the target changed after Preview.

Example:

```text
PREVIEW sees revision A
local WordPress edit creates revision B
APPLY for plan based on A
        ↓
409-style Manager conflict
        ↓
ChatGPT re-reads state and prepares a new plan
```

Never silently overwrite a newer state simply because an old plan exists.

---

## 11. Idempotency and retry safety

Network retries must not duplicate content or repeat mutations.

For write operations:

- `request_id` must be unique;
- repeated Apply with the same request/plan must return the existing operation result or a deterministic already-applied response;
- create operations should use stable external/request identities where appropriate;
- retries must not create duplicate Posts, Pages or research entities.

---

## 12. Audit trail

Every meaningful remote operation must create an audit record containing, at minimum:

- operation ID;
- request ID;
- timestamp;
- connection/client identity;
- WordPress user/capability context used for execution;
- operation type;
- target resource(s);
- reason/operator intent;
- plan ID;
- source revision;
- resulting revision;
- Apply status;
- stored verification status;
- rendered verification status;
- rollback availability/status;
- non-secret error information if failed.

Audit data must never log access tokens or secrets.

ChatGPT must be able to query the operation result by operation ID rather than relying only on the synchronous HTTP response.

---

## 13. Manager operation result contract

Every mutation result should be machine-readable and human-explainable.

Example logical response:

```json
{
  "operation_id": "op_...",
  "status": "completed",
  "changed": true,
  "targets": [],
  "stored_verification": "pass",
  "rendered_verification": "pass",
  "warnings": [],
  "rollback_available": true,
  "public_urls": []
}
```

ChatGPT should report from this result, not infer success because an HTTP request returned 200.

---

## 14. Error taxonomy

The Manager API must return typed errors useful to an orchestration client.

Minimum classes:

- `authentication_failed`;
- `scope_denied`;
- `capability_unavailable`;
- `validation_failed`;
- `plan_blocked`;
- `plan_expired`;
- `stale_revision`;
- `resource_not_found`;
- `theme_contract_mismatch`;
- `manager_theme_version_mismatch`;
- `evidence_required`;
- `external_connection_required`;
- `apply_failed`;
- `stored_verification_failed`;
- `rendered_verification_failed`;
- `rollback_unavailable`;
- `rollback_failed`;
- `rate_limited`.

ChatGPT should be able to distinguish "cannot do this", "needs user confirmation", "needs data/evidence", "site changed, re-plan", and "operation failed and should be rolled back".

---

## 15. Website capabilities required for `eduardoyauriluna.com`

The remote Manager is not complete until our normal work can be governed from ChatGPT across the following areas.

### 15.1 Site state

ChatGPT can ask:

- what Theme/Manager versions are running;
- what pages/resources exist;
- current languages/routes;
- current readiness;
- current SEO/GEO issues;
- current operations awaiting verification;
- current connection status.

### 15.2 Pages

ChatGPT can:

- create Theme-controlled pages from registered Research contracts;
- update structured slots;
- set permitted Theme variants;
- control title/slug/status where the contract permits;
- manage CTA/internal targets;
- create EN/ES counterparts;
- preview the rendered consequences;
- publish/update/archive;
- verify public output;
- rollback supported changes.

ChatGPT cannot inject arbitrary page-builder layout markup into Theme-controlled pages.

### 15.3 Insights / blog

ChatGPT can:

- create draft Insights;
- set title/excerpt/body;
- assign author/taxonomy;
- add related research resources/internal links;
- assign permitted media metadata/relationships;
- prepare SEO/GEO metadata;
- schedule/publish/update/archive;
- create/relate translations;
- verify the article URL and metadata after publication.

### 15.4 Research entities

ChatGPT can manage structured:

- Research Lines;
- Publications;
- Projects;
- Research Software;
- Datasets;
- evidence/provenance fields;
- relationships between those resources.

Academic claims remain subject to evidence rules already implemented by the Manager.

### 15.5 Researcher identity

The canonical researcher identity is high-impact.

ChatGPT may read identity state and propose edits, but changes to canonical name, ORCID or identity-critical fields must require elevated confirmation/policy handling.

### 15.6 Translations

ChatGPT can:

- inspect missing translations;
- create a translation from an approved source resource;
- update bounded translated content;
- pair EN/ES resources;
- verify hreflang and rendered language output.

### 15.7 SEO

ChatGPT can instruct the Manager to inspect and remediate:

- titles/descriptions;
- canonical URLs;
- robots/indexability;
- sitemap inclusion;
- Open Graph;
- hreflang;
- Schema;
- internal links;
- duplicate/missing metadata;
- rendered crawlability.

### 15.8 GEO / machine discoverability

ChatGPT can inspect and improve through structured Manager operations:

- entity clarity;
- authorship/provenance;
- extractable summaries/direct answers;
- semantic relationships;
- evidence/source links;
- machine-readable research objects;
- freshness/update signals;
- crawlable rendered content.

### 15.9 Design

The Manager may expose bounded Research Theme controls such as:

- palette/token selection;
- typography tokens;
- section/component variants;
- permitted spacing/density choices;
- dark/light behavior where Theme-supported.

It must not expose arbitrary raw CSS execution as the normal remote design interface.

### 15.10 Media

The Manager should evolve to expose bounded media operations:

- inventory;
- assignment to structured slots/resources;
- alt text;
- caption/descriptive metadata;
- dimensions/performance diagnostics;
- orphan/unused diagnostics.

Binary upload may be implemented separately, but lack of remote file upload must not force unrelated content operations out of the Manager.

---

## 16. SEO/GEO optimisation loop from ChatGPT

Target workflow:

```text
Eduardo: "optimiza la web"
        ↓
ChatGPT → GET readiness/diagnostics/inventory
        ↓
Manager returns typed findings
        ↓
ChatGPT groups deterministic remediations
        ↓
POST /plans
        ↓
Manager validates + previews exact changes
        ↓
Apply authorised plans
        ↓
Verify stored state
        ↓
Verify rendered pages
        ↓
Re-run readiness
        ↓
ChatGPT reports remaining manual/evidence/external items
```

The phrase "optimise" must not translate into uncontrolled automatic rewriting. Manager policy determines what can be remediated deterministically and what requires editorial or identity/evidence review.

---

## 17. ChatGPT connector contract

A connector/plugin that exposes this Manager to ChatGPT should provide high-level actions matching Manager capabilities, not a generic HTTP passthrough.

Minimum connector actions should conceptually include:

- `get_site_status`;
- `get_capabilities`;
- `list_resources`;
- `get_resource`;
- `get_readiness`;
- `create_plan`;
- `get_plan`;
- `apply_plan`;
- `verify_operation`;
- `rollback_operation`;
- `get_operation`;
- optionally specialised convenience actions for common Page/Insight/resource tasks that still resolve to the same Manager services.

The connector must never expose:

- arbitrary URL fetching against the site;
- arbitrary WordPress REST proxying;
- arbitrary SQL;
- arbitrary PHP;
- arbitrary filesystem access;
- shell command execution;
- raw secret retrieval.

### Connector behavioral rule

Before any material mutation, ChatGPT should normally:

1. read current target state;
2. read capabilities if not already known/current;
3. request a Preview/plan;
4. inspect blockers/warnings;
5. Apply the exact plan when permitted;
6. Verify;
7. return the operation/public result.

For clearly safe idempotent operations the Manager may support streamlined plans, but the safety model remains Manager-owned.

---

## 18. Local Admin parity

Although ChatGPT is our primary managed operating surface, WordPress Admin remains a required fallback and customer/self-service surface.

Every normal remote Manager capability should be backed by a shared domain/application service that can also be called from local Manager UI where appropriate.

Do not build:

```text
remote API business logic
and separately
Admin business logic
```

Build:

```text
shared Manager service
   ├── Admin adapter
   ├── REST/connector adapter
   └── WP-CLI adapter
```

This prevents behavior drift and makes remote governance safe to test locally and in CI.

---

## 19. Installation vs operation

Installation and ongoing operation are separate concerns.

### Installation/bootstrap

May use:

- WordPress Theme ZIP;
- WordPress Plugin ZIP;
- Greenfield bundle;
- WP-CLI;
- SSH where available.

### Normal operation after installation

Must use:

- local Manager UI; and/or
- authenticated Manager API/ChatGPT connector.

Normal operation must not require SSH.

For `eduardoyauriluna.com`, SSH may be used to accelerate initial bootstrap, but after Manager remote control is enabled we should intentionally operate the site through the Manager and record any reason we still need shell/manual WordPress access as a product gap.

---

## 20. Source-of-truth hierarchy

When deciding what to build next, use this order:

1. **this document** — chat-governed Manager operating contract;
2. `docs/10-decisions.md` — accepted ADRs;
3. `docs/15-manager-control-plane.md` — domain/control-plane architecture;
4. `docs/19-manager-productization.md` — productisation/access modes;
5. `docs/09-roadmap.md` — implementation sequence;
6. `docs/21-product-vision-backlog.md` — capability backlog;
7. issues/PRs — execution tracking.

Do not introduce a new architecture in an issue or conversation that conflicts with this hierarchy without first changing the canonical documentation and recording a new ADR.

---

## 21. Immediate implementation sequence

For the Eduardo Research project, the next Manager work should follow this order unless a blocking production defect requires otherwise.

### M1 — Remote Manager foundation

- versioned REST namespace;
- connection/auth data model;
- capability/scopes model;
- revocation/rotation UI;
- request ID/idempotency primitives;
- audit record model;
- read-only `status`, `versions`, `capabilities`, `readiness` endpoints;
- CI proving endpoints expose no secrets and enforce authentication/scopes.

### M2 — Remote Plan/Operation lifecycle

- generic operation/plan IDs;
- plan expiry;
- source revision/fingerprint;
- Preview endpoint;
- Apply exact plan;
- operation status;
- stored verification;
- rendered verification;
- rollback hook for supported operations;
- typed error model;
- audit correlation.

### M3 — Page control from ChatGPT

First end-to-end proof:

```text
ChatGPT → Manager → edit Theme-owned Page slot → Preview → Apply → Verify → rendered eduardoyauriluna.com
```

Capabilities:

- inspect Theme Pages;
- update slots;
- create missing Theme Page where supported;
- translation pairing;
- page-level SEO/GEO fields/remediation;
- public URL verification;
- rollback.

### M4 — Insight/blog control from ChatGPT

End-to-end create/update/schedule/publish/verify for Insights.

### M5 — Research object control from ChatGPT

Expose existing service-layer capabilities for Research Lines, Publications, Projects, Software, Datasets and evidence-aware relationships.

### M6 — SEO/GEO optimisation from ChatGPT

Expose diagnostics/remediation planning and site-level optimisation loops.

### M7 — Theme design controls from ChatGPT

Expose only bounded Theme tokens/variants that preserve deterministic rendering and accessibility.

### M8 — Real-site operating acceptance

For `eduardoyauriluna.com`, perform routine website operation from ChatGPT through the Manager for a defined acceptance window and record every task that required bypassing the Manager.

Any normal website task that still requires a bypass becomes a Manager backlog item.

---

## 22. Definition of Done — Chat-governed Manager v1 for Eduardo Research

The first remotely governed Manager milestone is complete only when all of the following are true:

1. `eduardoyauriluna.com` runs the supported Research Theme + Manager versions.
2. A site-specific remote Manager connection can be enabled and revoked locally.
3. ChatGPT can retrieve live site status and Manager capabilities without SSH.
4. ChatGPT can inventory Theme-controlled pages and research resources.
5. ChatGPT can create a Preview for a page content change.
6. Apply executes the exact approved plan through shared Manager services.
7. The Manager rejects stale plans if the resource changed after Preview.
8. Repeating a request does not duplicate the mutation.
9. Stored-state verification runs after Apply.
10. Rendered verification confirms the public page result.
11. ChatGPT receives an operation ID and can retrieve final status.
12. A supported change can be rolled back through the Manager.
13. All remote mutations appear in a non-secret audit log.
14. The remote connection can be reduced to read-only scopes.
15. Revoking the credential immediately prevents further remote operations.
16. No normal operation above requires WordPress admin password sharing, SSH, direct database access or arbitrary code execution.
17. Local WordPress Admin remains capable of inspecting/operating the same underlying Manager services.
18. At least one real Page change on `eduardoyauriluna.com` is completed end-to-end from this ChatGPT workflow and verified on the rendered site.
19. At least one real Insight is created or updated end-to-end through the same control path.
20. At least one SEO/GEO remediation is planned, applied and rendered-verified through the same control path.

Until these acceptance conditions are met, we may say the remote-control architecture is **implemented or partially implemented**, but not that the Eduardo site is fully governed from ChatGPT.

---

## 23. Non-goals for v1

To keep the path focused, the following are not prerequisites for the first Eduardo remote-control milestone:

- a multi-customer SaaS control center;
- billing/subscriptions;
- generic support for every WordPress Theme;
- unrestricted Gutenberg automation;
- arbitrary plugin administration;
- arbitrary server management;
- SSH orchestration;
- arbitrary database access;
- autonomous external academic publishing without explicit gates;
- full `emmake.com` migration/adoption support.

Those may be subsequent product layers. The first goal is to prove reliable ChatGPT → Research Manager → Theme → `eduardoyauriluna.com` control.

---

## 24. Anti-drift rules

Before proposing or starting substantial Manager work, answer these questions:

1. Does it help us govern `eduardoyauriluna.com` through the Manager from ChatGPT?
2. Does it reuse the existing Theme contract and Manager service layer?
3. Does it avoid creating an SSH/manual-WordPress dependency?
4. Is the operation bounded, auditable and verifiable?
5. Is it part of M1–M8 or a clearly documented blocker?

If not, it is not the current priority.

When the user asks "cómo lo llevamos" or requests continuation, inspect the real repository and report against M1–M8 plus real-site acceptance. Do not substitute generic product ideation for execution of this path.
