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
| Scattered `'en'` defaults in Page/Admin/other services | CENTRALIZE | IN PROGRESS | `6898a5550f0b107e4c5701daa8810e0c5022dba7` moves Page Resource default/creation/verification language selection to `contract()->default_language()`. Admin and any remaining callers still require incremental review. |

## P1 contract-consolidation status

| P1 finding | Status | Evidence |
| --- | --- | --- |
| Physical EN/ES storage rules reconstructed by consumers | DONE at central contract boundary | `9db6b9f535174bd54daed98a44f0015755e2ab55` adds the compatibility-only `LEGACY_LANGUAGE_STORAGE` map plus resolvers. No option/meta key is migrated. |
| Page option keys reconstruct `_es` locally | DONE for Page Resource | `2572abd709440bcaffbf9cd39d792b9d9d38b3fb` routes Page storage resolution through the Greenfield Theme Contract while keeping the public string wrapper compatible. |
| Identity fields reconstruct `headline` / `headline_es` locally | DONE for Identity Profile | `8c746dccfa61c24a6ad6deddeeb916eea7bed779` resolves physical headline fields through the contract and derives allowed/verifiable identity fields from that mapping. |
| Remaining direct `_es` / language-storage assumptions | IN PROGRESS | Search and migrate only when they represent storage ownership; do not change genuine bilingual content/data. |
| Research resource mappings repeated across services | NEXT | Build one exact Research Resource Registry without weakening mutation allowlists or Research semantics. |

## Current architecture after P0/P1 work

```text
Site Architect engine
→ Greenfield Theme Contract
→ Research vertical semantics / registries
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
- current EN/ES physical storage layout.

These are intentionally preserved while architecture boundaries are cleaned up.

## Next safe sequence

1. Finish behavior-preserving default-language caller migration where `'en'` is still used as an operational default rather than as real bilingual content.
2. Find remaining direct `_es`/storage assumptions and move only true storage ownership behind the compatibility contract.
3. Inventory repeated Research resource mappings in Contract / Resource Inventory / Plan / Executor.
4. Introduce an exact fail-closed Research Resource Registry and migrate callers incrementally.
5. Then continue broader Theme Contract/capability derivation consolidation.

## CI / production rule

Repository commits are engineering evidence only.

A change is not production-accepted until the relevant CI has completed successfully and, where the change affects live governance behavior, the production Site Architect/Gateway path has been read back and verified without residue.
