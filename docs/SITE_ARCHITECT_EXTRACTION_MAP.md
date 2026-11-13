# Site Architect extraction map

## Status

This document is an extraction/classification map for the current Greenfield implementation in `eduardoyauriluna-research`.

It does **not** turn this repository into a multi-client product. `ENGINEERING_RULES.md` remains authoritative: this repository exists only for Eduardo's website and research/doctoral infrastructure.

The purpose of this map is to identify which proven concepts may later become the reusable **Site Architect** product without accidentally exporting Eduardo-specific instance data or the Research vertical as universal architecture.

## Canonical classification

Every relevant runtime responsibility is classified as one of:

- **CORE** — reusable Greenfield governance mechanics that should not depend on one person, domain or vertical.
- **VERTICAL** — Research-specific semantics and resources intentionally used by the current researcher implementation.
- **INSTANCE** — concrete Eduardo site identity, domain, content, language selection and Theme/Blueprint values.
- **LEGACY-COMPAT** — accepted production identifiers/storage that must remain until an explicit compatibility migration is designed and accepted.
- **MIXED** — a current file/component that contains more than one responsibility and therefore needs a seam before any extraction.

A file being `MIXED` is not itself a defect. It is an extraction boundary.

## CORE candidates

These responsibilities are reusable in principle, subject to proving that they do not contain hidden Research/instance policy:

- Greenfield mode/lifecycle mechanics;
- mutation plan lifecycle: Preview / Apply / Verify / Rollback;
- checksums, stale-state protection and snapshots;
- bounded executor mechanics;
- remote credentials, request guard and audit mechanics;
- generic resource capability reporting;
- generic Blueprint validation/storage mechanics;
- generic Theme-contract adapter shape;
- generic navigation/media/design/SEO-GEO governance mechanics where policy is supplied by contracts;
- deterministic bootstrap/orchestration mechanics;
- fail-closed authorization and typed mutation infrastructure.

No CORE extraction may weaken scopes, allowlists, evidence gates, stale-state protection, verification or rollback semantics.

## VERTICAL — Research

The following belong to the current Research vertical unless later generalized through an explicit contract:

- `research_line` / lines;
- research outputs/publications;
- research projects;
- research software;
- research datasets;
- Insights / Research Notes where their semantics are Research-specific;
- academic evidence policy and evidence-sensitive scholarly claims;
- ORCID, Crossref, OpenAlex and Zenodo integrations;
- research relations/pairing semantics;
- scholarly metadata and identifiers;
- Research-specific catalogs, statuses, methods and taxonomies;
- Research-specific Blueprint compilation rules.

The current `Eduardo_Research_Manager_Resource_Registry` is therefore a **Research vertical contract**, not Site Architect Core.

## INSTANCE — Eduardo

The following are valid instance data/contracts for `eduardoyauriluna.com` and must not be mistaken for universal Site Architect requirements:

- `Eduardo Jose Yauri Luna`;
- `https://eduardoyauriluna.com`;
- Theme `eduardo-research` and its concrete visual/content model;
- the current Research page set and route values;
- EN/ES as the languages selected for this site;
- Eduardo's biography, research agenda and concrete copy;
- the concrete `config/research-site-blueprint.json` values;
- current Research content records and relationships.

INSTANCE data is allowed in the Theme, Blueprint, content and bounded instance contracts. It must not become an unexplained dependency of reusable Core mechanics.

## LEGACY-COMPAT

The following are production compatibility identifiers, not evidence that the future reusable product must be Eduardo-branded:

- `Eduardo_Research_Manager_*` PHP class names;
- `EDUARDO_RESEARCH_MANAGER_*` constants;
- `eduardo-research-manager` plugin path/text domain;
- accepted REST namespaces/headers/error codes already used in production;
- accepted option/meta keys such as `eduardo_research_*`;
- current physical EN/ES storage aliases such as `_es` where required by production compatibility.

Do not mass-rename these in the Eduardo production implementation. A rename is a compatibility migration, not cleanup.

## MIXED components — extraction seams required

### `class-research-manager-contract.php`

Contains reusable Theme-contract adapter mechanics, but also current-instance assumptions:

- expected preset `research`;
- expected stylesheet `eduardo-research`;
- `eduardo_research_*` Theme function names;
- Eduardo option/meta storage;
- physical EN/ES legacy storage;
- Research line/collection/insight catalogs.

Direction: preserve current behavior while progressively separating generic contract mechanics from Research/instance contract values.

### `class-research-manager-blueprint.php`

Contains generic Blueprint validation/summarization mechanics but currently declares Research resource keys directly.

Direction: Blueprint mechanics should consume the active vertical/site contract rather than define Research resources as universal Core.

### `class-research-manager-greenfield.php`

Contains generic Greenfield readiness/status mechanics but currently exposes Research services directly (`insights`, `lines`, `outputs`, `projects`, `software`, `datasets`).

Direction: orchestration may remain deterministic, but the resource set must be supplied by the active contract/vertical rather than treated as universal Site Architect knowledge.

### `class-research-manager-plan.php`

Contains highly valuable Core safety mechanics, but policy is mixed with Research/instance rules:

- generic checksums, duplicate-target protection, stale plan validation and bounded actions;
- Research creation action types and academic evidence risk;
- Eduardo option/meta allowlists and Theme-owned page assumptions.

Direction: do not weaken the current security boundary. Separate generic plan mechanics from contract-supplied mutation/risk policy only through small behavior-preserving cuts.

### `class-research-manager.php` and plugin bootstrap

Contain both reusable service wiring and Research-specific service registration.

Direction: do not mass-refactor the production bootstrap. First establish explicit Core/Vertical contracts so later extraction can choose the correct services without changing current runtime behavior.

## Canonical future Greenfield model

```text
Requirements of client X
        ↓
Site architecture + Theme created from zero
        ↓
Site Contract / Blueprint specific to client X
        ↓
Site Architect Core + selected vertical contracts/adapters
        ↓
New WordPress installation
        ↓
Governed operation from ChatGPT using known contracts
```

`Emmakex/Site-Manager` is not part of this flow. Site Manager remains the separate Brownfield product for discovering and governing sites that already exist and were not designed by us.

## Extraction sequence

1. **Inventory and classify** current runtime responsibilities as CORE / VERTICAL / INSTANCE / LEGACY-COMPAT / MIXED.
2. **Centralize vertical declarations** so Research resource keys/catalogs are declared by one Research contract instead of repeated inside generic-looking orchestration code.
3. **Create seams, not rewrites**: make mixed components consume explicit contracts while preserving exact Eduardo production behavior.
4. **Keep security fail-closed**: no extraction cut may broaden mutation scopes or reduce evidence/verification/rollback protections.
5. **Prove every cut** with exact-SHA CI and existing acceptance contracts.
6. **Extract later** into a separate reusable Site Architect product only after the boundary is demonstrated inside the Eduardo implementation.

## Immediate first code target

The first safe runtime target is the duplicated declaration of Blueprint/Greenfield Research resource keys.

Goal:

> make the Research vertical registry/contract the single authority for the current Research resource set, while keeping `pages` and other generic mechanics explicitly separated and preserving the exact current Blueprint behavior.

This is a centralization seam, not a product extraction and not a compatibility rename.


## Microcorte #475.11 — Insight execution leaves common Executor (candidate)

The Research Insight creation action (create_insight) targets WordPress post,
but remains Research-specific. Eduardo_Research_Manager_Research_Insight_Execution
now owns read/create/restore/desired_value, moved from the 0.11.1 Executor.

The generic Executor still owns Plan validation, Preview/Apply/Verify/Rollback,
permissions, stale-state and snapshots. The new adapter requires the exact
research Theme id, the concrete Research mutation policy and an allowed
create_insight action. No Registry-driven or prefix-driven grants are added.

Historical parity targets: post type, metadata keys, readback/defaults, slug
and token collision semantics, provenance-only rollback, route refresh and
error codes. The post_meta/post_field 49-key legacy authorization remains intact.

Pending: complete extraction of remaining Research services, independent
installable packaging of the REAL current product, isolated Theme Research
clone parity, and reversible Eduardo cutover only after equivalence.
Site Architect 0.11.1 in Eduardo production is not changed by this cut.


## #475.12 — Theme-owned Blueprint resources and Greenfield services

The current Site Architect 0.11.1 Core Blueprint, Compiler and Greenfield services
consume an explicit `Site_Architect_Blueprint_Resource_Provider` rather than
calling `Eduardo_Research_Manager_Resource_Registry` directly or treating a
global Research service map as universal.

The real Research provider `Eduardo_Research_Manager_Research_Blueprint_Resources`
declares the exact six existing vertical Blueprint keys and their labels,
binds the seven existing service objects including Pages and owns the
Research-only Line slug/language read-only idempotency preflight.

Security: a provider belongs to one exact Theme id; Blueprint and Greenfield
must agree on their resource catalogs; missing/misaligned bindings fail closed.
Describing or binding a resource is not authorization to mutate it.
Plan/Executor, ownership policy, evidence, snapshots, verification and
rollback remain unchanged and mandatory.

Compatibility: the historical `research_services()` facade, Research
Resource Registry, legacy class names/options/meta and the 0.11.1 plugin
header remain in place. The Theme Research is still the source of rendering.

The isolated PHP 8.1/8.2 contract test verifies exact Research resource
order, labels, Blueprint summary, evidence and Plan compile shape, Line
idempotency and positive/negative scenarios with an independent second Theme.
Existing WordPress Greenfield/Blueprint tests are also required to pass.

Not yet an independent installable distribution, WordPress-clone parity or
a production cutover. Continue under issue #475 before extending to a client.


## #475.13 — Explicit Theme-owned pairing and relation phases

Greenfield Pipeline now depends on `Site_Architect_Greenfield_Relationship_Phase`
for the vertical translation-pairing and resource-relation phases instead of
requiring concrete Research classes. The exact Research adapters implement
this narrow, bounded lifecycle contract and retain their existing EN/ES,
Research Line, evidence, Plan/Verify, snapshot and rollback behavior.

An explicit Blueprint resource provider supplies Theme ownership. Preview,
Apply and Rollback reject missing or cross-Theme relationship adapters **before**
calling any phase; a provider/adapter match is not a write grant.

The standalone PHP 8.1/8.2 contract regression covers positive Research and
independent second-Theme orchestration, rejected cross-Theme and empty authority,
and zero phase calls on rejection. Production 0.11.1 and legacy identifiers stay
unchanged. This does not certify an independent installable ZIP or clone parity.
