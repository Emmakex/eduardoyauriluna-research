# Changelog

## 1.1.3 — Author sitemap cleanup

Closes the remaining Phase 4 production SEO/GEO sitemap finding without changing public content, Research Manager, Gateway, navigation or schema semantics.

### Fixed

- Exclude WordPress core's `users` sitemap provider so the low-value `/author/eduardoyauri/` utility route is no longer advertised through the native sitemap index.
- Preserve the Theme-owned localized `researchlanguages` provider and all existing post/page sitemap behavior.

### Quality

- Extend the clean-WordPress sitemap regression guard to require the `users` provider to be absent and reject any `wp-sitemap-users-*` entry in the native sitemap index.
- Version the installable Theme artifact as `eduardo-research-1.1.3.zip`.

## 1.1.2 — Sitemap route integrity

Closes the Phase 4 production SEO/GEO sitemap defect without changing public content, Research Manager or Gateway behavior.

### Fixed

- Replace the hyphenated localized sitemap provider id with the WordPress-routable `researchlanguages` identifier.
- Centralize the provider id in `Eduardo_Research_Language_Sitemap_Provider::PROVIDER_NAME` so registry and provider metadata cannot drift.
- Restore alignment between the native sitemap index URL and WordPress sitemap rewrite routing.

### Quality

- Add a clean-WordPress runtime regression guard that requires a lowercase alphabetic provider id, verifies registry/index alignment and proves the generated sitemap route resolves through native rewrite rules.
- Version the installable Theme artifact as `eduardo-research-1.1.2.zip`.

## 1.1.0 — Governable Research Theme

Turns the evolved Research Theme into an explicit, versioned control surface for Research Manager / Gateway governance while keeping the public frontend deterministic and Theme-owned.

### Governance

- Add `eduardo_research_governance_contract()` as the Theme-native declaration of governable surfaces and safety constraints.
- Declare Theme-owned Page slots, registered navigation, design presets, component variants, Media slots and SEO/GEO as bounded control surfaces.
- Expose `eduardo_research_governance_snapshot()` for a compact state/readback view without introducing an arbitrary WordPress or REST proxy.
- Preserve researcher identity as verified/canonical state rather than free editorial copy.
- Keep Gutenberg out of Page layout authority; the Theme remains the deterministic frontend renderer.
- Explicitly reject arbitrary PHP, JavaScript, REST routes, SQL, filesystem access, arbitrary CSS/font URLs and arbitrary navigation URLs from the Theme governance contract.

### Contract

- Bump Research preset contract from version 5 to version 6.
- Keep EN/ES Theme-native models and registered Page targets unchanged.
- Retain bounded navigation state, palette/typography/density presets and component variants.
- Preserve SEO/GEO, evidence gates, bilingual routing and Manager verification semantics.

### Release

- Version Theme metadata as `1.1.0`.
- Package the installable artifact as `eduardo-research-1.1.0.zip`.
- Add release-quality checks for the governance contract and preset version 6.

## 1.0.0 — Research Theme milestone

First standalone release-candidate of the Eduardo Research Theme.

### Hotfix — Researcher-only public content cleanup (2026-10-21)

- Refocus the public site exclusively on the researcher identity of **Eduardo Jose Yauri Luna** and his research agenda in artificial intelligence, digital transformation and autonomous systems for business processes.
- Replace public-facing Theme/system/evidence language with researcher-facing academic copy while preserving evidence validation internally.
- Reframe the Home visual around the four research directions: autonomous AI agents, human–AI collaboration, digital transformation in SMEs and AI-mediated information discovery.
- Hide empty research outputs, academic identifiers and research-note sections instead of exposing implementation placeholders.
- Reduce the default primary navigation to About, Research, CV, Insights and Contact; collection destinations remain available once real records exist.
- Hide empty evidence sections on structured surfaces and keep collection links conditional on public content.
- Align EN/ES defaults and the canonical research blueprint with the same researcher-first wording.
- Keep SEO/GEO, schema, evidence gates, bilingual routing and Manager verification contracts unchanged.

### Hotfix — Phase 4 production Media acceptance

- Render the Theme-owned primary Media slot from `single-post.php`, the template WordPress actually resolves for managed Insight posts.
- Preserve the public verification markers `data-research-media-slot="primary"` and `data-attachment-id` so authenticated Manager assignment operations can complete stored + rendered verification.
- Add a dedicated CI guard that fails if the Insight template stops invoking the primary Media renderer.

### Added

- Theme-owned researcher website surfaces for Home, About, Research, Publications, Projects, Software, Datasets, CV, Insights, Contact and legal pages.
- Native EN/ES routing, navigation, hreflang, localized discovery surfaces and sitemap integration.
- First-class Research Lines and bidirectional relationships with Publications, Projects, Software and Datasets.
- Academic collection filters, sorting and pagination.
- Dedicated Insight index/single surfaces with bounded editor content.
- Verified-evidence academic CV with browser Print / Save PDF presentation.
- Native SEO/GEO runtime: canonical, Open Graph/Twitter, Schema graph, BreadcrumbList, citation metadata, `llms.txt` and `research.json`.
- Progressive-enhancement mobile navigation with keyboard handling.
- Clean-install bootstrap and installable ZIP packaging.

### Evidence safety

- Verified evidence is required before academic identity claims are exposed publicly.
- Research Output type is public only when `_research_output_type_verified=1`.
- Review/peer-review status is public only when `_research_review_status_verified=1`.
- DOI output is public only when `_research_doi_verified=1`.
- Public filters for academic type/review status automatically require the corresponding verification flag.
- Filter/search exploration views use `noindex,follow` while retaining the canonical collection URL.
- Legacy Research Line evidence is migrated to first-class `research_line` records rather than remaining as a duplicate source of truth.

### Product ownership

- Theme/product author: **Emmake by Kairoseth**.
- Public researcher identity remains content/configuration and is not used as Theme product ownership metadata.

### Quality gates

- PHP 8.1 / 8.2 lint and JSON validation.
- Clean WordPress + MySQL activation/runtime smoke tests.
- Native EN/ES, evidence, relationship, collection, Insight, CV and release-hardening smoke tests.
- Versioned release artifact: `eduardo-research-1.0.0.zip`.
