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
