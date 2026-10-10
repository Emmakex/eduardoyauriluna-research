# Remote SEO/GEO Control — M6a diagnostics + deterministic remediation

Status: first M6 repository/CI slice. Real `eduardoyauriluna.com` validation remains M8.

## Purpose

M6 begins by unifying SEO/GEO observability and safe remediation behind the same authenticated Manager gateway already used for Pages, Insights and Research resources.

The operating chain is:

`ChatGPT → authenticated Manager → SEO/GEO inspection → exact finding → deterministic remediation Plan → Preview → Apply → stored + rendered Verify → Rollback`

This slice intentionally does **not** expose arbitrary title/meta/canonical/schema editing. It reuses the existing Diagnostics, Remediation, Executor and Rendered Verifier services.

## Remote scopes

Read requires both:

- `site.diagnostics`
- `seo.read`

Mutation planning/apply requires:

- `operations.apply`
- `seo.write`

Rollback requires:

- `operations.rollback`
- `seo.write`

The existing `seo.read` / `seo.write` scopes are therefore activated as real operating scopes rather than placeholders.

## Unified inspection

### Site

`GET /research-manager/v1/seo-geo/site`

Returns:

- readiness state and summary;
- current diagnostics findings;
- auto-remediable findings;
- manual/evidence-only findings;
- counts of managed Pages, Insights, Lines, Outputs, Projects, Software and Datasets;
- the rendered verification contract used by the Manager.

### Resource

`GET /research-manager/v1/seo-geo/resource`

Supported types:

- `page` with Theme Page key + language;
- `insight` by post ID;
- `line` by post ID;
- `output` by post ID;
- `project` by post ID;
- `software` by post ID;
- `dataset` by post ID.

For Theme Pages the service combines structured Page inspection with HTTP-level rendered verification.

For published Research records it combines the existing resource editor inspection with `Rendered_Verifier::verify_record()`.

The rendered verifier checks:

- HTTP 200;
- self canonical;
- HTML language;
- current-language hreflang;
- Open Graph URL;
- JSON-LD presence;
- expected schema type for structured Research records.

Theme Pages continue to require the existing bilingual alternate contract.

Draft/future/private Research records are inspectable at stored-state level but their rendered verification is explicitly skipped rather than pretending that a public URL was verified.

## Deterministic remediation

M6a exposes one exact operation:

`seo-remediate`

It may target only findings that the shared `Remediation` service already marks `auto_remediable`.

Current deterministic candidates include:

- missing/structurally inconsistent Theme-owned Pages;
- static Home routing (`front-page`);
- native language contract.

Findings requiring manual configuration or evidence review remain blocked and are returned as residual actions. M6a does not widen the Remediation service merely to make a remote action possible.

## Exact-plan lifecycle

### Plan

`POST /seo-geo/remediation/plan`

Input:

- `check_id`
- optional human-readable `intent`

The Manager:

1. resolves the finding from current diagnostics;
2. requires it to be auto-remediable;
3. delegates plan creation to the shared Remediation service;
4. delegates Preview to the shared Executor;
5. fingerprints the exact pre-Apply state of every planned action;
6. stores a 15-minute remote plan tied to the authenticated connection.

Preview never mutates WordPress.

### Apply

Apply requires explicit confirmation.

Immediately before Apply, the Executor re-previews the stored Manager Plan and M6 compares the exact action baseline fingerprint with the one captured at Plan time.

If the target changed after Preview, Apply returns `stale_revision` and no mutation is performed.

### Verify

Verify requires:

1. generic Executor stored-state verification; and
2. the original diagnostic check to now report `pass`.

When rendered verification is requested and the finding maps to a Theme Page, the Manager additionally verifies the relevant Page in every active Research language using the HTTP-level Rendered Verifier.

Findings without a single meaningful public Page are marked rendered-verification `not_applicable`; they are never falsely claimed as rendered-verified.

### Rollback

Rollback delegates to the existing snapshot-backed Executor and then re-previews the same plan.

The operation reports `rollback_restored_baseline=true` only when the exact pre-Apply action baseline matches the original Preview baseline.

## Safety boundary

M6a deliberately does not provide:

- arbitrary post-meta mutation;
- arbitrary canonical URL mutation;
- arbitrary JSON-LD injection;
- arbitrary robots/indexability flags;
- arbitrary internal-link HTML insertion;
- arbitrary WordPress option access;
- PHP/SQL/filesystem/shell execution.

Advanced deterministic metadata/canonical/hreflang/schema/indexability operations belong to later M6 slices and must each be expressed as bounded Manager operations backed by shared services.

## Fresh-WordPress CI acceptance

`Research Manager Remote M6 SEO GEO Quality` installs WordPress + Research Theme + Manager from zero and proves:

1. `seo.read` is independently enforced;
2. M6 capability discovery is present;
3. unified rendered inspection succeeds for Research Home;
4. unified rendered inspection succeeds for a verified public Research Line with expected `CreativeWork` schema;
5. static-front-page routing is deliberately degraded;
6. site diagnostics exposes `front-page` as an auto-remediation candidate;
7. remediation Preview is non-mutating;
8. request-ID idempotency replays the same Plan;
9. explicit confirmation is required;
10. external target drift after Preview is rejected as `stale_revision`;
11. a fresh plan repairs the front-page routing;
12. stored diagnostic Verify passes;
13. rendered Home Verify passes for EN and ES;
14. site readiness recovers;
15. rollback restores the exact deliberately broken baseline;
16. the test finally restores the canonical Greenfield seed state.

## Remaining M6

After M6a, the roadmap still requires bounded implementations/acceptance for:

- deterministic metadata/canonical/hreflang/schema/indexability remediation where the Theme contract permits it;
- internal-link remediation beyond existing Research relationship operations;
- GEO/entity/direct-answer/provenance diagnostics;
- aggregate post-remediation readiness with residual manual/evidence actions clearly reported.

No CI result in this document is evidence of live deployment on `eduardoyauriluna.com`; that proof remains M8.
