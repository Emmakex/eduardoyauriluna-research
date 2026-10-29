# Greenfield Hardcode & Coupling Audit

## Status

**CLOSEOUT RECONCILED — final architectural ledger for Site Architect cleanup tracker #394.**

This document applies `ENGINEERING_RULES.md` and `ENGINEERING_GREENFIELD_RULE.md` to the current Site Architect / Research Theme implementation for `eduardoyauriluna.com`.

The initial audit identified accidental coupling, duplicated contracts, stale capability metadata and historical portability residue. Those findings were then resolved incrementally through bounded microcuts with exact-head CI, no-drift merge checks and post-merge certification.

This file now records the **current classification**. The original exploratory wording remains available in Git history; it is no longer the runtime remediation backlog.

The objective remains deliberately narrow:

- preserve the accepted Eduardo production path;
- keep Greenfield deterministic rather than turning it into Brownfield discovery;
- keep Research semantics where they improve correctness;
- keep mutation/security boundaries fail-closed;
- remove active second sources of architectural truth;
- retain historical identifiers/storage only where they have compatibility, recovery or audit value.

## 1. Authority order

When architectural sources disagree, use this order:

1. `ENGINEERING_RULES.md`
2. `ENGINEERING_GREENFIELD_RULE.md`
3. this reconciled audit
4. current production-proven contracts and code
5. historical documents only as evidence/history

The current separation is:

```text
Site Architect execution/security guarantees
↓
Greenfield Theme Contract
↓
Research Resource Registry / Research vertical services
↓
Eduardo active Instance Blueprint + governed WordPress state
↓
WordPress production
```

No lower layer may silently redefine a fact owned by a higher authoritative contract.

## 2. Final classification vocabulary

Every current finding is classified using the closeout vocabulary required by #394:

- **RESOLVED** — the former duplicate/stale/accidental authority has been removed or consumers now derive from the authoritative owner.
- **KEEP DOMAIN** — deliberate Greenfield/Research/instance specialization that belongs in the declared Theme, vertical or Blueprint contract.
- **KEEP SECURITY** — explicit fail-closed allowlists, scopes, mutation dispatch, lifecycle checks or other bounded controls that must remain independently restrictive.
- **COMPATIBILITY ONLY** — historical naming, storage, API or diagnostic/portability behavior retained for production compatibility, rollback, recovery or audit. It must not steer current architecture or roadmap.

A row may carry more than one KEEP classification when domain semantics and security are intentionally coupled.

## 3. Current sources of truth

### 3.1 Site Architect execution and security

Owned by the current Manager/Gateway execution path and its bounded contracts:

- authentication, credentials and scopes;
- explicit mutation action dispatch;
- Plan validation and checksums;
- Preview / Apply / Verify / Rollback;
- evidence-required academic mutation gates;
- stale-state and idempotency protections;
- exact option/meta/post-field restrictions;
- bounded operation records and audit.

`Eduardo_Research_Manager_Plan` remains intentionally explicit and fail-closed. Descriptive registries may inform validation/risk, but they do **not** replace the security boundary.

### 3.2 Greenfield Theme Contract

`Eduardo_Research_Manager_Contract` is the Manager-side authority boundary for facts owned by the Research Theme, including:

- expected preset/stylesheet compatibility;
- live Theme version;
- pages and front-page role;
- languages and default language;
- EN/ES physical storage compatibility aliases;
- Page option-key resolution;
- Identity headline field resolution;
- Theme-owned Research editorial/catalog contracts.

The physical `_es` and `headline_es` names remain production compatibility details, not a second conceptual language model.

### 3.3 Research vertical authority

`Eduardo_Research_Manager_Resource_Registry` is the descriptive authority for the structured Research vertical:

- Research Line / Output / Project / Software / Dataset membership;
- inventory keys and WordPress post types;
- bounded field vocabulary;
- field-to-meta storage descriptors;
- array fields and defaults;
- creation action descriptors;
- read scopes and relation-capable resource descriptors.

Operational service binding remains owned by `Eduardo_Research_Manager`; the Registry does not become a service locator or mutation authority.

### 3.4 Eduardo instance authority

The active bundled Eduardo Blueprint remains valid instance data and is described by `Eduardo_Research_Manager_Blueprint_Store`.

It may contain Eduardo's exact name, canonical domain, languages and site-specific content because this repository is Eduardo-only. Reusable execution code consumes those values through the active Blueprint/instance boundary instead of embedding them as universal engine constants.

Live WordPress state remains the source of truth for live website content. The Blueprint is the declared Greenfield instance contract, not a substitute for production readback/verification.

## 4. Final reconciliation of the original findings

| Original finding | Final classification | Closeout result |
| --- | --- | --- |
| 3.1 Exact researcher identity fallback | **RESOLVED** | Identity resolution is stored state → active instance Blueprint → WordPress site identity → explicit missing-state error. No embedded personal-name fallback remains in reusable Identity execution. |
| 3.2 Exact domain inside Eduardo Blueprint | **KEEP DOMAIN** | `eduardoyauriluna.com` remains valid Eduardo instance data. Runtime URL/state is read separately from WordPress and is not replaced by a universal domain constant. |
| 3.3 Bundled Blueprint treated as universal canonical Blueprint | **RESOLVED** | Active Blueprint descriptor/path/export identity has one store boundary; exact Identity defaults are read as instance data rather than reusable-engine fallbacks. Physical path/name compatibility is intentionally preserved. |
| 3.4 Theme stylesheet and preset identity | **KEEP DOMAIN** | Requiring the known Research Theme/preset is valid Greenfield specialization. Manager access is centralized through the Theme Contract rather than independently reconstructing Theme authority. |
| 3.5 Page architecture and Research surfaces | **KEEP DOMAIN** | The known researcher-site page architecture is intentional. Consumers derive Theme page contracts instead of maintaining competing architecture lists. |
| 3.6 Page target and slot restrictions | **KEEP SECURITY** | Registered Theme targets, bounded slots, immutable contract slugs and destructive-operation restrictions remain closed by design. |
| 3.7 Page storage keys and `_es` special casing | **RESOLVED** | Existing physical keys remain unchanged, while Page storage-key and language suffix resolution are centralized in the Theme Contract. No storage migration was performed. |
| 3.8 EN/ES architecture versus implementation branches | **RESOLVED** | Active language/default-language facts derive from the Theme Contract. EN/ES physical aliases are centralized compatibility mappings; language widening was not introduced. |
| 3.9 Research resource taxonomy | **KEEP DOMAIN** | Research taxonomy is intentional. The repeated descriptive mappings were consolidated into the Research Resource Registry and consumers derive from it. |
| 3.10 Research evidence/risk semantics | **KEEP DOMAIN / KEEP SECURITY** | Evidence-sensitive academic claims remain a first-class Research rule and continue to require the accepted fail-closed lifecycle. |
| 3.11 Mutation option/meta/action allowlists | **KEEP SECURITY** | Plan's mutation dispatcher and security allowlists remain explicit on purpose. Only duplicated descriptive facts were centralized; the security boundary was not generalized or widened. |
| 3.12 Design presets | **KEEP DOMAIN / KEEP SECURITY** | Bounded Theme design dimensions remain intentional; arbitrary CSS/font URL injection remains prohibited and accessibility/safe lifecycle constraints remain enforced. |
| 3.13 Component variants | **KEEP DOMAIN / KEEP SECURITY** | Known Theme variants remain bounded domain behavior; arbitrary classes/CSS/HTML and unsafe presentation mutation remain outside the contract. |
| 3.14 Navigation | **KEEP SECURITY** | Navigation remains limited to registered Theme targets with stale-state/verification/rollback protection. Generic arbitrary URL governance was not introduced. |
| 3.15 Scopes, credentials and historical Manager identifiers | **KEEP SECURITY / COMPATIBILITY ONLY** | Revocable scoped credentials remain security authority. `eduardo_research_manager_*`, REST namespace and token/operation naming are retained compatibility identifiers and are not a reason for mass rename. |
| 3.16 Stale Theme `1.0.0` capability metadata | **RESOLVED** | Capability reporting derives the live Theme version from the Theme Contract. |
| 3.17 Duplicated capability/surface lists | **RESOLVED** | Hand-maintained architecture/surface reporting that had no operational consumer was removed. Functional capability/security declarations remain bounded and source-derived where appropriate. |
| 3.18 Generic WordPress portability layer | **COMPATIBILITY ONLY** | Portability is registered only when the Research profile is unavailable and remains a read-only diagnostic compatibility surface. It does not govern Eduardo's Research runtime or Site Architect roadmap. |
| 3.19 README predicted Site Manager replacement | **RESOLVED** | README and mandatory engineering rules now describe Site Architect/Greenfield and Site Manager/Brownfield as independent tracks with no predetermined replacement relationship. |

## 5. #394 closeout areas

### A. Research vertical authority — CLOSED

- Resource field/schema/storage descriptors are centralized where they are descriptive.
- Line, Object, Translation, Blueprint, inventory and capability consumers derive Research facts from the Registry or their bounded service owner.
- Operational service binding is not stored in the descriptive Registry.
- Insights remain the explicit non-Registry editorial exception where their WordPress `post` implementation is intentionally distinct.

### B. Theme-contract authority — CLOSED

- Theme identity/version access is centralized.
- Page contract access is centralized.
- default language is Theme-derived.
- legacy EN/ES physical storage aliases are centralized.
- Identity field/language capability description derives from the Theme Contract via Identity Profile.
- direct Theme helper calls that remain inside the Contract adapter are adapter implementation, not competing authority.

### C. Capability reporting — CLOSED

- Theme version is live/contract-derived.
- Research Line fields derive from Registry.
- Identity fields/languages derive from Identity Profile/Theme Contract.
- Identity storage source key derives from `Identity_Profile::option_key()`.
- the manually maintained `manager_v1_development.surfaces` catalog was removed rather than replaced with another synthetic registry.
- transport/security declarations that intentionally describe a closed capability remain explicit.

### D. Mutation boundaries — CLOSED

The mutable-operation authority passes were completed independently for the bounded Evidence, Research Object, Research Line, Translation, Insight Pairing, Media Metadata and Page State lifecycles.

Plan remains the explicit fail-closed mutation/security boundary. No cleanup step relaxed:

- evidence-required behavior;
- stale-state protection;
- idempotency;
- Verify;
- Rollback;
- option/meta/post-field restrictions;
- arbitrary REST/WordPress proxy restrictions.

### E. Historical/portability residue — CLOSED

Historical implementation names and compatibility storage/API identifiers remain only where they still have production/recovery/audit value.

The generic portability implementation is not an active authority on the Research profile: it is conditionally registered only when Research is unavailable and remains read-only/diagnostic. It must not be expanded in this repository as a Brownfield roadmap.

### F. Closeout certification — READY FOR FINAL EXACT-MAIN CERTIFICATION

Regression coverage now protects the single-source boundaries for Registry, Identity, Theme Contract consumers, fail-closed Plan behavior and capability reporting. The final closeout change also guards this document/README status so documentation cannot silently revert to the pre-closeout architecture narrative.

The final certified exact `main` SHA and workflow counts belong in tracker #394's closure evidence rather than as a permanent version literal in this architecture document.

## 6. Explicitly retained hard boundaries

This closeout must never be interpreted as permission to add:

- arbitrary PHP or JavaScript execution;
- arbitrary SQL, shell or filesystem access;
- arbitrary WordPress REST proxying;
- arbitrary option/meta mutation;
- arbitrary CSS/class/font URL injection;
- arbitrary navigation URLs;
- uncontrolled deletion;
- caller-selected MCP tools or backend routes;
- evidence bypass for academic claims;
- stale-state/idempotency bypass;
- mutation completion without required verification.

These are **KEEP SECURITY** constraints.

## 7. Compatibility rule

Do not mass-rename or migrate solely for visual consistency:

- plugin folder/files;
- PHP classes/constants;
- text domain;
- WordPress option/meta names;
- REST namespace;
- operation/token prefixes;
- Theme functions;
- historical evidence identifiers.

Any future physical rename/storage migration requires a separate compatibility plan and independent production acceptance.

## 8. Greenfield / Brownfield boundary

The closeout preserves the mandatory split:

```text
Site Architect / Greenfield
known architecture → build → optimize → govern

Site Manager / Brownfield
unknown existing site → discover → understand → adapt → govern
```

Neither is a required stage or predetermined replacement for the other.

This repository remains exclusively the Eduardo Research reference/production implementation. Reusable patterns may be extracted elsewhere only through an explicit later decision; they do not expand this repository's scope.

## 9. Closeout conclusion

The architectural cleanup no longer has an open refactor mandate.

For current Site Architect operation, the active facts have clear owners:

```text
execution/security → bounded Site Architect/Plan/lifecycle contracts
Theme/site structure → Greenfield Theme Contract
Research taxonomy/descriptors → Research Resource Registry
Eduardo identity/domain/defaults → active Instance Blueprint + governed state
live visitor content → verified WordPress production state
```

Remaining literals are either declared domain facts, deliberate fail-closed security controls or compatibility-only residue. They are not automatically defects and must not trigger new cleanup work without evidence that they have become an active competing authority.

Future changes should therefore be driven by Eduardo's real website/research needs and protected by these authority boundaries, not by continuing this cleanup as an open-ended refactor programme.
