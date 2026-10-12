# Roadmap

## Canonical architecture

`eduardoyauriluna.com` is a **Greenfield WordPress Research site**.

The operational target is not manual WordPress management. The accepted control path is:

```text
Eduardo → ChatGPT → authenticated Research Manager → shared Manager services → WordPress resources → Research Theme → rendered eduardoyauriluna.com → verification back to ChatGPT
```

The Research Theme owns deterministic frontend rendering. The Research Manager owns controlled site operations. ChatGPT is the primary managed instruction surface. WordPress Admin is the local/fallback/self-service surface. WP-CLI and SSH are optional bootstrap/automation/support transports.

Canonical contract: `docs/22-chat-governed-manager-contract.md`.
Accepted decision: ADR-015 in `docs/10-decisions.md`.
Manager v1 completion tracker: issue #201.

---

## Permanent anti-drift rules

1. The current validation target is `eduardoyauriluna.com`.
2. Do not introduce migration/audit logic into the Greenfield creation path.
3. The Theme owns public layout/rendering; Gutenberg is not the layout authority.
4. Routine website operations must be expressible through Manager services.
5. Managed remote operation goes through the authenticated Manager; no direct DB/filesystem/shell bypass for normal work.
6. Admin, REST and WP-CLI adapters reuse shared Manager business logic.
7. Material mutations preserve Preview → Apply → Verify → Rollback where the operation contract supports it.
8. Every remote mutation is scoped, auditable and safe against duplicate retries/stale state.
9. Multi-client SaaS/Control Center work must not displace the single-site Eduardo acceptance path.
10. Status reporting must distinguish repository implementation, CI verification, real-site deployment and real ChatGPT-through-Manager verification.

---

## Phase 0 — Research identity foundation ✅

Completed foundation includes:

- canonical researcher identity: Eduardo Jose Yauri Luna;
- canonical domain: `eduardoyauriluna.com`;
- English primary / Spanish secondary direction;
- research identity/content documentation;
- academic CV/research statement source structure;
- integration strategy and academic evidence discipline.

---

## Phase 1 — Greenfield WordPress + Theme foundation

### Repository state

Implemented/CI-backed work already includes the Research Theme, Research preset contracts, Theme-owned pages/surfaces, multilingual behavior, SEO/academic rendering and installable packaging.

### Real-site acceptance

- [x] supported clean WordPress target confirmed for `eduardoyauriluna.com`;
- [x] Research Theme installed/activated on the real target;
- [x] Manager installed/activated on the real target;
- [x] expected permalink/language/domain state confirmed through M8 operating acceptance;
- [ ] production mobile/accessibility/performance validation across the final v1 surface set;
- [x] bounded palette/design baseline reviewed through the real rendered site;

---

## Phase 2 — Research Theme / preset ✅ repository implementation

Repository implementation includes the Theme-owned frontend model, page/single/archive surfaces, research collections/entities, responsive behavior, semantic rendering, SEO/GEO authority and the Research visual direction.

The Theme remains independently renderable and must never become dependent on a chat connection.

### Remaining acceptance

- [ ] verify all intended Theme surfaces on real `eduardoyauriluna.com` after final Manager v1 deployment;
- [x] verify representative EN/ES public routes/hreflang on real domain;
- [ ] verify production Core Web Vitals/accessibility across the final surface set;
- [ ] close any frontend gaps discovered only on real hosting.

---

## Phase 3 — Academic content model ✅ repository implementation

Implemented structured domain includes:

- Research Lines;
- Publications / outputs;
- Projects;
- Research Software;
- Datasets;
- Insights;
- evidence/provenance;
- EN/ES relationships;
- academic identifiers/metadata contracts;
- Scholar/Schema-oriented output.

Remaining work is primarily real content/evidence population and real-site acceptance.

---

## Phase 4 — Research Manager core ✅ repository implementation

The Manager contains shared services and Admin/remote surfaces for the accepted v1 domain, including:

- blueprint/Greenfield lifecycle;
- diagnostics/readiness;
- structured Pages and bounded Theme navigation;
- Insights;
- Research Lines;
- Research Objects;
- translations;
- evidence;
- SEO/GEO inspection, action planning and deterministic remediation;
- bounded Theme design controls;
- Media inspection, metadata, assignment and managed binary ingest;
- remote non-secret audit correlation;
- rendered verification;
- academic connector adapters;
- snapshot/executor/rollback infrastructure;
- WP-CLI Greenfield adapter;
- standalone install bundle.

This phase is the shared engine behind the authenticated ChatGPT governance path. Normal operations remain typed and bounded; it is not an unrestricted WordPress administrator proxy.

---

# Phase 5 — ChatGPT-governed Manager bridge — M8 OPERATING ACCEPTANCE COMPLETE / v1 CLOSEOUT IN PROGRESS

**Goal:** make the Research Manager the authenticated bridge through which we govern the real WordPress site from this ChatGPT workflow.

Historical M1–M8 tracker: #97. Current Manager v1 completion tracker: #201.

### Production acceptance milestone — Remote Media ✅ (2026-10-12)

Issue #170 is production-accepted and closed. On the real `eduardoyauriluna.com` site we proved:

- a separate temporary 8-scope Media credential/session, independent from the accepted Phase 2 credential;
- read-only Media preflight and capability discovery;
- real Media metadata Apply → Verify → Rollback with original metadata restoration;
- real Insight primary-Media assignment Apply → rendered Verify → Rollback with stored/public state restoration;
- read-only closeout diagnostics;
- local revocation of the temporary credential followed by HTTP 401 `authentication_failed`;
- final Phase 2 audit still passing with exactly 17 scopes, no extras, no dangerous grants and no registry drift.

Research Manager 0.9.2 introduced independent temporary credential storage so bounded acceptance sessions no longer modify the primary production connection.

### Production acceptance milestone — Page, Insight, SEO/GEO readiness and Design ✅ (2026-10-12)

The real `eduardoyauriluna.com` site has also passed these ChatGPT-through-Manager acceptance slices:

- issue #180: reversible Page slot mutation with Preview → Apply → stored/rendered Verify → Rollback and exact Page baseline restoration;
- issue #182: temporary Insight creation with stored/rendered Verify, authenticated inspection, Rollback and proof that the created Insight is absent from the final managed inventory;
- issue #184: SEO/GEO production discovery returned `ready`, `blocking=0`, `advisories=0` and zero safe remediation candidates, so the acceptance closed as an already-ready state without manufacturing an artificial SEO mutation;
- issue #186: bounded Theme Design Preview selected `palette -> ocean-lab`, then Apply → Verify → rendered design readiness → Rollback restored the exact Theme design baseline; the independent temporary 5-scope Design credential was then revoked and rejected with HTTP 401 `authentication_failed`.

The stable Phase 2 credential remained least-privilege throughout. Its missing `design.write` scope correctly produced a 403 on the first Design Preview attempt; Design acceptance therefore used a separate temporary scoped session instead of widening the primary credential.

### Production acceptance milestone — Read-only security and audit ✅ (2026-10-12)

Issue #190 is production-accepted and closed. We proved:

- an independent temporary credential with exactly `site.read` + `site.diagnostics`;
- `/capabilities` reported exactly those two scopes and `writes_enabled=false`;
- authenticated read endpoints remained available;
- a write-plan request was rejected before mutation with HTTP 403 `scope_denied`;
- the denied request was correlated by request ID as `remote-request-rejected`, `blocked`, scope `operations.apply`, error `scope_denied`;
- the audit implementation remains whitelist-only and the acceptance evidence contains no bearer/token/Authorization material;
- revoking the temporary credential immediately caused HTTP 401 `authentication_failed`.

The original proof used the local Admin audit view. PR #202 subsequently added typed authenticated `GET /audit` correlation so the final v1 re-acceptance no longer requires Admin inspection.

### Normal-operation bypass closure — implementation ✅ / final live acceptance pending

The three normal-operation bypasses found during M8 are no longer open implementation gaps:

- [x] #192 / PR #197 — remote non-secret audit retrieval/correlation;
- [x] #193 / PR #198 — bounded Theme-owned primary/footer navigation control;
- [x] #194 / PR #200 — managed Media binary ingest without arbitrary URL/path proxying;
- [x] PR #202 — production acceptance harnesses for remote audit, navigation and Media ingest;
- [x] PR #202 — Media ingest Preview staging hardened with explicit cancel plus 15-minute scheduled cleanup;
- [ ] deploy the Manager RC containing these capabilities to the real site;
- [ ] execute the three new live production acceptances and preserve evidence.

Installation/bootstrap, emergency recovery, local credential administration and optional WP-CLI/SSH support are not classified as normal-operation bypasses because the architecture explicitly permits them as bootstrap/fallback/support surfaces.

## M1 — Remote Manager foundation ✅ implementation complete

- [x] versioned REST namespace `/wp-json/research-manager/v1/`;
- [x] site-specific remote connection identity;
- [x] local WordPress enable/disable/revoke controls;
- [x] credential rotation;
- [x] read/write scope model;
- [x] `/capabilities` discovery;
- [x] request IDs/idempotency primitives;
- [x] replay/expiry protection;
- [x] whitelist-only audit record model plus authenticated remote audit read surface;
- [x] typed remote error contract;
- [x] authenticated `/status`;
- [x] `/versions`;
- [x] `/readiness` / diagnostics reads;
- [x] CI for authentication/scopes/revocation/no-secret exposure.

**M1 implementation is complete.** Final v1 closeout only re-smokes least privilege, revocation and remote audit against the deployed RC.

## M2 — Plan / operation lifecycle ✅ implementation complete

- [x] generic operation/request IDs;
- [x] source revision/fingerprint;
- [x] plan ID and plan expiry;
- [x] typed Preview response;
- [x] risk/confirmation class;
- [x] exact-plan Apply;
- [x] stale plan/revision rejection;
- [x] operation status retrieval;
- [x] stored-state verification;
- [x] rendered verification;
- [x] rollback invocation where supported;
- [x] retry/idempotency acceptance;
- [x] audit correlation.

**M2 implementation is complete.** Page, Insight, Media and Design production slices already prove the lifecycle; the final RC battery extends that proof to navigation and managed Media ingest.

## M3 — Pages from ChatGPT ✅ implementation complete

- [x] inspect Theme-controlled Pages remotely;
- [x] inspect structured slots/current revision;
- [x] create supported missing Theme Page with provenance and rollback;
- [x] update structured Page slots;
- [x] manage permitted Theme presentation through bounded M7 design capabilities;
- [x] EN/ES Page behavior defined by one Theme-owned Page resource with language-specific structured models/routes; separate WP Page pairing is N/A by architecture;
- [x] Page-level SEO/GEO inspect/remediate capability;
- [x] Preview exact change;
- [x] Apply exact plan;
- [x] stored verify;
- [x] rendered verify;
- [x] rollback supported change;
- [x] complete first real Page mutation on `eduardoyauriluna.com` originating from ChatGPT.

A missing production contract Page must never be manufactured solely to exercise Page-create acceptance. Creation remains available if a legitimate missing supported resource occurs.

## M4 — Insights/blog from ChatGPT ✅ implementation complete

- [x] create draft/published Insight;
- [x] update title/excerpt/body;
- [x] bounded authorship/type policy: author is the bound execution identity; `insight_type` replaces arbitrary taxonomy proxying;
- [x] internal/research relationships to verified Research Lines;
- [x] EN/ES translation pairing;
- [x] Media metadata/assignment where supported;
- [x] SEO/GEO inspection/preparation;
- [x] reversible scheduling control;
- [x] bounded draft/publish status lifecycle;
- [x] rendered verification;
- [x] rollback where supported;
- [x] first real Insight operation from ChatGPT.

Remaining v1 work is representative production breadth acceptance, not missing base implementation.

## M5 — Research objects from ChatGPT ✅ implementation complete

Authenticated typed gateways exist for:

- [x] Research Lines;
- [x] Publications / outputs;
- [x] Projects;
- [x] Research Software;
- [x] Datasets;
- [x] evidence/provenance;
- [x] verified Research Line relations;
- [x] structured EN/ES translations;
- [x] stored/rendered verification and safe rollback contracts.

Identity/evidence-sensitive changes remain more strongly gated. Final v1 acceptance must use clearly temporary fixtures or real evidence; it must not fabricate academic claims.

## M6 — SEO/GEO optimisation from ChatGPT ✅ implementation complete

- [x] live site/readiness diagnostics retrieval;
- [x] deterministic remediation grouping/planning;
- [x] title/description structured-source remediation routing;
- [x] canonical/hreflang/indexability checks plus bounded indexability lifecycle;
- [x] Schema checks/rendered verification;
- [x] sitemap/crawlability checks;
- [x] internal-link/entity-graph guidance through bounded relations/navigation;
- [x] entity/authorship/direct-answer/GEO checks;
- [x] evidence/provenance checks and gated actions;
- [x] Preview → Apply → Verify/rollback infrastructure for deterministic supported remediation;
- [x] rerun readiness and report residual manual/editorial/evidence actions;
- [ ] first legitimate real SEO/GEO remediation from ChatGPT when production naturally exposes a safe candidate.

**Production acceptance rule (ADR-016):** issue #184 proved the real site was already `ready` with zero blockers, zero advisories and zero safe remediation candidates. A defect must never be manufactured merely to exercise Apply.

## M7 — Bounded Theme design control from ChatGPT ✅ implementation complete

- [x] palette/design token selection;
- [x] typography tokens;
- [x] spacing/density tokens;
- [x] Theme-supported section/component variants (`hero`, `sections`, `cards`, `collections`);
- [x] preserve responsive/accessibility rules;
- [x] rendered visual/state verification where practical;
- [x] no arbitrary CSS/PHP execution as normal control path;
- [x] palette production acceptance;
- [ ] typography production acceptance;
- [ ] density production acceptance;
- [ ] representative component-variant production acceptance;
- [ ] final responsive/accessibility/readiness confirmation after those reversible checks.

## M8 — Real-site operating acceptance ✅ COMPLETE (2026-10-12)

- [x] supported Theme + Manager installed on real site;
- [x] remote connection enabled locally;
- [x] ChatGPT can read live status/capabilities;
- [x] real Page task completed end-to-end;
- [x] real Insight task completed end-to-end;
- [x] real SEO/GEO readiness acceptance completed end-to-end (`ready`, 0 blockers, 0 advisories, no remediation required);
- [x] real bounded Theme Design task completed end-to-end with exact rollback restoration;
- [x] rollback proven for supported Page, Insight, Media and Design operations;
- [x] audit records proven without secrets;
- [x] read-only scope mode proven;
- [x] revocation proven to immediately block remote use;
- [x] normal bypasses to Admin/SSH/manual code identified;
- [x] each normal-operation bypass converted into a typed Manager implementation gap and subsequently implemented (#192–#194).

**M8 closeout remains valid.** Manager v1 closeout under #201 now proves broader routine-operation coverage and removes the three recorded normal-operation bypasses from the actual product path.

**Phase 5 done when:** routine management of the Eduardo site can genuinely be driven from ChatGPT through the Manager, with real rendered verification.

---

## Manager v1 closeout — issue #201

Repository implementation for the accepted v1 contract is substantially complete. Remaining closure work is deliberately separated from implementation status:

- [x] remote audit, bounded navigation and managed Media ingest implemented;
- [x] private Media ingest staging has explicit cancel + scheduled TTL cleanup;
- [x] Preview-first production harnesses exist for the three new normal-operation closures;
- [x] M1–M7 roadmap reconciled against the real implementation;
- [ ] Manager 0.10.0 RC package passes full CI and is merged;
- [ ] deploy the RC to `eduardoyauriluna.com`;
- [ ] production-accept remote audit without Admin;
- [ ] production-accept bounded navigation with exact rollback restoration;
- [ ] production-accept managed Media ingest with final inventory cleanup;
- [ ] production-accept representative remaining M4/M5/M7 breadth using reversible/non-fabricated fixtures;
- [ ] final `/capabilities`, read-only, revocation and audit smoke checks;
- [ ] final no-normal-bypass review;
- [ ] promote the validated Manager release from RC to v1 and build final installable artifacts.

---

## Phase 6 — Academic connections

Existing repository adapters include ORCID, Crossref, OpenAlex, Zenodo, GitHub research-software preview and Scholar-compatible metadata.

Real-data/production work remains:

- [ ] verified ORCID supplied/confirmed;
- [ ] real external identity reconciliation;
- [ ] production tokens where optional authenticated reads require them;
- [ ] real connection status exposed through Manager remote read surface;
- [ ] any external writes remain explicitly gated and are not implied by remote website-management permission.

---

## Phase 7 — Real research content/output portfolio

- [ ] real Research Lines reviewed;
- [ ] initial real Projects;
- [ ] initial real Publications/outputs with correct status;
- [ ] Research Software records;
- [ ] Datasets where appropriate;
- [ ] evidence/source references;
- [ ] DOI/identifier linkage where real and verified;
- [ ] EN/ES content review.

No fabricated academic identifiers or publication status.

---

## Phase 8 — Academic visibility

- [ ] ORCID profile complete;
- [ ] Google Scholar indexing/profile linkage readiness;
- [ ] Zenodo/OpenAlex identity resolution;
- [ ] CVN/FECYT where appropriate;
- [ ] external profile consistency;
- [ ] researcher-name search consistency audit.

---

## Phase 9 — Doctoral application 2027

- [ ] define doctoral proposal;
- [ ] select target programmes;
- [ ] identify supervisors;
- [ ] review recent supervisor work;
- [ ] prepare outreach package;
- [ ] final academic CV;
- [ ] final research statement;
- [ ] final research proposal;
- [ ] credible portfolio of research outputs;
- [ ] outreach/application execution.

---

## Work explicitly deferred during Eduardo M1–M8

The following items were intentionally deferred while proving the single-site Eduardo operating model. M8 is now complete, so they may be reconsidered only when they do not displace the remaining academic/site priorities:

- generic multi-client SaaS Control Center;
- fleet management across many customer sites;
- billing/subscriptions;
- arbitrary Theme compatibility;
- broad existing-site migration/adoption product work;
- `emmake.com` as active implementation priority;
- unrestricted remote WordPress administrator proxying.

`emmake.com` remains the second real validation scenario for existing-site adoption; it is not automatically promoted ahead of the Eduardo academic-content and visibility phases merely because M8 is now proven.

---

## Definition of current success

For this phase of the project, success means that Eduardo can give a routine website instruction in ChatGPT and the system can:

1. connect to the live Research Manager;
2. inspect current state;
3. prepare a bounded plan;
4. show/validate the Preview as required;
5. Apply through shared Manager services;
6. verify stored state;
7. verify the rendered public site;
8. return the real result/URL/status;
9. preserve an audit record;
10. roll back when supported;
11. do all of this without SSH, direct DB access or arbitrary code execution for the normal operation.