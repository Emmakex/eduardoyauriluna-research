# Manager productization — access-independent architecture

## Goal

The Research/SEO-GEO Manager must evolve from a project implementation tool into a reusable WordPress product that can be installed and operated across different customer environments without requiring permanent SSH access.

The sellable product is the Manager itself. SSH, WP-CLI and future remote APIs are transports around the same control plane.

---

## 1. Core product principle

Normal product operation must not depend on server shell access.

A customer should be able to:

1. install the Theme ZIP;
2. install the Manager ZIP;
3. activate both from WordPress;
4. complete initial Manager setup;
5. preview/apply/verify/rollback controlled changes;
6. manage content, SEO/GEO, translations, research entities and diagnostics;
7. receive future updates;
8. operate the site without giving us SSH credentials.

SSH remains optional.

---

## 2. One service layer, multiple transports

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
- slot hydration;
- research-object mutation;
- translation pairing;
- SEO/GEO optimisation;
- readiness diagnostics;
- evidence operations;
- relationship/navigation operations;
- academic connection previews.

Transport adapters validate their own authentication/input and then call the same application service.

---

## 3. Access modes

### Mode A — Self-service WordPress customer

Requirements:

- no SSH;
- no WP-CLI required;
- installable ZIP packages;
- setup wizard / Manager onboarding;
- WordPress capability checks;
- upgrade/migration handling;
- diagnostics visible in Admin;
- backup/rollback boundaries for controlled Manager mutations.

This is the minimum commercial product mode.

### Mode B — Managed customer

For customers managed by Emmake/Kairoseth/our service layer:

- Manager installed locally in WordPress;
- optional authenticated remote API;
- no need to store client SSH credentials for normal operations;
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

## 4. Future remote API boundary

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

- Preview proposed mutation;
- Apply previously previewed operation;
- Verify operation;
- Rollback operation;
- hydrate approved structured content;
- update bounded Manager configuration.

### Explicitly privileged / separately gated

- plugin/theme updates;
- destructive deletion;
- identity-critical changes;
- external publishing;
- credential changes;
- irreversible data migration.

The API must not become an unrestricted remote WordPress administrator proxy.

---

## 5. Authentication direction

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

## 6. Installation product contract

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

## 7. Product completeness rule

For every new feature, ask:

1. Is the business logic implemented in a reusable service/domain layer?
2. Can an authorised WordPress administrator execute it through Manager UI?
3. Can WP-CLI reuse exactly the same service instead of duplicating behavior?
4. Could a future authenticated API safely expose it without refactoring the core mutation logic?
5. Is its result auditable and, for meaningful mutations, previewable/verifiable/rollback-capable where appropriate?

If the answer to #2 is no because shell access is required, the feature is not commercially complete.

---

## 8. Commercial consequence

This architecture allows the same Theme + Manager product to serve:

- our own sites;
- agency-managed clients;
- clients on shared hosting;
- managed WordPress customers;
- VPS customers;
- enterprise/on-premise customers;
- future multi-site remote-management products.

It separates the value of the software from our access to the customer's infrastructure.

That is the product boundary: **the customer buys the Manager capability, not our SSH session.**

---

## 9. Next implementation milestones

### Productization P1 — Admin parity

Audit existing WP-CLI-only operations and ensure every normal operation has a WordPress Manager UI path backed by the same services.

Priority:

- Greenfield Status;
- Greenfield Preview;
- Greenfield Apply;
- Greenfield Verify/readiness;
- Greenfield Rollback;
- bundle/setup status.

### Productization P2 — installer/onboarding

Create a bounded WordPress onboarding surface that detects:

- Theme availability/compatibility;
- Manager version;
- WordPress/PHP requirements;
- preset selection;
- Greenfield readiness;
- administrator capability;
- configuration prerequisites.

It should guide the operator through Preview → Apply → Verify without requiring WP-CLI.

### Productization P3 — remote-read API

Expose authenticated, read-only status/readiness/version/diagnostic endpoints with revocable site-specific credentials.

### Productization P4 — controlled remote mutations

Expose Preview → Apply → Verify → Rollback over the remote API with strong permissions, audit records and explicit mutation scopes.

---

## 10. Immediate rule for the Eduardo Research deployment

SSH may be used for the first real deployment of `eduardoyauriluna.com` because it gives us a fast, observable bootstrap path.

However, every step discovered during that deployment that is needed by ordinary future customers must be converted into a Manager/admin capability rather than becoming an SSH runbook requirement.

The Eduardo site therefore serves both as the first real Greenfield Research deployment and as the validation environment for the sellable access-independent Manager product.
