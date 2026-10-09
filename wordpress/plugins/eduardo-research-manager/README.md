# Research Manager

Independent WordPress control plane for the Eduardo Research Theme. The Manager never becomes the public renderer: the Theme remains the layout, route, SEO/GEO, Schema and accessibility authority.

## Current version

`0.5.0`

The Manager now covers:

- safe diagnostics and checksummed `Preview → Apply → Verify → Rollback`;
- Theme-owned Page hydration and reversible creation;
- bounded EN/ES Insight creation and updates;
- evidence-aware Research Output / Publication creation and updates.

## Evidence discipline

Academic claims are not inferred from prose or accepted merely because WordPress can store them. Plans touching DOI, publication type, review state, publication date, venue, publisher, authors/affiliations, grants, awards, identifiers or Research Line relations are `evidence-required`.

Evidence-sensitive Apply requires both:

- `evidence_confirmed=true`;
- a non-empty `evidence_reference` included in the checksummed plan.

Preview remains available before evidence is confirmed so a proposed mutation can be inspected safely. Changing confirmation/reference after Preview changes the checksum and requires a new Preview.

## Pages

`Eduardo_Research_Manager_Page_Resource` works from the active Research preset. It hydrates only Theme-defined EN/ES slots, repairs role/model drift and can explicitly create missing Theme-owned Pages with provenance-safe rollback. Page `post_content` remains empty; Gutenberg is not the Page layout engine.

## Insights

`Eduardo_Research_Manager_Insight_Resource` manages Theme editorial `post` records. Creation supports only Theme Insight types, EN/ES, bounded title/excerpt/body and initial `draft` or `publish`. Updates are limited to title, excerpt, body, language and Insight type. Generic status/slug mutation remains unavailable.

The long-form Insight body is the bounded editorial zone inside the Theme-owned single shell.

## Research Outputs / Publications

`Eduardo_Research_Manager_Output_Resource` manages `research_output` records against the Theme academic object contract.

### Creation

A dedicated `create_output` action supports:

- title, slug, excerpt and bounded narrative body;
- EN/ES language;
- initial `draft` or `publish` state;
- Theme publication type;
- Theme academic review status;
- publication date (`YYYY`, `YYYY-MM` or `YYYY-MM-DD`);
- venue;
- normalized DOI (`10.xxxx/...`);
- structured authors;
- verified Research Line relations;
- Manager provenance token.

**Every `create_output` plan is `evidence-required`.** Creating a Research Output asserts the existence and identity of an academic/research result even if the WordPress record begins as a draft.

### Verified public claims

The Theme already gates public academic metadata with verification flags. The Manager owns these flags during controlled Publication operations:

- `_research_output_type_verified`
- `_research_review_status_verified`
- `_research_doi_verified`

When a non-empty type, review state or DOI is accepted through an evidence-confirmed Manager plan, its corresponding flag is stored as `1`. Clearing the claim stores `0`.

This preserves the Theme behavior:

- unverified type/review values remain private;
- filtered public Publication queries require their verification flags;
- DOI is emitted publicly only when verified;
- Schema falls back to `CreativeWork` until the output type is verified.

### Authors

Authors are normalized into structured records with `display_name` and optional `given_name`, `family_name`, `affiliation`, ORCID and `is_site_researcher`. ORCID must use the canonical `0000-0000-0000-0000` shape. Because author identity and affiliation are academic claims, Publication creation/update requires evidence.

### Research Line relationships

`_research_line_ids` may only contain published, evidence-verified Research Lines in the same language as the Publication. The Manager rejects an unverified, missing or cross-language line rather than creating a relationship the Theme would later hide.

### Updates

The Publication service can prepare updates for:

- title, excerpt and body;
- language;
- publication type;
- review status;
- publication date;
- venue;
- DOI;
- authors;
- Research Line relations.

Status and slug are intentionally not generic update fields in 0.5.0. Type/review/DOI updates also update their verification flags in the same reversible plan.

## Mutation boundaries

Generic mutations remain narrow: approved Research options, Theme/Research metadata and bounded title/excerpt/body/menu-order fields. Dedicated creation actions exist for Pages, Insights and Research Outputs; they do not grant generic `post_status` writes to arbitrary content.

Snapshots are non-autoloaded, bounded and one-shot for rollback. Creation rollback deletes only a resource carrying the matching Manager provenance token; a conflicting resource is never overwritten or deleted.

## Admin surface

WordPress → Tools → Research Manager exposes readiness/control-plane state. Raw write controls remain intentionally absent until each resource workflow has a verified service behind it.

## Next implementation blocks

1. Research Project resource service.
2. Rendered-frontend verification adapters.
3. Readiness remediation plans wired to Preview/Apply.
4. EN/ES record pairing and translation operations.
5. External academic connectors behind explicit authorization and additional evidence gates.
