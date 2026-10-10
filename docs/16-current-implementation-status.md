# Current implementation status — 2026-10-10

This document separates **what is already implemented in the repository**, **what is verified by CI**, and **what is still pending on the real WordPress installation / production site**.

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
- Deterministic Greenfield WP-CLI operator lifecycle:
  - `wp research-manager greenfield status`;
  - `wp research-manager greenfield preview`;
  - `wp research-manager greenfield apply --user=<administrator> --yes`;
  - `wp research-manager greenfield rollback --user=<administrator> --yes`.

### Standalone Greenfield installation bundle

A standalone deployment bundle is implemented and verified by CI. It contains:

- versioned Research Theme ZIP;
- versioned Research Manager ZIP;
- canonical Research blueprint;
- `manifest.json` with component identity, versions and SHA-256 metadata;
- `SHA256SUMS` integrity boundary;
- standalone `install.sh`;
- installation README.

`Research Greenfield Bundle Quality` builds the archive, extracts it away from repository source paths, verifies hashes and manifest data, installs a fresh WordPress core, installs Theme + Manager only from the extracted bundle, executes the Greenfield lifecycle, verifies readiness and proves repeated Apply is idempotent.

Reference: `docs/18-greenfield-installation-bundle.md`.

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

Repository-wide WP-CLI resilience is complete.

- `.github/scripts/ensure-wp-cli.sh` provides the shared deterministic fallback.
- Every workflow that declares WP-CLI is required to invoke the fallback before WordPress runtime work.
- `.github/scripts/check-wp-cli-workflows.sh` enforces that contract globally.
- `CI WP-CLI Fallback Quality` exercises both the repository contract and a forced fallback installation.
- The final legacy workflow set was hardened in PR #89; the guardrail and all affected runtime smoke tests passed.

`wp: command not found` is no longer an accepted infrastructure failure mode for repository WordPress workflows.

## Verified by CI

The repository currently has automated evidence for, among other areas:

- clean WordPress Theme activation and Research bootstrap;
- EN/ES routes, relationships and translations;
- Theme-owned structured pages and interior Research surfaces;
- Research Lines and structured Research objects;
- evidence gates, controlled mutations and rollback;
- Research Manager runtime/readiness;
- academic connection previews and reconciliation contracts;
- Theme and Manager installable packages;
- standalone Greenfield bundle construction and integrity verification;
- clean WordPress installation from that standalone bundle;
- Greenfield Apply readiness and idempotent re-Apply.

The standalone bundle produced from `main` at merge commit `148e7f6fb4476f98ce632333ef02f766b6ab125d` passed `Research Greenfield Bundle Quality` on 2026-10-10.

## Still pending outside repository implementation

The following items require the real WordPress target, production/staging environment or verified external identity data and must not be marked complete only because supporting code exists:

- fresh WordPress installation for `eduardoyauriluna.com` on the real target environment;
- installation of the verified Greenfield bundle on that target;
- real site hydration and verification through the canonical Research pipeline;
- final visual validation of the selected researcher palette/design system;
- mobile, accessibility and Core Web Vitals validation on the deployed frontend;
- real English/Spanish content population and review;
- real verified ORCID and other academic identifiers supplied/confirmed by the researcher;
- production external credentials/tokens where optional authenticated reads are desired;
- final academic SEO/GEO validation against rendered production pages;
- real publications, projects, datasets and research-software portfolio content;
- doctoral-application content and 2027 outreach package.

## Immediate engineering priority

1. Treat `main` + the verified Greenfield bundle as the deployment baseline.
2. Install the bundle on the real clean WordPress target for `eduardoyauriluna.com`.
3. Run Greenfield Status → Preview → Apply → Status on the target and preserve the resulting evidence.
4. Verify the rendered EN/ES frontend, Theme authority, academic metadata and readiness against the real domain.
5. Continue product development only from gaps found on the deployed Research site, preserving Theme authority and structured Manager control.

## Rule for status reporting

When reporting progress, distinguish these three states explicitly:

- **implemented in repository** — code/contract/admin surface exists;
- **verified by CI** — automated tests have exercised the behavior;
- **deployed/verified on real site** — the behavior has been tested against the actual WordPress installation and rendered `eduardoyauriluna.com` frontend.

This prevents repository progress from being confused with production readiness.
