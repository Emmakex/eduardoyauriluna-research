# Global Engineering Rules — Eduardo Jose Yauri Luna Research

## Status

**Mandatory repository-wide engineering rule.**

This file defines the governing scope and operating architecture for this project. If a roadmap, issue, historical document, command fixture, old implementation, experiment or previous architecture statement conflicts with this file, **this file wins** unless Eduardo explicitly replaces it with a later architecture decision.

# 0. Project authority — Eduardo only

This project exists **only for Eduardo Jose Yauri Luna**.

Its two allowed purposes are:

1. build, operate, govern, improve and maintain `eduardoyauriluna.com` as Eduardo's researcher website; and
2. create the digital, research and academic infrastructure that Eduardo genuinely needs for his doctoral/research trajectory.

No other company, client, generic product or unrelated website has authority over this project.

## 0.1 In scope

Work is in scope when it directly serves Eduardo's website, researcher identity, research activity or doctoral work, including:

- `eduardoyauriluna.com` and its WordPress installation;
- Eduardo's researcher identity and public academic presence;
- Home, About, Research, CV, Insights / Research Notes and Contact;
- research lines, questions, hypotheses and research agenda;
- publications and publication metadata when genuine;
- research projects, software, datasets and reproducibility material when genuine;
- academic identifiers and verified profiles such as ORCID, OpenAlex, Google Scholar, Crossref/DOI or equivalent sources when applicable;
- bibliography, citations, research-output records and machine-readable scholarly metadata;
- research notes, literature-related content and other website structures useful for the doctorate;
- academic SEO/GEO, schema, canonical, hreflang, knowledge-graph and researcher discoverability;
- multilingual EN/ES website operation;
- media, navigation, internal linking and presentation required by Eduardo's website;
- Research Manager, its production MCP path and Command Gateway while they are the accepted production governance system;
- the gradual migration from Research Manager to Site Manager;
- Eduardo-specific adapters, contracts, tests and acceptance needed for that migration;
- infrastructure required to operate, secure, verify or recover Eduardo's website and doctoral/research digital assets;
- tooling created specifically because Eduardo's doctoral/research workflow needs it.

The test for new work is:

> **Does this directly help operate Eduardo's website or advance/support Eduardo's real researcher/doctoral work?**

If the answer is no, it does not belong in this project.

## 0.2 Explicitly out of scope

The following are outside this project unless a narrowly bounded dependency is genuinely required by Eduardo's website or doctoral work:

- generic Site Manager product development;
- development or acceptance for unrelated WordPress installations;
- client websites;
- EMMake, Kairoseth Platform, IA Empleado, Barcelona Confort or any other business/product project;
- multi-client or multi-site productization;
- generic WordPress portability whose purpose is another site;
- generic MCP/gateway infrastructure unrelated to Eduardo's production path;
- using this repository as the backlog or proving ground for `Emmakex/Site-Manager`;
- consultancy/service marketing unrelated to Eduardo's researcher identity;
- invented academic output, affiliation, degree, grant, award, publication or identifier.

Reusable work may later be extracted to another repository, but **reusability does not expand this project's scope**.

# 1. Current production authority — Research Manager

Until Site Manager has demonstrated and accepted production parity for the required capability, **Research Manager remains the authoritative operational control plane for `eduardoyauriluna.com`.**

This is not merely a compatibility fallback. It is the currently accepted system used to govern the live site.

The current canonical production path is:

```text
Eduardo
→ ChatGPT
→ GitHub connector
→ GitHub Actions / Command Gateway
→ production MCP (`eduardo-research`)
→ Research Manager
→ WordPress
→ Verify
→ sanitized result to GitHub
→ ChatGPT
```

For capabilities already governed safely by Research Manager, continue using Research Manager until the corresponding Site Manager capability has passed explicit production acceptance for Eduardo's site.

Research Manager may therefore continue to receive:

- bug fixes;
- security fixes;
- operational fixes;
- narrowly scoped capability work genuinely required by Eduardo's website or doctoral/research workflow;
- changes required to preserve safe governance during the transition.

Do **not** freeze or weaken Eduardo's live website merely because Site Manager is planned for the future.

# 2. Site Manager is the replacement target, not the current authority

**Site Manager is the intended future control plane**, but it does not become authoritative merely because code exists in `Emmakex/Site-Manager`.

A capability moves from Research Manager to Site Manager only after Site Manager proves on `eduardoyauriluna.com` that it can provide equivalent or stronger governance, including the applicable parts of:

```text
Read actual state
→ Plan / Preview
→ explicit authorization where required
→ Apply
→ Verify stored state
→ Verify rendered state where applicable
→ Rollback / recovery where applicable
→ audit/result readback
```

Migration is **capability by capability**, not a big-bang replacement.

For every capability:

```text
Research Manager authoritative
        ↓
Site Manager parity implementation
        ↓
Eduardo-site production acceptance
        ↓
Site Manager becomes authoritative for that capability
        ↓
old Research Manager path retired/isolated
```

Until that final acceptance occurs, Research Manager remains authoritative for that capability.

Generic/core Site Manager development belongs in `Emmakex/Site-Manager`. This repository contains only the Eduardo-specific integration, acceptance, compatibility and cutover work required to adopt it safely.

# 3. Historical code and residue rule

The repository contains historical Theme, plugin, MCP, fixture, script and earlier architecture code accumulated while building the current system.

That material must be handled carefully.

## 3.1 Preserve first

Do not mass-delete historical code simply because a newer architecture exists.

Keep it while it may still be needed for:

- live production behaviour;
- rollback;
- migration parity analysis;
- recovery;
- audit/history;
- extracting valuable contracts or behaviour.

## 3.2 Residue must not steer new decisions

Historical code that is **not part of the current accepted production path** must not influence roadmap or architecture simply because it exists.

Old fixtures, abandoned experiments, superseded Phase code, obsolete scripts and replaced abstractions are evidence/history — not requirements.

## 3.3 Research Manager is not residue while it governs production

Do not classify the active Research Manager control plane itself as irrelevant legacy while it still governs `eduardoyauriluna.com`.

The distinction is mandatory:

- **Research Manager active production capabilities:** authoritative until individually replaced by accepted Site Manager capabilities.
- **obsolete or superseded Research Manager/Theme implementation residue:** preserve temporarily but do not let it drive new design.
- **Research Theme rendering still required by the live site:** maintain only as needed for Eduardo's live visitor-facing behaviour until safely replaced/refactored.

## 3.4 Gradual retirement

As Site Manager assumes accepted capabilities, retire the corresponding obsolete code progressively.

Every migration step should reduce unnecessary historical coupling while preserving:

- Eduardo's data;
- URLs;
- SEO/GEO value;
- researcher semantics;
- production availability;
- rollback/recovery evidence.

# 4. Website source of truth

The real governed WordPress production state is the source of truth for live website content.

A normal website task is **not complete** because a repository file changed or CI passed.

For normal site operations, completion requires the applicable production Apply and Verify evidence.

GitHub is the governed command transport and engineering repository; it is not the editorial source of truth for WordPress content.

Do not turn normal editing into:

```text
Conversation → edit repository content → commit → publish repository content to WordPress
```

The normal model is:

```text
Conversation
→ governed control plane
→ actual WordPress production state
→ Apply
→ Verify
→ result
```

# 5. Conversation is the primary operating surface

Eduardo should be able to govern the site from this ChatGPT conversation.

The expected operation is:

1. understand Eduardo's requested outcome;
2. inspect actual production state when necessary;
3. use the currently authoritative capability (Research Manager today; Site Manager after accepted cutover);
4. produce a bounded plan/preview where appropriate;
5. apply the authorised operation to production;
6. verify actual stored/rendered state;
7. return the sanitized result through the governed path;
8. report what really happened.

WordPress Admin remains a fallback/admin surface. SSH, direct SQL, arbitrary PHP, FTP and ad-hoc WP-CLI writes are not normal website-operation paths.

# 6. Mandatory mutation lifecycle

For material mutations, use the applicable lifecycle:

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

Permitted non-success outcomes include:

```text
BLOCKED
FAILED
ROLLED_BACK
PARTIALLY_VERIFIED
REQUIRES_HUMAN_REVIEW
```

Never report completion when the required verification has not succeeded.

# 7. Researcher and doctorate-first content rule

The public website must tell one coherent story:

> **Eduardo Jose Yauri Luna — researcher.**

The site and supporting digital research infrastructure should increasingly support Eduardo's doctoral trajectory through genuine, evidence-based academic material.

Prioritise:

- research identity;
- research agenda and questions;
- relevant methodology and theoretical framing when developed;
- research lines;
- academic/research CV;
- genuine Research Notes;
- genuine publications/projects/software/datasets as they arise;
- verified academic profiles/identifiers;
- reproducibility, citations and scholarly metadata where useful;
- research collaboration/contact;
- trustworthy machine-readable researcher identity.

Professional experience may be used as relevant background/evidence for the research agenda, but the website must not become a consultancy or services portfolio.

Never fabricate:

- publications;
- affiliations;
- degrees;
- doctoral status beyond what is actually true at that point in time;
- grants;
- awards;
- research appointments;
- DOI/ORCID/Scholar/OpenAlex or other identifiers;
- empirical results or research evidence.

# 8. Internal engineering must not leak into the public narrative

Do not expose as visitor-facing researcher content:

- Research Manager / Site Manager mechanics;
- MCP details;
- gateway details;
- command IDs;
- CI/CD evidence;
- tenant/scopes;
- acceptance fixtures;
- implementation tests;
- migration internals.

Those are engineering mechanisms, not Eduardo's research output.

# 9. Engineering repository rule

Use this repository for engineering changes that directly support Eduardo's website or doctoral/research infrastructure, including:

- Research Manager changes while it remains authoritative;
- Eduardo-specific Site Manager integration/cutover code;
- current Theme fixes required by the live site;
- command/gateway code for Eduardo's governed production path;
- academic/research data contracts needed by Eduardo;
- tests protecting Eduardo's production behaviour;
- CI/CD required by this project;
- documentation of Eduardo-specific architecture and operations.

Do not implement generic Site Manager product expansion here. Put generic/core work in `Emmakex/Site-Manager` and consume it here only when Eduardo's site needs it.

# 10. Decision priority

When deciding what to build or fix next, use this order:

1. keep `eduardoyauriluna.com` safe and operational;
2. improve Eduardo's real researcher website and doctoral/research infrastructure;
3. use Research Manager to govern production while it remains the accepted authority;
4. migrate capabilities safely to Site Manager when parity is proven;
5. remove obsolete code only after its production/migration value is no longer needed;
6. ignore unrelated generic-product ambitions in this project.

The final test is simple:

> **Eduardo's website and doctoral/research needs define this project. The tooling serves them; the tooling does not define the project.**
