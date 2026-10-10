# Manager productization — WordPress website operating control plane

## Canonical operating rule

For `eduardoyauriluna.com`, the Manager is being productized as the **authenticated execution gateway between ChatGPT and WordPress**.

The managed operating chain is:

```text
Eduardo
   ↓ natural-language instruction
ChatGPT
   ↓ typed authenticated Manager operation
Research Manager
   ↓ shared application/domain services
WordPress resources + Research Theme
   ↓ deterministic render
eduardoyauriluna.com
   ↓ stored + rendered verification
Research Manager → ChatGPT → Eduardo
```

The complete security, command, API, capability and acceptance contract is defined in `docs/22-chat-governed-manager-contract.md`. That document is canonical if any wording here is ambiguous.

---

## 1. Product definition

The Manager is not primarily:

- a migration plugin;
- an installer;
- a diagnostics dashboard;
- an SEO checklist;
- a page-builder wrapper;
- a WP-CLI frontend;
- a generic unrestricted remote WordPress proxy.

It is the higher-level operating/control layer for the WordPress website.

The key product idea is that WordPress can remain the CMS/runtime used by many companies while we control website outcomes through structured Manager services, in the same instruction-driven way we work on custom applications such as Kairoseth or IA Empleado.

---

## 2. Operating surfaces

### Managed primary surface — ChatGPT

For our management of `eduardoyauriluna.com`, routine orders are given from the ChatGPT conversation.

Examples:

- create a new Theme-controlled page;
- update a page section;
- publish an Insight;
- improve internal linking;
- optimise SEO/GEO;
- add/update a research object;
- create an EN/ES pair;
- verify public output;
- roll back a supported operation.

ChatGPT must inspect and act through the Manager rather than bypassing it with direct database/filesystem/server writes.

### Authoritative gateway — Manager

The Manager validates authentication, scopes, Theme contracts, resource state, evidence rules and operation safety. It creates plans, applies approved operations, verifies results and records audit information.

### Local fallback/self-service — WordPress Admin

Local Admin remains mandatory. A disconnected site must remain operable. Administrators must be able to inspect status, revoke remote access and execute supported local Manager operations.

### Optional automation — WP-CLI

WP-CLI calls shared Manager services for repeatable install/automation/recovery. It does not own exclusive product behavior.

### Optional infrastructure — SSH

SSH is limited to bootstrap, hosting/infrastructure work and exceptional support/recovery. It is not the normal content/site-management path.

---

## 3. One service layer, multiple adapters

The architecture must converge on:

```text
Manager domain/application services
        │
        ├── authenticated REST/API adapter → ChatGPT connector
        ├── WordPress Admin adapter
        └── WP-CLI adapter
```

Do not duplicate business logic across adapters.

Shared services cover, progressively:

- Greenfield state/lifecycle;
- page/resource inspection and mutation;
- structured slot hydration;
- Posts/Insights;
- Research Lines;
- Publications;
- Projects;
- Software;
- Datasets;
- translations;
- evidence/provenance;
- navigation/relationships;
- media metadata/assignment;
- Theme design tokens/variants;
- SEO/GEO diagnostics/remediation;
- readiness;
- stored verification;
- rendered verification;
- rollback where supported.

---

## 4. Theme / Manager boundary

### Theme owns rendering

- layout;
- component structure;
- responsive behavior;
- accessibility behavior;
- design tokens/allowed variants;
- semantic HTML;
- SEO/GEO frontend rendering;
- structured data rendering;
- archive/single/page templates.

### Manager owns controlled intent/operations

- which resources exist;
- what structured content they contain;
- which allowed Theme variants apply;
- relationships/navigation;
- publication timing/state;
- language pairing;
- SEO/GEO configuration;
- diagnostics/remediation;
- verification/audit.

Gutenberg may be a bounded rich-text editor where explicitly allowed. It is not the layout architecture.

---

## 5. Remote control is a first-class requirement

For the Eduardo project, authenticated remote Manager control is **not postponed behind a future multi-client control center**.

The first remote-control objective is a single-site, bounded, secure connection to `eduardoyauriluna.com` that allows ChatGPT to:

1. read live state;
2. discover allowed capabilities;
3. create a Preview/plan;
4. Apply the exact plan;
5. obtain an operation ID;
6. Verify stored state;
7. Verify rendered public output;
8. Roll back when supported;
9. report the real result.

Only after this is proven should we generalise the transport for fleets/multiple customers.

---

## 6. Remote security boundary

Remote control must have:

- site-specific credentials;
- local enable/disable/revoke/rotate controls;
- least-privilege scopes;
- separate read/write authority;
- request expiry/replay protection;
- request IDs/idempotency;
- optimistic concurrency/stale-state protection;
- rate limiting;
- TLS;
- audit records;
- no secrets in Git/audit responses;
- no WordPress admin password sharing;
- no arbitrary PHP, SQL, shell or filesystem execution;
- no generic unrestricted WordPress REST passthrough.

High-impact operations such as canonical identity changes, destructive deletion, external publication, credentials and software/plugin updates require stronger gates.

---

## 7. Daily operating model

### Page task

```text
Eduardo: "cambia la sección X"
        ↓
ChatGPT reads Page/resource state
        ↓
Manager prepares plan
        ↓
Preview exact structured slot changes
        ↓
Apply
        ↓
Theme renders
        ↓
Manager verifies stored + rendered state
        ↓
ChatGPT reports URL/result
```

### Editorial task

```text
Eduardo: "publica la entrada de hoy"
        ↓
ChatGPT creates/updates Insight through Manager
        ↓
content + taxonomy + relationships + translation
        ↓
SEO/GEO plan
        ↓
publish/schedule
        ↓
rendered verification
```

### Optimisation task

```text
Eduardo: "optimiza la web"
        ↓
ChatGPT requests readiness/diagnostics
        ↓
Manager returns typed findings
        ↓
deterministic remediations become plans
        ↓
Preview → Apply → Verify
        ↓
remaining evidence/editorial/manual items reported separately
```

---

## 8. Installation vs normal operation

### Bootstrap may use

- Theme ZIP;
- Manager ZIP;
- Greenfield bundle;
- WP-CLI;
- SSH when available.

### Normal operation must use

- local Manager services through WordPress Admin; and/or
- authenticated remote Manager API through the ChatGPT connector.

Any routine operation that still requires shell/database/manual code after installation is a product gap.

---

## 9. Commercial consequence

The reusable product model becomes:

```text
Customer WordPress
   + compatible Theme contract
   + Manager
   + optional secure ChatGPT/managed connection
```

This lets us offer a managed operating experience without demanding continuous SSH access. Customers may keep local control and revoke the remote connection, while our managed workflow can still operate the website through bounded Manager capabilities.

The differentiator is not merely WordPress automation. It is **instruction-driven, structured website operation with deterministic rendering, SEO/GEO optimisation, verification and rollback boundaries**.

---

## 10. Completeness rule

For every routine Manager capability ask:

1. Is business logic in the shared service layer?
2. Can local Admin call it where applicable?
3. Can the authenticated Manager API expose it safely for ChatGPT where applicable?
4. Does it achieve a website outcome rather than expose arbitrary raw internals?
5. Is the operation typed and scope-gated?
6. Is the relevant state revision/fingerprint known?
7. Can the change be previewed when material?
8. Is Apply bound to the reviewed plan?
9. Can the result be verified from stored state and public render when relevant?
10. Is the mutation audited?
11. Is retry/idempotency safe?
12. Is rollback available when the operation contract supports it?

If remote managed operation requires SSH/manual DB edits, the ChatGPT-governed capability is incomplete.

---

## 11. Current implementation sequence

Use the milestones in `docs/22-chat-governed-manager-contract.md` and issue #97:

- **M1:** Remote Manager foundation.
- **M2:** Plan/operation lifecycle.
- **M3:** Page control from ChatGPT.
- **M4:** Insight/blog control from ChatGPT.
- **M5:** Research object control from ChatGPT.
- **M6:** SEO/GEO optimisation from ChatGPT.
- **M7:** bounded Theme design controls from ChatGPT.
- **M8:** real-site operating acceptance.

Issue #92 is supporting work for local Admin parity/shared services, not a replacement for this order.

---

## 12. Current site focus

`eduardoyauriluna.com` is the first and current validation target.

Do not divert current implementation toward:

- multi-client SaaS dashboards;
- billing;
- generic fleet management;
- arbitrary Theme support;
- the `emmake.com` migration/adoption scenario;
- unrelated hosting automation;

until the Eduardo ChatGPT → Manager → Theme → rendered-site path is proven end to end.

Every ordinary task discovered while operating Eduardo's site must be evaluated as a Manager capability. If it is needed for normal website management, avoid turning it into an Eduardo-specific manual runbook.
