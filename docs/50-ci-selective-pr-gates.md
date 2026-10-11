# Selective Research Manager PR gates

## Goal

Keep pull-request CI fast enough to support iterative Research Manager development without weakening the accepted security, rollback, idempotency, evidence and regression contract.

## Final policy

Historical feature suites must not watch the entire Research Manager plugin or Research Theme tree on pull requests when their acceptance surface is bounded to a small set of owned files.

For pull requests:

- each bounded historical workflow watches only the implementation and Theme contract files it owns;
- superseded runs for the same workflow and pull request are cancelled with a workflow-scoped concurrency group;
- the current feature acceptance gate remains mandatory for the feature being changed;
- security-sensitive scope, idempotency, stale-revision, rollback and mutation-contract workflows remain independently triggered by their owned security surfaces;
- the global WP-CLI fallback gate remains an infrastructure safety net when workflow/bootstrap surfaces change.

For `push` to `main`:

- broad plugin/theme path coverage is intentionally retained where a workflow has a post-merge regression role;
- historical suites therefore continue to produce comprehensive regression evidence before release/deployment decisions;
- package/bundle smoke gates may remain intentionally broad when their purpose is to prove installable release integrity rather than feature ownership.

This creates a two-layer model: **targeted PR evidence for iteration speed** and **broad post-merge evidence for release confidence**.

## Phase 1 — M3/M4

Issue #143 started by narrowing the noisiest historical M3/M4 pull-request workflows and adding `cancel-in-progress` concurrency. Each workflow kept its existing job body and clean-WordPress acceptance test; only PR path ownership and superseded-run behaviour changed.

Phase 1 covers:

- M3 Pages;
- M3 Page remediation;
- M4 Insight mutations;
- M4 Insight pairing;
- M4 Insight relations;
- M4 Insight status;
- M4 Insight scheduling;
- M4 aggregate acceptance.

## Phase 2 — M5/M6/M7/M8

The second migration applied the same boundary to the remaining high-cost historical Manager gates that were being activated by unrelated feature work.

Phase 2 covers:

- M5 Research Lines;
- M5 Research Objects;
- M5 Translations;
- M5 Evidence;
- M5 relationship/public-schema acceptance;
- M5 aggregate acceptance;
- M6 structured action planner;
- M6 indexability/internal-link control;
- M6 SEO/GEO lifecycle;
- M6 aggregate readiness;
- M7 Theme design tokens;
- M7 component variants;
- M7 aggregate design readiness;
- M8 live Greenfield regression.

The Phase 2 validation PR instantiated 14 migrated historical gates plus the WP-CLI fallback rather than the roughly 60-workflow matrix seen before the optimisation.

## Phase 3 — Page authoring

Page Editor and Page Editor Admin now watch the Page/contract surfaces they own and cancel superseded pull-request runs. The validation PR instantiated only those two workflows plus the global WP-CLI fallback.

## Phase 4 — Local editorial authoring

The same ownership rule now applies to:

- Insight Editor and Insight Admin;
- Research Line Editor and Research Line Admin;
- Research Object Editor and Research Object Admin;
- Translation Editor and Translation Admin;
- Evidence Editor and Evidence Admin.

These migrations changed workflow routing only. Test bodies, evidence gates, rollback semantics and runtime code remained unchanged.

## Phase 5 — Academic Connections

Academic Connections was separated from whole-plugin pull-request routing. Its PR gate owns the Connections service/Admin, ORCID adapter and Workspace surfaces it exercises. The validation PR instantiated only Research Connections Quality plus the global WP-CLI fallback.

## Phase 6 — Crossref and OpenAlex providers

Crossref and OpenAlex provider suites were narrowed to their provider-specific adapters/Admin/runtime contracts. The validation PR instantiated only Research Crossref Quality, Research OpenAlex Quality and the WP-CLI fallback.

## Phase 7 — Zenodo provider

Zenodo was narrowed to its provider-specific surfaces. The validation PR instantiated only Research Zenodo Quality plus the WP-CLI fallback.

## Phase 8 — GitHub provider

GitHub provider CI was narrowed to its provider-specific surfaces. The validation PR instantiated only Research GitHub Quality plus the WP-CLI fallback.

## Phase 9 — Google Scholar provider

Google Scholar CI was narrowed to its provider-specific surfaces. The validation PR instantiated only Research Google Scholar Quality plus the WP-CLI fallback.

## Phase 10 — Blueprint services

The historical Blueprint Store, Hydration and Relations PR gates were narrowed to the canonical blueprint and bounded Manager/Theme services they actually exercise. Workflow-scoped cancellation was added while their acceptance bodies remained unchanged.

The validation PR instantiated exactly:

- Research Blueprint Store Quality;
- Research Blueprint Hydration Quality;
- Research Blueprint Relations Quality;
- the global WP-CLI fallback.

All four passed.

## Phase 11 — Readiness remediation

Research Manager Readiness Remediation was narrowed from the whole Manager tree to Remediation, Diagnostics, Executor, Snapshot, Plan, Contract and Page ownership surfaces. Its Preview → Apply → Verify → Rollback acceptance body was preserved.

The validation PR instantiated only Research Manager Readiness Remediation plus the WP-CLI fallback, and both passed.

## Phase 12 — Historical Greenfield gates

Research Greenfield Admin Quality, Research Greenfield CLI Quality and Research Greenfield Pipeline Quality were narrowed to their owned Greenfield, canonical blueprint, pipeline/phase and bounded Theme contract dependencies.

The validation PR instantiated exactly those three Greenfield suites plus the global WP-CLI fallback, and all four passed.

`Research Greenfield Bundle Quality` remains intentionally broad because it is a package/release smoke gate rather than a feature-owned historical suite.

## Final gate classification

### Targeted PR-required gates

Use narrow PR paths and workflow-scoped `cancel-in-progress` for:

- historical feature/editor/admin suites with bounded implementation ownership;
- provider-specific integrations;
- Blueprint Store/Hydration/Relations;
- readiness remediation;
- Greenfield Admin/CLI/Pipeline;
- current feature acceptance for the change under development;
- relevant security-sensitive mutation gates on their owned surfaces.

### Intentionally broad gates

Keep broad coverage when the gate exists to prove a cross-cutting property rather than one bounded feature, especially:

- comprehensive `push`-to-`main` regression evidence;
- package/bundle/installability smoke checks such as Research Greenfield Bundle Quality;
- core/bootstrap or security contract gates whose correctness genuinely spans the Manager control plane.

A broad gate must therefore have an explicit cross-cutting reason. New feature-specific workflows should not default to whole-plugin or whole-theme PR routing.

## Safety invariants

This optimisation does **not**:

- remove any existing acceptance test;
- change test assertions;
- relax mutation scopes;
- alter request idempotency or stale-revision protection;
- weaken Preview → Apply → Verify → Rollback semantics;
- disable comprehensive regression on `main`;
- make current-feature acceptance optional;
- let a feature-specific green check substitute for relevant security regression coverage.

## Closure evidence for #143

The original bottleneck was reproduced with feature PRs that triggered roughly 60 historical workflows. The migration then moved the major historical Manager suites to owned PR paths in incremental, independently validated batches. Each later batch produced only the workflows intentionally edited plus the global WP-CLI fallback, while broad post-merge regression remained available.

Issue #143 is complete when this policy is merged: selective PR routing, superseded-run cancellation, preserved acceptance bodies, explicit broad-gate exceptions and documented PR-vs-post-merge responsibilities are all in place.
