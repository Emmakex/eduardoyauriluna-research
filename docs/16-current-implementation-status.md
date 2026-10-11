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

### Remote Media control — Phase 3 repository/CI complete

Phase 3 is implemented and CI-accepted through #134. The bounded Media surface now includes:

- authenticated Media inventory and attachment inspection through `media.read`;
- safe public attachment metadata with no absolute filesystem path disclosure;
- title/alt/caption/description metadata mutation through exact Plan → Apply → Verify → Rollback;
- `media.write` enforcement, idempotency and stale attachment-revision protection;
- bounded image assignment to supported Page, Insight and Research targets;
- target-specific semantic read/write scopes;
- dual target/attachment stale protection for assignment;
- stored and rendered assignment verification;
- missing/weak-alt diagnostics;
- unused/orphan candidate diagnostics;
- bounded duplicate-candidate detection;
- image-dimension/performance advisories;
- safe operator-supplied alt remediation delegated to the existing metadata lifecycle.

The Media contract does not expose arbitrary upload proxying, filesystem access, destructive delete, binary replacement or generic WordPress proxying.

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
- Remote Media inventory, metadata lifecycle, assignment/rendered verification and diagnostics;
- academic adapter/Admin contracts;
- installable packages and Greenfield bundle integrity;
- repository-wide WP-CLI fallback protection.

PR #130 additionally hardened semantic write scopes and passed the affected historical regression matrix before merge. PR #131 added a dedicated production capability-contract audit and CI for that audit.

CI optimisation #143 is complete. Historical feature/provider/Blueprint/Remediation/Greenfield PR suites now use bounded owned paths plus workflow-scoped cancellation where appropriate, while broad push-to-main regression and intentionally broad package/release smoke coverage remain available. The final policy is documented in `docs/50-ci-selective-pr-gates.md`.

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

Phase 3 Remote Media is **not yet claimed as production-accepted** merely because its repository/CI implementation is complete. Production Media acceptance is tracked separately in #170.

## Evidence boundary

Research Outputs, Projects, Software and Datasets are published only when real evidence supports them. Phase 2 deliberately did not create filler records where evidence was absent.

Likewise, academic identifiers/affiliations/publications must not be inferred merely because connector code exists. A connector being implemented and CI-verified is distinct from a researcher identifier or external record being personally verified and approved for publication.

## Phase 3 — Remote Media control ✅ repository/CI implementation

Tracker #134 is closed as completed.

Completed slices:

1. **P3.1 — read-only Media foundation:** authenticated inventory/inspection, safe fields and scope enforcement.
2. **P3.2 — bounded Media metadata lifecycle:** title/alt/caption/description, exact Plan → Apply → Verify → Rollback, idempotency and stale-revision protection.
3. **P3.3 — bounded Media assignment:** Page/Insight/Research targets, target-specific semantic scopes, dual-resource stale protection and rendered verification.
4. **P3.4 — Media diagnostics:** alt readiness, unused/orphan candidates, bounded duplicate candidates, image-dimension/performance advisories and safe operator-supplied alt remediation.

The accepted Phase 2 17-scope production credential was not broadened by Phase 3.

## Current next development — Phase 4 production Media acceptance

Primary tracker: #170.

The current gap is no longer Media implementation; it is proving the completed Media contract on the real site through the intended authenticated boundary.

### P4.1 — Deploy the Phase 3 Manager runtime

- [ ] obtain a CI-validated installable Manager package containing P3.1–P3.4;
- [ ] verify package checksum/evidence;
- [ ] replace only the Manager plugin in production;
- [ ] do not run the full Greenfield installer or reapply the canonical blueprint;
- [ ] verify the site and Manager remain available after replacement.

### P4.2 — Read-only Media production preflight

Use a separate short-lived Media acceptance credential. For an Insight-targeted acceptance, the intended least-privilege profile is:

- `site.read`;
- `site.diagnostics`;
- `media.read`;
- `media.write`;
- `insights.read`;
- `insights.write`;
- `operations.apply`;
- `operations.rollback`.

The accepted Phase 2 17-scope credential remains untouched.

Acceptance:

- [ ] `/capabilities` exposes the complete Media contract;
- [ ] Media inventory succeeds;
- [ ] Media diagnostics succeed;
- [ ] no filesystem paths or secrets are exposed;
- [ ] identify one existing image and one safe existing Insight target.

### P4.3 — Real metadata lifecycle proof

- [ ] Preview a safe text-only metadata change;
- [ ] Apply with explicit confirmation;
- [ ] Verify;
- [ ] Rollback;
- [ ] confirm original metadata is restored;
- [ ] preserve idempotency/stale-state guarantees.

### P4.4 — Real assignment lifecycle proof

- [ ] inspect current assignment;
- [ ] Preview assignment of an existing image to the selected Insight;
- [ ] Apply with explicit confirmation;
- [ ] verify stored and rendered state;
- [ ] Rollback;
- [ ] verify previous relationship/rendered state is restored.

### P4.5 — Closeout

- [ ] rerun Media diagnostics;
- [ ] record only non-secret operation/request IDs and public URLs;
- [ ] revoke the temporary Media credential;
- [ ] confirm the Phase 2 credential remained unchanged;
- [ ] only then mark Remote Media production-accepted.

## Repository hygiene

Historical issues may retain old implementation wording for context, but current status reporting must follow this document and the active tracker for the current phase. Completed implementation must not be presented as production-accepted until the corresponding real-site acceptance has been run.

## Rule for status reporting

Always distinguish:

- **implemented in repository** — code/contract/admin/remote surface exists;
- **verified by CI** — automated tests exercised it;
- **deployed on real site** — supported code is installed on `eduardoyauriluna.com`;
- **verified through the authenticated Manager** — the production operation/readiness contract was exercised through the intended managed boundary.

Do not present future Media production acceptance, unverified external academic identity data, or evidence-sensitive Research Objects as complete before their own acceptance conditions are met.
