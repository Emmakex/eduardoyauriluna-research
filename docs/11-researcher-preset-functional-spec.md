# Researcher Preset — Functional Specification v1

Project: **eduardoyauriluna.com**  
Product target: reusable **SEO/GEO Theme + Manager**  
Preset name: **Researcher**

## 1. Purpose

The Researcher preset turns the generic SEO/GEO Theme + Manager into an academic identity and research-output platform for individual researchers.

The preset must support:

- a canonical researcher identity;
- research lines and interests;
- academic identifiers and external profiles;
- structured research outputs;
- academic SEO and machine-readable metadata;
- Google Scholar-compatible publication pages;
- integrations with research infrastructure;
- a Research Readiness dashboard;
- English-primary / Spanish-secondary content;
- strict separation between peer-reviewed and non-peer-reviewed outputs.

The first real implementation target is **Eduardo Jose Yauri Luna / eduardoyauriluna.com**, but reusable code must remain generic.

---

## 2. Product boundary

### Belongs in the reusable Theme / Manager

- Researcher preset definition
- Researcher profile model
- Academic identifier fields
- Research Line model
- Publication CPT and metadata
- Research Project CPT and metadata
- Research Software CPT and metadata
- Dataset CPT and metadata
- Talk / Conference CPT and metadata
- Academic SEO renderer
- Google Scholar citation meta renderer
- Schema.org mappings
- Connection adapters
- Research Readiness engine
- Import/export logic
- Multilingual field strategy

### Belongs in this repository

- Eduardo Jose Yauri Luna identity content
- eduardoyauriluna.com configuration
- research statement
- actual research lines
- actual profile IDs
- site-specific design/content decisions
- real publications, projects, software and datasets
- deployment notes specific to this site

No site-specific personal content should be hard-coded into the reusable product.

---

## 3. Core data model

### 3.1 Researcher Profile

Single canonical profile per site for v1.

Required fields:

- canonical_name
- slug
- primary_language
- secondary_language
- research_headline
- short_bio
- long_bio
- canonical_description
- research_interests[]
- keywords[]
- profile_image
- canonical_domain
- contact_url

Optional fields:

- current_affiliation
- position_title
- location
- education_summary
- languages[]
- CV file / CV URL
- research_statement URL

Integrity rules:

- affiliation is optional and must never be inferred;
- academic title is optional and must never be inferred;
- canonical_name is immutable without an explicit migration;
- all external IDs must be stored independently from display URLs.

---

## 4. Academic Identifiers

Store identifiers as first-class fields, not arbitrary social links.

### Required identifier slots

- ORCID iD
- Google Scholar profile ID
- OpenAlex Author ID
- Semantic Scholar Author ID
- Web of Science ResearcherID
- Scopus Author ID
- Zenodo profile / community identifier where applicable
- GitHub username / organisation

### Identifier object

Each identifier should support:

- provider
- identifier
- canonical_url
- status: `not_configured | configured | verified | error`
- verified_at
- last_checked_at
- visibility
- notes

No identifier may be automatically invented from a name search without explicit confirmation.

---

## 5. Research Line model

Content type: `research_line`

Fields:

- title
- slug
- short_description
- full_description
- central_question
- topics[]
- methods[]
- status: `active | exploratory | archived`
- related_publications[]
- related_projects[]
- related_software[]
- related_datasets[]
- order

Purpose:

- provide coherent research narrative;
- connect outputs to research questions;
- generate Research page sections;
- support structured internal linking.

---

## 6. Publication model

Content type: `publication`

### Publication types

- journal_article
- conference_paper
- book_chapter
- book
- preprint
- working_paper
- technical_report
- thesis
- poster
- other

### Review status

Separate field from publication type:

- peer_reviewed
- accepted
- under_review
- submitted
- preprint
- working_draft
- technical_output

### Core fields

- title
- slug
- abstract
- authors[]
- author_order[]
- publication_date
- year
- publication_type
- review_status
- journal_or_venue
- publisher
- volume
- issue
- pages
- DOI
- URL
- PDF URL
- open_access status
- license
- keywords[]
- research_lines[]
- citation_text
- BibTeX
- external identifiers

### Author object

- display_name
- family_name
- given_name
- ORCID
- affiliation
- is_site_researcher

### Integrity rules

- Peer-reviewed state must be explicit.
- Preprints must be visibly labelled.
- DOI is optional but validated when present.
- Author order must be preserved.
- A publication page must have its own stable canonical URL.
- If a PDF is exposed for indexing, it must represent a single scholarly work.

---

## 7. Research Project model

Content type: `research_project`

Fields:

- title
- slug
- summary
- full_description
- research_question
- status: `planned | active | completed | paused`
- start_date
- end_date
- role
- collaborators[]
- institution / partner
- funding
- methods[]
- ethics / consent notes where relevant
- outputs[]
- software[]
- datasets[]
- publications[]
- project URL

Professional software projects must not automatically become Research Projects. A research question and explicit research methodology are required.

---

## 8. Research Software model

Content type: `research_software`

Fields:

- title
- slug
- description
- version
- release_date
- repository_url
- archive_url
- Zenodo DOI
- software DOI / identifier
- license
- programming_languages[]
- contributors[]
- citation
- related_research_project
- related_publications[]
- documentation_url
- status

Versioned software releases should be citable independently where appropriate.

---

## 9. Dataset model

Content type: `dataset`

Fields:

- title
- slug
- description
- version
- creators[]
- publication_date
- repository
- DOI
- license
- access_level
- methodology
- provenance
- format[]
- size
- documentation_url
- related_project
- related_publications[]
- ethics / privacy notes

Dataset publication must explicitly consider privacy, consent and anonymisation where real organisational data is involved.

---

## 10. Talk / Conference model

Content type: `research_event_output`

Types:

- invited_talk
- conference_presentation
- poster
- workshop
- panel
- seminar

Fields:

- title
- event_name
- event_type
- date
- location
- organisers
- URL
- slides URL
- recording URL
- related_project
- related_publications[]

---

## 11. Academic SEO layer

Academic SEO is an extension of the existing SEO/GEO engine.

Every research output should support:

- canonical URL
- title and description
- Open Graph
- standard JSON-LD
- academic identifiers
- author entity linkage
- ORCID linkage
- DOI linkage
- citation metadata where applicable
- internal links to research lines and projects
- language alternates
- sitemap inclusion rules

The page title must describe the research output, not the website brand.

---

## 12. Google Scholar compatibility

Google Scholar does not provide a general public profile API that this product should depend on. The preset must therefore optimise publication pages for inclusion rather than scrape Scholar.

Official Google Scholar inclusion guidance requires each scholarly work to have its own URL and supports bibliographic meta tags such as Highwire Press citation tags.

Source: https://scholar.google.com/intl/en-us/scholar/inclusion.html

### Publication meta tags

For publication-like outputs render, when data is available:

- `citation_title`
- `citation_author` — one tag per actual author
- `citation_publication_date`
- `citation_journal_title`
- `citation_volume`
- `citation_issue`
- `citation_firstpage`
- `citation_lastpage`
- `citation_doi`
- `citation_pdf_url`

Rules:

- `citation_title` contains the work title only;
- each author is output separately;
- do not include degrees or affiliations inside `citation_author`;
- do not emit fake journal metadata for preprints;
- only emit fields supported by known values;
- each work page has one canonical scholarly work.

### Scholar connection UI

The Manager may store:

- Scholar profile ID
- Scholar profile URL
- profile status
- last manually verified date

It must not claim live citation synchronization unless a legitimate supported data source is implemented.

Optional import strategy:

- BibTeX import
- CSV import
- manual publication reconciliation

No scraping dependency in core product.

---

## 13. Schema.org mappings

### Researcher homepage / About

Primary types:

- `Person`
- optionally `ProfilePage` as the page entity

Key properties where valid:

- name
- url
- image
- description
- sameAs
- knowsAbout
- affiliation only when verified

### Publication

Use the most specific valid CreativeWork type available, typically:

- `ScholarlyArticle` for scholarly articles
- `Article` where ScholarlyArticle is not appropriate
- `Report` for technical reports where semantically suitable

Properties:

- headline / name
- author
- datePublished
- abstract / description
- identifier / DOI
- sameAs
- isPartOf
- keywords
- license

### Dataset

- `Dataset`

### Research Software

- `SoftwareSourceCode` and/or `SoftwareApplication` depending on the artefact

Do not emit unsupported or misleading types merely for SEO appearance.

---

## 14. Connections architecture

Every connection must implement a common contract.

### Connection state

- provider
- status: `not_configured | connecting | connected | degraded | error`
- mode: `oauth | api_key | public_api | manual_link | import_only`
- data_direction: `read | write | bidirectional | manual`
- identifier
- last_sync_at
- last_success_at
- last_error_at
- last_error_code
- last_error_message
- capabilities[]

Secrets must never be stored in Git or exposed through the public WordPress REST API.

---

## 15. ORCID adapter

Priority: highest.

Official ORCID Public API capabilities include authenticated ORCID collection, sign-in, search and retrieval of public ORCID record data. Writing to ORCID records is a Member API capability requiring permission and organisational membership.

Sources:

- https://info.orcid.org/what-is-orcid/services/public-api/
- https://info.orcid.org/what-is-orcid/services/member-api/

### v1 capabilities

- Connect ORCID using OAuth where configured
- Store verified ORCID iD
- Read public profile data
- Show sync status
- Map public works for reconciliation
- Never overwrite local data silently

### v1 data direction

`ORCID → WordPress` plus manual reconciliation.

### Future capability

`WordPress → ORCID` only if legitimate Member API access exists and the researcher has granted required permissions.

---

## 16. Zenodo adapter

Official Zenodo REST API supports deposits, published records and file upload/download. It supports OAuth 2.0 and provides a sandbox environment.

Source: https://developers.zenodo.org/

### v1 capabilities

- connect credentials securely;
- search/fetch existing records;
- link a local output to a Zenodo record;
- store DOI and concept DOI where available;
- sandbox test mode.

### v2 candidate capabilities

- create draft deposit from WordPress metadata;
- upload files;
- publish only after explicit human confirmation;
- sync DOI back to WordPress.

Publishing research output is irreversible enough to require explicit confirmation and should never occur automatically from a normal content save.

---

## 17. OpenAlex adapter

OpenAlex exposes authors, works, sources, institutions and related scholarly entities via REST API.

Source: https://help.openalex.org/api/

### v1 capabilities

- store confirmed OpenAlex Author ID;
- fetch author summary;
- discover candidate works;
- reconcile works by DOI and title;
- retrieve citation/count metadata for display with timestamp and provenance;
- never auto-claim a candidate author solely because the name matches.

---

## 18. Crossref adapter

Crossref REST API exposes scholarly metadata and supports direct work lookup by DOI.

Source: https://www.crossref.org/documentation/retrieve-metadata/rest-api/

### v1 capabilities

- DOI lookup;
- metadata hydration;
- DOI agency check;
- retrieve title, authors, venue, date, publisher and other available bibliographic metadata;
- use polite API access with identifiable contact configuration in production.

Crossref should be treated as a metadata source, not as the canonical authority for all research-output types.

---

## 19. GitHub adapter

Purpose: research software identity.

Capabilities:

- link repositories;
- store release/version metadata;
- detect CITATION.cff where available;
- link GitHub releases to Zenodo archives;
- distinguish research software from unrelated repositories.

No repository should be surfaced as research software until explicitly selected.

---

## 20. Research Readiness

The Manager must calculate a transparent readiness score. It should be informative, not gamified as an academic-quality score.

### Dimensions

#### Identity — 20%

- canonical name
- headline
- short bio
- long bio
- research interests
- canonical domain

#### Academic identifiers — 20%

- ORCID verified
- Scholar profile linked when available
- OpenAlex confirmed when available
- other IDs stored as they become available

#### Research narrative — 20%

- active research line
- research statement
- methods statement
- research questions

#### Research outputs — 25%

- publication/preprint/technical report
- research software
- dataset
- project-output linkage

Score must reflect existence and metadata completeness, not prestige.

#### Academic discoverability — 15%

- Person/ProfilePage schema
- canonical links
- sitemap
- academic metadata
- publication-specific URLs
- citation tags on scholarly works

### Readiness states

- 0–24: Foundation
- 25–49: Identity established
- 50–69: Research profile active
- 70–84: Discoverable research profile
- 85–100: Application-ready profile

The UI must explain exactly why points are earned or missing.

---

## 21. Manager navigation

Recommended information architecture:

```text
Research
├── Overview
├── Identity
├── Research Lines
├── Publications
├── Projects
├── Research Software
├── Datasets
├── Talks & Conferences
├── Academic Identifiers
├── Connections
└── Research Readiness
```

### Overview dashboard

Display:

- researcher identity card
- readiness status
- outputs count by type
- connection health
- next recommended action
- warnings for incomplete or conflicting metadata

---

## 22. Multilingual strategy

English is the default academic language for this site.

Requirements:

- translatable bios/descriptions;
- translatable research-line narrative;
- publication bibliographic metadata must preserve original publication language;
- titles must not be machine-translated unless an explicit translated-title field exists;
- hreflang between profile/site translations;
- DOI and scholarly identifiers remain language-independent.

---

## 23. Permissions and safety

Minimum roles:

- Administrator
- Research Editor
- Content Editor

Sensitive actions requiring elevated permission:

- connect/disconnect OAuth integrations;
- modify canonical researcher identity;
- publish to external repositories;
- delete scholarly outputs;
- alter DOI mappings;
- change academic identifiers.

Every external-write action should be auditable.

---

## 24. Audit log

Record for research-critical changes:

- user
- timestamp
- action
- entity
- previous value where practical
- new value
- external provider
- external response / identifier

The audit log is especially important for future Zenodo/ORCID write operations.

---

## 25. Definition of done for Researcher preset v1

A v1 implementation is complete when:

1. Researcher Profile can be configured without code changes.
2. Research Lines are supported.
3. Publication, Project, Research Software and Dataset content types exist.
4. Academic identifiers are first-class fields.
5. Publication pages emit validated academic metadata.
6. Publication pages implement Google Scholar-compatible citation meta where applicable.
7. Person/ProfilePage and output JSON-LD are rendered correctly.
8. ORCID connection has a documented/testable read path.
9. Zenodo, OpenAlex and Crossref adapters have explicit contracts.
10. Google Scholar is treated as profile/indexing integration without scraping.
11. Research Readiness is explainable and calculated from real profile state.
12. All reusable logic is independent from Eduardo-specific content.
