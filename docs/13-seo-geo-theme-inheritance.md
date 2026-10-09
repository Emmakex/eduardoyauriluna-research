# Research preset — inheritance from SEO/GEO Theme

## Purpose

The `research` preset is **not a separate academic theme** and must not create a parallel architecture.

It extends the existing SEO/GEO Theme contract and uses the same product philosophy already proven in the current Theme + Manager work. `eduardoyauriluna.com` is the first greenfield real implementation of that preset.

Current known Theme baseline from the latest Manager diagnostic:

- Theme: `SEO GEO Starter — MF08.10 Corporate Complete`
- Version observed: `0.1.5`
- Stylesheet: `seo-geo-theme-mf0810`
- Existing preset observed: `corporate`

This document defines which concepts are inherited and how they are specialised for research.

---

## 1. Core rule: extend, do not fork

The Research preset must reuse the same primitives as the existing SEO/GEO Theme:

- preset contracts;
- expected pages;
- page roles;
- content contracts;
- structured page models;
- required slots;
- `required_any` groups;
- verification/evidence groups;
- internal-target contracts;
- SEO output authority;
- rendered frontend verification;
- readiness checks;
- controlled Manager mutations;
- Preview → Apply → Verify → Rollback.

Research-specific behaviour is added through new contracts and models, not by bypassing the existing system.

---

## 2. Theme first, Manager second

For this project the implementation sequence is:

1. Fresh WordPress installation.
2. Install the reusable SEO/GEO Theme.
3. Add the `research` preset to the Theme.
4. Render the complete research frontend using Theme contracts and models.
5. Define/validate the academic content model from the real frontend.
6. Hydrate `eduardoyauriluna.com` with real content.
7. Extend the existing SEO/GEO Manager so it understands the `research` preset.
8. Add academic external connections.

The Theme must remain functional without the Manager. The Manager is the controlled hydration, diagnostic, mutation, synchronization and readiness layer.

---

## 3. Research preset contract

The existing Theme already resolves preset pages and associates each page with a role, expected slug/title, content contract and optional structured model.

The Research preset must follow the same pattern.

### Expected pages

Initial contract:

| key | role | purpose |
| --- | --- | --- |
| `home` | `front-page` | researcher identity and research discovery |
| `about` | `profile` | verified researcher biography and background |
| `research` | `research-hub` | active research lines and agenda |
| `publications` | `output-index` | scholarly/research outputs |
| `projects` | `project-index` | research projects |
| `software` | `software-index` | research software |
| `datasets` | `dataset-index` | research datasets |
| `cv` | `academic-cv` | academic CV presentation/download |
| `insights` | `editorial-index` | research notes and explainers |
| `contact` | `contact` | contact and academic profile links |
| `privacy-policy` | `legal` | authoritative legal content |
| `legal-notice` | `legal` | authoritative legal content |

Archive/single templates for structured output types are part of the Theme implementation even when they are not represented by a static WordPress Page record.

---

## 4. Content contracts

Every expected page must declare a `primary_intent`, `required_sections` and `internal_targets`, following the same contract used by the existing corporate preset.

Example for Research Home:

```text
primary_intent: researcher-discovery
required_sections:
- researcher-identity
- research-positioning
- active-research-lines
- selected-outputs
- verified-identifiers
- latest-insights
- primary-contact

internal_targets:
- research
- publications
- projects
- about
- cv
- contact
```

Example for Publications:

```text
primary_intent: scholarly-output-discovery
required_sections:
- publication-index-intro
- output-status-legend
- publication-list
- citation-guidance

internal_targets:
- research
- projects
- software
- datasets
```

The same principle applies to Research, Projects, Software, Datasets, About and CV.

---

## 5. Structured models and slots

The current Theme/Manager architecture detects whether a resolved page model has all required structured slots. The Research preset must use exactly the same approach.

Initial model IDs:

- `research-home-v1`
- `research-about-v1`
- `research-hub-v1`
- `research-publications-v1`
- `research-projects-v1`
- `research-software-v1`
- `research-datasets-v1`
- `research-cv-v1`
- `research-contact-v1`
- `research-insights-v1`

Examples of Research Home slots:

- `hero-eyebrow`
- `researcher-name`
- `researcher-headline`
- `hero-lead`
- `hero-primary-cta`
- `research-lines-heading`
- `research-lines-intro`
- `selected-outputs-heading`
- `selected-outputs-intro`
- `academic-identifiers-heading`
- `latest-insights-heading`
- `final-cta-heading`
- `final-cta-body`
- `final-cta-button`

Slots must be reusable and semantic. Eduardo-specific prose must never be baked into the reusable Theme.

---

## 6. Evidence-first content

The existing Manager distinguishes ordinary hydration from content that requires verified evidence before it can be written. The Research preset must strengthen this rule.

Examples that require evidence/provenance review:

- academic degrees and institutions;
- current or past affiliations;
- peer-review status;
- journal/conference acceptance;
- DOI ownership;
- citation counts and h-index;
- grants/funding;
- awards;
- datasets derived from real organisations or people;
- claims about research outcomes;
- ORCID/Scholar/OpenAlex/Scopus/WoS identity matches.

The Manager may flag these as `evidence-required`, but must not fabricate or auto-promote them.

---

## 7. SEO authority remains Theme-native

The current SEO/GEO architecture resolves a single output authority for:

- title/meta;
- canonical;
- robots;
- Open Graph;
- schema;
- hreflang;
- sitemap.

The Research preset must preserve that ownership model.

Academic SEO is an **extension of Theme-native SEO authority**, not a second SEO plugin layer.

Additional Research outputs include:

- Google Scholar-compatible `citation_*` meta;
- DOI/identifier markup;
- Person/ProfilePage entity graph;
- ScholarlyArticle / Report / Dataset / SoftwareSourceCode mappings;
- author ↔ ORCID linkage;
- publication language metadata;
- research-output sitemap rules;
- PDF/indexing rules where applicable.

No duplicate canonical/schema/meta ownership should be introduced by the Research Manager.

---

## 8. SEO/GEO + Academic GEO

The inherited GEO philosophy remains:

- clear semantic entities;
- explicit authorship;
- stable canonical URLs;
- structured internal linking;
- machine-readable structured data;
- extractable direct answers and summaries;
- trustworthy provenance;
- crawler-accessible rendered content;
- no hidden claims created only for machines.

Research adds:

- canonical researcher entity;
- stable scholarly output entities;
- explicit research-line relationships;
- citation-ready bibliographic metadata;
- persistent identifiers;
- source/evidence links;
- reproducibility assets where appropriate;
- machine-readable separation of peer-reviewed, preprint, working draft and technical output states.

---

## 9. Frontend verification

The existing Manager performs bounded rendered-page verification. The Research preset must be testable by the same mechanism.

Minimum verification surfaces:

- home;
- about;
- research;
- publications archive;
- one publication single when available;
- projects archive;
- one project single when available;
- software/dataset output when available;
- CV;
- contact;
- EN/ES alternates when enabled.

Verification should check rendered output rather than relying only on stored WordPress fields.

---

## 10. Readiness model

Research Readiness must be implemented as a specialised view over the existing SEO/GEO readiness philosophy.

Inherited categories:

- `structure`
- `content`
- `navigation`
- `seo-authority`
- `media`
- `frontend-verification`
- `operations`

Research-specific categories can extend this with:

- `research-identity`
- `academic-identifiers`
- `research-narrative`
- `research-outputs`
- `academic-discoverability`
- `external-connections`

Important: readiness is diagnostic evidence, **not** a ranking guarantee, academic-quality score or admission probability.

---

## 11. Manager safety model

The Research Manager must inherit the existing controlled mutation workflow:

```text
Detect / prepare
      ↓
Preview
      ↓
Apply
      ↓
Verify
      ↓
Rollback
```

No auto-fix classification bypasses this policy.

This is especially important for:

- canonical researcher identity;
- academic identifiers;
- publication metadata;
- DOI mappings;
- multilingual URLs;
- schema;
- ORCID/Zenodo/OpenAlex synchronization;
- external publication actions.

External writes require an additional explicit authorization gate.

---

## 12. Navigation and environment integrity

The existing Manager detects environment/domain leakage, unresolved internal destinations and orphan pages. The Research preset must inherit these checks from day one even though this installation is greenfield.

For a new site this prevents accidental links to:

- staging hosts;
- local/dev domains;
- old domain placeholders;
- wrong-language paths;
- missing output routes;
- unresolved permalink tokens.

Research-specific orphan checks should also detect outputs not linked from their research line/project where such a relationship is expected.

---

## 13. Media intelligence

The existing Theme/Manager scans image metadata and missing alt text. Research must reuse that layer and add context-specific rules:

- researcher portrait alt text;
- figures/charts with meaningful accessible description;
- publication cover/poster images;
- research software screenshots;
- dataset diagrams;
- no decorative scientific-looking imagery presented as evidence.

---

## 14. Blueprint / Content Kit interpretation

For the Research preset, the same product flow should be retained conceptually:

```text
Research Blueprint
      ↓
Research Content Kit
      ↓
Theme contracts + structured slots
      ↓
Rendered Research preset
      ↓
Manager hydration / evidence review
      ↓
SEO/GEO + Academic metadata
      ↓
Frontend verification
      ↓
Research Readiness
```

### Research Blueprint

Defines:

- canonical researcher identity;
- positioning;
- research lines;
- audiences;
- languages;
- expected pages;
- internal-link graph;
- output types;
- evidence requirements;
- academic identifier strategy.

### Research Content Kit

Provides slot-ready content and evidence references for the Theme models:

- bios;
- headlines;
- research-line narratives;
- research statement excerpts;
- verified background facts;
- CTA/contact copy;
- profile links;
- publication/project/output metadata;
- translation-ready variants.

The Content Kit does not invent missing research credentials or outputs.

---

## 15. Product boundary

### SEO/GEO Theme owns

- rendering;
- preset/page contracts;
- structured models and slots;
- native SEO/GEO output;
- academic metadata rendering;
- schema;
- hreflang;
- sitemap rules;
- frontend accessibility/performance behaviour.

### SEO/GEO Manager owns

- diagnostics;
- hydration;
- evidence requirements;
- preview/apply/verify/rollback;
- readiness;
- connection health;
- imports/reconciliation;
- audited external synchronization.

### `eduardoyauriluna-research` owns

- Eduardo Jose Yauri Luna-specific content;
- Research Blueprint for this site;
- real Content Kit;
- verified identifiers;
- research statement;
- actual projects/outputs;
- site-specific deployment/configuration decisions.

---

## Definition of architectural compliance

The `research` preset is compliant when:

1. It is selectable as a normal SEO/GEO Theme preset, alongside existing presets such as `corporate`.
2. Expected pages are resolvable through the same Theme contract mechanism.
3. Research page models expose required structured slots.
4. Missing content can be diagnosed as hydration/evidence work by the existing Manager architecture.
5. Theme-native SEO remains the single SEO output authority.
6. Rendered surfaces can be verified through the existing frontend verification layer.
7. Research Readiness extends, rather than replaces, existing launch/readiness diagnostics.
8. All mutations follow Preview → Apply → Verify → Rollback.
9. The site renders correctly without the Manager installed.
10. No Eduardo-specific content is hardcoded into reusable Theme or Manager code.
