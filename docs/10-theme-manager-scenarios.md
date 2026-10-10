# Theme + Manager — Two Operating Scenarios

## Purpose

The reusable SEO/GEO Theme + Manager architecture must explicitly distinguish two different operating scenarios. They share the same principles and reusable components, but the Manager must not apply migration/audit logic when the target is a new WordPress installation.

This repository (`eduardoyauriluna-research`) is **Scenario A: Greenfield**.

---

## Scenario A — Greenfield / New website

### Context

A completely new WordPress installation with no legacy website, content model, page structure or frontend that needs to be preserved.

### Principle

The Manager does **not** need to interpret the existing website because there is nothing to interpret.

The Theme and Manager start from a known contract:

`Manager -> structured content/configuration -> Theme -> frontend`

### Theme responsibility

The Theme owns the frontend:

- visual system and design tokens;
- layouts and templates;
- components;
- responsive behaviour;
- performance-oriented rendering;
- semantic HTML;
- technical SEO/GEO presentation layer;
- controlled frontend behaviour without depending on Gutenberg for page composition.

### Manager responsibility

The Manager creates and maintains the website directly from the predefined Theme contract:

- pages;
- posts and research insights;
- publications;
- research projects;
- researcher profile;
- research lines;
- taxonomies;
- structured metadata;
- SEO/GEO fields;
- internal relationships and navigation;
- multilingual content where applicable;
- optimisation and validation of managed resources.

### What is NOT required

The Greenfield Manager must not require a preliminary legacy-analysis pipeline such as:

- scan an existing website;
- infer the existing information architecture;
- interpret legacy page builders;
- map unknown templates;
- preserve legacy URLs unless they are deliberately defined;
- migration compatibility analysis;
- content extraction from an old installation;
- migration-oriented Preview -> Apply -> Rollback as a prerequisite for normal content creation.

Validation, versioning, previews and rollback may still exist as quality/safety features, but they are **not an interpretation or migration mechanism**.

### Current project

`eduardoyauriluna.com` belongs to this scenario. WordPress starts from zero and the Research Theme defines the frontend contract. The Research Manager should therefore operate natively against that contract and create the academic website directly.

---

## Scenario B — Existing website / Migration or adoption

### Context

An existing WordPress website or another existing web property already contains content, URLs, metadata, templates, navigation, SEO signals or business rules that need to be retained, transformed or migrated.

### Principle

Before changing the site, the Manager must understand the current state and build a controlled mapping between legacy resources and the target Theme contract.

Typical flow:

`Existing site -> scan/audit -> interpretation + mapping -> preview/plan -> apply -> verify -> target Theme`

### Manager responsibility

Depending on the migration, the Manager may need to:

- inventory existing pages, posts, custom post types and taxonomies;
- inspect URLs, redirects and canonical relationships;
- detect existing metadata and structured data;
- identify page-builder or theme dependencies;
- map legacy content to the new Theme structures;
- preserve or deliberately redirect valuable URLs;
- detect collisions and unsupported structures;
- preview planned changes;
- apply transformations in controlled batches;
- verify results;
- keep evidence/snapshots where useful;
- support rollback/recovery for migration operations.

In this scenario, interpretation is necessary because the source state is not guaranteed to follow the Manager's native contract.

---

## Shared architecture vs scenario-specific behaviour

Both scenarios should reuse the same core wherever possible: content schemas, SEO/GEO rules, Theme contracts, validation, resource services and optimisation logic.

The difference is the **entry path**:

- **Greenfield:** create directly from a known contract.
- **Existing/Migration:** discover and map the unknown/legacy state before writing to the target contract.

Migration complexity must therefore remain isolated from the normal Greenfield creation path. A new website must never pay the architectural or operational cost of pretending that it is a migration.

---

## Two real validation sites — fixed product path

The Manager is intentionally being validated on **two real WordPress sites** that represent the two operating scenarios. This is the current product-development path and must not be replaced by speculative platform work before both scenarios are proven in practice.

### Test bed A — `eduardoyauriluna.com`

**Scenario:** Greenfield / new website.

Purpose:

- validate the Research Theme as a Theme-owned frontend from a clean WordPress installation;
- validate direct creation of pages, research entities, navigation, multilingual content and editorial content from known contracts;
- validate Manager-controlled SEO/GEO and academic discoverability;
- validate that the Manager can create, modify, optimise, publish and verify the site without first interpreting legacy WordPress state;
- expose any operation that still unnecessarily depends on SSH/WP-CLI so it can be moved into the normal Manager product surface.

This site is the reference for **native creation and operation**.

### Test bed B — `emmake.com`

**Scenario:** Existing website / migration-adoption.

Purpose:

- validate the generic SEO/GEO Theme + Manager against a real existing WordPress site;
- validate scan/audit, mapping and controlled adoption of existing content and URLs;
- validate preservation of valuable SEO state while moving frontend control toward the Theme contract;
- validate page, blog, media, navigation and SEO/GEO operations on a non-greenfield installation;
- validate that migration/adoption complexity stays isolated from the normal Greenfield workflow.

This site is the reference for **existing-site adoption and optimisation**.

### Shared validation target

Both sites must prove the same end-state capability:

`Manager -> create / modify / style / publish / optimise SEO+GEO / verify -> WordPress site`

The implementation may enter through different paths, but the reusable Manager services, Theme contracts and optimisation logic should converge wherever possible.

### Development guardrail

Until these two sites have validated the Manager end to end, product development should prioritise gaps discovered while operating them. Remote API, multi-client control-center and other broader platform capabilities remain future layers; they must not displace the two-site validation path.

When discussing roadmap or next steps, always identify which of the two scenarios is being advanced and preserve the distinction between:

1. implemented in repository;
2. verified by CI/tests;
3. validated on `eduardoyauriluna.com` or `emmake.com` in the corresponding real scenario.

---

## Decision for Research

For the Research project, all upcoming Manager development must assume **Greenfield mode by default**. Migration/adoption capabilities are reusable platform concerns and must not dictate the workflow of `eduardoyauriluna.com`.
