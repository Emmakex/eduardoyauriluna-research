# Research Manager as SEO/GEO control plane

## Principle

The Research implementation must reuse the architecture and operational lessons of the existing SEO/GEO Theme + Manager.

The reason for this architecture is not cosmetic. It gives us:

- faster site creation;
- deterministic frontend rendering;
- reusable presets;
- consistent SEO/GEO output;
- structured content instead of page-by-page rebuilding;
- safer bulk changes;
- measurable readiness;
- controlled optimisation;
- reproducible deployments across sites.

The Theme owns rendering. The Manager owns controlled creation, modification, optimisation and verification.

---

## 1. Theme = deterministic rendering engine

The SEO/GEO Theme must remain the single rendering authority for Theme-controlled surfaces.

It owns:

- page templates;
- post/output templates;
- semantic HTML;
- component hierarchy;
- responsive layout;
- design tokens;
- navigation presentation;
- structured-data rendering;
- SEO/GEO output;
- academic metadata rendering;
- hreflang/canonical presentation;
- empty/fallback states;
- accessibility behaviour.

The Theme receives structured data and renders it consistently. It does not require Gutenberg layouts to produce the public frontend.

---

## 2. Manager = control plane

The Manager is the operational control surface for the whole site.

It must ultimately be able to manage the lifecycle of:

- Pages;
- Posts / Insights;
- Publications;
- Research Projects;
- Research Software;
- Datasets;
- Research Lines;
- Researcher Profile;
- Academic identifiers;
- navigation;
- media metadata;
- structured slots;
- SEO/GEO metadata;
- academic metadata;
- internal links;
- translations;
- external research connections;
- readiness and verification.

The Manager should not be limited to a dashboard that reports problems. It must be able to prepare safe changes and, when authorised, execute them through the established mutation workflow.

---

## 3. Creation workflow

Creating a Theme-controlled Page or structured output should be possible from the Manager without manually composing the frontend.

Example:

```text
Create Research page
      ↓
select preset/page role
      ↓
Manager creates/resolves WordPress resource
      ↓
assign page_key + model_id
      ↓
hydrate structured slots
      ↓
Theme renders page
      ↓
verify rendered frontend
      ↓
readiness updated
```

For a Publication:

```text
Create Publication
      ↓
structured bibliographic fields
      ↓
review/output status
      ↓
research-line/project relations
      ↓
DOI/identifier metadata when available
      ↓
Theme single template
      ↓
Scholar/Schema/SEO metadata
      ↓
frontend verification
```

No manual layout reconstruction is required.

---

## 4. Modification workflow

A content change should mutate the structured source, not arbitrary frontend markup.

Examples:

- edit researcher headline → Researcher Profile / Theme slot;
- edit Research introduction → `research-hub-v1` slot;
- change publication DOI → Publication metadata;
- change CTA → structured page slot;
- reorder research lines → structured relationship/order field;
- update internal target → relationship/navigation model;
- change title/meta → Theme-native SEO field/adapter;
- update alt text → Media metadata.

The Theme immediately renders the new state from the same contract.

---

## 5. Optimisation workflow

The Manager should turn SEO/GEO optimisation into structured operations rather than ad-hoc page editing.

It should analyse and optimise:

### Structure

- expected preset pages;
- missing routes;
- orphan content;
- internal-link graph;
- wrong environment/domain URLs;
- permalink problems;
- language-route consistency.

### Content

- missing required slots;
- weak or absent direct-answer sections;
- incomplete researcher/entity descriptions;
- missing relationships between Research Lines and outputs;
- missing evidence for claims;
- content freshness/update metadata.

### SEO

- title/meta;
- canonical;
- robots;
- sitemap inclusion;
- hreflang;
- Open Graph;
- structured data;
- indexability.

### GEO / machine discoverability

- entity clarity;
- authorship;
- extractable summaries;
- source/provenance links;
- internal semantic relationships;
- crawlable rendered text;
- structured research entities;
- research output status and identifiers.

### Academic discoverability

- Scholar-compatible citation metadata;
- DOI completeness;
- ORCID linkage;
- publication-specific canonical URLs;
- ScholarlyArticle/Report/Dataset/SoftwareSourceCode mappings;
- original publication language;
- persistent identifiers.

### Media

- alt text;
- missing descriptive context;
- duplicate/unused assets;
- figure accessibility;
- image dimensions/performance.

---

## 6. Mutation safety

All Manager writes inherit the established SEO/GEO mutation discipline:

```text
Analyse
   ↓
Prepare change
   ↓
Preview
   ↓
Apply
   ↓
Verify stored state
   ↓
Verify rendered frontend
   ↓
Rollback if needed
```

A deterministic fix being available does not mean it may bypass preview/verification policy.

For research-critical or external actions, extra gates are required.

Examples:

- changing ORCID or DOI mapping;
- changing review status;
- publishing to Zenodo;
- writing to an external academic system;
- deleting a research output;
- changing canonical researcher identity.

---

## 7. Manager should know the Theme contract

The Manager must not treat WordPress as an arbitrary collection of pages.

For every Theme-controlled resource it should know:

- preset;
- page key / entity type;
- role;
- model ID;
- required slots;
- optional slots;
- `required_any` groups;
- verification/evidence groups;
- internal targets;
- SEO owner;
- language mapping;
- rendered URL;
- readiness state.

This is what allows the Manager to create and optimise reliably instead of guessing from HTML.

---

## 8. Research Blueprint → automated build path

The goal is to reuse the same speed advantage that motivated the SEO/GEO Theme architecture.

```text
Research Blueprint
        ↓
Research Content Kit
        ↓
Manager creates required resources
        ↓
Manager hydrates Theme models/slots
        ↓
Theme renders full frontend
        ↓
Manager verifies structure/content/navigation
        ↓
SEO/GEO + Academic SEO verification
        ↓
Research Readiness
```

This should make a Research site reproducible from configuration/content rather than handcrafted page composition.

---

## 9. Pages and Posts

The Manager must cover ordinary WordPress content too.

### Pages

For Theme-controlled preset pages:

- create page record;
- assign expected role/page key;
- resolve slug/permalink;
- manage structured slots;
- manage language relation;
- manage SEO/GEO metadata;
- verify frontend.

### Posts / Insights

For editorial content:

- create/update post;
- manage title, excerpt, author, dates and taxonomy;
- allow bounded rich body content;
- manage internal research relationships;
- optimise SEO/GEO fields;
- verify rendered article;
- detect missing author/entity/context/related links.

The body may use Gutenberg as a text-content editor where explicitly allowed, but the Theme still controls the surrounding page template.

---

## 10. Research entities

The same control model extends to CPTs/entities:

### Publication

- create/edit/archive;
- authors and order;
- status/type;
- bibliographic fields;
- DOI;
- citations;
- relations;
- Scholar metadata;
- Schema;
- visibility/indexability.

### Research Project

- question;
- methods;
- status;
- dates;
- related outputs;
- collaborators/evidence;
- structured project page.

### Research Software

- repository;
- release/version;
- citation;
- DOI/archive;
- documentation;
- related research.

### Dataset

- creators;
- version;
- DOI;
- methodology;
- provenance;
- licence/access/privacy;
- related publications/projects.

---

## 11. Readiness is operational

Readiness is not merely a score.

Every failing check should ideally map to one of:

- auto-fix candidate;
- structured hydration action;
- manual editorial review;
- evidence-required action;
- external-connection action;
- environment/configuration action.

The Manager should tell us not only **what is wrong**, but **what the next controlled action is**.

---

## 12. Definition of success

We have successfully applied the SEO/GEO Theme lessons when:

1. A new Research site can be created primarily from preset + structured content, not manual page design.
2. The same Theme contracts drive frontend and Manager diagnostics.
3. The Manager can create and modify Pages, Posts and research entities without rebuilding layouts.
4. SEO/GEO optimisation is performed through structured fields/contracts and verified on rendered output.
5. Bulk or repeated improvements can be applied consistently across the site.
6. Gutenberg remains a bounded content editor, not the layout architecture.
7. Research-specific academic metadata is integrated into the existing Theme-native SEO authority.
8. Every meaningful Manager mutation can be previewed, verified and rolled back where appropriate.
9. Readiness produces actionable next steps.
10. Improvements developed for Research remain reusable in the broader SEO/GEO Theme + Manager product.
