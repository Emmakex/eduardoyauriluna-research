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

`0.5.0` introduces the premium academic visual milestone on top of the verified-evidence foundation.

The Home surface now uses a split editorial hero, a decorative research-system visual, stronger section hierarchy, indexed content sections, richer collection cards, a dark verified-identity surface, an upgraded collaboration CTA and a refined sticky navigation system. Decorative graphics describe the site architecture only; they do not introduce unverified academic claims.

On Theme activation it provisions the structural WordPress pages required by the `research` preset when they do not already exist, assigns the Home page as the static front page, records page role/model metadata, registers the Research content types and refreshes rewrite rules.

The bootstrap creates structure only. It does not fabricate degrees, affiliations, identifiers, publication status, citation metrics, grants, awards or research outcomes.

## Verified evidence contract

The Theme reads structured evidence from the `eduardo_research_evidence` option and only renders records whose `status` is `verified`. `unverified`, `needs-review`, missing and unknown states remain private from factual public rendering.

The initial evidence groups are `profile`, `affiliations`, `research_lines`, `methods`, `experience`, `education`, `awards`, `contact` and `identifiers`.

About, Research, CV and Contact use those verified groups directly. The Home surface can expose selected verified research lines and academic identifiers. The full contract is documented in `docs/17-research-theme-evidence-contract.md`.

## URL ownership

The controlled index pages are the canonical collection surfaces:

- `/publications/`
- `/projects/`
- `/software/`
- `/datasets/`

Research CPTs do not expose competing archive roots. Their singular records live below those bases while the Theme-owned page remains the index surface.

## Native SEO/GEO runtime

The Theme provides canonical metadata, Open Graph/Twitter metadata, Person/WebSite/WebPage schema, evidence-aware research output schema, Dataset and SoftwareSourceCode schema, BreadcrumbList, `/llms.txt` and `/research.json` discovery surfaces.

Verified identifier URLs may populate `Person.sameAs`. Verified affiliation records may populate `Person.affiliation`. Research outputs default conservatively to `CreativeWork`; a more specific schema type such as `ScholarlyArticle` and DOI output require their dedicated verified metadata flags.

## Quality and packaging

GitHub Actions validates required files, lints all Theme PHP on PHP 8.1 and 8.2, validates `theme.json`, installs and activates the Theme on a clean WordPress + MySQL runtime, checks the bootstrap and evidence-visibility contracts, and builds an installable `eduardo-research.zip` artifact only after all gates pass.

Research Manager development starts only after the Theme milestone is closed.
