# Selective Research Manager PR gates

## Goal

Keep pull-request CI fast enough to support iterative Research Manager development without weakening the accepted security and regression contract.

## Policy

Historical feature suites must not watch the entire Research Manager plugin or Research Theme tree on pull requests when their acceptance surface is bounded to a small set of owned files.

For pull requests:

- each historical workflow watches only the implementation and Theme contract files it owns;
- superseded runs for the same workflow and pull request are cancelled with a workflow-scoped concurrency group;
- current feature acceptance remains mandatory for the feature being changed;
- security-sensitive scope/idempotency/stale-revision workflows remain independently triggered by their owned security surfaces.

For `push` to `main`:

- existing broad plugin/theme path coverage is intentionally retained where it is already part of a workflow's regression role;
- historical suites therefore continue to produce comprehensive post-merge regression evidence before release/deployment decisions.

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

The second migration applies the same boundary to the remaining high-cost historical Manager gates that were still activated by unrelated Media work.

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

These PR gates now follow their actual implementation dependencies rather than the full plugin/theme trees. Their `push` rules remain intentionally broad on `main`, so a merged Research Manager or Theme change still receives comprehensive historical regression evidence before any release/deployment decision.

## Phase 3 — Page authoring

Page Editor and Page Editor Admin now watch the Page/contract surfaces they own and cancel superseded pull-request runs. The validation PR instantiated only those two workflows plus the global WP-CLI fallback.

## Phase 4 — Local editorial authoring

The same routing rule now applies to:

- Insight Editor and Insight Admin;
- Research Line Editor and Research Line Admin;
- Research Object Editor and Research Object Admin;
- Translation Editor and Translation Admin;
- Evidence Editor and Evidence Admin.

These migrations changed workflow routing only. Test bodies, evidence gates, rollback semantics and runtime code remain unchanged.

## Phase 5 — Academic Connections

Academic Connections is being separated from whole-plugin pull-request routing. Its PR gate owns only the Connections service/Admin, ORCID adapter and Workspace integration surfaces, while its existing broad `push`-to-`main` rule remains available for post-merge regression evidence. Superseded PR runs are cancelled with workflow-scoped concurrency.

The provider-specific Crossref, OpenAlex, Zenodo, GitHub and Scholar suites should follow the same ownership model instead of being activated by unrelated Media/editorial changes.

## Safety invariants

This optimisation does **not**:

- remove any existing acceptance test;
- change test assertions;
- relax mutation scopes;
- alter request idempotency or stale-revision protection;
- disable full regression on `main`;
- make current-feature acceptance optional;
- let a feature-specific green check substitute for relevant security regression coverage.

New historical suites should use the same ownership rule from the start: narrow PR paths, workflow-scoped cancellation of superseded runs, and broad post-merge evidence where full-regression coverage is required.
