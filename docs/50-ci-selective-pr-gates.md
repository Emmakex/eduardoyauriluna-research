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

- existing broad plugin/theme path coverage is intentionally retained;
- historical suites therefore continue to produce comprehensive post-merge regression evidence before release/deployment decisions.

## Phase 1

Issue #143 starts by narrowing the noisiest historical M3/M4 pull-request workflows and adding `cancel-in-progress` concurrency. This is deliberately incremental: each workflow keeps its existing job body and clean-WordPress acceptance test; only PR path ownership and superseded-run behaviour change.

The initial set covers:

- M3 Pages;
- M3 Page remediation;
- M4 Insight mutations;
- M4 Insight pairing;
- M4 Insight relations;
- M4 Insight status;
- M4 Insight scheduling;
- M4 aggregate acceptance.

## Safety invariants

This optimisation does **not**:

- remove any existing acceptance test;
- change test assertions;
- relax mutation scopes;
- alter request idempotency or stale-revision protection;
- disable full regression on `main`;
- make current-feature acceptance optional.

Further M5–M8 path ownership should be migrated in the same pattern only after confirming each workflow's owned implementation surface.