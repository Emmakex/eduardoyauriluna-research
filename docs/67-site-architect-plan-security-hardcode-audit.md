# Site Architect — Plan Security Hardcode Audit

## Status

P1 follow-up to `docs/63-greenfield-hardcode-coupling-audit.md` and `docs/65-site-architect-research-resource-registry-audit.md`.

This document classifies hardcoded knowledge inside `Eduardo_Research_Manager_Plan` after the Research Resource Registry was introduced.

The objective is **not** to make Plan dynamic. `Eduardo_Research_Manager_Plan` is the fail-closed mutation/security boundary. Descriptive duplication may be centralized, but authorization, bounded schemas and risk gates must remain explicit unless an equally strict replacement is proven.

## Core rule

```text
Research Resource Registry = descriptive vertical facts
Plan = authorization + validation + risk boundary
Executor = execution of already validated actions
```

A Registry entry must never authorize a mutation by itself.

## Classification table

| Current Plan knowledge | Classification | Direction |
| --- | --- | --- |
| `ALLOWED_OPTIONS` | KEEP SECURITY | Keep a closed explicit option allowlist. Individual storage facts may later be generated only if the resulting set remains exact and reviewable. |
| `ALLOWED_SURFACE_KEYS` | KEEP SECURITY + CENTRALIZE candidate | It duplicates Theme page/surface knowledge, but it also bounds writable options. Do not automatically expose every Theme page as writable. |
| `ALLOWED_POST_FIELDS` | KEEP SECURITY | Keep explicit. Registry must not add WordPress post fields implicitly. |
| `STRUCTURED_RESEARCH_POST_TYPES` | CENTRALIZE | Pure descriptive duplication of the Research Resource Registry when used to classify evidence-sensitive title mutations. May derive from Registry without widening mutation permission. |
| `MAX_TITLE_BYTES` / `MAX_EXCERPT_BYTES` / `MAX_CONTENT_BYTES` | KEEP SECURITY | Bounded content limits are policy, not accidental hardcoding. |
| explicit `normalize_action()` branches | KEEP SECURITY | The closed dispatcher is an authorization boundary. Do not replace with automatic Registry dispatch. |
| Research creation actions in `action_risk()` | KEEP DOMAIN + KEEP SECURITY | Research creation remains evidence-sensitive. Any future derivation must fail closed to equal-or-higher risk. |
| evidence-sensitive field/risk needles | KEEP DOMAIN + KEEP SECURITY | Academic/evidence semantics are intentional Research policy. Consolidation is allowed only if risk cannot be weakened. |
| language defaults `'en'` in normalizers | CENTRALIZE | Resolve operational defaults from the Greenfield Theme Contract while preserving current EN behavior. |
| Theme collection option calls using `'en'` | CENTRALIZE | Use the contract default language when the call is selecting the primary contract vocabulary rather than literal bilingual content. |
| `allowed_option_key()` reconstruction of surface `_es` keys | CENTRALIZE inside KEEP SECURITY boundary | Use exact contract-derived compatible storage keys, but preserve a closed writable option set. |
| `allowed_meta_key()` prefix authorization | SECURITY HARDENING | Current prefix rule is broader than the desired exact trusted contract. Replace eventually with exact registered meta keys; never broaden it. |
| `is_theme_owned_page()` Theme page discovery | CENTRALIZE | Reuse Greenfield Theme Contract page ownership/state while keeping the same fail-closed ownership check. |
| `theme_home_page_id()` | CENTRALIZE | Reuse the Theme Contract `home` page identity rather than independently reading preset structure. |
| `target_signature()` structured creation action list | CENTRALIZE candidate | Safe only after `normalize_action()` has already authorized the action; Registry may provide descriptive action identity. |

## 1. Explicit action dispatch is a security boundary

Current Plan contains branches such as:

```text
create_page
create_insight
create_line
create_output
create_project
create_software
create_dataset
```

These branches must remain explicit unless a future schema registry is itself a closed, versioned security contract.

Do **not** implement:

```text
if Registry knows action → action is allowed
```

The correct relationship remains:

```text
Plan explicitly allows action
→ Plan validates exact schema and values
→ Registry may provide already-known descriptive facts
→ Executor executes validated action
```

## 2. Safe descriptive centralization: structured post types

`STRUCTURED_RESEARCH_POST_TYPES` repeats the same five WordPress post types now owned descriptively by `Eduardo_Research_Manager_Resource_Registry`.

Its current use in `action_risk()` is to ensure title mutation on any structured Research object is `evidence-required`.

A safe migration may replace the local list with:

```text
Research Resource Registry::post_types()
```

provided the rule remains:

```text
structured Research title change → evidence-required
```

and an unknown resource never receives a lower risk because of missing Registry data.

## 3. Option allowlist must stay closed

`ALLOWED_OPTIONS` and `allowed_option_key()` protect WordPress from arbitrary option writes.

The current code contains both fixed options and surface option patterns. The surface patterns duplicate the existing EN/ES storage scheme.

A future cleanup may derive the exact physical page option keys from the Greenfield Theme Contract, but the result must still be an explicit finite set.

Desired model:

```text
Theme Contract pages
+
Theme Contract compatible languages
+
compatibility storage resolver
→ exact set of writable structured page option keys
```

Not allowed:

```text
prefix match → writable option
```

## 4. Metadata allowlist needs hardening

Current Plan accepts post meta when the key starts with either:

```text
_eduardo_research_
_research_
```

That is fail-limited by namespace, but it is still broader than the desired exact contract.

Target direction:

```text
exact Theme-owned page meta
+
exact Research Resource Registry meta
+
exact shared Research metadata
+
exact provenance metadata
→ finite writable meta allowlist
```

This should tighten security while preserving all current legitimate operations.

The migration must prove every currently used metadata key remains accepted before the prefix fallback is removed.

## 5. Default language duplication

Several creation normalizers still use literal `en` for:

- missing action language;
- primary Theme collection option lookup;
- Insight type lookup;
- Research Output / Project / Software / Dataset contract option lookup.

These are operational defaults, not literal English content, so they should progressively resolve through:

```text
Eduardo_Research_Manager::contract()->default_language()
```

The current Eduardo instance must continue to resolve to English.

## 6. Theme page ownership duplication

`is_theme_owned_page()` and `theme_home_page_id()` independently inspect `eduardo_research_preset()`.

That is valid Greenfield knowledge but duplicated ownership logic.

Target direction:

```text
Greenfield Theme Contract
→ pages()
→ page_state()/page_id()
```

Plan should consume that authoritative boundary while retaining the same fail-closed checks for front-page and status mutations.

## 7. Risk policy must not become data-driven by accident

The current Plan distinguishes:

```text
standard
editorial-review
evidence-required
```

Academic identifiers, publication state, authorship, affiliations, research relationships, methods, funding, DOI and similar fields are intentionally evidence-sensitive.

This is Research vertical policy and should remain explicit or move only to a dedicated versioned risk contract.

A generic Registry should never be able to lower risk merely by omitting a field annotation.

## 8. Recommended implementation order

Use microphases:

1. derive only `STRUCTURED_RESEARCH_POST_TYPES` from the Research Resource Registry;
2. replace behavior-preserving operational `'en'` defaults with Theme Contract default language;
3. centralize Theme home/page ownership through the Greenfield Theme Contract;
4. make page option allowlisting consume exact contract-derived storage keys while remaining finite;
5. inventory every legitimate writable post meta key;
6. introduce an exact trusted meta allowlist;
7. only then remove the broad `_research_*` / `_eduardo_research_*` prefix fallback;
8. leave explicit action dispatch, bounded post fields, content limits, evidence gates and risk policy closed.

Each security-sensitive change requires CI and later governed production readback before acceptance.

## 9. Acceptance criteria

The Plan cleanup is successful only if:

- no new action becomes valid;
- no new WordPress option becomes writable;
- no new metadata key becomes writable;
- no post field is added implicitly;
- evidence-required operations remain evidence-required;
- current Research resources remain exactly the same;
- EN/ES production storage remains compatible;
- Preview / Apply / Verify / Rollback semantics remain unchanged;
- stale/checksum/idempotency boundaries are unaffected;
- CI passes;
- production acceptance is proven separately through Site Architect governance.

The goal is a **smaller duplication surface with an equal-or-stronger security boundary**, not a more generic mutation engine.
