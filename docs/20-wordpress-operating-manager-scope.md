# WordPress Operating Manager — product scope

## Canonical operating clarification

For the Eduardo Research implementation, **ChatGPT is the primary managed operating surface and the Research Manager is the authenticated execution gateway into WordPress**. WordPress Admin remains a required local/fallback and self-service surface. The canonical contract is `docs/22-chat-governed-manager-contract.md` and overrides earlier wording that treated remote access as only a later optional layer.

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

For our managed workflow the operating chain is:

```text
Eduardo → ChatGPT → authenticated Manager connector/API → Manager services → WordPress + Theme → rendered site → verification result back to ChatGPT
```

## Core surfaces

The Manager product should converge around these daily capability groups, all callable through shared services and, where appropriate, from both local Admin and the authenticated remote control path.

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

### Remote governance

Expose bounded, authenticated operations for ChatGPT/managed control without turning the Manager into an unrestricted WordPress proxy. Remote control must provide capabilities, planning, exact-plan Apply, verification, rollback where supported, typed errors, idempotency, stale-state protection and an audit trail.

## Non-goal

The product should not become another unrestricted visual page builder or an arbitrary remote WordPress administrator proxy. The Theme remains responsible for coherent frontend rendering, responsive rules, accessibility and performance. The Manager controls structured intent and bounded design choices. Remote access must not expose arbitrary PHP, SQL, shell, filesystem or generic REST passthrough.

## Customer outcome

A normal WordPress customer should be able to operate the website locally from the Manager without SSH, WP-CLI or a developer touching templates manually.

For managed sites such as `eduardoyauriluna.com`, the intended daily workflow goes further: routine website orders are given from ChatGPT and executed through the site's authenticated Manager gateway, while WordPress Admin remains the local safety/fallback surface.

The first acceptance target is `eduardoyauriluna.com`, not a generic multi-client SaaS control center.
