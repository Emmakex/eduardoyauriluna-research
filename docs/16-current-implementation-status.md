# Current implementation status — 2026-10-10

This document separates **what is already implemented in the repository**, **what is verified by CI**, **what is deployed/verified on the real site**, and **what is still missing for ChatGPT-governed operation**.

## Scope and canonical operating model

`eduardoyauriluna.com` is the **Greenfield Research** scenario.

There is no legacy site to interpret, audit or migrate. The operating architecture is:

```text
Eduardo → ChatGPT → authenticated Research Manager → shared Manager services → WordPress resources → Research Theme → rendered eduardoyauriluna.com → verification back to ChatGPT
```

The Research Theme owns deterministic frontend rendering. The Research Manager owns structured website control. ChatGPT is the intended primary managed instruction surface. WordPress Admin is the required local/fallback surface. WP-CLI and SSH are optional bootstrap/automation/support transports.

Canonical reference: `docs/22-chat-governed-manager-contract.md`.

## Implemented in the repository

### Research Theme

The Research Theme is implemented as a Theme-owned frontend. Controlled surfaces do not rely on Gutenberg as the layout authority. The Theme includes the Research preset, page/single/archive rendering, responsive/semantic frontend, multilingual behavior, Research entities, SEO/academic discovery rendering and the selected visual direction.

### Manager service/control-plane foundation

The Manager currently includes reusable services and bounded mutation patterns for:

- canonical Research blueprint and blueprint store;
- Greenfield pipeline with Preview → Apply → Verify → Rollback semantics;
- direct canonical blueprint JSON download;
- structured Theme Page inspection/editing;
- Insights;
- Research Lines;
- Publications / research outputs;
- Projects;
- Research Software;
- Datasets;
- evidence/provenance controls;
- EN/ES translation pairing/editing;
- readiness/diagnostics;
- deterministic remediation services;
- stored-state execution/snapshots/rollback infrastructure;
- rendered HTTP verification;
- academic connections/adapters for ORCID, Crossref, OpenAlex, Zenodo and bounded GitHub research-software preview;
- Google Scholar-compatible citation/indexing metadata contract.

### WordPress Admin surfaces

The repository already contains Manager Admin surfaces for major service groups, including:

- Greenfield/status/readiness control;
- structured Page editing;
- Insights;
- Research Lines;
- Research Objects;
- translations;
- evidence;
- connections;
- selected academic connector workflows.

The local Admin surface is therefore more than a diagnostics mockup; it already exercises real Manager services.

### WP-CLI operator lifecycle

The repository also implements:

- `wp research-manager greenfield status`;
- `wp research-manager greenfield preview`;
- `wp research-manager greenfield apply --user=<administrator> --yes`;
- `wp research-manager greenfield rollback --user=<administrator> --yes`.

WP-CLI is an adapter/automation path, not the target day-to-day operating surface.

### Standalone Greenfield installation bundle

A standalone deployment bundle is implemented and verified by CI. It contains:

- versioned Research Theme ZIP;
- versioned Research Manager ZIP;
- canonical Research blueprint;
- `manifest.json` with component identity, versions and SHA-256 metadata;
- `SHA256SUMS` integrity boundary;
- standalone installer;
- installation README.

## Verified by CI

The repository currently has automated evidence for, among other areas:

- clean WordPress Theme activation and Research bootstrap;
- EN/ES routes, relationships and translations;
- Theme-owned structured pages and interior Research surfaces;
- Research Lines and structured Research objects;
- Insights/editorial paths;
- evidence gates, controlled mutations and rollback;
- Research Manager runtime/readiness;
- page/editor Admin behavior;
- academic connection previews/reconciliation contracts;
- Theme and Manager installable packages;
- standalone Greenfield bundle construction/integrity;
- clean WordPress installation from that bundle;
- Greenfield Apply readiness and idempotent re-Apply;
- repository-wide WP-CLI availability/fallback protection.

The standalone bundle produced from `main` at merge commit `148e7f6fb4476f98ce632333ef02f766b6ab125d` passed `Research Greenfield Bundle Quality` on 2026-10-10.

## Not yet implemented — ChatGPT ↔ Manager bridge

The central missing layer for the intended operating model is the authenticated remote Manager interface and ChatGPT connector.

The following must **not** be reported as complete yet:

- versioned remote Manager REST/control API;
- site-specific remote connection identity;
- local enable/revoke/rotate UI;
- read/write scope model;
- request identity/idempotency layer for remote operations;
- stale-revision/concurrency enforcement for remote plans;
- remote audit record/correlation model;
- typed remote error contract;
- remote `status` / `versions` / `capabilities` / `readiness` endpoints;
- remote Plan/Preview lifecycle;
- exact-plan Apply over the remote boundary;
- operation status/verification retrieval;
- remote rollback invocation for supported operations;
- ChatGPT connector exposing high-level Manager actions;
- a real end-to-end Page mutation originating from this ChatGPT workflow and verified on `eduardoyauriluna.com`.

The current Manager core makes this bridge feasible, but the bridge itself is still pending implementation.

## Still pending on the real WordPress target

The following require the actual `eduardoyauriluna.com` WordPress environment and must not be marked complete solely from repository/CI evidence:

- install/confirm the supported Theme + Manager build on the real target;
- real Greenfield hydration and Manager readiness verification;
- real rendered EN/ES verification against the public domain;
- real ChatGPT ↔ Manager authenticated connection;
- real remote Page Preview → Apply → Verify → Rollback acceptance;
- real remote Insight creation/update/publish acceptance;
- real remote SEO/GEO remediation acceptance;
- mobile/accessibility/Core Web Vitals production validation;
- final real EN/ES content population/review;
- verified ORCID and other academic identifiers supplied/confirmed by the researcher;
- production external credentials/tokens where optional authenticated reads are required;
- real publications/projects/datasets/software portfolio content.

## Immediate engineering priority — M1 to M8

The canonical implementation order is defined in `docs/22-chat-governed-manager-contract.md`:

1. **M1 — Remote Manager foundation:** authenticated versioned API, capabilities/scopes, credential lifecycle, idempotency primitives, audit model, read-only status/version/readiness endpoints.
2. **M2 — Plan/operation lifecycle:** Preview/plan IDs, exact-plan Apply, revision protection, verification, typed errors, rollback hooks.
3. **M3 — Page control from ChatGPT:** first real end-to-end Page operation.
4. **M4 — Insight/blog control from ChatGPT.**
5. **M5 — Research object control from ChatGPT.**
6. **M6 — SEO/GEO optimisation from ChatGPT.**
7. **M7 — bounded Research Theme design controls from ChatGPT.**
8. **M8 — real-site operating acceptance:** operate routine site work through ChatGPT → Manager and convert any normal bypass into backlog.

Do not replace this sequence with speculative multi-client SaaS/Control Center work before the Eduardo single-site control path is proven.

## Rule for status reporting

When reporting progress, explicitly distinguish:

- **implemented in repository** — code/contract/admin/remote surface exists;
- **verified by CI** — automated tests have exercised it;
- **deployed on real site** — supported code is installed on `eduardoyauriluna.com`;
- **verified from ChatGPT through Manager** — the operation originated through the authenticated Manager bridge and the rendered public result was verified.

The final state is the acceptance target for routine managed website operations.
