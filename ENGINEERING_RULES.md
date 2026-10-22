# Global Engineering Rules — Eduardo Research

## Status

**Mandatory repository-wide engineering rule.**

This file defines the default operating rule for all work on the live website `eduardoyauriluna.com`. If a roadmap, implementation note, issue, command fixture, content source file or historical workflow conflicts with this rule, **this rule wins** unless Eduardo explicitly approves a replacement architecture decision.

## 0. Exclusive project scope — Eduardo Jose Yauri Luna website only

This repository and this project exist **exclusively** to build, operate, govern, improve and maintain the website `eduardoyauriluna.com` for **Eduardo Jose Yauri Luna** and his researcher identity.

Everything in this repository must have a direct, defensible relationship to that website.

### In scope

- the public website `eduardoyauriluna.com`;
- Eduardo Jose Yauri Luna's researcher identity and public research presence;
- the Research Theme used by this website;
- the Research Manager capabilities required to govern this website;
- the production MCP tenant `eduardo-research` used to operate this website;
- the GitHub Command Gateway path required to govern this website from ChatGPT;
- content, navigation, multilingual, media, SEO/GEO, schema, research outputs and other website capabilities specifically required by this installation;
- infrastructure, tests, contracts and operational tooling required to run or verify this website safely.

### Explicitly out of scope

The following do **not** belong in this project unless they are strictly necessary to support `eduardoyauriluna.com` itself:

- generic Site Manager product development;
- development for other WordPress sites;
- client websites or acceptance environments unrelated to Eduardo's website;
- multi-site or multi-client productization;
- generic WordPress portability work whose purpose is another installation;
- Kairoseth Platform product work;
- IA Empleado or other unrelated products;
- EMMake, Barcelona Confort or any other business/site project;
- generic MCP, gateway or infrastructure work that is not required by the `eduardo-research` production path;
- roadmap items whose primary purpose is proving applicability to other sites rather than improving or safely governing this website.

If a capability developed here later proves reusable, it may be extracted or ported to another repository, but **reusability is not a reason to expand the scope of this project**.

Any generic Site Manager or cross-site product work must be tracked and implemented in its own repository/project, currently `Emmakex/Site-Manager` where applicable. This project must not become its development backlog, acceptance environment or product roadmap.

When there is doubt, apply this test:

> **Would we still need this work if the only website that existed were `eduardoyauriluna.com`?**

If the answer is no, the work is outside this project's scope and must be moved elsewhere.

This scope rule has priority over historical issues, documents, roadmap items or experiments that attempted to turn the Eduardo Research repository into a generic Site Manager product repository.

## 1. Canonical operating flow for website changes

For all normal website content, structure, SEO/GEO, research-profile and presentation operations, the mandatory governance lifecycle is:

```text
Conversation
    ↓
Governance
    ↓
Preview / validation
    ↓
Apply directly in WordPress production
    ↓
Verify
    ↓
Result reported back in the conversation
```

The **canonical production transport from this ChatGPT conversation** is:

```text
Eduardo
→ ChatGPT
→ GitHub connector
→ GitHub Actions / Kairoseth Command Gateway
→ production MCP tenant (`eduardo-research`)
→ Research Manager
→ WordPress
→ Verify
→ sanitized result to GitHub
→ ChatGPT
```

This is the accepted operator path for governing `eduardoyauriluna.com` from ChatGPT.

Expanded lifecycle:

```text
Eduardo → ChatGPT conversation
        → governed command through the GitHub connector
        → GitHub Actions / Kairoseth Command Gateway
        → selected production MCP tenant `eduardo-research`
        → Research Manager capability
        → read actual production state
        → bounded Preview / validation
        → authorised Apply against the real production WordPress resource
        → stored-state verification
        → rendered production verification when applicable
        → sanitized execution result returned through GitHub
        → ChatGPT reports the real outcome to Eduardo
```

A direct `ChatGPT → MCP` channel may be used in the future when explicitly available and authorised, but it is **not required** for the canonical managed flow above.

## 2. Production is the normal target

Normal managed website work is performed against the **real production WordPress site**.

This includes, when supported by the Manager capability:

- pages and page sections;
- Home / About / Research / CV / Insights / Contact;
- Research Lines;
- Insights / Research Notes;
- Publications;
- Projects;
- Research Software;
- Datasets;
- researcher profile fields;
- academic identifiers and profile links;
- navigation;
- translations and EN/ES relationships;
- media metadata and bounded media assignments;
- internal linking;
- SEO titles and descriptions;
- canonical / hreflang relationships;
- Schema and other machine-readable SEO/GEO state;
- approved Theme variants exposed as Manager capabilities.

A change is **not complete** because a repository file was changed or a commit passed CI. For normal website operations, completion requires a successful production Apply and Verify result.

## 3. GitHub is the governed transport, not an editorial staging layer

GitHub has two distinct roles and they must never be confused.

### 3.1 Allowed and canonical role: execution bridge

For operations initiated from this conversation, GitHub is the controlled transport between ChatGPT and the production MCP:

```text
ChatGPT
→ GitHub connector
→ GitHub Actions / Kairoseth Command Gateway
→ MCP `eduardo-research`
→ Research Manager
→ WordPress production
```

The bridge may carry validated command envelopes, invoke bounded actions, enforce tenant/capability rules, preserve idempotency and return sanitized verification results.

### 3.2 Forbidden normal-content role: repository staging

Do **not** use Git commits, repository content files, command fixtures or CI as an intermediate publishing store for ordinary website updates.

The repository may document intent, architecture, contracts, engineering rules, schemas, tests and implementation code, but the normal content lifecycle must not become:

```text
Conversation → edit repo content → commit → deploy that content to WordPress
```

That is **not** the operating model for `eduardoyauriluna.com`.

Therefore:

> **GitHub is part of the command transport, but the repository is not the source of truth or staging area for normal WordPress content.**

The source of truth for live content is the governed production WordPress state verified through Research Manager/MCP.

## 4. Repository changes are reserved for Eduardo website code / architecture

Use the repository when the requested outcome requires an actual software change **for `eduardoyauriluna.com`**, for example:

- Research Theme code used by Eduardo's website;
- Research Manager capability or security changes required by Eduardo's website;
- command/gateway implementation for the `eduardo-research` governed path;
- schemas / contracts used by this installation;
- automated tests protecting this installation;
- CI/CD infrastructure required by this website;
- website-specific or research-site code that directly serves this installation;
- engineering documentation for this project.

Do **not** use this repository as the implementation location for generic Site Manager product work merely because code originated here or could be reused elsewhere. Generic extraction, portability, multi-site support and other-site acceptance belong in the dedicated Site Manager/product project.

Even then, a code change must not be confused with a completed production-content change. After code reaches production, any content operation still follows the governed production flow above.

## 5. Mandatory mutation lifecycle

Material production mutations use the following lifecycle:

```text
REQUESTED
→ VALIDATED
→ PLANNED
→ PREVIEWED
→ AUTHORIZED
→ APPLIED
→ STORED_VERIFIED
→ RENDERED_VERIFIED
→ COMPLETED
```

Allowed non-success outcomes include:

```text
BLOCKED
FAILED
ROLLED_BACK
PARTIALLY_VERIFIED
REQUIRES_HUMAN_REVIEW
```

A task must not be reported as completed unless the applicable verification has succeeded.

## 6. Conversation is the primary operating surface

The ChatGPT conversation is the primary control surface for Eduardo.

The expected behaviour is:

1. understand the requested outcome;
2. inspect actual production state through the governed MCP path when needed;
3. translate intent into a bounded command/action;
4. send it through the GitHub connector and Kairoseth Command Gateway to tenant `eduardo-research`;
5. preview/validate material mutations through Research Manager;
6. apply directly to WordPress production;
7. verify stored and rendered state;
8. read the sanitized result returned through GitHub;
9. report what actually changed, including failures or partial results.

WordPress Admin remains a fallback/admin surface. SSH, direct database edits, arbitrary PHP, FTP and ad-hoc WP-CLI writes are not normal content-operation paths.

## 7. Researcher-only public-content rule

The public website exists exclusively to present **Eduardo Jose Yauri Luna as a researcher**.

Public-facing content must therefore prioritise:

- researcher identity;
- research agenda and research questions;
- relevant academic and professional background;
- genuine publications and research outputs when they exist;
- Research Notes / Insights with genuine research value;
- academic profiles and identifiers when verified;
- contact and research collaboration information.

Do not expose as public narrative:

- Research Manager / MCP / gateway mechanics;
- CI/CD or deployment evidence;
- tenants, command IDs or verification lifecycles;
- acceptance fixtures or test content;
- product/consultancy/service marketing;
- unsupported affiliations, degrees, publications, grants, awards or academic identifiers.

## 8. Evidence rule

For normal production operations, the authoritative completion evidence is the governed operation result plus verification of the actual production state.

The expected readback chain is:

```text
WordPress production
→ Research Manager Verify
→ production MCP
→ Kairoseth Command Gateway / GitHub Actions
→ sanitized GitHub result
→ ChatGPT
→ Eduardo
```

Repository commits are authoritative only for code/documentation changes, not as proof that a normal content update is live.

## 9. Related canonical contracts

This global rule specialises and operationalises the repository contracts and accepted production bridge, including:

- `docs/22-chat-governed-manager-contract.md`
- Phase 2D accepted ChatGPT/GitHub Command Bridge to production MCP

Where there is ambiguity about ordinary website operation, use both of these rules together:

> **Governance lifecycle:** Conversation → governance → Preview/validation → Apply directly in WordPress production → Verify → result.

> **Canonical transport from here:** Eduardo → ChatGPT → GitHub connector → GitHub Actions / Kairoseth Command Gateway → MCP production (`eduardo-research`) → Research Manager → WordPress → Verify → result to GitHub → ChatGPT.
