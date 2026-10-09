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

## Decision for Research

For the Research project, all upcoming Manager development must assume **Greenfield mode by default**. Migration/adoption capabilities are reusable platform concerns and must not dictate the workflow of `eduardoyauriluna.com`.
