# Eduardo Research Theme

Greenfield WordPress implementation of the `research` preset derived from the SEO/GEO Theme architecture.

## Non-negotiable architecture

- Theme owns public page composition.
- Gutenberg does not own controlled page layouts.
- Structured semantic slots hydrate Theme templates.
- Long-form editorial/research bodies may use the editor only inside bounded Theme-owned surfaces.
- Publications, projects, software, datasets and Research Lines are first-class structured content types.
- Evidence-sensitive academic claims must be verified before they are exposed as academic facts, filters or structured data.
- Manager is a later control plane: Preview -> Apply -> Verify -> Rollback.
- Theme remains independently functional without Manager.

## Current Theme version

**1.0.0** is the first release-candidate milestone of the standalone Research Theme.

The Theme is owned by **Emmake by Kairoseth**. The public researcher identity remains independent from Theme/product ownership.

English is the primary language at `/`. Spanish is a first-class locale under `/es/`, with localized Theme-owned routes, EN/ES navigation, self canonicals, reciprocal hreflang, Open Graph locales, Schema `inLanguage`, localized breadcrumbs, discovery endpoints and Spanish aliases in the WordPress sitemap index. WPML or Polylang are not required for this baseline.

Routable Publications, Projects, Software, Datasets, Research Lines and Insights may have explicit EN/ES record pairs. A Spanish record is never inferred from an English record: `_research_language` defines ownership and `_research_translation_en` / `_research_translation_es` relate real counterparts. Cross-language hreflang is emitted only when a published counterpart exists.

## Theme-owned surfaces

The activation/bootstrap contract provisions and controls:

- Home
- About / Profile
- Research
- Publications
- Projects
- Software
- Datasets
- Academic CV
- Insights
- Contact
- Privacy Policy
- Legal Notice

Research CPTs use stable singular URLs below their language-specific collection surfaces. Competing CPT archives are disabled.

## Research object graph

`research_line` is the sole public source of truth for Research Lines. Publications, Projects, Software and Datasets relate to verified Research Lines through `_research_line_ids`.

Relationships are rendered bidirectionally:

- a research object can show its verified Research Lines;
- a Research Line can show its related Publications, Projects, Software and Datasets;
- related objects can be discovered through shared verified Research Lines.

The relationship contract is language-aware and excludes non-published or non-verified Research Lines.

## Academic collections

Publications, Projects, Software and Datasets are queryable Theme-owned academic indexes rather than static grids.

They support Research Line filtering, server-rendered sorting and pagination. Publications additionally support output type, academic/review status and publication year. Projects, Software and Datasets expose their own status/access facets.

Filtered/search result states are exploration views: they keep the clean collection canonical and are emitted as `noindex,follow` rather than being treated as independent SEO documents.

## Insights

Insights are a bounded editorial surface with Theme-owned index/single layouts and editor-controlled long-form bodies.

The editorial contract supports Research notes, Explainers, Method notes, Working notes and Commentary, with bilingual search, type filtering, pagination and Article structured data.

## Academic CV

The CV surface is generated from verified evidence plus published research outputs. It includes a print-focused presentation and a small CV-only interaction for browser Print / Save PDF.

The Theme does not fabricate CV entries to fill empty sections.

## Academic Intelligence visual system

- Academic Midnight `#0B1820`
- Deep Petrol `#123A3A`
- Research Teal `#087F76`
- Signal Mint `#61D6C6`
- Warm Ivory `#F5F5F0`
- Paper White `#FCFCFA`
- Graphite `#293638`
- Research Grey `#8B9897`
- Academic Cobalt `#4169A8` as a limited secondary accent

The Home surface uses a split editorial hero, decorative research-system visual, indexed content hierarchy, research collection cards, verified-identity surface and collaboration CTA. Decorative graphics describe site architecture only and never introduce academic claims.

The responsive header includes a progressive-enhancement mobile menu with `aria-expanded`, keyboard Escape handling, focus restoration and reduced-motion support. Without JavaScript, primary navigation remains available.

## Verified evidence contract

The Theme reads structured evidence from the `eduardo_research_evidence` option and only renders records whose `status` is `verified`. `unverified`, `needs-review`, missing and unknown states remain private from factual public rendering.

The active evidence store covers profile, affiliations, research questions/method context, experience, education, awards, contact and academic identifiers. Research Lines are no longer duplicated in the evidence store; they are first-class `research_line` records. A one-time migration converts verified legacy Research Line evidence into the CPT and keeps a legacy backup.

Academic output classification is separately evidence-gated:

- `_research_output_type` requires `_research_output_type_verified=1` before it is publicly exposed as a factual classification.
- `_research_review_status` requires `_research_review_status_verified=1` before it is publicly exposed or used to satisfy a public filtered query.
- DOI output requires `_research_doi_verified=1`.

This gate applies to presentation, structured metadata and public academic filtering.

## Native SEO/GEO runtime

The Theme provides canonical metadata, EN/ES hreflang, Open Graph/Twitter metadata, Person/WebSite/WebPage schema, evidence-aware research output schema, Dataset and SoftwareSourceCode schema, BreadcrumbList, localized WordPress sitemap entries, `/llms.txt`, `/research.json`, `/es/llms.txt` and `/es/research.json` discovery surfaces.

Verified identifier URLs may populate `Person.sameAs`. Verified affiliation records may populate `Person.affiliation`. Verified Research Lines populate the research agenda and `Person.knowsAbout`. Research outputs default conservatively to `CreativeWork`; a more specific schema type such as `ScholarlyArticle` requires verified output classification.

## Clean-install behavior

On Theme activation it provisions the structural WordPress pages required by the `research` preset when they do not already exist, assigns Home as the static front page, records page role/model metadata, registers Research content types, registers the bilingual route contract and refreshes rewrite rules.

The bootstrap creates structure only. It does not fabricate degrees, affiliations, identifiers, publication/review status, citation metrics, grants, awards or research outcomes.

## Quality and packaging

GitHub Actions validate the Theme on PHP 8.1 and 8.2, lint all PHP, validate `theme.json`, install and activate the Theme on clean WordPress + MySQL, verify the bootstrap and EN/ES contracts, translated records, evidence visibility, Research Lines, academic objects, bidirectional relationships, collection filters, Insights, CV, release evidence gates and mobile-navigation release assets.

The release gate builds a versioned installable `eduardo-research-1.0.0.zip` artifact. The general Theme quality workflow also builds the standard installable Theme package after its runtime gates pass.

Research Manager development starts only after the Theme 1.0 milestone is closed and verified.
