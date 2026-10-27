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
