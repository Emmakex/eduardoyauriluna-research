# Architecture Decision Record

This file records durable project decisions. New decisions should be appended rather than silently overwritten.

## ADR-001 — Canonical researcher name

**Decision:** Use **Eduardo Jose Yauri Luna** as the canonical public researcher name.

**Reason:** Consistent identity across academic systems reduces author fragmentation and ambiguity.

**Status:** Accepted

---

## ADR-002 — Canonical domain

**Decision:** Use **eduardoyauriluna.com** as the canonical public domain.

**Status:** Accepted

---

## ADR-003 — WordPress as public research hub

**Decision:** Build the public site on WordPress using the reusable SEO/GEO Theme + Manager.

**Reason:** Reuse existing product development, enable structured content management, multilingual publishing and academic-specific extensions without creating a second frontend stack.

**Status:** Accepted

---

## ADR-004 — Product code remains separate

**Decision:** Generic Researcher preset features belong in the Theme/Manager repository; this repository stores site-specific configuration, content and extensions only.

**Status:** Accepted

---

## ADR-005 — ORCID as canonical external identity

**Decision:** Prefer ORCID as the primary external researcher identifier.

**Status:** Accepted

---

## ADR-006 — No Google Scholar scraping dependency

**Decision:** Do not make Google Scholar scraping a required architectural component.

**Reason:** The site should rely on stable public metadata, profile links, import/export mechanisms and Scholar-compatible indexing rather than brittle scraping.

**Status:** Accepted

---

## ADR-007 — Publications are structured content

**Decision:** Publications, projects, research software and datasets must be modeled as structured entities rather than ordinary free-form WordPress pages.

**Status:** Accepted

---

## ADR-008 — English primary, Spanish secondary

**Decision:** Publish the research site primarily in English with Spanish as the secondary language.

**Status:** Accepted

---

## ADR-009 — Explicit research output status

**Decision:** Every research output must clearly state its type and review/publication status.

**Reason:** Avoid conflating working papers, preprints, reports and peer-reviewed publications.

**Status:** Accepted

---

## ADR-010 — Security boundary

**Decision:** Never commit academic-platform credentials or website secrets to Git.

**Status:** Accepted

---

## ADR-011 — Theme owns the frontend; Gutenberg is not the layout authority

**Decision:** The SEO/GEO Theme controls the complete public layout, component structure, responsive behaviour, semantic hierarchy and SEO/GEO rendering of Research preset pages. Gutenberg must not be used as the page-layout engine for Theme-controlled preset surfaces.

WordPress Page records may be used for routing, status, translation mapping and Theme contract resolution. Structured Theme models/slots and research entities provide the rendered data.

Gutenberg may be enabled only as a bounded rich-text editor where useful, such as the body of an Insight or a narrative field inside a Theme-defined single template. It may edit content inside a controlled slot, but it does not own the surrounding page layout.

**Reason:** Avoid fighting Gutenberg markup/CSS, layout drift, duplicated components and conflicts with Manager slot hydration. The same `research` preset must render deterministically across installations from Theme contracts and structured data.

**Reference:** `docs/14-theme-owned-frontend.md`

**Status:** Accepted

---

## ADR-012 — Apply the SEO/GEO Theme operating model end-to-end

**Decision:** The Research implementation must inherit the operating model learned from SEO/GEO Theme: reusable preset contracts, deterministic Theme rendering, structured models/slots, Theme-native SEO/GEO authority, rendered verification, readiness diagnostics and controlled mutations.

The Research Manager is the site control plane. It must be designed to create, modify, hydrate, optimise and verify Pages, Posts/Insights and research entities through structured sources rather than manual layout editing.

Manager changes must follow the established safety workflow where applicable: **Preview → Apply → Verify → Rollback**. Optimisation must cover structure, content, navigation, SEO, GEO, academic discoverability, media and multilingual consistency.

**Reason:** This architecture is the reason for using the SEO/GEO Theme path: it increases development speed, makes frontend output reproducible, centralises optimisation, prevents page-by-page drift and lets improvements be reused across installations.

**Reference:** `docs/15-manager-control-plane.md`

**Status:** Accepted

---

## ADR-013 — Manager must not depend on SSH for normal operation

**Decision:** SSH and WP-CLI are deployment, automation, recovery and support transports. They are not runtime dependencies of the Research/SEO-GEO Manager.

All normal site-management capabilities that define the sellable product must be executable from inside WordPress through the Manager control plane. The same application/service layer should be reusable by the WordPress admin UI, WP-CLI commands and a future authenticated remote API instead of implementing separate mutation paths.

The intended access model is:

- **WordPress Admin / Manager:** primary operating surface for customers;
- **WP-CLI:** optional automation and repeatable deployment surface;
- **SSH:** optional bootstrap, infrastructure maintenance and emergency/recovery surface;
- **authenticated Manager API:** future remote-management surface for managed service / multi-client operation without requiring client SSH credentials.

A client must be able to install Theme + Manager as normal WordPress packages and use the product without granting server shell access.

**Reason:** Requiring SSH would turn the Manager into an agency-only implementation tool. Access independence makes it installable, supportable and commercially reusable across shared hosting, managed WordPress, VPS, enterprise/on-premise and customer-controlled environments.

**Product rule:** New Manager capabilities are incomplete if they exist only through WP-CLI/SSH. They must live in a shared service layer and be callable from the Manager UI; transport-specific adapters may then expose the same operation through WP-CLI or a secure API.

**Reference:** `docs/19-manager-productization.md`

**Status:** Accepted

---

## ADR-014 — Manager is the WordPress website operating control plane

**Decision:** The sellable product is not primarily a deployment helper, migration utility or diagnostics dashboard. The Manager must let us operate a WordPress website with the same direct, structured workflow used on custom products such as Kairoseth or IA Empleado.

From the Manager, an authorised operator must progressively be able to create, modify, publish, optimise and verify the complete site without manually rebuilding pages in Gutenberg or moving through disconnected WordPress screens.

The product scope includes:

- creating complete pages from Theme/preset contracts;
- controlling page sections, structured content and visual/style configuration owned by the Theme;
- creating and maintaining navigation and internal relationships;
- creating, editing, scheduling and optimising blog posts / Insights;
- managing reusable media and metadata;
- managing multilingual content and route relationships;
- applying SEO optimisation to pages, posts and structured entities;
- applying GEO / machine-discoverability optimisation;
- managing schema, canonical, hreflang, metadata and indexability;
- detecting and correcting site-wide consistency/readiness problems;
- previewing, applying, verifying and rolling back meaningful controlled changes;
- supporting reusable presets so the same operating model can be deployed across many WordPress businesses.

WordPress provides the widely adopted CMS/runtime and ecosystem. The Theme provides deterministic rendering. The Manager provides the higher-level website operating layer.

The intended interaction is therefore closer to managing a custom application than to using a conventional page builder:

```text
Intent / site operation
        ↓
Manager
        ↓
shared application services
        ↓
structured WordPress resources + Theme contracts
        ↓
Theme renders the website
        ↓
Manager verifies SEO/GEO/readiness/rendered state
```

**Reason:** A large commercial opportunity exists because many companies already run WordPress. We should bring our structured, direct website-building and continuous-optimisation workflow to that installed base instead of forcing every customer onto a new bespoke stack.

**Product rule:** A feature is commercially complete when the Manager can control the relevant website outcome end-to-end. Merely exposing raw WordPress fields or reporting a problem is not sufficient when a safe deterministic action can be provided.

**Reference:** `docs/19-manager-productization.md`

**Status:** Accepted
