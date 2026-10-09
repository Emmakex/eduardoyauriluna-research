# Research Manager

Independent WordPress control plane for the Eduardo Research Theme. The Manager does **not** render the public frontend; the Theme remains the deterministic rendering, SEO/GEO, Schema and accessibility authority.

## Current version

`0.4.0`

The Manager now includes the safe mutation/readiness kernel, Theme-owned Page hydration/creation, and a bounded **Insight Resource Service** for research notes and editorial content.

## Control-plane kernel

The Manager can inspect the active Research preset, diagnose missing or misaligned resources, build checksummed mutation plans, Preview stored-state changes, Apply authorised writes, Verify persisted state and Rollback from bounded snapshots.

Evidence-sensitive academic keys remain `evidence-required`: Apply requires explicit evidence confirmation plus a source/verification reference inside the checksummed plan. Editorial prose is handled separately as `editorial-review`; writing an Insight does not by itself assert a verified DOI, peer-review state, affiliation, award, grant or metric.

## Page Resource Service

`Eduardo_Research_Manager_Page_Resource` works with preset page keys rather than arbitrary WordPress pages. It can inspect role/model contracts, hydrate EN/ES Theme slots, repair role/model drift, explicitly create missing Theme-owned Pages and safely rollback only resources carrying the matching Manager provenance token.

Page creation leaves `post_content` empty. The Theme continues to own Page composition and layout; Gutenberg is not the Page layout engine.

## Insight Resource Service

`Eduardo_Research_Manager_Insight_Resource` works with the Theme editorial contract for WordPress `post` records.

Theme-supported Insight types are read directly from `eduardo_research_insight_types()`:

- `research_note`
- `explainer`
- `method_note`
- `working_note`
- `commentary`

Language is restricted to the active Research preset (`en` / `es`). The Theme continues to own localized routes, filtering and `Article` schema.

### Explicit Insight creation

`build_creation_plan()` creates a dedicated `create_insight` action. It supports only:

- title;
- slug;
- excerpt;
- bounded long-form body;
- language;
- Theme Insight type;
- initial status `draft` or `publish`;
- Manager provenance token.

The creation action is `editorial-review`. Preview never creates a post. Apply refuses to overwrite an existing slug or reuse a provenance token. Rollback permanently deletes only the Manager-created Insight carrying the matching token.

Publishing permission is **not** added to the generic `post_field` mutation contract. `draft` / `publish` is accepted only as part of the dedicated creation action.

### Bounded Insight updates

`build_update_plan()` can update an existing Insight only through these fields:

- `title`
- `excerpt`
- `content`
- `language`
- `insight_type`

It intentionally does not expose generic status changes or slug changes in 0.4.0. Existing updates reuse the checksummed mutation executor and therefore support Preview, Apply, Verify and Rollback.

Current bounds enforced before a plan can be created:

- title: 200 bytes;
- excerpt: 1,000 bytes;
- body: 60,000 bytes.

The body is sanitized with WordPress `wp_kses_post()`. This is the intended bounded Gutenberg/editorial zone inside the Theme-owned single Insight shell; it does not transfer frontend layout authority to the editor.

### EN / ES behavior

The Manager stores `_research_language` and `_research_insight_type`; the Theme decides how those values affect public behavior. At present the Theme owns:

- `/insights/<slug>/` for English;
- `/es/notas/<slug>/` for Spanish;
- language-restricted Insight queries;
- localized permalink generation;
- Insight cards and filters;
- `Article` structured data.

The Manager verifies persisted editorial state and a non-empty Theme-generated route, but does not duplicate route or schema generation.

## Diagnostics and mutation discipline

Readiness failures map to action classes such as `create-resource`, `hydrate`, `auto-fix-candidate`, `manual-review` and `configuration-required`.

The generic mutation surface remains deliberately narrow:

- approved Research options and bounded Theme Page-model options;
- Theme/Research post metadata (`_eduardo_research_*`, `_research_*`);
- bounded post fields: title, excerpt, body content and menu order;
- contract-bound `create_page` actions;
- contract-bound `create_insight` actions.

Every plan is checksummed and may target each stored field/resource only once.

## Preview → Apply → Verify → Rollback

Before Apply, the Manager stores previous supported state in a bounded, non-autoloaded snapshot store. A failed write or verification triggers restoration. An authorised user may explicitly rollback a successful mutation once. The foundation retains at most 25 recent snapshots.

## Admin surface

WordPress → Tools → Research Manager shows the readiness/control-plane state. Raw mutation controls are intentionally not exposed yet; resource UX will be added only on top of verified services.

## Next implementation blocks

1. Publication and Research Project services using evidence-aware metadata schemas.
2. Rendered-frontend verification adapters.
3. Readiness remediation plans wired to Preview/Apply.
4. EN/ES record pairing and translation operations.
5. External academic connectors behind explicit authorization and additional evidence gates.
