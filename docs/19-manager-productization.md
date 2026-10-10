# Manager productization — WordPress website operating control plane

## Goal

The Research/SEO-GEO Manager must evolve from a project implementation tool into a reusable WordPress product that lets us operate a website with the same direct, structured workflow used on custom products such as Kairoseth or IA Empleado.

The commercial opportunity is WordPress itself: many companies already use it. Instead of replacing their stack, the Manager should bring our website-building, publishing and continuous SEO/GEO optimisation workflow into WordPress.

The sellable product is the Manager control plane. The Theme is the deterministic rendering layer. WordPress is the CMS/runtime and ecosystem. SSH, WP-CLI and future remote APIs are transports around the same control plane.

---

## 1. Product definition

The Manager is not just:

- a migration plugin;
- an installer;
- a diagnostics dashboard;
- an SEO checklist;
- a page builder wrapper;
- a WP-CLI frontend.

It is the higher-level operating layer for the whole WordPress website.

An authorised operator should progressively be able to do from the Manager what we do when developing and operating a custom application:

```text
Create / change website intent
        ↓
Manager
        ↓
structured application services
        ↓
WordPress resources + Theme contracts
        ↓
Theme renders deterministic frontend
        ↓
Manager verifies rendered state + SEO/GEO + readiness
```

This means managing outcomes, not just individual WordPress fields.

---

## 2. What the Manager must control

### Website structure

- create complete pages;
- assign page roles and presets;
- create/update slugs and routes;
- manage sections and structured slots;
- manage menus/navigation;
- manage internal links and relationships;
- manage multilingual route pairing;
- detect missing, duplicate or orphan resources.

### Visual system

- select Theme preset;
- control Theme-owned design tokens;
- control permitted section variants;
- configure typography, spacing, palette and component choices through bounded Theme contracts;
- preserve responsive/accessibility rules;
- avoid arbitrary Gutenberg/page-builder layout drift.

### Editorial / blog

- create posts / Insights;
- edit title, excerpt, body and structured metadata;
- assign taxonomy, author and relationships;
- schedule/publish/update/archive;
- generate or prepare SEO/GEO-ready editorial structure;
- connect articles with relevant pages, products, services or research entities;
- verify rendered article output.

### SEO

- titles and descriptions;
- canonical URLs;
- robots/indexability;
- sitemap state;
- Open Graph/social metadata;
- schema/structured data;
- hreflang/language consistency;
- internal linking;
- crawlability;
- duplicate/missing metadata diagnostics;
- rendered verification.

### GEO / machine discoverability

- direct-answer structures;
- entity clarity;
- authorship/provenance;
- structured relationships;
- evidence/source links;
- extractable summaries;
- machine-readable entities;
- consistent semantic hierarchy;
- content freshness signals;
- rendered text availability.

### Media

- media assignment;
- alt text and descriptive metadata;
- image dimensions/performance checks;
- reusable asset relationships;
- missing/duplicate/unused asset diagnostics.

### Operations and quality

- site inventory;
- diagnostics;
- readiness;
- environment/domain checks;
- Theme/Manager compatibility;
- Preview → Apply → Verify → Rollback;
- audit trail for meaningful changes;
- repeatable site-wide optimisation.

Research-specific objects remain an extension of this same generic operating model rather than a separate architecture.

---

## 3. Daily operating model

The target experience should resemble managing Kairoseth/IA Empleado rather than manually assembling WordPress pages.

Examples:

```text
Create Service page
    ↓
choose page role / preset
    ↓
Manager creates route + structured sections
    ↓
fill/prepare content
    ↓
apply SEO/GEO
    ↓
Theme renders
    ↓
Manager verifies
```

```text
Publish today's blog article
    ↓
create Insight/Post
    ↓
content + media + taxonomy
    ↓
SEO/GEO optimisation
    ↓
internal relationships
    ↓
publish/schedule
    ↓
verify rendered article
```

```text
Improve whole website
    ↓
Manager analyses inventory
    ↓
finds weak/missing structure, SEO, GEO, links, media or translations
    ↓
prepares deterministic remediations
    ↓
Preview
    ↓
Apply
    ↓
Verify
```

The operator should not need to open twenty different WordPress screens to achieve one business outcome.

---

## 4. Core product principle — no SSH dependency

Normal product operation must not depend on server shell access.

A customer should be able to:

1. install the Theme ZIP;
2. install the Manager ZIP;
3. activate both from WordPress;
4. complete initial Manager setup;
5. create and operate website content from the Manager;
6. preview/apply/verify/rollback controlled changes;
7. manage SEO/GEO, translations, media and diagnostics;
8. receive future updates;
9. operate the site without giving us SSH credentials.

SSH remains optional.

---

## 5. One service layer, multiple transports

Do not create separate business logic for WordPress Admin, WP-CLI and future API access.

The desired architecture is:

```text
Manager domain/application services
        │
        ├── WordPress Admin UI
        ├── WP-CLI adapter
        └── authenticated REST API adapter (future)
```

Examples of shared services:

- Greenfield Preview / Apply / Verify / Rollback;
- page/resource creation;
- page/section mutation;
- Theme design-token mutation;
- slot hydration;
- post/Insight creation and publishing;
- research-object mutation;
- translation pairing;
- SEO/GEO optimisation;
- readiness diagnostics;
- evidence operations;
- relationship/navigation operations;
- media operations;
- academic connection previews.

Transport adapters validate their own authentication/input and then call the same application service.

---

## 6. Access modes

### Mode A — Self-service WordPress customer

Requirements:

- no SSH;
- no WP-CLI required;
- installable ZIP packages;
- setup wizard / Manager onboarding;
- WordPress capability checks;
- website creation/operation from Manager;
- upgrade/migration handling;
- diagnostics visible in Admin;
- backup/rollback boundaries for controlled Manager mutations.

This is the minimum commercial product mode.

### Mode B — Managed customer

For customers managed by Emmake/Kairoseth/our service layer:

- Manager installed locally in WordPress;
- optional authenticated remote API;
- no need to store client SSH credentials for normal operations;
- remote content/site operations through bounded Manager services;
- remote status/readiness retrieval;
- controlled remote Preview/Apply operations only when explicitly authorised;
- audit trail of remote actions;
- revocable credentials.

### Mode C — WP-CLI / SSH operations

Useful for:

- initial bootstrap;
- CI/CD;
- bulk installation;
- recovery;
- support incidents;
- hosting migrations;
- enterprise/on-premise automation.

This mode accelerates operations but must not contain exclusive product capabilities.

### Mode D — Enterprise/on-premise

The customer may control all infrastructure and credentials.

The Manager remains local to WordPress and operational without external SaaS dependency. Optional remote-management connections must be explicit and revocable.

---

## 7. Theme / Manager boundary

The Theme owns deterministic public rendering:

- layout;
- components;
- responsive behaviour;
- accessibility behaviour;
- semantic HTML;
- design tokens;
- SEO/GEO rendering;
- structured-data rendering.

The Manager owns website intent and controlled operations:

- what resources exist;
- what structured content they contain;
- which Theme variants/tokens apply;
- how resources relate;
- when editorial content is published;
- how SEO/GEO is configured;
- what needs remediation;
- whether the rendered result passes verification.

Gutenberg may remain a bounded rich-text editor, but it is not the website architecture.

---

## 8. Future remote API boundary

A future Manager API should expose bounded control-plane capabilities without exposing WordPress administrator passwords or SSH credentials.

Initial API classes can be divided into:

### Read-only

- product/version information;
- Theme/Manager compatibility;
- readiness report;
- diagnostics;
- resource inventory;
- connection status;
- rendered-verification status;
- pending remediation actions.

### Controlled mutations

- create/update bounded pages/posts/entities;
- Preview proposed mutation;
- Apply previously previewed operation;
- Verify operation;
- Rollback operation;
- hydrate approved structured content;
- update bounded Manager/Theme configuration;
- perform approved SEO/GEO remediation.

### Explicitly privileged / separately gated

- plugin/theme updates;
- destructive deletion;
- identity-critical changes;
- external publishing;
- credential changes;
- irreversible data migration.

The API must not become an unrestricted remote WordPress administrator proxy.

---

## 9. Authentication direction

The exact implementation can evolve, but the required properties are fixed:

- site-specific credentials;
- revocable access;
- least privilege;
- credential rotation;
- no secrets committed to Git;
- no reusable global customer secret;
- scoped permissions;
- audit trail;
- rate limiting;
- signed/expiring requests or equivalent modern authenticated transport;
- explicit distinction between read access and mutation access.

Remote access is optional. A disconnected installation must continue to function locally.

---

## 10. Installation product contract

The Greenfield bundle already proves that Theme + Manager + blueprint can be installed reproducibly through WP-CLI.

Productization extends that result so the same package family can support:

```text
Manual WordPress ZIP install
        │
        ├── Manager setup/onboarding
        └── normal customer operation

Automated bundle/WP-CLI install
        │
        └── same Manager state/services

Future remote provisioning
        │
        └── same Manager state/services
```

The package must not assume filesystem paths, shell availability or hosting-provider-specific infrastructure during normal runtime.

---

## 11. Product completeness rule

For every new feature, ask:

1. Is the business logic implemented in a reusable service/domain layer?
2. Can an authorised WordPress administrator execute it through Manager UI?
3. Does it achieve the intended website outcome instead of merely exposing raw fields?
4. Can WP-CLI reuse exactly the same service instead of duplicating behavior?
5. Could a future authenticated API safely expose it without refactoring the core mutation logic?
6. Is its result auditable and, for meaningful mutations, previewable/verifiable/rollback-capable where appropriate?

If the answer to #2 is no because shell access is required, the feature is not commercially complete.

If the Manager only reports a deterministic problem but cannot safely remediate it, the feature is operationally incomplete.

---

## 12. Commercial consequence

This architecture allows the same Theme + Manager product to serve:

- our own sites;
- agency-managed clients;
- clients on shared hosting;
- managed WordPress customers;
- VPS customers;
- enterprise/on-premise customers;
- future multi-site remote-management products.

The differentiator is not simply that it runs on WordPress. It is that it gives WordPress installations a structured website operating layer for creation, publishing and continuous SEO/GEO optimisation.

It separates the value of the software from our access to the customer's infrastructure.

That is the product boundary: **the customer buys the Manager capability, not our SSH session.**

---

## 13. Product roadmap

### Productization P1 — Admin parity

Audit existing WP-CLI-only operations and ensure every normal operation has a WordPress Manager UI path backed by the same services.

Priority:

- Greenfield Status;
- Greenfield Preview;
- Greenfield Apply;
- Greenfield Verify/readiness;
- Greenfield Rollback;
- bundle/setup status.

### Productization P2 — Website authoring cockpit

Unify the existing Manager capabilities into a coherent daily operating surface for:

- Pages;
- page sections/slots;
- Theme style controls;
- Posts / Insights;
- media;
- navigation/relationships;
- translations;
- SEO/GEO;
- readiness/remediation.

The user should be able to execute complete website tasks without leaving the Manager for routine operations.

### Productization P3 — installer/onboarding

Create a bounded WordPress onboarding surface that detects:

- Theme availability/compatibility;
- Manager version;
- WordPress/PHP requirements;
- preset selection;
- Greenfield readiness;
- administrator capability;
- configuration prerequisites.

It should guide the operator through Preview → Apply → Verify without requiring WP-CLI.

### Productization P4 — continuous optimisation

Turn diagnostics into a repeatable website-improvement loop:

- analyse;
- prioritise;
- prepare deterministic remediation;
- Preview;
- Apply;
- Verify;
- record result.

This should cover structure, editorial quality, SEO, GEO, internal links, media, translations and Theme consistency.

### Productization P5 — remote-read API

Expose authenticated, read-only status/readiness/version/diagnostic endpoints with revocable site-specific credentials.

### Productization P6 — controlled remote mutations

Expose bounded website operations and Preview → Apply → Verify → Rollback over the remote API with strong permissions, audit records and explicit mutation scopes.

---

## 14. Immediate rule for the Eduardo Research deployment

`eduardoyauriluna.com` is the first real Greenfield Research deployment, but it is also a reference implementation of the broader WordPress operating model.

SSH may be used for the first real bootstrap because it gives us a fast, observable installation path.

However, every ordinary task discovered during deployment or ongoing operation — creating a page, changing Theme-controlled presentation, publishing an Insight, improving internal links, fixing metadata, optimising SEO/GEO, correcting translations or resolving readiness failures — must be evaluated as a Manager product capability.

If the same task would be needed by a future WordPress customer, it should not become an Eduardo-specific manual runbook.
