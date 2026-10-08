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
