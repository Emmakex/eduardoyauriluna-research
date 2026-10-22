# Eduardo Jose Yauri Luna — Research Identity Hub

Repository for the academic and research identity of **Eduardo Jose Yauri Luna** and the implementation of **eduardoyauriluna.com**.

## Purpose

Build a coherent and verifiable research presence around artificial intelligence, digital transformation, autonomous systems and applied business research.

The public website is exclusively about **Eduardo Jose Yauri Luna as a researcher**. Internal implementation, verification and management mechanisms must not become part of the visitor-facing narrative.

## Global engineering rules

All normal website content, structure and SEO/GEO operations follow this mandatory production flow:

```text
Conversation → governance → Preview/validation → Apply directly in WordPress production → Verify → result
```

A repository commit is **not** evidence that a normal website-content change is live. Repository changes are reserved for code, architecture, schemas, tests, infrastructure and engineering documentation.

Canonical rules:

- [`ENGINEERING_RULES.md`](ENGINEERING_RULES.md) — repository-wide authority and production governance.
- [`ENGINEERING_GREENFIELD_RULE.md`](ENGINEERING_GREENFIELD_RULE.md) — Greenfield architecture: build from zero with known deterministic contracts.
- [`docs/63-greenfield-hardcode-coupling-audit.md`](docs/63-greenfield-hardcode-coupling-audit.md) — current hardcode/coupling classification and remediation order.

## Current operating model — Greenfield

The site runs on WordPress with the Research Theme + Research Manager architecture. Research Manager is a provisional product name; the current implementation is the independent **Greenfield** governance track for a WordPress site designed and built by us from zero.

```text
Eduardo
→ ChatGPT
→ GitHub connector
→ GitHub Actions / Command Gateway
→ production MCP
→ Research Manager
→ WordPress
→ Research Theme
→ Verify
→ result
```

For the managed Eduardo workflow:

- **ChatGPT is the primary instruction surface**;
- **Research Manager is the current authoritative Greenfield execution/control plane**;
- **Research Theme is the current frontend rendering authority**;
- **WordPress is the live content/runtime source of truth**;
- **WordPress Admin is the local/fallback/self-service surface**;
- **WP-CLI and SSH are bootstrap/recovery/development transports**, not normal operating dependencies.

The Greenfield track evolves independently from `Emmakex/Site-Manager`. Site Manager is the separate **Brownfield** track for discovering, understanding and adapting to pre-existing client websites. Neither is the predetermined replacement for the other. They may be compared and crossed later only after sufficient independent production evidence.

Canonical governance specification: [`docs/22-chat-governed-manager-contract.md`](docs/22-chat-governed-manager-contract.md).

## Canonical identity

- Researcher name: **Eduardo Jose Yauri Luna**
- Canonical domain: **eduardoyauriluna.com**
- Primary language: **English**
- Secondary language: **Spanish**
- Primary external researcher identifier: **ORCID**

## Academic identity rollout

External academic identity should be consolidated in this order:

1. **ORCID**
2. **Google Scholar**
3. **OpenAlex**
4. Other relevant academic profiles and research services

**LinkedIn is a supporting source for biographical and professional facts needed by the research website.** It is not the primary academic identity layer and should not drive the public structure of the site.

Any external profile data used on the website must be checked before publication.

## Research platform goals

- Research profile and research statement
- Research lines and research questions
- Publications and working papers
- Research projects
- Research software and datasets when they become genuine research outputs
- DOI-aware publication records
- Academic SEO and structured metadata
- Connections with ORCID, Google Scholar, OpenAlex, Zenodo, Crossref and GitHub
- Preparation for doctoral applications and future research activity
- ChatGPT-governed website operation through the current authenticated Greenfield Manager path

## Public content principle

Verification is an internal system capability, not a public message.

The public site should present:

- Eduardo Jose Yauri Luna;
- his research agenda;
- relevant academic and professional background;
- publications and outputs when they exist;
- research notes;
- academic profiles;
- contact and collaboration information.

Empty collections, internal evidence states, implementation terminology and product mechanics should not be promoted as public content.

## Greenfield engineering principle

Greenfield is allowed to know the architecture we deliberately created. It should prefer deterministic contracts over discovery.

The relevant distinction is:

```text
Greenfield architecture
≠ Research vertical semantics
≠ Eduardo instance data
```

Research-specific behavior, evidence rules and bounded Theme contracts are intentional strengths. Accidental instance coupling and duplicated architectural truth should be removed or centralized without weakening security.

The current classification is:

- **REMOVE** — accidental/stale coupling;
- **CENTRALIZE** — valid knowledge duplicated across layers;
- **KEEP DOMAIN** — deliberate Research/Greenfield semantics;
- **KEEP SECURITY** — closed allowlists, scopes and fail-closed behavior;
- **EXTRACT LATER** — patterns to compare with Brownfield only after independent evidence.

## Repository structure

- `ENGINEERING_RULES.md` — mandatory repository-wide operating rule
- `ENGINEERING_GREENFIELD_RULE.md` — mandatory Greenfield architecture rule
- `docs/` — current architecture, decisions, acceptance evidence and audits
- `content/` — source/reference content; not the normal publishing path for production site mutations
- `config/` — non-secret site and connector configuration
- `wordpress/` — current Greenfield Theme and Research Manager implementation
- `bridge/`, `scripts/`, `commands/` — governed ChatGPT/GitHub/MCP production path

## Architecture sources of truth

Use this order when deciding implementation work:

1. [`ENGINEERING_RULES.md`](ENGINEERING_RULES.md)
2. [`ENGINEERING_GREENFIELD_RULE.md`](ENGINEERING_GREENFIELD_RULE.md)
3. [`docs/63-greenfield-hardcode-coupling-audit.md`](docs/63-greenfield-hardcode-coupling-audit.md)
4. [`docs/22-chat-governed-manager-contract.md`](docs/22-chat-governed-manager-contract.md)
5. [`docs/10-decisions.md`](docs/10-decisions.md)
6. [`docs/15-manager-control-plane.md`](docs/15-manager-control-plane.md)
7. [`docs/09-roadmap.md`](docs/09-roadmap.md)

Historical documents remain evidence/history when they conflict with later mandatory engineering rules.

## Licensing

This repository is **proprietary**. See [`LICENSE`](LICENSE).

No open-source licence is granted at repository level. Third-party dependencies and separately identified third-party material remain subject to their own licences.

A future decision to reuse or extract proven patterns elsewhere does not change the proprietary status or architectural independence of this repository.

## Security

Never commit API keys, OAuth client secrets, tokens, passwords or private credentials. The remote Manager must use site-specific, revocable, scoped credentials and must not expose arbitrary PHP, SQL, REST proxying, filesystem or shell execution.

## Current direction

- Research identity foundation: implemented and being refined for researcher-only presentation.
- Research Theme: operational and remains the deterministic frontend authority.
- Research Manager: current authoritative Greenfield governance implementation; product name is provisional.
- Greenfield hardcode/coupling cleanup: active, preserving production compatibility and security boundaries.
- Site Manager: separate Brownfield project; it does not define this repository's roadmap or architecture.
- Academic profile priority: **ORCID → Google Scholar → OpenAlex → additional research profiles**.
- LinkedIn: supporting factual source for the website, not the primary academic profile.
