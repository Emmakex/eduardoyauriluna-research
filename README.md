# Eduardo Jose Yauri Luna — Research Identity Hub

Repository for the academic and research identity of **Eduardo Jose Yauri Luna** and the implementation of **eduardoyauriluna.com**.

## Purpose

Build a coherent and verifiable research presence around artificial intelligence, digital transformation, autonomous systems and applied business research.

The public website is exclusively about **Eduardo Jose Yauri Luna as a researcher**. Internal implementation, verification and management mechanisms must not become part of the visitor-facing narrative.

## Global engineering rule

All normal website content, structure and SEO/GEO operations follow this mandatory production flow:

```text
Conversation → governance → Preview/validation → Apply directly in WordPress production → Verify → result
```

A repository commit is **not** evidence that a normal website-content change is live. Repository changes are reserved for code, architecture, schemas, tests, infrastructure and engineering documentation.

Canonical repository-wide rule: [`ENGINEERING_RULES.md`](ENGINEERING_RULES.md).

## Current operating model

The site currently runs on WordPress with the Research Theme + Research Manager architecture. This is a transitional implementation while the reusable management capabilities are progressively extracted into the separate **Site Manager** product.

```text
Eduardo → ChatGPT → authenticated Research Manager → WordPress resources → Research Theme → eduardoyauriluna.com
```

For the managed Eduardo workflow:

- **ChatGPT is the primary instruction surface**;
- **Research Manager is the current authenticated execution bridge/gateway**;
- **Research Theme is the current frontend rendering authority**;
- **WordPress Admin is the local/fallback/self-service surface**;
- **WP-CLI and SSH are optional bootstrap/automation/support transports**, not normal operating dependencies.

The Research Manager and generic product code will be removed from this repository progressively as Site Manager reaches functional parity. The migration must not block development of Eduardo's research identity or academic presence.

Canonical specification: [`docs/22-chat-governed-manager-contract.md`](docs/22-chat-governed-manager-contract.md).

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
- ChatGPT-governed website operation through the current authenticated Manager bridge

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

## Repository structure

- `ENGINEERING_RULES.md` — mandatory repository-wide operating rule for production website changes
- `docs/` — current architecture, roadmap, integrations and transition decisions
- `content/` — source/reference content; not the normal publishing path for production site mutations
- `config/` — non-secret site and connector configuration
- `wordpress/` — current website implementation and transitional Research Manager code

## Architecture sources of truth

Until the Site Manager extraction is complete, use this order when deciding implementation work:

1. [`ENGINEERING_RULES.md`](ENGINEERING_RULES.md)
2. [`docs/22-chat-governed-manager-contract.md`](docs/22-chat-governed-manager-contract.md)
3. [`docs/10-decisions.md`](docs/10-decisions.md)
4. [`docs/15-manager-control-plane.md`](docs/15-manager-control-plane.md)
5. [`docs/19-manager-productization.md`](docs/19-manager-productization.md)
6. [`docs/09-roadmap.md`](docs/09-roadmap.md)
7. [`docs/21-product-vision-backlog.md`](docs/21-product-vision-backlog.md)

## Licensing

This repository is **proprietary**. See [`LICENSE`](LICENSE).

No open-source licence is granted at repository level. Third-party dependencies and separately identified third-party material remain subject to their own licences.

Reusable product components may later move to the separate Site Manager repository under whatever licence is chosen for that product. That future decision does not change the proprietary status of this repository.

## Security

Never commit API keys, OAuth client secrets, tokens, passwords or private credentials. The remote Manager must use site-specific, revocable, scoped credentials and must not expose arbitrary PHP, SQL, filesystem or shell execution.

## Current direction

- Research identity foundation: implemented and being cleaned for researcher-only presentation.
- Research Theme: operational; public copy is being separated from internal implementation language.
- Research Manager: remains temporarily in this repository while reusable capabilities are moved gradually to Site Manager.
- Academic profile priority: **ORCID → Google Scholar → OpenAlex → additional research profiles**.
- LinkedIn: supporting factual source for the website, not the primary academic profile.
