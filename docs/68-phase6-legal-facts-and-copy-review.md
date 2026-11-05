# Phase 6 — Evidence-bound Legal/Privacy Content Review (EN/ES)

> STATUS: INTERNAL REVIEW ONLY — NOT AUTHORISED FOR LIVE PUBLICATION.
> This document is not a substitute for verified contractual processing facts or legal review. Nothing in it constitutes a WordPress content Apply or rendered Verify.

## Scope and authority

- Website: https://eduardoyauriluna.com — Eduardo Research, Site Architect Greenfield.
- Live website changes are only allowed through the governed Manager/Gateway lifecycle; the Theme renders the site. Site Manager is a separate Brownfield product and out of scope.
- Last infrastructure observation: GitHub Actions Phase 6 production run 41993094728 on 2026-11-05, main commit 78325491a9f4b115a77a1fb4cd5c36aaeddc739b.
- Current production state (2026-11-05): **Legal Notice ES and EN each have four substantive sections, published and rendered-verified through A8**. The two Privacy Policy pages remain unpublished/zero-section pending verified processing facts; the overall visitor audit must remain red until all four pages pass.

## 1. Fact and evidence ledger

| Claim | Evidentiary status | Approved usage | Source |
|---|---|---|---|
| Website owner / controller is Eduardo Jose Yauri Luna as a natural person | **Confirmed by user** | May identify the individual legal responsible person | Phase 6 issue #315 user-confirmation ledger, 2026-11-04 |
| Public legal contact is hola@emmake.com | **Explicitly authorised by user** | May publish in Privacy / Legal Notice | Phase 6 issue #315 user-confirmation ledger, 2026-11-04 |
| EMMake is the data controller/website owner | **Contradicted by user** | Do not assert; email domain does not confer ownership | Phase 6 issue #315 user-confirmation ledger |
| The website uses Hostinger-managed DNS | **Publicly observed; provider documentation corroborates** | May cite DNS operator, not a processor contract | GitHub run 41993094728 DNS records lunar.dns-parking.com and solar.dns-parking.com; Hostinger DNS support |
| A Hostinger CDN/edge serves the public site | **Strong public network evidence** | May describe observed delivery stack, not infer contractual data-processing arrangements | GitHub run 41993094728 header Server: hcdn, Hostinger CDN docs |
| Production WordPress host, account owner and precise contracting legal entity | **Not yet verified contractually** | Do not name a processor or country in public legal copy yet | Provider account / invoice / DPA needed |
| Public site has two anonymous GET search forms (insights EN/ES), and no visible contact forms | **Observed on 16-page HTML snapshot** | Describe only the tested anonymous public behavior | GitHub run 41899408356 |
| A Chromium snapshot of eight routes saw no external network hosts, no cookies or localStorage keys | **Observed in bounded anonymous browser test** | May cite in engineering evidence only; cannot claim no tracking exists in all sessions | GitHub run 41902152110 |
| Chromium saw the WordPress sessionStorage key wpEmojiSettingsSupports | **Observed** | Technical inventory; do not misrepresent it as absence of browser storage | GitHub run 41902152110 |
| Initial HTML contains a www.googletagmanager.com preconnect hint | **Observed**, not evidence of loaded analytics | Do not infer GTM tags, analytics, consent or third-party transfers from the hint | GitHub run 41899408356 |
| Server access/security logs: fields, purpose and retention | **Unknown** | Must be verified before any final Privacy text | Hosting/platform configuration |
| Email provider and email retention for messages sent to hola@emmake.com | **Unknown** | Must be verified before naming processors/retention | Mail account/provider configuration |
| LSSI applicability to this personal academic site | **Conditional on economic activity** | Do not demand personal address or NIF solely on the basis of having a personal website; verify direct/indirect revenue first | Official Spanish LSSI FAQ: https://lssi.digital.gob.es/lssi/la-ley/preguntas-frecuentes |
| Residence/contact postal address | **Not supplied** | Do not publish guessed address or tax identifier; assess disclosure only after determining applicable requirements | Owner/legal review |
| Legal bases, transfer destinations, categories of processors/recipients and retention by purpose | **Review required** | Must be grounded in actual operation before Apply | Processing inventory and legal review |

Provider documentation:
- Hostinger DNS: https://www.hostinger.com/support/how-to-look-up-domain-nameservers-at-hostinger/
- Hostinger CDN/DNS: https://www.hostinger.com/support/hostinger-cdn-and-your-dns-records/
- Hostinger CDN eligibility: https://www.hostinger.com/support/which-plans-include-hostinger-cdn/

## 2. Reference legal requirements to reconcile

- Spain LSSI Article 10 and scope: https://boe.es/buscar/act.php?id=BOE-A-2002-13758 and https://lssi.digital.gob.es/lssi/la-ley/preguntas-frecuentes
- Spain LOPDGDD and EU GDPR information duty: https://www.boe.es/buscar/act.php?id=BOE-A-2018-16673 and https://www.aepd.es/derechos-y-deberes/conoce-tus-derechos/derecho-de-informacion
- A natural-person research site is not automatically a commercial service; assess whether paid activity/advertising/sponsorship makes LSSI applicable before assuming additional obligations. This is not a determination of legal status.

## 3. Bilingual copy ready for factual verification (NOT LIVE)

The following are original evidence-bound copy fragments retained for traceability; **the complete Legal Notice texts were subsequently published by A8 in both languages (see §3C)**. Privacy Policy fragments are not a complete or publishable Privacy Policy. Any new Privacy copy must satisfy each page's 2–6 substantive-section contract without placeholders.

### Spanish — Aviso legal (approved identity and contact fragments)

**Titularidad.** Este sitio web, eduardoyauriluna.com, presenta el perfil y la actividad de investigación de Eduardo Jose Yauri Luna. Su titular es Eduardo Jose Yauri Luna, que actúa como persona física.

**Contacto.** Para consultas relativas a la titularidad del sitio web o a su contenido, puede escribir a hola@emmake.com. El dominio del correo de contacto no implica que EMMake sea titular de esta web.

**Pending:** applicability of LSSI to actual activities, any required postal address/other identification, intellectual property of individual pages/media and verified terms concerning third-party content. The final copy must not pretend these facts are settled.

### English — Legal notice (approved identity and contact fragments)

**Ownership.** This website, eduardoyauriluna.com, presents the research profile and activities of Eduardo Jose Yauri Luna. The website is operated by Eduardo Jose Yauri Luna in a personal capacity.

**Contact.** For enquiries concerning the website's ownership or content, contact hola@emmake.com. Use of this email domain does not make EMMake the owner of this site.

**Pending:** legal scope/applicability, any mandatory postal/contact identifiers, verified intellectual-property and third-party-content terms.

### Spanish — Política de privacidad (approved identity and rights-contact fragments)

**Responsable.** El responsable del tratamiento de datos personales realizado, en su caso, mediante eduardoyauriluna.com es Eduardo Jose Yauri Luna, persona física. El correo de contacto para consultas de privacidad y ejercicio de derechos es hola@emmake.com.

**Derechos.** Puede solicitar información sobre el tratamiento de sus datos personales y ejercer, cuando corresponda, los derechos de acceso, rectificación, supresión, oposición, limitación y portabilidad mediante hola@emmake.com. También puede presentar una reclamación ante la Agencia Española de Protección de Datos (www.aepd.es).

**Pending:** purposes and legal bases for messages and server access, categories of data and recipients, contractual hosting/email processors, retention criteria, international transfers, security and analytics/cookie practices. No unsupported global 'no cookies', 'no logs' or 'no transfers' claims are permissible.

### English — Privacy policy (approved identity and rights-contact fragments)

**Controller.** The controller of personal data processed, where applicable, through eduardoyauriluna.com is Eduardo Jose Yauri Luna, acting as a natural person. Contact hola@emmake.com for privacy enquiries and requests to exercise data protection rights.

**Your rights.** You may request information about the processing of your personal data and, where applicable, exercise rights of access, rectification, erasure, objection, restriction and portability by emailing hola@emmake.com. You may also lodge a complaint with the Spanish Data Protection Agency (www.aepd.es).

**Pending:** processing purposes and lawful bases, data categories, actual providers/processors, retention, international transfers, security, server logs, analytics and cookies. Do not present these unknowns as established facts.

## 3A. Governed contact-evidence authorization checkpoint — 2026-11-05

- The production MCP catalog contains `site_evidence_create_plan`, `site_evidence_apply`, `site_evidence_verify` and `site_evidence_rollback`. These are already implemented by the backend M5 lifecycle; no alternate WordPress write path is justified.
- Gateway v2 read-only `manager.capability.check` was expanded in [PR #444](https://github.com/Emmakex/eduardoyauriluna-research/pull/444) strictly to **inspect** `evidence.write` without granting it. [Production read run 41996484136](https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/41996484136), command `cmd-phase6-evidence-write-scope-20261105-01`, `status=success`, returned true for `evidence.read`, `evidence.write`, `operations.apply` and `operations.rollback` on the **WordPress Manager credential**.
- The canonical GitHub MCP principal configuration `config/phase2e-gateway-capabilities.json` includes `evidence.read` but **does not** grant the separate commercial `evidence.write` entitlement. The backend Manager scope check does not override this independent MCP authorization boundary.
- The existing Gateway v2 action registry has no evidence-contact-create mutation route. There has been **no production M5 evidence write** and the last verified public contact-evidence count was zero. Do not claim the email is stored or published because it is authorised in issue #315.
- Any future contact publication must be tightly scoped to the user-approved legal contact `hola@emmake.com`, accompanied by a separate authorization decision for the broader `evidence.write` commercial entitlement, bounded operation validation, provenance, stored/rendered Verify and rollback. Do **not** silently add broad `evidence.write` privileges to the canonical Gateway principal as a side effect of this documentary cut.
- This contact-evidence pathway is distinct from A8 legal content, which uses `pages.write` and remains blocked by missing verified privacy treatment/provider facts.

## 3B. LSSI scope checkpoint — 2026-11-05

- [Official Spanish LSSI FAQ](https://lssi.digital.gob.es/lssi/la-ley/preguntas-frecuentes): a purely personal website without economic activity is **not** within the LSSI merely because it is online. Direct or indirect income from the website, including paid advertising or sponsorship, changes the analysis.
- The Eduardo Research public content is positioned as an academic profile; this does **not**, by itself, prove the absence of paid activity, sponsorships or other economic uses. Do not prematurely assert legal exemption or applicability.
- Only once economic activity has been confirmed or ruled out should the relevant public owner/contact identifiers (including address or tax identifier when required) be considered. Do not place private address/tax IDs in GitHub, this dossier or public logs.
- GDPR/LOPDGDD information obligations require a separate processing analysis irrespective of the LSSI assessment. No global `no logs`, `no cookies`, `no recipients` or `no data processing` statements follow from anonymous-browser probes.

## 3C. Production Legal Notice EN/ES acceptance — 2026-11-05

- The user confirmed that the site is a **personal academic website**. This does not, by itself, prove the absence of economic activity; do not assert an unconditional LSSI exemption without establishing whether any direct or indirect monetisation exists.
- **ES Legal Notice:** https://eduardoyauriluna.com/es/aviso-legal/ — 4 sections, key `legal-notice`, language `es`. Immutable command `cmd-phase6-academic-legal-es-20261105-01`, [Gateway v2 run 41998123585](https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/41998123585), `status=success`, `verified=true`. Exact A8 operation `erm-legal-op-7f5e77f9fa9141be9f78a9a21e03929d`.
- **EN Legal Notice:** https://eduardoyauriluna.com/legal-notice/ — 4 sections, key `legal-notice`, language `en`. Immutable command `cmd-phase6-academic-legal-en-20261105-01`, [Gateway v2 run 41998336132](https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/41998336132), `status=success`, `verified=true`. Exact A8 operation `erm-legal-op-21a5f11cc8494ddd9aa8e0908268a6d6`.
- A8 Preview → Apply → **rendered** Verify completed successfully for both; the text uses only factual owner, academic purpose, contact and conditional third-party references. No unverified provider/retention/cookie assertions or LSSI exemption were published.
- The first ES attempt in [Gateway run 41997650324](https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/41997650324) failed **before** Preview because the A8 runner required `--command` while Gateway v2 passes a positional command path. [PR #446](https://github.com/Emmakex/eduardoyauriluna-research/pull/446) repaired the CLI with regression testing and exact-head/post-merge quality, without changing backend semantics. A subsequent new read-only request triggered the unresolved immutable ES command's execution and verification.
- **Still blocked:** Privacy Policy ES and EN. No legal policy has been published for these. The separate user-facing public visitor gate, and Phase 6/Phase 7 gate, remain **incomplete** pending processing/provider/email/server-log/retention facts and Privacy A8 acceptance.
- Site Manager remains out of scope. Do not use this internal dossier as a WordPress source of truth; production readback + governed operation evidence are authoritative.

## 4. Publication gate — must be fail-closed

1. Confirm the owner-approved public details required by the applicable law, without publishing private residential identifiers in GitHub issues or public repositories.
2. Verify actual host and email-provider contractual entity, legal terms and data-processing terms, and the CMS/server log and analytics/cookie configuration.
3. Assess and confirm purpose, data categories, lawful basis, recipients/transfers and retention for each real processing activity. Resolve every PENDING item above; do not publish markers or example terms.
4. Final human/legal editorial review of all four EN/ES texts. Ensure 2–6 substantive plain-text sections per page with titles of 1–120 characters and bodies of 45–4000 characters.
5. Legal Notice EN and ES were applied and rendered-verified as documented in §3C. **Only Privacy Policy ES and EN remain to publish** through the governed Gateway action legal.content.update: Preview → Apply → stored/rendered Verify, with rollback if verification fails.
6. Re-run independently the full Phase 6 production visitor audit. Open Phase 7 only when four legal routes and all visitor-surface checks are proven green.

References: issue https://github.com/Emmakex/eduardoyauriluna-research/issues/315 ; Phase 6 production audit workflow https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/41993094728 .
