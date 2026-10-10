# Current implementation status — 2026-10-10

This document separates **what is already implemented in the repository** from **what is still pending on the real WordPress installation / production site**.

## Scope and scenario

`eduardoyauriluna.com` is the **Greenfield Research** scenario.

There is no existing site to interpret, audit or migrate. The operating path is:

1. clean WordPress installation;
2. SEO/GEO Theme;
3. `research` preset and Theme-owned frontend;
4. structured academic content model;
5. Research Manager as control plane;
6. controlled hydration, optimisation, verification and academic connections.

The separate **existing-site / migration scenario** belongs to the generic SEO/GEO Theme + Manager product. That scenario may need to inspect an existing WordPress installation, map legacy content and preserve SEO during migration. Those interpretation/migration responsibilities must not be introduced into this Greenfield Research Manager.

Reference: `docs/10-theme-manager-scenarios.md`.

## Implemented in the repository

### Greenfield control plane

- Canonical Research blueprint and blueprint store.
- Greenfield pipeline with Preview → Apply → Verify → Rollback semantics.
- Direct canonical blueprint JSON download from the WordPress admin surface.
- Theme-owned frontend contract; controlled pages do not rely on Gutenberg layout composition.
- Structured Page editor for Theme-owned slots.
- Research Manager admin/control-plane foundation.
- Readiness/diagnostic services and controlled mutation patterns.

### Structured academic management

- Academic Evidence editor/admin surfaces.
- Research Line editor/admin surfaces.
- Generic Research Object editor/admin surfaces for structured academic records.
- Insights editor/admin surfaces.
- Translation editor/admin and EN/ES relationship controls.
- Workspace/relationship infrastructure used by the Manager.

### Academic connections

Implemented bounded read/reconciliation capabilities now include:

- ORCID;
- Crossref / DOI;
- OpenAlex using exact ORCID identity;
- Zenodo using verified ORCID identity;
- GitHub selected-repository Research Software preview;
- Google Scholar-compatible citation/indexing metadata contract.

The GitHub integration is deliberately bounded: it works with one explicitly selected repository, performs public/token-assisted reads only, exposes no automatic Apply action and does not write to GitHub.

### CI resilience

A shared `.github/scripts/ensure-wp-cli.sh` fallback exists and is already used by several newer quality workflows. It successfully recovers jobs when `shivammathur/setup-php` cannot install WP-CLI.

The fallback is **not yet adopted by every older WordPress workflow**. This is the current CI-hardening task; failures caused by `wp: command not found` are infrastructure/test-bootstrap failures, not product regressions.

## Still pending outside repository implementation

The following items require the real WordPress target, production/staging environment or verified external identity data and must not be marked complete only because supporting code exists:

- fresh WordPress installation for `eduardoyauriluna.com`;
- installation/activation of the Research Theme and Manager on the target environment;
- real site hydration using the canonical Research blueprint;
- final visual validation of the selected researcher palette/design system;
- mobile, accessibility and Core Web Vitals validation on the deployed frontend;
- real English/Spanish content population and review;
- real verified ORCID and other academic identifiers supplied/confirmed by the researcher;
- production external credentials/tokens where optional authenticated reads are desired;
- final academic SEO/GEO validation against rendered production pages;
- real publications, projects, datasets and research-software portfolio content;
- doctoral-application content and 2027 outreach package.

## Immediate engineering priority

1. Finish applying the shared WP-CLI fallback to all WordPress CI workflows that still depend directly on `setup-php` tool installation.
2. Keep `main` green and eliminate infrastructure-only CI noise.
3. Move from repository implementation to the real clean WordPress installation.
4. Install Theme + Manager, apply the canonical Greenfield blueprint and verify the rendered site.
5. Continue product work only from gaps found on the real Research site, preserving Theme authority and structured Manager control.

## Rule for status reporting

When reporting progress, distinguish these three states explicitly:

- **implemented in repository** — code/contract/admin surface exists;
- **verified by CI** — automated tests have exercised the behavior;
- **deployed/verified on real site** — the behavior has been tested against the actual WordPress installation and rendered `eduardoyauriluna.com` frontend.

This prevents repository progress from being confused with production readiness.
