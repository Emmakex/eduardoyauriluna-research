# Research Manager

Independent WordPress control plane for the Eduardo Research Theme. The Manager does **not** render the public frontend; the Theme remains the deterministic rendering, SEO/GEO, Schema and accessibility authority.

## Current version

`0.2.0`

The safe mutation/readiness kernel from 0.1.0 now includes the first resource-specific service: **Theme-owned Pages and structured slot hydration**.

## Control-plane kernel

The Manager can inspect the active Research preset, diagnose missing or misaligned resources, build checksummed mutation plans, Preview stored-state changes, Apply authorised writes, Verify persisted state and Rollback from bounded snapshots.

Evidence-sensitive academic keys are classified as `evidence-required`. Apply requires both explicit evidence confirmation and a non-empty source/verification reference inside the checksummed plan.

## Page Resource Service

`Eduardo_Research_Manager_Page_Resource` operates on preset page keys rather than arbitrary WordPress pages.

It can:

- inspect a Theme-controlled page and its role/model contract;
- discover the allowed slot schema from the active Theme;
- read effective EN or ES slot state;
- prepare a hydration plan for only allowed slots;
- repair incorrect `_eduardo_research_role` and `_eduardo_research_model` metadata in the same reversible plan;
- verify stored hydration after Apply;
- preserve the Theme as layout/rendering authority.

Home uses the Theme model options:

- `eduardo_research_model`
- `eduardo_research_model_es`

Interior surfaces use the bounded Theme options `eduardo_research_surface_<page-key>` and their `_es` counterparts. Only preset-defined Research surfaces are permitted by the generic mutation-plan whitelist.

Hydration of Theme copy/model options is classified as `editorial-review`; it is visible in Preview but does not claim academic evidence by itself.

If the expected WordPress page does not exist, the Page Resource Service returns `research_manager_page_missing` with next action `create-resource`. It does **not** recreate or publish a missing page implicitly. Explicit reversible resource creation is the next Page milestone.

## Diagnostics

Readiness checks map failures to actionable classes such as `create-resource`, `hydrate`, `auto-fix-candidate`, `manual-review` and `configuration-required`. The contract covers the Research Theme/preset, expected pages, page role/model assignment, Research CPTs, languages, Home routing and evidence-store shape.

## Mutation contract

The foundation remains deliberately narrow:

- approved Research options and bounded Theme page-model options;
- Theme/Research post metadata (`_eduardo_research_*`, `_research_*`);
- bounded post fields: title, excerpt, body content and menu order.

Publishing status, arbitrary WordPress options and arbitrary post fields remain outside the whitelist. Every plan is checksummed and may target each stored field only once.

## Preview → Apply → Verify → Rollback

Before Apply, the Manager stores previous supported state in a bounded, non-autoloaded snapshot store. A failed write or verification triggers restoration. An authorised user may explicitly rollback a successful mutation once. The foundation retains at most 25 recent snapshots.

## Admin surface

WordPress → Tools → Research Manager shows the readiness/control-plane state. Raw mutation controls are intentionally not exposed yet; resource UX will be added only on top of verified services.

## Next implementation blocks

1. Explicit reversible creation of missing Theme-controlled Page resources.
2. Insight creation/update service with bounded body content.
3. Publication and Research Project services using evidence-aware metadata schemas.
4. Rendered-frontend verification adapters.
5. Readiness remediation plans wired to Preview/Apply.
6. EN/ES record pairing and translation operations.
7. External academic connectors behind explicit authorization and additional evidence gates.
