# Current implementation status — 2026-10-11

This document separates **implemented in the repository**, **verified by CI**, **deployed/verified on the real site**, and **current next development**.

## Canonical operating model

`eduardoyauriluna.com` is the **Greenfield Research** scenario. There is no legacy site to interpret or migrate before normal operation.

```text
Eduardo → ChatGPT/operator → authenticated Research Manager → shared Manager services → WordPress resources → Research Theme → rendered eduardoyauriluna.com → verification
```

The Research Theme owns deterministic frontend rendering. The Research Manager owns structured website control. WordPress Admin remains the local bootstrap/security/fallback surface. WP-CLI and SSH are support transports, not the normal managed workflow.

Canonical contracts: `docs/22-chat-governed-manager-contract.md` and `docs/15-production-operating-playbook.md`.

## Implemented in the repository

### Research Theme

The Theme owns the Greenfield Research frontend, including:

- Theme-controlled Page, single and archive rendering;
- EN/ES routes and language-aware rendering;
- Research Lines, Insights and structured Research objects;
- responsive/semantic frontend behavior;
- academic/SEO/GEO discovery rendering;
- bounded design tokens and component variants.

Controlled frontend layout does not depend on Gutenberg as the layout authority.

### Research Manager service layer

The Manager includes bounded services for:

- canonical Research blueprint and Greenfield bootstrap/hydration;
- Preview → Apply → Verify → Rollback execution and snapshots;
- structured Theme Page control;
- Insights/editorial control;
- Research Lines;
- Publications / Research Outputs;
- Projects;
- Research Software;
- Datasets;
- evidence/provenance;
- EN/ES translation pairing;
- SEO/GEO diagnostics, action planning, indexability/internal-link control and readiness;
- bounded Research Theme design controls/readiness;
- rendered HTTP verification;
- academic adapters for ORCID, Crossref, OpenAlex, Zenodo and GitHub;
- Google Scholar-compatible citation/indexing metadata.

### Authenticated Remote Manager bridge

M1–M8 are implemented. The remote surface includes:

- versioned `research-manager/v1` namespace;
- site-specific bearer credential lifecycle;
- supported read/write scope registry;
- request IDs, nonce/timestamp guard and idempotency;
- typed remote errors and audit correlation;
- status, versions, capabilities, readiness and diagnostics;
- exact Plan → Apply → Status → Verify → Rollback lifecycle;
- stale-revision protection;
- bounded Page, Insight, Research Object, translation, evidence, SEO/GEO and design control;
- semantic write-scope enforcement on generic Page/Insight mutations.

The production semantic contract requires resource-specific write scopes in addition to lifecycle permission. Generic `operations.apply` alone is not sufficient for Page or Insight mutation.

### Local/Admin and WP-CLI surfaces

WordPress Admin remains available for local security/bootstrap/fallback operations and existing Manager service workflows. WP-CLI includes the Greenfield status/preview/apply/rollback lifecycle. Neither is the target day-to-day remote mutation path.

### Standalone Greenfield bundle

The repository builds an auditable standalone bundle containing:

- versioned Theme ZIP;
- versioned Research Manager ZIP;
- canonical Research blueprint;
- manifest and SHA-256 integrity metadata;
- installer and installation documentation.

## Verified by CI

Automated fresh-WordPress acceptance covers the major Manager/Theme capability groups, including:

- Greenfield bootstrap, hydration, pairing and idempotency;
- Page control and remediation;
- Insight create/update/status/scheduling/relations/pairing;
- Research Lines, Objects, translations and evidence;
- SEO/GEO remediation, action planning, indexability, internal links and aggregate readiness;
- bounded design tokens, variants and readiness;
- remote authentication, plans/operations, rollback and semantic scope guards;
- academic adapter/Admin contracts;
- installable packages and Greenfield bundle integrity;
- repository-wide WP-CLI fallback protection.

PR #130 additionally hardened semantic write scopes and passed the affected historical regression matrix before merge. PR #131 added a dedicated production capability-contract audit and CI for that audit.

## Deployed and verified on `eduardoyauriluna.com`

Phase 2 production acceptance is complete.

Verified production state includes:

- supported Research Theme + Research Manager deployed;
- Greenfield Research site operating through the authenticated Manager;
- exact accepted 17-scope Phase 2 credential profile;
- no dangerous-by-default grants in that production profile;
- semantic write-scope hardening deployed;
- post-deploy capability audit PASS with no registry drift;
- operation→scope contract matched;
- Insight pairing write-scope contract matched;
- final authenticated production preflight PASS;
- EN/ES researcher profile/content baseline;
- four bilingual Research Line pairs;
- first bilingual Research Note published and paired;
- per-Insight and aggregate SEO/GEO/discovery verification green at Phase 2 acceptance.

The Phase 2 tracker is #128 and is closed as completed. Credential lifecycle maintenance continues separately in #132 under the 90-day maximum routine rotation policy in `docs/44-production-credential-rotation-policy.md`.

## Evidence boundary

Research Outputs, Projects, Software and Datasets are published only when real evidence supports them. Phase 2 deliberately did not create filler records where evidence was absent.

Likewise, academic identifiers/affiliations/publications must not be inferred merely because connector code exists. A connector being implemented and CI-verified is distinct from a researcher identifier or external record being personally verified and approved for publication.

## Current next development — Phase 3

The next product gap is bounded **Remote Media control**, tracked by #134.

The product backlog already identifies Media inventory, assignment, alt/descriptive metadata, performance checks and duplicate/unused detection as supporting capabilities. The scope registry already contains `media.read` / `media.write`, but Phase 2 intentionally did not grant those scopes or expose a dedicated remote Media gateway.

Phase 3 sequence:

1. **P3.1 — read-only Media foundation (#135):** authenticated inventory and inspection through `media.read`, safe public attachment metadata only, no filesystem leakage or upload proxy.
2. **P3.2 — bounded Media metadata lifecycle (#137):** title/alt/caption/description through exact Plan → Apply → Verify → Rollback and `media.write`.
3. **P3.3 — bounded Media assignment:** relate attachments to supported Page/Insight/Research resources without giving Media authority over layout.
4. **P3.4 — Media diagnostics:** missing alt, orphan/unused assets, duplicate candidates and image-performance advisories without destructive auto-delete.

The accepted Phase 2 17-scope credential remains unchanged. Media scopes are for deliberate Media sessions only.

## Repository hygiene

Several historical Phase/roadmap issues still use pre-M1–M8 wording. Issue #136 tracks reconciliation of those historical trackers so they remain useful records without contradicting the accepted production state.

## Rule for status reporting

Always distinguish:

- **implemented in repository** — code/contract/admin/remote surface exists;
- **verified by CI** — automated tests exercised it;
- **deployed on real site** — supported code is installed on `eduardoyauriluna.com`;
- **verified through the authenticated Manager** — the production operation/readiness contract was exercised through the intended managed boundary.

Do not present future Media capabilities, unverified external academic identity data, or evidence-sensitive Research Objects as complete before their own acceptance conditions are met.
