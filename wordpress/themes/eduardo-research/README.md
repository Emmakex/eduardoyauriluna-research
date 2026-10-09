# Eduardo Research Theme

Greenfield WordPress implementation of the `research` preset derived from the SEO/GEO Theme architecture.

## Non-negotiable architecture

- Theme owns public page composition.
- Gutenberg does not own controlled page layouts.
- Structured semantic slots hydrate Theme templates.
- Long-form editorial/research bodies may use the editor only inside bounded Theme-owned surfaces.
- Publications, projects, software and datasets are first-class structured content types.
- Evidence-sensitive academic claims must be verified before they are exposed as academic facts or structured data.
- Manager is a later control plane: Preview -> Apply -> Verify -> Rollback.
- Theme remains independently functional without Manager.

## Current Theme version

`0.3.0` is the first installability-focused Theme milestone.

On Theme activation it provisions the structural WordPress pages required by the `research` preset when they do not already exist, assigns the Home page as the static front page, records page role/model metadata, registers the Research content types and refreshes rewrite rules.

The bootstrap creates structure only. It does not fabricate degrees, affiliations, identifiers, publication status, citation metrics, grants, awards or research outcomes.

## URL ownership

The controlled index pages are the canonical collection surfaces:

- `/publications/`
- `/projects/`
- `/software/`
- `/datasets/`

Research CPTs do not expose competing archive roots. Their singular records live below those bases while the Theme-owned page remains the index surface.

## Native SEO/GEO runtime

The Theme currently provides canonical metadata, Open Graph/Twitter metadata, Person/WebSite/WebPage schema, evidence-aware research output schema, Dataset and SoftwareSourceCode schema, BreadcrumbList, `/llms.txt` and `/research.json` discovery surfaces.

Research outputs default conservatively to `CreativeWork`. A more specific schema type such as `ScholarlyArticle` is emitted only when the structured output type is explicitly marked verified. DOI output follows the same verified-evidence rule.

## Quality and packaging

GitHub Actions validates required files, lints all Theme PHP on PHP 8.1 and 8.2, validates `theme.json`, and builds an installable `eduardo-research.zip` artifact after successful checks.

The next Theme slices are runtime installation verification on a clean WordPress instance, deeper visual refinement, and final release packaging. Research Manager development starts only after the Theme milestone is closed.
