# Research Manager

Independent WordPress control plane for the Eduardo Research Theme. The Manager never becomes the public renderer: the Theme remains the layout, route, SEO/GEO, Schema and accessibility authority.

## Current version

`0.7.0`

The Manager now covers:

- safe diagnostics and checksummed `Preview → Apply → Verify → Rollback`;
- Theme-owned Page hydration and reversible creation;
- bounded EN/ES Insight creation and updates;
- evidence-aware Research Output / Publication creation and updates;
- evidence-aware Research Project creation and updates;
- evidence-aware Research Software creation and updates.

## Evidence discipline

Academic and research claims are not inferred from prose or accepted merely because WordPress can store them. Evidence-sensitive plans require both `evidence_confirmed=true` and a non-empty `evidence_reference` inside the checksummed plan. Preview remains available before evidence confirmation; changing confirmation or evidence reference requires a new checksummed plan.

## Resource boundaries

Pages remain Theme-owned structured surfaces; Gutenberg is not the Page layout engine. Insights retain bounded long-form body content inside the Theme-owned editorial shell. Publications, Projects and Software are first-class research objects with dedicated creation actions; generic `post_status` and slug mutation remain unavailable.

## Research Software

`Eduardo_Research_Manager_Software_Resource` manages `research_software` records against the Theme software contract.

A dedicated `create_software` action supports:

- title, slug, excerpt and bounded narrative body;
- EN/ES language;
- initial `draft` or `publish` state;
- Theme-controlled software status: `active`, `maintained`, `experimental`, `archived`;
- software version and structured release date (`YYYY`, `YYYY-MM`, `YYYY-MM-DD`);
- validated http/https repository, archive and documentation URLs;
- license;
- DOI with Manager-controlled `_research_doi_verified` flag;
- structured programming-language list;
- verified same-language Research Line relations;
- Manager provenance token.

Every `create_software` plan is `evidence-required`, including draft creation, because the record asserts a research software identity. DOI is normalized and is publicly verifiable only when accepted through the evidence gate.

Updates are reversible and bounded to title, excerpt/body, language, software status, version/release, URLs, license, DOI, programming languages and Research Line relations. Status and slug remain creation-time properties.

Creation rollback deletes only the `research_software` carrying the matching Manager provenance token. A resource occupying the planned slug without that token is never overwritten or deleted.

## Other resource services

`Eduardo_Research_Manager_Page_Resource` hydrates Theme-defined EN/ES slots, repairs role/model drift and can explicitly recreate missing Theme-owned Pages with provenance-safe rollback.

`Eduardo_Research_Manager_Insight_Resource` manages Theme editorial `post` records with Theme-controlled Insight types and bounded body content.

`Eduardo_Research_Manager_Output_Resource` manages `research_output` records with publication/review state, dates, venue, DOI, structured authors and verified Research Line relations.

`Eduardo_Research_Manager_Project_Resource` manages `research_project` records with project status, research question, role, dates, partner, funding, methods, URL and verified Research Line relations.

## Mutation and rollback discipline

Generic mutations remain narrow: approved Research options, Theme/Research metadata and bounded title/excerpt/body/menu-order fields. Dedicated resource creation actions do not grant generic publishing control to arbitrary WordPress content.

Snapshots are non-autoloaded, bounded and one-shot for rollback. The Manager verifies private stored state while the Theme independently determines what is safe to expose publicly.

## Admin surface

WordPress → Tools → Research Manager exposes readiness/control-plane state. Raw write controls remain intentionally absent until each resource workflow has a verified service behind it.

## Next implementation blocks

1. Dataset resource service.
2. Rendered-frontend verification adapters.
3. Readiness remediation plans wired to Preview/Apply.
4. EN/ES record pairing and translation operations.
5. External academic connectors behind explicit authorization and additional evidence gates.
