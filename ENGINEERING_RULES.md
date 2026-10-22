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
- content, navigation, multilingual, media, SEO/GEO, schema, research outputs and other website capabilities specifically required by this installation;
- the production control path required to govern this website safely from ChatGPT;
- the integration/cutover work required to make **Site Manager** the operational control plane for this website;
- infrastructure, tests, contracts and operational tooling required to run or verify this website safely;
- preservation, compatibility and migration handling for historical Theme / Research Manager code only as defined by the legacy rule below.

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
- generic MCP, gateway or infrastructure work that is not required by Eduardo's production control path;
- roadmap items whose primary purpose is proving applicability to other sites rather than improving or safely governing this website.

If a capability developed here later proves reusable, it may be extracted or ported to another repository, but **reusability is not a reason to expand the scope of this project**.

Any generic Site Manager or cross-site product work must be tracked and implemented in its own repository/project, currently `Emmakex/Site-Manager` where applicable. This project must not become its development backlog, acceptance environment or product roadmap.

When there is doubt, apply this test:

> **Would we still need this work if the only website that existed were `eduardoyauriluna.com`?**

If the answer is no, the work is outside this project's scope and must be moved elsewhere.

This scope rule has priority over historical issues, documents, roadmap items or experiments that attempted to turn the Eduardo Research repository into a generic Site Manager product repository.

## 0.1 Legacy preservation rule — Theme and Research Manager must no longer drive the project

The historical **Research Theme**, **Research Manager plugin**, their MCP-specific implementation, fixtures, compatibility code, scripts and related architecture are now treated as **legacy implementation assets**.

They may remain physically present in this repository while Site Manager becomes operational, but their presence **must not influence new product, architecture, content or roadmap decisions**.

Mandatory rules:

1. **Preserve before removing.** Historical Theme/Research Manager code must be kept until the equivalent Site Manager-controlled behaviour has been accepted for `eduardoyauriluna.com`. Cleanup is gradual and evidence-based; do not destroy useful migration/reference history prematurely.
2. **Legacy is not the target architecture.** Do not design a new capability merely because the old Theme or Research Manager implemented it in a certain way.
3. **No legacy feature expansion.** Do not add new Theme/Research Manager product features, generic abstractions or new architectural dependencies unless a narrowly bounded change is required to keep the live Eduardo site safe and operational during migration.
4. **Production continuity is allowed.** Bug fixes, security fixes and minimum compatibility changes to legacy code are permitted only when needed to prevent breakage or to enable the Site Manager cutover.
5. **Site Manager is the destination.** New governance capability for this website should be implemented through Site Manager, or through the smallest temporary compatibility adapter necessary to reach Site Manager safely.
6. **Generic Site Manager development stays elsewhere.** Reusable/core Site Manager work belongs in `Emmakex/Site-Manager`; this repository may contain only the Eduardo-specific integration, acceptance and cutover work needed by this website.
7. **Historical names do not define current ownership.** A file, endpoint, command, issue or document containing `Research Manager`, `Research Theme`, `Phase 2`, research-specific MCP tool names or similar historical terminology does not automatically make that mechanism canonical.
8. **Do not reactivate legacy by convenience.** When both a legacy path and an accepted Site Manager path can perform an operation, the Site Manager path wins.
9. **Migration must reduce legacy influence over time.** Every relevant migration step should leave less operational dependence on Research Theme/Research Manager-specific control logic, not more.
10. **Public website behaviour is the requirement; old implementation is not.** Preserve the visitor-facing behaviour, data and verified website semantics that remain valuable. Do not preserve obsolete implementation mechanics merely for architectural compatibility.

The practical transition model is therefore:

```text
TODAY / TRANSITION
eduardoyauriluna.com
    ↑
legacy Theme / Research Manager may still contain production dependencies
    ↑
preserved only for continuity and migration evidence

TARGET
Eduardo → ChatGPT → GitHub Command Gateway → production MCP → Site Manager → WordPress → Verify → ChatGPT
```

Until the Site Manager path has production acceptance for a capability, the existing legacy path may continue to execute that capability if required to operate the site. That temporary operational use **does not grant legacy code architectural authority**.

Once Site Manager has accepted parity for a capability, the corresponding legacy control path should be retired, isolated or archived progressively while retaining enough source/history for audit and recovery.

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

The **target canonical production transport from this ChatGPT conversation** is:

```text
Eduardo
→ ChatGPT
→ GitHub connector
→ GitHub Actions / Command Gateway
→ production MCP
→ Site Manager
→ WordPress
→ Verify
→ sanitized result to GitHub
→ ChatGPT
```

During the controlled transition, Research Manager may remain behind the gateway for capabilities not yet accepted in Site Manager. This is a temporary compatibility implementation, not the target control plane.

Expanded target lifecycle:

```text
Eduardo → ChatGPT conversation
        → governed command through the GitHub connector
        → GitHub Actions / Command Gateway
        → production MCP
        → Site Manager capability
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

This includes, when supported by the active governed capability:

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
- approved presentation/design variants exposed through the governed control plane.

A change is **not complete** because a repository file was changed or a commit passed CI. For normal website operations, completion requires a successful production Apply and Verify result.

## 3. GitHub is the governed transport, not an editorial staging layer

GitHub has two distinct roles and they must never be confused.

### 3.1 Allowed and canonical role: execution bridge

For operations initiated from this conversation, GitHub is the controlled transport between ChatGPT and the production control plane:

```text
ChatGPT
→ GitHub connector
→ GitHub Actions / Command Gateway
→ production MCP
→ Site Manager (target)
→ WordPress production
```

During migration, an explicitly bounded legacy Research Manager adapter may temporarily occupy the Site Manager position only for capabilities not yet cut over.

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

The source of truth for live content is the governed production WordPress state verified through the active production control plane.

## 4. Repository changes are reserved for Eduardo website code / architecture

Use the repository when the requested outcome requires an actual software change **for `eduardoyauriluna.com`**, for example:

- Eduardo-specific integration or compatibility code required during the Site Manager cutover;
- command/gateway implementation for Eduardo's governed production path;
- schemas / contracts used specifically by this installation;
- automated tests protecting this installation;
- CI/CD infrastructure required by this website;
- website-specific or research-site code that directly serves this installation;
- engineering documentation for this project;
- minimum legacy bug/security/compatibility fixes permitted by section 0.1.

Do **not** use this repository as the implementation location for generic Site Manager product work merely because code originated here or could be reused elsewhere. Generic extraction, portability, multi-site support and other-site acceptance belong in the dedicated Site Manager/product project.

Do **not** extend Research Theme or Research Manager as an alternative future architecture. Their source may be retained for production continuity, reference, migration, rollback and historical evidence while their operational influence is progressively removed.

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

The target behaviour is:

1. understand the requested outcome;
2. inspect actual production state through the governed production path when needed;
3. translate intent into a bounded command/action;
4. send it through the GitHub connector and Command Gateway to the production MCP;
5. preview/validate material mutations through Site Manager;
6. apply directly to WordPress production;
7. verify stored and rendered state;
8. read the sanitized result returned through GitHub;
9. report what actually changed, including failures or partial results.

During migration, step 5 may use a bounded Research Manager capability only when the equivalent Site Manager capability has not yet passed production acceptance.

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

- Site Manager / Research Manager / MCP / gateway mechanics;
- CI/CD or deployment evidence;
- tenants, command IDs or verification lifecycles;
- acceptance fixtures or test content;
- product/consultancy/service marketing;
- unsupported affiliations, degrees, publications, grants, awards or academic identifiers.

## 8. Evidence rule

For normal production operations, the authoritative completion evidence is the governed operation result plus verification of the actual production state.

The target readback chain is:

```text
WordPress production
→ Site Manager Verify
→ production MCP
→ Command Gateway / GitHub Actions
→ sanitized GitHub result
→ ChatGPT
→ Eduardo
```

During transition, Research Manager verification is acceptable only for capabilities not yet migrated to Site Manager.

Repository commits are authoritative only for code/documentation changes, not as proof that a normal content update is live.

## 9. Related canonical contracts

Historical contracts remain useful as migration/reference evidence but do not override the scope and legacy rules in this file.

The accepted Phase 2D ChatGPT/GitHub Command Bridge may be reused as a proven transport pattern while Site Manager replaces the legacy control layer.

Where there is ambiguity about ordinary website operation, these rules win:

> **Project scope:** this project is only `eduardoyauriluna.com` and Eduardo Jose Yauri Luna's researcher website.

> **Legacy rule:** preserve Theme/Research Manager source while needed, but do not let it influence new architecture, roadmap or feature decisions.

> **Governance lifecycle:** Conversation → governance → Preview/validation → Apply directly in WordPress production → Verify → result.

> **Target transport:** Eduardo → ChatGPT → GitHub connector → GitHub Actions / Command Gateway → production MCP → Site Manager → WordPress → Verify → result to GitHub → ChatGPT.
