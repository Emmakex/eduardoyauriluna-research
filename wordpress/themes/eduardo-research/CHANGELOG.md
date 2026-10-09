# Changelog

## 1.0.0 — Research Theme milestone

First standalone release-candidate of the Eduardo Research Theme.

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
