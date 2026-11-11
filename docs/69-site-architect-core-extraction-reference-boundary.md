# Site Architect — protected boundary for independent Core extraction (2026-11-11)

## Status

**Reference boundary documented; Eduardo live runtime unchanged.** This file is governance evidence for the exclusively Eduardo Research repository, **not** the generic Site Architect product source or a second client Blueprint.

Site Architect / Greenfield and Site Manager / Brownfield remain separate products. Site Manager is never a stage in this flow.

## Frozen Eduardo reference

- Reference main on starting this cut: `1c3468ab4af29e30efa5db85b9e2e618af193a2b`.
- Live site: `eduardoyauriluna.com`; Research Theme + legacy Research Manager remain installed and authoritative.
- No WordPress install, Theme, Manager PHP class, option/meta, REST route, MCP catalog, production credential or tenant grant is modified by this documentary cut.
- Existing Research vertical inventory belongs to `Eduardo_Research_Manager_Resource_Registry`; Blueprint delegates resource keys to `vertical_blueprint_resource_keys()`.
- Preserve the exact production legacy compatibility identifiers. Do not mass-rename the plugin.

## Independent Core candidate

The self-contained `site-architect-core-v0.1.0.zip` package was built **outside** this Eduardo-only repository and supplied as a conversation artifact. Archive SHA256:

`02cc336f4d264264d9bbd38c178645b5ecc134125b4a29c8c28c1d860018e1fe`.

Its contents are a standalone, PHP 8.2+ contract-first **kernel candidate**, not a deployable WordPress plugin:

| Layer | Generic product responsibility | Eduardo implementation authority |
|---|---|---|
| Core | Target validation, source revisions, bounded Plan/Apply/stored Verify/Rollback, explicit confirmation, tenant isolation | Accepted Research Manager lifecycle remains running unchanged |
| Vertical | Separate Research/Corporate semantics, policy and evidence vocabulary | Research Registry and academic evidence rules stay in the Research plugin |
| Theme Contract | Pages, languages, registered slots, frontend authority | Eduardo Research Theme preset v6 remains instance-owned |
| Instance Blueprint | Domain, person/site identity, copy, selected languages | `config/research-site-blueprint.json` and Research Theme remain Eduardo-only |

Two distinct **synthetic** site/language scenarios were tested within the separate archive (ES/EN and FR/DE). Package local verification: 41 assertions, PHP syntax green, ZIP contents and member SHA256 verified from a clean directory.

The reusable kernel is an independent implementation of the proven bounded lifecycle contract, **not a byte-for-byte or production-equivalent migration** of the 48KB legacy Research Plan class. Never claim Eduardo is running it, or that it has passed a second-client WordPress deployment.

## Mandatory separation / next product acceptance

1. Create and own a **separate Site Architect product repository**. The GitHub connector available at this checkpoint cannot create repositories; do not add unrelated client or generic product runtime code to this Eduardo repository.
2. Implement a closed WordPress `SlotPort` and durable, atomic, access-controlled `PlanStore`; included in-memory adapters are test doubles, not production storage.
3. Bind the selected Site Contract/Theme and vertical policy without copying Eduardo identity or Research physical storage into Core.
4. Certify independent rendered verification, rollback and security authorization on a clean, disposable WordPress instance with **a different client/Theme**; preserve Eduardo behavior and production baseline.
5. Only then declare the Site Architect reusable package ready for an installable release. Until then, the kernel ZIP remains an **extraction candidate v0.1.0**.

## This cut's acceptance

- Static boundary check: `Eduardo_Research_Manager_Blueprint` must continue using the Research Registry instead of a hardcoded generic universal Research resource list.
- No production source is modified by this PR: only this documentation and one CI workflow.
- PR exact-head green, no main drift, automatic merge and post-merge verification according to the Eduardo engineering rule.
- No WordPress Apply, Deploy, Theme/Manager install, or credentials change is allowed or needed.

## Independent distribution 0.2.0 — completed offline extraction (2026-11-11)

This section supersedes only the earlier v0.1.0 packaging status; it does not supersede the production compatibility constraints.

The independent `site-architect-independent-v0.2.0.zip` distribution, built and tested outside this Eduardo-only repository, now contains:

- **Core**: bounded Greenfield Contract, Blueprint, Plan / Apply / stored Verify / Rollback, stale source and tenant protection, plus compensating restoration after a failed Plan status persistence.
- **WordPress plugin**: installable `site-architect/` root, administrator-only native REST access, one-time bound contract/Blueprint bootstrap, three fixed private options, per-site MySQL/MariaDB advisory write lock, exact registered Theme routes and rendered verification.
- **Starter Theme**: installable `sa-starter/` root, registered Theme-owned slots, explicit locales, same-origin canonical and hreflang links.
- **Instance/vertical boundaries**: fictional ES/CA corporate site and another FR/DE site, no researcher identity, client domain, Research Manager production storage aliases or Site Manager code baked into reusable runtime.
- **Offline internal certification**: 41 Core checks + 9 persistence-compensation checks + 169 WordPress API mock checks + 64 independent HTTP/HTTP-restart regression checks = **283 distinct assertions**; PHP syntax validation of 28 files; same 283 pass after a clean ZIP extraction; ZIP member and SHA256 validation.

Immutable package SHA256:

| Package | SHA256 |
|---|---|
| Full independent source `site-architect-independent-v0.2.0.zip` | `eea6d690dd7b790ad34f99d17221f7bb680184a162559716c35cbc3e85920d40` |
| WordPress plugin `site-architect-wordpress-plugin-v0.2.0.zip` | `6c6b58e11e943628d9deff3cc75614de5f3fece12271c2c44fba19612329a6c8` |
| Starter Theme `site-architect-starter-theme-v1.0.0.zip` | `2ba903a71d727b2dba410c2bb630960afb6d425bdc20253995900f4b97ed3fc9` |

**Acceptance truth:** `PASS_OFFLINE_ONLY`, with `wordpress_core_started=false`, `genuine_wordpress_plugin_activation_certified=false`. The test environment has no WordPress Core or MySQL/MariaDB runtime. The WordPress API calls and rendering have been **mocked**, even though the new plugin and Theme have structurally installable ZIP roots. These tests are not a certification of actual WordPress activation, WP permissions, MySQL locks or real public website delivery.

**Point 2 — extraction/distribution complete within local capability.** The remaining genuine WordPress installation/second Greenfield client acceptance belongs to the separate end-to-end deployment milestone. The GitHub connector at this checkpoint cannot create a product repository, so the independent source and Git bundle are conversation deliverables until a dedicated product repository is created. This Eduardo-only repository retains only this reference; no generic Core is deployed here.

**Eduardo production remains unchanged by the extraction.** No WP Theme/Manager migration, API change, MCP permission update, live content Apply or Site Manager/Brownfield coupling was performed.
