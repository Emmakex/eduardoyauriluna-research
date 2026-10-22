# Site Architect — Greenfield Hardcode & Coupling Remediation Log

## Status

Implementation log for `docs/63-greenfield-hardcode-coupling-audit.md`.

This log records remediation evidence. It does not redefine the Greenfield architecture and does not authorize storage/API migrations.

## Naming decision

The Greenfield product name is now **Site Architect**.

Legacy production identifiers such as `Eduardo_Research_Manager_*`, `eduardo_research_manager_*`, the `eduardo-research-manager` text domain/plugin path and `/research-manager/v1` REST namespace remain compatibility identifiers until a separately approved migration exists.

## P0 remediation status

| Audit finding | Classification | Status | Evidence |
| --- | --- | --- | --- |
| Stale capability Theme `1.0.0` | REMOVE | DONE | `b1185c8429a65d7da104d4ebd775c75f08e3fcd4` now reads the live Theme contract version. |
| README says Site Manager is predetermined successor | REMOVE | DONE | `9fedcfeed4c9b606abf8a32fa938fecc0df84f2f`; later Site Architect naming completed in `b81c97f77d02fedca502c496f458501b91ee3ef1`. |
| Product-facing Research Manager naming | P2 compatibility-aware rename | PARTIAL / INTENTIONAL | `3050a14cd3e5dad82332cfe235f481c87912d413` + `e85bd725ca3a0c63e15a8343f5d65be6fe03649e`: product is Site Architect; legacy technical identifiers preserved. |
| Exact Eduardo name fallback in reusable identity logic | REMOVE | DONE | `d92b287a4c10310db4493255ffd9b5af500f9266`: stored state → active instance blueprint → WordPress identity → explicit missing-state error. |
| Exact Eduardo name fallback in Blueprint Store metadata | REMOVE | DONE | `5fba085421b5f04707486df0ed011a8b20493f2f`. |
| Active blueprint path/export knowledge scattered | CENTRALIZE | DONE for current P0 boundary | `5fba085421b5f04707486df0ed011a8b20493f2f` adds one active blueprint descriptor and central constants while preserving the current path and filename. |
| Instance identity copy embedded in engine | REMOVE / CENTRALIZE | DONE for current identity path | `5fba085421b5f04707486df0ed011a8b20493f2f` exposes instance defaults from the blueprint; `d92b287a4c10310db4493255ffd9b5af500f9266` consumes them. |
| EN/ES array hardcoded in Identity Profile | CENTRALIZE | DONE for Identity Profile | `d92b287a4c10310db4493255ffd9b5af500f9266` reads languages from the Greenfield Theme Contract. |
| Default language not exposed by Theme Contract | CENTRALIZE | DONE at contract boundary | `d45a593d9c1c2fa2e96a6cfadbee7c0a223df881` adds `default_language()` and exposes it in contract snapshots. |
| Theme stylesheet repeated as literal in contract | CENTRALIZE | DONE at contract boundary | `d45a593d9c1c2fa2e96a6cfadbee7c0a223df881` centralizes `EXPECTED_STYLESHEET`. |
| Runtime WordPress URL conflated with blueprint-declared canonical domain in remote status | CENTRALIZE / REMOVE ambiguity | DONE in repository | `9e928ec1b897f8392e41414aeb04ff458fa9ed2c` preserves legacy `canonical_domain` and adds `runtime_url`, `declared_canonical_domain`, match state and source. Production readback still required after deployment. |
| Scattered `'en'` defaults in Page/Admin/other services | CENTRALIZE | IN PROGRESS | `6898a5550f0b107e4c5701daa8810e0c5022dba7` moves Page Resource defaults to `contract()->default_language()`; `cb249a8badbdd1d60f59c46c7c77f07996ed57d6` removes equivalent inventory defaults. The Admin selector still has one explicit fallback to review separately. |

## P1 contract-consolidation status

| P1 finding | Status | Evidence |
| --- | --- | --- |
| Physical EN/ES storage rules reconstructed by consumers | DONE at central contract boundary | `9db6b9f535174bd54daed98a44f0015755e2ab55` adds the compatibility-only `LEGACY_LANGUAGE_STORAGE` map plus resolvers. No option/meta key is migrated. |
| Page option keys reconstruct `_es` locally | DONE for Page Resource | `2572abd709440bcaffbf9cd39d792b9d9d38b3fb` routes Page storage resolution through the Greenfield Theme Contract while keeping the public string wrapper compatible. |
| Identity fields reconstruct `headline` / `headline_es` locally | DONE for Identity Profile | `8c746dccfa61c24a6ad6deddeeb916eea7bed779` resolves physical headline fields through the contract and derives allowed/verifiable identity fields from that mapping. |
| Remaining direct `_es` / language-storage assumptions | IN PROGRESS | Search and migrate only when they represent storage ownership; do not change genuine bilingual content/data. |
| Research resource mapping design | DONE | `0ff750696c3aaa237f7593526f1b2432c624829b` documents the exact Registry boundary and security rule. |
| Research Resource Registry implementation | DONE for Contract / Inventory / Executor; targeted CI PASS | `63b3b77a4285bb4a4d6d4f4827e4c99bd97f3c0a` adds the descriptive Registry; `601b8112dfead8ab5f02996dc4ef55c21dde4382` derives Research post types from it; `cb249a8badbdd1d60f59c46c7c77f07996ed57d6` derives structured inventory facts; `998b0b2ae91d94c4d3bb6b08641661f984a25c1d` loads the Registry before consumers; `93404340c0e3fc204473978920dbdd3c46b27e7d` removes the duplicated Executor specification table and resolves execution metadata fail-closed from the Registry. Source-contract and workflow guards were aligned in `92e2d744f5b8151e7f6801ce2ecf74edf474b949` and `1dbdff1585751a3401de5686b5842f9bcc720cc7`; `Manager v1 Development Completion Quality` run `39806057974` completed successfully. Production acceptance remains separate. |
| Plan mutation/security allowlists | AUDITED — KEEP SECURITY by default | `docs/67-site-architect-plan-security-hardcode-audit.md` classifies each Plan hardcode. Only descriptive duplication should be centralized; action dispatch, writable option/meta/field boundaries, content limits and evidence/risk gates remain closed. |

## Current architecture after P0/P1 work

```text
Site Architect engine
→ Greenfield Theme Contract
→ Research vertical semantics / Research Resource Registry
→ active instance blueprint
→ stored WordPress state
→ production
```

The intended identity resolution is now:

```text
verified/stored WordPress identity state
→ active instance blueprint defaults
→ WordPress site identity where applicable
→ explicit missing-state error
```

Canonical-domain reporting is now explicitly split as:

```text
WordPress runtime URL
≠ blueprint-declared canonical domain
```

The legacy `canonical_domain` field remains for existing Gateway/MCP consumers while new consumers can reason about runtime and declared state independently.

The active language model and physical storage compatibility are now distinct concepts:

```text
Theme Contract languages/default language
→ compatibility storage resolver
→ existing EN/ES physical option/meta fields
```

The physical names remain unchanged. Centralizing their ownership is not a data migration.

The Research Registry is descriptive rather than permissive:

```text
Research Resource Registry = vertical facts
Plan = security / validation boundary
Executor = execution of validated actions
```

Adding a Registry entry must never authorize a new mutation automatically.

Executor therefore consumes only a normalized execution subset from the Registry:

```text
post_type
creation_conflict
rollback_conflict
meta
array_fields
defaults
```

Missing required execution metadata fails closed and is treated as unsupported. Executor does not grant mutation permission; every normal execution path first validates the Plan.

No reusable identity service should invent Eduardo's name or biography as a final fallback.

## Compatibility boundary

The following remain unchanged by the current P0/P1 work:

- plugin directory;
- PHP class names;
- WordPress option names;
- metadata keys;
- REST namespace;
- remote credential/scopes vocabulary;
- existing Preview → Apply → Verify → Rollback behavior;
- current Eduardo blueprint path and contents;
- current EN/ES physical storage layout;
- current Research post types, public resource keys and read scopes;
- current Plan action allowlist and risk/evidence gates.

These are intentionally preserved while architecture boundaries are cleaned up.

## Next safe sequence

1. In Plan, centralize only the duplicated structured Research post-type list through the Registry; do not change the explicit action dispatcher or risk policy.
2. Replace behavior-preserving operational `'en'` defaults with the Theme Contract default language in isolated microphases.
3. Centralize Theme home/page ownership checks through the Greenfield Theme Contract while retaining fail-closed mutation rules.
4. Derive exact page option keys from the compatibility storage contract without widening the writable option set.
5. Inventory every legitimate writable meta key and later replace broad prefix authorization with an exact trusted allowlist.
6. Finish the isolated Admin default-language fallback and remaining true storage assumptions.

## CI / production rule

Repository commits and CI are engineering evidence only.

A change is not production-accepted until the relevant governed Site Architect/Gateway production path has been read back and verified without residue.
