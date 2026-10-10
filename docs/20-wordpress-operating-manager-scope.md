# WordPress Operating Manager — product scope

## Product thesis

Many companies already have WordPress. The opportunity is not to force them onto a new CMS, but to give their existing WordPress installation a higher-level operating system for building, publishing and continuously optimising the website.

The Manager should reproduce the way we work on custom products such as Kairoseth or IA Empleado:

- define the desired website outcome;
- create or modify the required resources directly;
- keep visual structure controlled by reusable contracts;
- publish editorial content;
- optimise SEO and GEO continuously;
- verify the rendered result;
- repeat improvements safely.

## Core surfaces

The Manager product should converge around these daily surfaces:

### Site

Inventory, routes, pages, menus, language relations, health and readiness.

### Pages

Create/edit Theme-controlled pages, structured sections, content slots, CTA, internal relationships and page-level SEO/GEO.

### Design

Control permitted Theme tokens and variants: palette, typography, spacing, component variants and preset-level presentation without turning Gutenberg into the layout engine.

### Blog / Insights

Create, edit, schedule, publish and optimise posts with media, taxonomy, authorship, internal linking and SEO/GEO verification.

### SEO / GEO

Analyse and remediate metadata, schema, canonicals, hreflang, indexability, internal links, entity clarity, direct-answer content, provenance/evidence and machine discoverability.

### Media

Assign assets, edit alt/descriptive metadata and detect missing, duplicate, unused or performance-problematic assets.

### Optimise

Run whole-site analysis, prioritise issues and convert deterministic findings into Preview → Apply → Verify operations.

### Connections

Manage optional external integrations and credentials through explicit, revocable boundaries.

## Non-goal

The product should not become another unrestricted visual page builder. The Theme remains responsible for coherent frontend rendering, responsive rules, accessibility and performance. The Manager controls structured intent and bounded design choices.

## Customer outcome

A normal WordPress customer should be able to operate the website from the Manager for routine work without SSH, WP-CLI or a developer touching templates manually.

The long-term managed-service variant may expose the same Manager services through a secure remote API, allowing multi-client operation without storing SSH credentials.
