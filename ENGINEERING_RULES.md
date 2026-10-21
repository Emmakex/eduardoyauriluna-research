# Global Engineering Rules — Eduardo Research

## Status

**Mandatory repository-wide engineering rule.**

This file defines the default operating rule for all work on the live website `eduardoyauriluna.com`. If a roadmap, implementation note, issue, command fixture, content source file or historical workflow conflicts with this rule, **this rule wins** unless Eduardo explicitly approves a replacement architecture decision.

## 1. Canonical operating flow for website changes

For all normal website content, structure, SEO/GEO, research-profile and presentation operations, the mandatory flow is:

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

Expanded:

```text
Eduardo → ChatGPT conversation
        → authenticated governance / Research Manager capability
        → read actual production state
        → bounded Preview / validation
        → authorised Apply against the real production WordPress resource
        → stored-state verification
        → rendered production verification when applicable
        → real result returned to Eduardo
```

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

## 3. Repository is not a staging layer for normal content operations

Do **not** use Git commits, repository content files, command fixtures or CI as an intermediate publishing workflow for ordinary website updates.

The repository may document intent, architecture, contracts, engineering rules, schemas, tests and implementation code, but the normal content lifecycle must not become:

```text
Conversation → edit repo content → commit → deploy content to WordPress
```

That is **not** the operating model for `eduardoyauriluna.com`.

## 4. Repository changes are reserved for code / architecture

Use the repository when the requested outcome requires an actual software change, for example:

- Research Theme code;
- Research Manager capability or security changes;
- command/gateway implementation;
- schemas / contracts;
- automated tests;
- CI/CD infrastructure;
- reusable code;
- engineering documentation.

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
2. inspect actual production state when needed;
3. translate intent into bounded Manager operations;
4. preview/validate material mutations;
5. apply directly to WordPress production;
6. verify stored and rendered state;
7. report what actually changed, including failures or partial results.

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

Repository commits are authoritative only for code/documentation changes, not as proof that a normal content update is live.

## 9. Related canonical contract

This global rule specialises and operationalises the repository contract in:

- `docs/22-chat-governed-manager-contract.md`

Where there is ambiguity about ordinary website operation, use this rule:

> **Conversation → governance → Preview/validation → Apply directly in WordPress production → Verify → result.**
