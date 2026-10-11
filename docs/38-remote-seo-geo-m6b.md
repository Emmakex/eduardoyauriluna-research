# M6b — Structured SEO/GEO action planning

Status boundary: repository/CI implementation only. Nothing in this document claims deployment or live validation on `eduardoyauriluna.com`; that remains M8.

## Objective

M6b turns SEO/GEO inspection into an operating decision without creating a second editor or an unrestricted SEO metadata API.

The flow is:

`ChatGPT → M6 action planner → bounded source finding → existing Manager gateway → Apply/Verify → M6 reanalysis`

The planner must identify the structured source that controls the public SEO/GEO output and route remediation to the Manager service that already owns that source.

## Non-negotiable architecture

The Research Theme remains the authority that derives canonical URLs, hreflang, descriptions, Open Graph metadata and JSON-LD from structured WordPress resources and Theme contracts.

M6b therefore does **not** introduce:

- arbitrary post-meta writes;
- arbitrary canonical/hreflang/schema writes;
- arbitrary HTML injection;
- unrestricted WordPress REST proxying;
- direct SQL/PHP/filesystem mutation;
- auto-application of planner recommendations.

Instead, every planner action names an existing bounded Manager gateway and operation. Examples:

- Page lead/summary gaps → `pages / page-slots-update`;
- Insight title/excerpt/research-line relationship gaps → `insights / insight-update`;
- Insight EN/ES pairing → `insight-translations / insight-pair`;
- Research Line evidence/question/topics/methods gaps → `research-lines / line-update`;
- Output/Project/Software/Dataset structured gaps → `research-objects / object-update`;
- Research object translation pairing → `research-translations / translation-pair`;
- deterministic readiness findings already supported by M6a → `seo-geo / seo-remediate`.

## Remote surface

Authenticated read endpoint:

`GET /wp-json/research-manager/v1/seo-geo/resource/actions`

Parameters:

- `type`: `page | insight | line | output | project | software | dataset`;
- `key`: Theme Page key for Page resources;
- `id`: managed WordPress resource ID for non-Page resources;
- `language`: Page language where relevant.

Required scopes:

- `site.diagnostics`;
- `seo.read`.

The endpoint returns:

- target identity;
- current rendered/stored inspection status;
- a summary of `pass`, `fail`, `advisory` and `not-applicable` checks;
- SEO/GEO/provenance dimensions;
- structured reasons;
- bounded action descriptors;
- the required additional input/evidence for each action;
- `auto_apply=false`;
- `direct_meta_mutation=false`.

## Planner action contract

Each actionable finding identifies:

- `gateway` — the existing Manager control surface;
- `operation` — the exact bounded operation family;
- `target` — resource identity and source field/slot;
- `required_input` — values, counterpart IDs or evidence references still needed;
- `requires_evidence` — whether the downstream evidence gate must be satisfied;
- `auto_apply=false` — the planner never bypasses Preview/confirmation;
- an explicit reason that the existing gateway must be used instead of direct SEO metadata writes.

The action descriptor is a routing contract. The downstream gateway still owns normalization, Preview, stale detection, evidence gates, Apply, Verify and Rollback.

## Current checks

### Pages

- structured description source (`hero-lead` for Home, `lead` for other Theme Pages);
- rendered SEO contract readiness.

### All managed record types

- title;
- excerpt/description source;
- rendered contract for public records;
- bilingual EN/ES counterpart where supported.

### Insights

- verified Research Line relationship / research entity graph.

### Research Lines

- evidence-verified provenance;
- central direct-answer research question;
- structured topic entities;
- structured methods context.

### Publications / Outputs

- Research Line relationship;
- verified output/schema classification;
- authorship;
- publication date;
- DOI provenance when a DOI is stored.

### Projects

- Research Line relationship;
- direct-answer project question;
- structured methods context.

### Research Software

- Research Line relationship;
- repository/source URL;
- programming-language metadata;
- DOI provenance when present.

### Datasets

- Research Line relationship;
- repository/landing context;
- formats;
- explicit provenance;
- DOI provenance when present.

## Acceptance contract

Fresh-WordPress CI must prove composition with previous milestones, not merely inspect planner JSON.

The M6b acceptance deliberately creates two source-level gaps:

1. a Theme Page with an empty description source;
2. a Research Output with an empty excerpt.

For each gap CI must prove:

1. M6b detects the finding;
2. the finding points to the correct existing Manager gateway;
3. no direct metadata mutation is proposed;
4. the recommended downstream operation is executed through its existing authenticated lifecycle;
5. M6b reanalysis changes the finding to `pass`;
6. the downstream operation can be rolled back;
7. the fresh WordPress installation returns to its baseline.

CI also proves independent `seo.read` enforcement and capability discovery.

## What M6b does not yet close

M6 remains open after this slice. Later slices must still address, where justified by the current Theme contract:

- explicit indexability/robots diagnostics and bounded remediation;
- broader internal-link graph diagnostics/remediation beyond existing Research Line relationships;
- additional GEO/direct-answer/entity/provenance checks that can be objectively derived from structured sources;
- aggregate post-optimisation readiness reporting;
- the real `eduardoyauriluna.com` operating proof in M8.

Any future M6 capability must preserve this rule: correct the authoritative structured source or deterministic site contract, never create a competing arbitrary SEO metadata layer.
