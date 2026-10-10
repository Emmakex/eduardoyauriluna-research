# Product vision backlog — WordPress Operating Manager

## Vision

Bring the direct website-development workflow used on custom applications into WordPress through a reusable Manager + Theme product.

For `eduardoyauriluna.com`, the primary managed workflow is explicitly:

```text
Eduardo → ChatGPT → authenticated Research Manager → shared Manager services → WordPress/Theme → rendered site → verification back to ChatGPT
```

Canonical contract: `docs/22-chat-governed-manager-contract.md`.

## Priority order for the Eduardo Research implementation

### P0. Chat-governed Manager foundation

- versioned Manager REST namespace;
- site-specific connection identity;
- local enable/disable/revoke/rotate controls;
- read/write scopes;
- capability discovery;
- request IDs and idempotency;
- stale-state/revision protection;
- audit records;
- typed operation errors;
- read-only status/version/readiness/diagnostics endpoints;
- CI for authentication, scopes and secret non-disclosure.

### P1. Remote plan / operation lifecycle

- create Preview/plan from a typed operation;
- plan ID and expiry;
- exact-plan Apply;
- operation ID/status;
- stored verification;
- rendered verification;
- rollback for supported operations;
- deterministic retry behavior;
- operation audit retrieval.

### P2. Page control from ChatGPT

- inspect Theme-controlled Pages;
- create supported missing Page resources;
- update structured slots;
- control bounded Theme variants;
- manage language pairing;
- page-level SEO/GEO inspection/remediation;
- public rendered verification;
- rollback.

This is the first required real end-to-end proof on `eduardoyauriluna.com`.

### P3. Editorial control from ChatGPT

- create/edit/schedule/publish Posts/Insights;
- taxonomy and authorship;
- media relationships/metadata;
- internal relationships;
- translations;
- SEO/GEO preparation;
- rendered verification.

### P4. Research object control from ChatGPT

Expose the existing bounded service-layer capabilities for:

- Research Lines;
- Publications;
- Projects;
- Research Software;
- Datasets;
- evidence/provenance;
- relations and translations.

### P5. SEO/GEO continuous optimisation from ChatGPT

- analyse whole site;
- prioritise deterministic issues;
- prepare remediation plans;
- Preview;
- Apply;
- Verify;
- Rollback where appropriate;
- rerun readiness;
- separate automatic fixes from editorial/evidence/manual review.

### P6. Design control from ChatGPT

- preset-level palette;
- typography tokens;
- spacing/density tokens;
- component/section variants;
- responsive/accessibility constraints;
- no arbitrary CSS/code execution as the normal control path.

### P7. Real-site operating acceptance

- run routine Eduardo site operation through ChatGPT → Manager;
- record every bypass to WordPress Admin/SSH/manual code;
- convert normal bypasses into Manager backlog items;
- prove Page, Insight and SEO/GEO end-to-end operations on the rendered real site.

## Supporting capability groups

### Site creation
- preset selection;
- Greenfield bootstrap;
- page/route creation;
- navigation creation;
- multilingual structure;
- baseline SEO/GEO configuration.

### Page operations
- create page from role/contract;
- edit structured sections and content;
- control bounded Theme variants;
- manage CTA/media/internal links;
- preview and verify frontend;
- page-level SEO/GEO optimisation.

### Editorial operations
- create/edit/schedule/publish Posts/Insights;
- taxonomy and authorship;
- media and internal relationships;
- SEO/GEO preparation;
- update/freshness workflows;
- rendered verification.

### Design operations
- preset-level palette;
- typography tokens;
- spacing/layout tokens;
- component variants;
- responsive/accessibility constraints;
- no arbitrary layout drift.

### SEO/GEO operations
- metadata;
- canonicals/hreflang;
- schema;
- sitemap/indexability;
- internal-link graph;
- entity clarity;
- direct-answer structures;
- evidence/provenance;
- machine discoverability;
- deterministic remediation.

### Media operations
- asset inventory;
- assignment;
- alt/descriptive metadata;
- performance checks;
- duplicate/unused detection.

### Continuous optimisation
- analyse whole site;
- prioritise issues;
- prepare safe changes;
- Preview;
- Apply;
- Verify;
- Rollback where appropriate;
- record audit/result.

## Later product layers — not current Eduardo priority

Only after the single-site ChatGPT-governed Eduardo flow is proven:

- generic multi-client control center;
- generic onboarding across unrelated Themes;
- commercial billing/subscriptions;
- customer fleet dashboards;
- broader existing-site/migration automation for `emmake.com` and other sites.

## Commercial completeness

A capability is not complete merely because it exists in code, Admin UI or WP-CLI. For the managed Eduardo workflow it is complete when ChatGPT can use the authenticated Manager to achieve the intended website result with bounded permissions, Preview/Apply/Verify semantics, auditability and rendered verification where applicable.
