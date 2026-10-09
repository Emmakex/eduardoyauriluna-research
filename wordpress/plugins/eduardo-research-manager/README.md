# Research Manager

Independent WordPress control plane for the Eduardo Research Theme. The Manager never becomes the public renderer: the Theme remains the layout, route, SEO/GEO, Schema and accessibility authority.

## Current version

`0.6.0`

The Manager now covers:

- safe diagnostics and checksummed `Preview → Apply → Verify → Rollback`;
- Theme-owned Page hydration and reversible creation;
- bounded EN/ES Insight creation and updates;
- evidence-aware Research Output / Publication creation and updates;
- evidence-aware Research Project creation and updates.

## Evidence discipline

Academic claims are not inferred from prose or accepted merely because WordPress can store them. Evidence-sensitive plans require both `evidence_confirmed=true` and a non-empty `evidence_reference` inside the checksummed plan. Preview remains available before evidence confirmation; changing the evidence confirmation/reference changes the checksum and requires a new Preview.

The Manager treats publication identity, DOI, review state, authors/affiliations, project status, research question, role, dates, partners, funding, methods and Research Line relations as evidence-sensitive academic/research claims.

## Pages

`Eduardo_Research_Manager_Page_Resource` works from the active Research preset. It hydrates only Theme-defined EN/ES slots, repairs role/model drift and can explicitly create missing Theme-owned Pages with provenance-safe rollback. Page `post_content` remains empty; Gutenberg is not the Page layout engine.

## Insights

`Eduardo_Research_Manager_Insight_Resource` manages Theme editorial `post` records. Creation supports only Theme Insight types, EN/ES, bounded title/excerpt/body and initial `draft` or `publish`. Updates are limited to title, excerpt, body, language and Insight type. Generic status/slug mutation remains unavailable.

The long-form Insight body is the bounded editorial zone inside the Theme-owned single shell.

## Research Outputs / Publications

`Eduardo_Research_Manager_Output_Resource` manages `research_output` records against the Theme academic object contract. It supports bounded narrative content, EN/ES, publication/review state, structured dates, venue, DOI, structured authors and verified Research Line relations.

Every `create_output` plan is `evidence-required`. The service controls the Theme verification flags for output type, review state and DOI, so unverified academic claims remain private and public Schema/filtering only uses claims accepted through the evidence gate.

Status and slug remain creation-time properties; generic update mutation for them is intentionally unavailable.

## Research Projects

`Eduardo_Research_Manager_Project_Resource` manages `research_project` records against the Theme project contract.

### Creation

A dedicated `create_project` action supports:

- title, slug, excerpt and bounded narrative body;
- EN/ES language;
- initial `draft` or `publish` state;
- Theme-controlled project status: `planning`, `active`, `completed`, `paused`, `archived`;
- research question;
- researcher/project role;
- structured start/end dates (`YYYY`, `YYYY-MM`, `YYYY-MM-DD`);
- institution/partner;
- funding/source description;
- validated http/https project URL;
- structured method list;
- verified Research Line relations;
- Manager provenance token.

Every `create_project` plan is `evidence-required`, including draft creation, because the record asserts the existence/identity of a research project.

### Research Line relationships

`_research_line_ids` only accepts published, evidence-verified Research Lines in the same language as the Project. Cross-language or unverified relationships are rejected before Apply.

### Updates

The Project service can prepare reversible updates for title, excerpt/body, language, project status, question, role, dates, partner, funding, URL, methods and Research Line relations. Status and slug are not generic update fields.

Project metadata changes are evidence-gated. Narrative-only excerpt/body edits remain editorial-review operations unless the same plan also changes an evidence-sensitive field.

### Verification and rollback

After Apply, the service verifies stored state and the Theme-generated route. Creation rollback deletes only the `research_project` carrying the matching Manager provenance token; a conflicting resource is never overwritten or deleted.

## Mutation boundaries

Generic mutations remain narrow: approved Research options, Theme/Research metadata and bounded title/excerpt/body/menu-order fields. Dedicated creation actions exist for Pages, Insights, Research Outputs and Research Projects; they do not grant generic `post_status` writes to arbitrary WordPress content.

Snapshots are non-autoloaded, bounded and one-shot for rollback.

## Admin surface

WordPress → Tools → Research Manager exposes readiness/control-plane state. Raw write controls remain intentionally absent until each resource workflow has a verified service behind it.

## Next implementation blocks

1. Research Software resource service.
2. Dataset resource service.
3. Rendered-frontend verification adapters.
4. Readiness remediation plans wired to Preview/Apply.
5. EN/ES record pairing and translation operations.
6. External academic connectors behind explicit authorization and additional evidence gates.
