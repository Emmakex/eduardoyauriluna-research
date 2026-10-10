# Eduardo Jose Yauri Luna — Research Identity Hub

Repository for the academic and research identity of **Eduardo Jose Yauri Luna** and the implementation of **eduardoyauriluna.com**.

## Purpose

Build a coherent, verifiable and reusable research presence around artificial intelligence, digital transformation, autonomous systems and applied business research.

The site runs on WordPress with the Research Theme + Research Manager architecture. The Theme owns deterministic public rendering. The Manager owns controlled site operations.

## Canonical operating model

The current product/workflow direction is:

```text
Eduardo → ChatGPT → authenticated Research Manager → shared Manager services → WordPress resources → Research Theme → rendered eduardoyauriluna.com → verification back to ChatGPT
```

For the managed Eduardo workflow:

- **ChatGPT is the primary instruction surface**;
- **Research Manager is the authenticated execution bridge/gateway**;
- **Research Theme is the frontend rendering authority**;
- **WordPress Admin is the local/fallback/self-service surface**;
- **WP-CLI and SSH are optional bootstrap/automation/support transports**, not normal operating dependencies.

Canonical specification: [`docs/22-chat-governed-manager-contract.md`](docs/22-chat-governed-manager-contract.md).

Primary execution tracker: **issue #97 — ChatGPT-governed Manager for eduardoyauriluna.com (M1–M8)**.

Do not replace this path with speculative multi-client Control Center work before the Eduardo single-site flow is proven end to end.

## Canonical identity

- Researcher name: **Eduardo Jose Yauri Luna**
- Canonical domain: **eduardoyauriluna.com**
- Primary language: **English**
- Secondary language: **Spanish**
- Preferred external researcher identifier: **ORCID**

## Research platform goals

- Academic profile and research statement
- Research lines and projects
- Publications and working papers
- Research software and datasets
- DOI-aware publication records
- Academic SEO and structured metadata
- Connections with ORCID, Google Scholar, Zenodo, OpenAlex, Crossref and GitHub
- Research Readiness dashboard
- ChatGPT-governed website operation through the authenticated Manager bridge
- Preparation for doctoral applications in 2027

## Repository structure

- `docs/` — architecture, roadmap, integrations and decisions
- `content/` — source content for biography, research, projects and publications
- `config/` — non-secret site and connector configuration examples
- `wordpress/` — Research Theme and Research Manager implementation

## Architecture sources of truth

Use this order when deciding what to build:

1. [`docs/22-chat-governed-manager-contract.md`](docs/22-chat-governed-manager-contract.md)
2. [`docs/10-decisions.md`](docs/10-decisions.md)
3. [`docs/15-manager-control-plane.md`](docs/15-manager-control-plane.md)
4. [`docs/19-manager-productization.md`](docs/19-manager-productization.md)
5. [`docs/09-roadmap.md`](docs/09-roadmap.md)
6. [`docs/21-product-vision-backlog.md`](docs/21-product-vision-backlog.md)

## Security

Never commit API keys, OAuth client secrets, tokens, passwords or private credentials. The remote Manager must use site-specific, revocable, scoped credentials and must not expose arbitrary PHP, SQL, filesystem or shell execution.

## Current status

- Research identity foundation: implemented/documented.
- Research Theme: operational in repository/CI; real-site acceptance remains separate.
- Research Manager core: substantial implementation exists for Pages, Insights, Research objects, translations, evidence, diagnostics, remediation, rendered verification and academic connections.
- ChatGPT ↔ Manager authenticated bridge: **not yet implemented; current primary engineering work**.

See [`docs/16-current-implementation-status.md`](docs/16-current-implementation-status.md) for the current implementation state and [`docs/09-roadmap.md`](docs/09-roadmap.md) for M1–M8 execution order.
