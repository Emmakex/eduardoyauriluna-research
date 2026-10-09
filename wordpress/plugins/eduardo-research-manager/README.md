# Research Manager

Independent WordPress control plane for the Eduardo Research Theme. The Manager never becomes the public renderer: the Theme remains the layout, route, SEO/GEO, Schema and accessibility authority.

## Current version

`0.8.0`

The Manager now covers:

- safe diagnostics and checksummed `Preview → Apply → Verify → Rollback`;
- Theme-owned Page hydration and reversible creation;
- bounded EN/ES Insight creation and updates;
- evidence-aware Research Output / Publication creation and updates;
- evidence-aware Research Project creation and updates;
- evidence-aware Research Software creation and updates;
- evidence-aware Research Dataset creation and updates.

## Evidence discipline

Academic and research claims are not inferred from prose or accepted merely because WordPress can store them. Evidence-sensitive plans require both `evidence_confirmed=true` and a non-empty `evidence_reference` inside the checksummed plan. Preview remains available before evidence confirmation; changing confirmation or evidence reference requires a new checksummed plan.

## Resource boundaries

Pages remain Theme-owned structured surfaces; Gutenberg is not the Page layout engine. Insights retain bounded long-form body content inside the Theme-owned editorial shell. Publications, Projects, Software and Datasets are first-class research objects with dedicated creation actions; generic `post_status` and slug mutation remain unavailable.

## Research Datasets

`Eduardo_Research_Manager_Dataset_Resource` manages `research_dataset` records against the Theme dataset contract.

A dedicated `create_dataset` action supports:

- title, slug, excerpt and bounded narrative body;
- EN/ES language;
- initial `draft` or `publish` state;
- dataset version and structured publication date (`YYYY`, `YYYY-MM`, `YYYY-MM-DD`);
- validated http/https repository and documentation URLs;
- DOI with Manager-controlled `_research_doi_verified` flag;
- license;
- Theme-controlled access level: `open`, `restricted`, `embargoed`, `on_request`;
- structured formats list;
- methodology and provenance statements;
- dataset size description;
- ethics/privacy notes;
- verified same-language Research Line relations;
- Manager provenance token.

Every `create_dataset` plan is `evidence-required`, including draft creation, because the record asserts the existence and metadata of a research dataset. DOI is normalized and becomes publicly verifiable only through the evidence gate.

Updates are reversible and bounded to title, excerpt/body, language, version/date, repository, DOI, license, access level, methodology, provenance, size, documentation, ethics notes, formats and Research Line relations. Status and slug remain creation-time properties.

Creation rollback deletes only the `research_dataset` carrying the matching Manager provenance token. A conflicting resource is never overwritten or deleted.

## Research-object executor registry

From 0.8.0 the executor uses a common internal registry for Research Outputs, Projects, Software and Datasets. Each resource still has its own service/validation contract, but creation, stored-state verification and provenance-safe rollback share the same execution path. This reduces duplicate mutation logic without weakening resource-specific evidence gates.

## Other resource services

`Eduardo_Research_Manager_Page_Resource` hydrates Theme-defined EN/ES slots, repairs role/model drift and can explicitly recreate missing Theme-owned Pages with provenance-safe rollback.

`Eduardo_Research_Manager_Insight_Resource` manages Theme editorial `post` records with Theme-controlled Insight types and bounded body content.

`Eduardo_Research_Manager_Output_Resource` manages `research_output` records with publication/review state, dates, venue, DOI, structured authors and verified Research Line relations.

`Eduardo_Research_Manager_Project_Resource` manages `research_project` records with project status, research question, role, dates, partner, funding, methods, URL and verified Research Line relations.

`Eduardo_Research_Manager_Software_Resource` manages `research_software` records with lifecycle status, version/release, repositories, documentation, license, DOI, programming languages and verified Research Line relations.

## Mutation and rollback discipline

Generic mutations remain narrow: approved Research options, Theme/Research metadata and bounded title/excerpt/body/menu-order fields. Dedicated resource creation actions do not grant generic publishing control to arbitrary WordPress content.

Snapshots are non-autoloaded, bounded and one-shot for rollback. The Manager verifies private stored state while the Theme independently determines what is safe to expose publicly.

## Admin surface

WordPress → Tools → Research Manager exposes readiness/control-plane state. Raw write controls remain intentionally absent until each resource workflow has a verified service behind it.

## Next implementation blocks

1. Rendered-frontend verification adapters.
2. Readiness remediation plans wired to Preview/Apply.
3. EN/ES record pairing and translation operations.
4. External academic connectors behind explicit authorization and additional evidence gates.
