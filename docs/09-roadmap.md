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
Primary execution tracker: issue #97.

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

### Real-site acceptance still required

- [ ] supported clean WordPress target confirmed for `eduardoyauriluna.com`;
- [ ] Research Theme installed/activated on the real target;
- [ ] Manager installed/activated on the real target;
- [ ] expected permalink/language/domain state confirmed;
- [ ] production mobile/accessibility/performance validation;
- [ ] final visual/palette review on the real rendered site.

---

## Phase 2 — Research Theme / preset ✅ repository implementation

Repository implementation includes the Theme-owned frontend model, page/single/archive surfaces, research collections/entities, responsive behavior, semantic rendering, SEO/GEO authority and the Research visual direction.

The Theme remains independently renderable and must never become dependent on a chat connection.

### Remaining acceptance

- [ ] verify all intended Theme surfaces on real `eduardoyauriluna.com`;
- [ ] verify EN/ES public routes/hreflang on real domain;
- [ ] verify production Core Web Vitals/accessibility;
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

## Phase 4 — Research Manager core ✅ substantial repository implementation

The Manager already contains shared services and Admin surfaces for major domain areas, including:

- blueprint/Greenfield lifecycle;
- diagnostics/readiness;
- structured Pages;
- Insights;
- Research Lines;
- Research Objects;
- translations;
- evidence;
- remediation;
- rendered verification;
- academic connector adapters;
- snapshot/executor/rollback infrastructure;
- WP-CLI Greenfield adapter;
- standalone install bundle.

This phase is not equivalent to remote ChatGPT governance; it is the engine that the bridge will expose.

---

# Phase 5 — ChatGPT-governed Manager bridge — CURRENT PRIMARY WORK

**Goal:** make the Research Manager the authenticated bridge through which we govern the real WordPress site from this ChatGPT workflow.

Primary tracker: #97.

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

The real `eduardoyauriluna.com` site has now also passed these ChatGPT-through-Manager acceptance slices:

- issue #180: reversible Page slot mutation with Preview → Apply → stored/rendered Verify → Rollback and exact Page baseline restoration;
- issue #182: temporary Insight creation with stored/rendered Verify, authenticated inspection, Rollback and proof that the created Insight is absent from the final managed inventory;
- issue #184: SEO/GEO production discovery returned `ready`, `blocking=0`, `advisories=0` and zero safe remediation candidates, so the acceptance closed as an already-ready state without manufacturing an artificial SEO mutation;
- issue #186: bounded Theme Design Preview selected `palette -> ocean-lab`, then Apply → Verify → rendered design readiness → Rollback restored the exact Theme design baseline; the independent temporary 5-scope Design credential was then revoked and rejected with HTTP 401 `authentication_failed`.

The stable Phase 2 credential remained least-privilege throughout. In particular, its missing `design.write` scope correctly produced a 403 on the first Design Preview attempt; Design acceptance therefore used a separate temporary scoped session instead of widening the primary credential.

## M1 — Remote Manager foundation

- [ ] versioned REST namespace, e.g. `/wp-json/research-manager/v1/`;
- [ ] site-specific remote connection identity;
- [ ] local WordPress enable/disable/revoke controls;
- [ ] credential rotation;
- [ ] read/write scope model;
- [ ] `/capabilities` discovery;
- [ ] request IDs/idempotency primitives;
- [ ] replay/expiry protection;
- [ ] audit record model;
- [ ] typed remote error contract;
- [ ] authenticated `/status`;
- [ ] `/versions`;
- [ ] `/readiness` / diagnostics reads;
- [ ] CI for authentication/scopes/revocation/no-secret exposure.

**M1 done when:** ChatGPT-compatible tooling can securely read live Manager/site state with a revocable site-specific credential, but cannot mutate anything without write scopes.

## M2 — Plan / operation lifecycle

- [ ] generic operation/request IDs;
- [ ] source revision/fingerprint;
- [ ] plan ID and plan expiry;
- [ ] typed Preview response;
- [ ] risk/confirmation class;
- [ ] exact-plan Apply;
- [ ] stale plan/revision rejection;
- [ ] operation status retrieval;
- [ ] stored-state verification;
- [ ] rendered verification;
- [ ] rollback invocation where supported;
- [ ] retry/idempotency acceptance;
- [ ] audit correlation.

**M2 done when:** a remote caller can safely plan, apply and verify a bounded mutation without duplicate writes or silent stale overwrites.

## M3 — Pages from ChatGPT

- [x] inspect Theme-controlled Pages remotely;
- [x] inspect structured slots/current revision;
- [ ] create supported missing Theme Page;
- [x] update structured Page slots;
- [ ] manage permitted Theme variant(s);
- [ ] pair EN/ES Page resources;
- [ ] Page-level SEO/GEO inspect/remediate;
- [x] Preview exact change;
- [x] Apply exact plan;
- [x] stored verify;
- [x] rendered verify;
- [x] rollback supported change;
- [x] complete first real Page mutation on `eduardoyauriluna.com` originating from ChatGPT.

**M3 is the first end-to-end proof of the product model.**

## M4 — Insights/blog from ChatGPT

- [ ] create draft Insight;
- [ ] update title/excerpt/body;
- [ ] taxonomy/author;
- [ ] internal/research relationships;
- [ ] translation pair;
- [x] media metadata/assignment where supported;
- [ ] SEO/GEO preparation;
- [ ] schedule;
- [ ] publish/update/archive;
- [x] rendered verification;
- [x] first real Insight operation from ChatGPT.

## M5 — Research objects from ChatGPT

Expose existing service-layer capabilities through the authenticated bridge for:

- [ ] Research Lines;
- [ ] Publications;
- [ ] Projects;
- [ ] Research Software;
- [ ] Datasets;
- [ ] evidence/provenance;
- [ ] relations;
- [ ] translations.

Identity/evidence-sensitive changes remain more strongly gated.

## M6 — SEO/GEO optimisation from ChatGPT

- [x] live site/readiness diagnostics retrieval;
- [ ] deterministic remediation grouping/planning;
- [ ] title/meta remediation;
- [ ] canonical/hreflang/indexability checks/remediation;
- [ ] Schema checks/remediation;
- [ ] sitemap/crawlability checks;
- [ ] internal-link optimisation;
- [ ] entity/authorship/direct-answer/GEO checks;
- [ ] evidence/provenance checks;
- [ ] Preview → Apply → Verify;
- [x] rerun readiness and report residual manual/editorial/evidence actions;
- [ ] first real SEO/GEO remediation from ChatGPT on the rendered site.

**Production acceptance note:** issue #184 proved the real site was already `ready` with zero blockers, zero advisories and zero safe remediation candidates. No remediation was fabricated solely to exercise Apply; the first real remediation remains pending until production exposes a legitimate candidate.

## M7 — Bounded Theme design control from ChatGPT

- [x] palette/design token selection;
- [ ] typography tokens;
- [ ] spacing/density tokens;
- [ ] Theme-supported section/component variants;
- [x] preserve responsive/accessibility rules;
- [x] rendered visual/state verification where practical;
- [x] no arbitrary CSS/PHP execution as normal control path.

**Production acceptance note:** issue #186 proved one bounded Theme-native palette operation end-to-end using a temporary exact 5-scope Design credential, including exact baseline restoration, credential revocation and post-revocation 401 rejection. Typography, density and section/component variant breadth remain separate acceptance work.

## M8 — Real-site operating acceptance

- [x] supported Theme + Manager installed on real site;
- [x] remote connection enabled locally;
- [x] ChatGPT can read live status/capabilities;
- [x] real Page task completed end-to-end;
- [x] real Insight task completed end-to-end;
- [x] real SEO/GEO readiness acceptance completed end-to-end (`ready`, 0 blockers, 0 advisories, no remediation required);
- [x] real bounded Theme Design task completed end-to-end with exact rollback restoration;
- [x] rollback proven for supported Page, Insight, Media and Design operations;
- [ ] audit records proven without secrets;
- [ ] read-only scope mode proven;
- [x] revocation proven to immediately block remote use;
- [ ] normal bypasses to Admin/SSH/manual code recorded;
- [ ] every normal bypass converted into Manager backlog.

**Phase 5 done when:** routine management of the Eduardo site can genuinely be driven from ChatGPT through the Manager, with real rendered verification.

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

## Work explicitly deferred until Eduardo M1–M8 is proven

- generic multi-client SaaS Control Center;
- fleet management across many customer sites;
- billing/subscriptions;
- arbitrary Theme compatibility;
- broad existing-site migration/adoption product work;
- `emmake.com` as active implementation priority;
- unrestricted remote WordPress administrator proxying.

`emmake.com` remains the second real validation scenario for existing-site adoption, but this repository/workstream stays focused on `eduardoyauriluna.com` until the ChatGPT-governed Greenfield path is proven.

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