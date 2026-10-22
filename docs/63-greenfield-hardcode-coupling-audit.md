# Greenfield Hardcode & Coupling Audit

## Status

**Initial architectural audit.**

This document applies `ENGINEERING_RULES.md` and `ENGINEERING_GREENFIELD_RULE.md` to the current Research Manager / Research Theme implementation.

The objective is **not** to turn this Greenfield implementation into Site Manager, a generic Brownfield discovery engine, or an unrestricted WordPress proxy.

The objective is to distinguish intentional Greenfield specialization from accidental coupling, duplicated contracts and stale implementation assumptions while preserving the accepted production behavior of `eduardoyauriluna.com`.

## 1. Audit principle

Greenfield is allowed to know the architecture we deliberately created.

Therefore, a hardcode is not automatically a defect. Every finding is classified as one of:

- **REMOVE** — accidental instance coupling, stale value or obsolete architectural assumption.
- **CENTRALIZE** — valid Greenfield knowledge duplicated across multiple layers and requiring one authoritative contract/registry.
- **KEEP DOMAIN** — deliberate Research/Greenfield specialization that improves semantic correctness and automation.
- **KEEP SECURITY** — deliberate closed boundary that must remain fail-closed.
- **EXTRACT LATER** — a proven pattern worth comparing with Brownfield/Site Manager after both tracks have independent production evidence.

No classification in this document authorizes an automatic storage migration, product rename or production mutation.

## 2. Target separation

The desired Greenfield separation is:

```text
Greenfield governance engine
↓
Greenfield Theme Contract
↓
Research vertical contract / registries
↓
Eduardo instance blueprint + WordPress state
↓
WordPress production
```

### 2.1 Greenfield governance engine

Owns reusable execution guarantees such as:

- authenticated credentials and scopes;
- request validation and idempotency;
- audit;
- plans and previews;
- apply;
- verification;
- snapshots and rollback;
- stale-state protection;
- bounded operation records.

It must not need to know Eduardo's name, biography or canonical domain.

### 2.2 Greenfield Theme Contract

Owns the deterministic frontend/site contract we created, including:

- active preset identity and version;
- pages and routes;
- languages and default language;
- structured page models/slots;
- navigation contract;
- design presets;
- component variants;
- UI-copy contract;
- media surfaces;
- SEO/GEO surface;
- frontend-authority rules;
- storage aliases needed for compatibility.

This is deterministic Greenfield knowledge, not Brownfield discovery.

### 2.3 Research vertical contract

Owns Research-specific semantics such as:

- researcher identity;
- Research Lines;
- outputs/publications;
- research projects;
- research software;
- datasets;
- evidence-sensitive academic claims;
- scholarly metadata and identifiers;
- research resource relationships;
- Research-specific validation and risk policy.

These semantics are a strength of the Greenfield implementation and are not portability defects.

### 2.4 Eduardo instance

Owns exact instance data such as:

- `Eduardo Jose Yauri Luna`;
- `eduardoyauriluna.com`;
- biography/profile copy;
- current research agenda;
- current content;
- concrete research lines;
- instance-specific legal/contact copy;
- verified identifiers and evidence as they become available.

Instance data may legitimately exist in the repository and WordPress. It must simply not become an implicit architectural constant in reusable execution logic when a cleaner boundary exists.

## 3. Audit findings

### 3.1 Exact researcher identity fallback

Current identity logic falls back directly to `Eduardo Jose Yauri Luna` when stored identity and the WordPress site name are empty.

**Classification: REMOVE.**

The current site is Eduardo-specific, but reusable identity execution logic should resolve identity from authoritative instance state, for example:

```text
stored verified identity
→ instance blueprint/site configuration
→ WordPress site identity
→ explicit missing-state error/default policy
```

It should not contain an embedded person's name as the final execution fallback.

The evidence requirement, stale-preview protection and rollback around identity changes remain **KEEP DOMAIN + KEEP SECURITY**.

### 3.2 Exact domain inside Eduardo blueprint

`blueprints/eduardo-research.json` contains `https://eduardoyauriluna.com` and Eduardo-specific copy.

**Classification: KEEP as instance data.**

The defect is not that the instance blueprint contains Eduardo's domain. The defect would be treating that value as universal runtime truth.

Runtime site status should distinguish:

- actual WordPress runtime URL (`home_url()` / site state); and
- declared/expected canonical domain from the Eduardo instance blueprint.

### 3.3 Bundled blueprint treated as universal canonical blueprint

`class-research-manager-blueprint-store.php` defaults to `blueprints/eduardo-research.json`, falls back to Eduardo's exact name and exports an Eduardo-specific filename.

**Classification: CENTRALIZE + partial REMOVE.**

The current blueprint may remain the default Eduardo instance blueprint, but the active blueprint descriptor/path/name should have one authoritative source. The embedded person-name fallback should be removed.

No immediate physical path or filename migration is required; preserving existing paths first is safer.

### 3.4 Theme stylesheet and preset identity

The Manager recognizes the Greenfield Theme through `eduardo_research_preset()`, preset id `research`, minimum preset version and stylesheet `eduardo-research`.

**Classification: KEEP DOMAIN, but CENTRALIZE access.**

Greenfield is intentionally allowed to require our Theme/contract. We do not need Brownfield theme discovery here.

However, Manager classes should not independently know multiple Theme function names, stylesheet checks and storage details. Access should converge through one trusted Greenfield Theme Contract service.

### 3.5 Page architecture and Research surfaces

The Theme preset intentionally defines:

- home;
- about;
- research;
- publications;
- projects;
- software;
- datasets;
- CV;
- insights;
- contact;
- privacy policy;
- legal notice.

**Classification: KEEP DOMAIN.**

This is the known Research website architecture we deliberately created.

Duplicated page/surface allowlists in Plan, Manager capabilities or other services are **CENTRALIZE** findings. The Theme/preset contract should be authoritative.

### 3.6 Page target and slot restrictions

Page hydration permits only registered slots and page-target slots resolve only to registered Theme pages. Page slug changes and destructive deletion are deliberately restricted.

**Classification: KEEP SECURITY.**

Do not replace this with arbitrary URLs, arbitrary options or unrestricted Gutenberg/DOM editing.

Identity-owned slots excluded from generic Page hydration are **KEEP DOMAIN**, but their ownership should be declared once rather than repeated procedurally.

### 3.7 Page storage keys and `_es` special casing

Current Page storage uses patterns such as:

- `eduardo_research_model`;
- `eduardo_research_model_es`;
- `eduardo_research_surface_<key>`;
- `eduardo_research_surface_<key>_es`.

**Classification: CENTRALIZE.**

The current physical option names must remain compatible with production for now. The improvement is to resolve them through one storage-key contract instead of reconstructing naming rules across services.

This is not a request to migrate production data immediately.

### 3.8 EN/ES architecture versus Spanish implementation branches

The current site is intentionally bilingual EN/ES.

**EN/ES as the active Greenfield language contract: KEEP DOMAIN.**

However, several implementations directly special-case Spanish via:

- `/es/`;
- `_es` suffixes;
- `headline_es`;
- direct checks such as `if ('es' === $language)`;
- default `'en'` literals spread across services.

**Classification: CENTRALIZE.**

The Theme should expose one language registry containing default language, locale, prefix, labels and compatibility storage aliases. Services should ask that registry rather than independently knowing that Spanish is the only secondary language.

This does not imply adding more languages now.

### 3.9 Research resource taxonomy

The system intentionally knows:

- Research Lines;
- Research Outputs;
- Research Projects;
- Research Software;
- Research Datasets;
- Insights.

**Classification: KEEP DOMAIN.**

The taxonomy is valid Research specialization.

The mappings between Manager resource names, WordPress post types, metadata fields, scopes and creation/update schemas are currently repeated in Contract, Resource Inventory, Plan, Executor and Theme helpers.

**Repeated mappings: CENTRALIZE** into a Research Resource Registry.

### 3.10 Research evidence/risk semantics

The Manager treats claims such as degrees, affiliations, DOI, grants, awards, publication metadata, research outcomes and other academic evidence as sensitive and may require evidence before Apply.

**Classification: KEEP DOMAIN + KEEP SECURITY.**

This is a core advantage of the Research Greenfield path and must not be weakened to look more generic.

### 3.11 Mutation option/meta allowlists

Plan restricts writable options, post fields, action types and metadata.

**Classification: KEEP SECURITY.**

The concept must remain closed and fail-closed.

However, allowlists duplicated from Theme/storage/resource contracts should be **CENTRALIZE**.

Where metadata allowance is currently expressed broadly through prefix checks such as `_eduardo_research_*` / `_research_*`, the preferred direction is an exact trusted registry or ownership contract rather than widening the proxy surface.

### 3.12 Design presets

Greenfield Design intentionally exposes bounded dimensions such as palette, typography and density, with accessibility checks and no arbitrary CSS/font URLs.

**Bounded Design model: KEEP DOMAIN.**

**No arbitrary CSS/font URL + accessibility validation: KEEP SECURITY.**

The allowed dimensions/presets should be obtained from the Theme design contract rather than separately re-declared in Manager execution code: **CENTRALIZE**.

The safe Preview/Apply/Verify/Rollback lifecycle is **EXTRACT LATER** for eventual comparison with Brownfield.

### 3.13 Component variants

Current variants cover known Theme dimensions such as hero, sections, cards and collections, with bounded generated classes and protections against arbitrary classes/CSS/HTML or content reordering.

**Known Theme variants: KEEP DOMAIN.**

**Safety constraints: KEEP SECURITY.**

The exact dimension list and class contract should have one Theme source: **CENTRALIZE**.

Lifecycle mechanics are **EXTRACT LATER**.

### 3.14 Navigation

Navigation correctly uses registered Theme targets, forbids arbitrary URLs and implements stale-state protection plus verified rollback.

**Registered targets / no arbitrary URLs / lifecycle: KEEP SECURITY.**

Direct knowledge of multiple `eduardo_research_navigation_*` functions across Manager code should be **CENTRALIZE** behind the Greenfield Theme Contract.

The lifecycle itself is **EXTRACT LATER**.

### 3.15 Scopes and credentials

Remote scopes are explicitly enumerated and credentials are revocable/rotatable/scoped.

**Classification: KEEP SECURITY.**

Names such as `eduardo_research_manager_*` options and `erm*` token prefixes are naming/compatibility concerns, not urgent architecture defects.

They should be revisited only during a controlled product rename/migration with backward compatibility: **CENTRALIZE / P2**.

### 3.16 Manager capability metadata contains stale Theme version

The v1 capability reconciliation still reports:

```text
production_pair.theme = 1.0.0
```

while the accepted production Theme is newer.

**Classification: REMOVE.**

Runtime capability/status output must not embed a historical Theme version. It should read the active contract/theme version.

### 3.17 Capability/surface lists are duplicated

The Manager v1 capability layer re-declares surfaces such as identity, pages, navigation, insights, research objects, translations, evidence, media, SEO/GEO and Design.

**Classification: CENTRALIZE.**

Capability reporting should be assembled from authoritative registered surfaces/contracts rather than maintained as another hand-written architectural list.

### 3.18 Generic WordPress portability layer in Manager 0.11

The current portability layer can expose a read-only baseline on a WordPress installation without the Research Theme.

Under the new Greenfield/Brownfield separation, this must **not** become a Brownfield product-development path inside this repository.

**Classification: REVIEW RESIDUE / EXTRACT LATER.**

It may remain temporarily for diagnostics, history or comparison, but no new generic WordPress governance should be added here solely to compete with Site Manager.

### 3.19 README still describes extraction into Site Manager

The current repository README still says that Research Manager is transitional, that reusable management capabilities are progressively extracted to Site Manager and that Research Manager code will be removed as Site Manager reaches parity.

That conflicts with the later mandatory Greenfield engineering decision that both tracks evolve independently until evidence-based comparison.

**Classification: REMOVE / UPDATE IMMEDIATELY.**

The README must describe the current independent Greenfield authority and treat Site Manager as a separate Brownfield track, not as the predetermined replacement.

## 4. What must explicitly remain hard-bounded

The audit must never be used as justification to introduce:

- arbitrary PHP execution;
- arbitrary JavaScript execution;
- arbitrary REST proxying;
- arbitrary SQL;
- arbitrary shell/filesystem access;
- arbitrary WordPress option/meta mutation;
- arbitrary CSS or class injection;
- arbitrary navigation URLs;
- uncontrolled destructive deletion;
- caller-selected MCP tools or backend routes;
- bypass of evidence-sensitive academic validation;
- bypass of stale-state/idempotency protections;
- mutation completion without required verification.

These boundaries are product strengths, not portability defects.

## 5. Remediation priorities

### P0 — safe corrections with no storage migration

1. Replace the stale hardcoded Theme `1.0.0` capability value with the live Theme contract/version.
2. Remove the exact Eduardo-name fallback from reusable identity execution logic and resolve instance identity through authoritative state.
3. Distinguish actual runtime URL from blueprint-declared/expected canonical domain.
4. Establish one active blueprint descriptor while preserving the current Eduardo blueprint path and data.
5. Read default language from the Theme contract instead of scattered `'en'` literals where doing so is behavior-preserving.
6. Update stale README statements that define Site Manager as the predetermined replacement.

### P1 — contract consolidation

1. Introduce one Greenfield Theme Contract boundary consumed by Manager services.
2. Centralize the language registry/resolver while preserving EN/ES behavior and current storage compatibility.
3. Centralize storage-key resolution for existing options/meta; do not migrate keys yet.
4. Create a Research Resource Registry for resource type → post type → metadata/schema → scope ownership.
5. Generate exact mutation allowlists from trusted registries/contracts where possible.
6. Resolve Design and component-variant dimensions from Theme public contracts.
7. Declare page-target ownership and identity-owned slot rules once.
8. Derive capability reporting from registered/available contracts rather than a manually duplicated surface list.

### P2 — naming and migration only after architecture stabilizes

Do not mass-rename now:

- plugin folder;
- PHP classes;
- constants;
- text domain;
- WordPress option names;
- metadata names;
- REST namespace;
- operation/token prefixes;
- Theme functions.

The eventual Research Manager product rename must have an explicit compatibility/migration strategy and must not jeopardize the accepted production path.

## 6. Proposed implementation sequence

The safest sequence is:

```text
Audit/document
→ remove stale documentation/runtime constants
→ introduce read-only centralized contract facades
→ prove parity with current production behavior
→ migrate internal callers incrementally
→ keep physical storage/API compatibility
→ only later consider naming/storage migration
```

At every step, `eduardoyauriluna.com` remains the production authority and existing accepted governance must continue working.

## 7. Greenfield versus Brownfield boundary

This audit does not change the agreed product split:

```text
Greenfield
known architecture → build → optimize → govern

Brownfield / Site Manager
unknown existing site → discover → understand → adapt → govern
```

The Greenfield implementation should become cleaner and less accidentally coupled without sacrificing the value of knowing its architecture.

Site Manager should independently solve discovery/adaptation for existing client sites.

Cross-project extraction/convergence comes only later, after both have meaningful production evidence.

## 8. Immediate conclusion

The largest technical problem is **not the number of `eduardo_research_*` strings by itself**.

The real problem is that four kinds of truth are currently interleaved:

```text
execution/security guarantees
Research vertical semantics
Theme/site contract
Eduardo instance data
```

The first Greenfield refactor should separate those responsibilities while retaining the current deterministic Theme-owned architecture and all security boundaries.

That gives us a stronger independent Greenfield product without prematurely turning it into the Brownfield Site Manager architecture.
