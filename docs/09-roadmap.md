# Roadmap

## Architecture principle

`eduardoyauriluna.com` is a greenfield WordPress implementation.

There is no legacy site or installed theme to audit or migrate. We start from a clean WordPress installation, install the reusable SEO/GEO Theme, and build the `research` preset directly on top of that foundation.

Implementation order:

1. Clean WordPress installation
2. SEO/GEO Theme installation
3. `research` preset
4. Academic content model
5. Real site hydration and validation
6. Research Manager
7. Academic connections
8. Research outputs and visibility
9. Doctoral application 2027

The Theme must remain functional without the Research Manager. The Manager is an enhancement layer for structured administration, automation, synchronization and readiness.

### Frontend authority

The SEO/GEO Theme owns the public frontend. Gutenberg is not the layout authority for controlled preset pages.

- preset pages render through Theme contracts, structured models and slots;
- WordPress Page records may provide routing/status/translation mapping;
- controlled pages must not depend on Gutenberg blocks or page-builder markup;
- Gutenberg may be used only as a bounded content editor where explicitly useful, such as an editorial body slot;
- the future Manager hydrates structured Theme slots rather than generating Gutenberg layouts.

Reference: `docs/14-theme-owned-frontend.md`.

## Phase 0 — Research identity foundation ✅

**Goal:** establish a documented and consistent research identity.

- [x] Canonical researcher name: Eduardo Jose Yauri Luna
- [x] Canonical domain: eduardoyauriluna.com
- [x] Repository created
- [x] README and documentation baseline
- [x] Integration strategy documented
- [x] Short academic biography
- [x] Long academic biography
- [x] Research statement v1
- [x] Academic CV source structure

## Phase 1 — Greenfield WordPress foundation

**Goal:** create a clean implementation target for the Research preset.

- [ ] Fresh WordPress installation
- [ ] Reusable SEO/GEO Theme installed from our current Theme project
- [ ] Minimal required plugins only
- [ ] English primary / Spanish secondary architecture prepared
- [ ] Permalink strategy defined
- [ ] Development/staging workflow defined
- [ ] Theme-controlled frontend; no Gutenberg/page-builder layout dependency
- [ ] Define where Gutenberg is disabled and where bounded editorial body editing is allowed
- [ ] Baseline performance, accessibility and security settings

**Important:** no legacy-theme audit, content migration or compatibility layer is required.

## Phase 2 — Theme preset: `research`

**Goal:** create the complete academic visual/content presentation layer before building the Manager.

### Theme contract first

- [ ] Register/select `research` as a normal SEO/GEO Theme preset
- [ ] Define expected pages and page roles
- [ ] Define `primary_intent`, `required_sections` and `internal_targets`
- [ ] Define structured page model IDs
- [ ] Define required slots / `required_any`
- [ ] Define evidence/verification groups
- [ ] Ensure pages render without Gutenberg layout markup

### Design system

- [ ] Research typography system
- [ ] Academic/technology visual language
- [ ] Spacing and layout tokens
- [ ] Light/dark behavior if retained by the base theme
- [ ] Responsive behavior
- [ ] Accessible interaction states

### Templates

- [ ] Home
- [ ] About
- [ ] Research
- [ ] Publications archive
- [ ] Publication single
- [ ] Projects archive
- [ ] Project single
- [ ] Research Software archive/single
- [ ] Datasets archive/single
- [ ] CV
- [ ] Contact
- [ ] Insights

### Components

- [ ] Researcher hero
- [ ] Research interests
- [ ] Research-line cards
- [ ] Publication card
- [ ] DOI / citation block
- [ ] Project card
- [ ] Research software card
- [ ] Dataset card
- [ ] Academic profile links
- [ ] Research timeline
- [ ] Metrics presentation component

## Phase 3 — Academic content model

**Goal:** define the data contract the theme actually needs, based on the implemented frontend.

- [ ] Researcher profile model
- [ ] Research lines taxonomy/model
- [ ] Publication CPT
- [ ] Research project CPT
- [ ] Dataset CPT
- [ ] Research software CPT
- [ ] Talk / conference output model
- [ ] Review/publication status taxonomy
- [ ] Academic identifier fields
- [ ] DOI and citation fields
- [ ] Author model and author ordering
- [ ] Stable canonical URLs
- [ ] Schema.org mappings
- [ ] Google Scholar-compatible citation metadata
- [ ] Bounded rich-text/body fields only where the Theme contract permits them

The content model is validated against real rendered templates before the Manager is implemented.

## Phase 4 — Hydrate `eduardoyauriluna.com`

**Goal:** use the Research preset as a real production case and discover any missing requirements before Manager development.

- [ ] Home content
- [ ] About content
- [ ] Research agenda
- [ ] Research lines
- [ ] Initial project records
- [ ] CV content
- [ ] Academic profile placeholders/verified identifiers
- [ ] English primary content
- [ ] Spanish translations
- [ ] Mobile validation
- [ ] Accessibility validation
- [ ] Performance validation
- [ ] Academic SEO validation

## Phase 5 — Research Manager

**Goal:** create the administration and orchestration layer only after the theme and data model are proven.

- [ ] Overview dashboard
- [ ] Researcher identity editor
- [ ] Research lines manager
- [ ] Publications manager
- [ ] Projects manager
- [ ] Research software manager
- [ ] Datasets manager
- [ ] CV/academic profile manager
- [ ] Academic identifiers manager
- [ ] Academic SEO/GEO checks
- [ ] Connection status model
- [ ] Audit log
- [ ] Permissions/capabilities
- [ ] Research Readiness dashboard
- [ ] Structured slot hydration; no Gutenberg layout generation

Reusable implementation belongs in the main Theme/Manager product repository. This repository remains the source of truth for Eduardo Jose Yauri Luna-specific content, configuration, requirements and decisions.

## Phase 6 — Academic connections

**Goal:** connect the proven Manager/content model to external research infrastructure.

Priority order:

- [ ] ORCID
- [ ] Google Scholar profile linkage and indexing readiness
- [ ] Crossref
- [ ] Zenodo
- [ ] OpenAlex
- [ ] GitHub research software integration

Secondary:

- [ ] Semantic Scholar
- [ ] ResearchGate
- [ ] Web of Science Researcher Profile
- [ ] Scopus Author ID when available

Rules:

- no fabricated identifiers;
- no dependency on Google Scholar scraping;
- external writes require explicit authorization;
- imported metadata must preserve source and retrieval timestamp;
- curated local data must not be silently overwritten.

## Phase 7 — Research outputs

Target: create a credible initial portfolio, clearly labelled by output type and review status.

Candidate themes:

1. Autonomous AI agents for SME administrative processes
2. SEO/GEO and visibility in generative search
3. Digital transformation and automation barriers in SMEs
4. Research software release with reproducible documentation
5. Dataset related to one empirical study, when ethically and legally appropriate

For each output:

- [ ] Research question
- [ ] Literature review
- [ ] Methodology
- [ ] Ethics/privacy review where relevant
- [ ] Evidence/data
- [ ] Limitations
- [ ] Reproducibility assets
- [ ] Publication venue/status
- [ ] DOI where appropriate
- [ ] ORCID linkage

## Phase 8 — Academic visibility

- [ ] ORCID profile complete
- [ ] CVN / FECYT
- [ ] Google Scholar profile
- [ ] Zenodo profile
- [ ] OpenAlex author identity resolved
- [ ] Semantic Scholar author page claimed where possible
- [ ] ResearchGate profile
- [ ] LinkedIn updated with research positioning
- [ ] Search result consistency audit for researcher name

## Phase 9 — Doctoral application 2027

- [ ] Define doctoral research proposal
- [ ] Select target programmes
- [ ] Identify potential supervisors
- [ ] Review supervisors' recent publications
- [ ] Prepare outreach package
- [ ] Academic CV final
- [ ] Research statement final
- [ ] Research proposal final
- [ ] Portfolio of research outputs
- [ ] Contact supervisors before formal application windows where appropriate
- [ ] Submit applications

## Definition of ready

A doctoral application is considered research-profile ready when a reviewer can independently verify:

- who the researcher is;
- what the research agenda is;
- what outputs exist and their exact status;
- what methods and evidence have been used;
- where persistent identifiers resolve;
- what software/data support the work;
- and how the proposed PhD follows coherently from prior experience and research activity.
