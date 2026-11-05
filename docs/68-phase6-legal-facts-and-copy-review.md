# Phase 6 — Evidence-bound Legal/Privacy Content Review (EN/ES)

> STATUS: INTERNAL REVIEW ONLY — NOT AUTHORISED FOR LIVE PUBLICATION.
> This document is not a substitute for verified contractual processing facts or legal review. Nothing in it constitutes a WordPress content Apply or rendered Verify.

## Scope and authority

- Website: https://eduardoyauriluna.com — Eduardo Research, Site Architect Greenfield.
- Live website changes are only allowed through the governed Manager/Gateway lifecycle; the Theme renders the site. Site Manager is a separate Brownfield product and out of scope.
- Last infrastructure observation: GitHub Actions Phase 6 production run 41993094728 on 2026-11-05, main commit 78325491a9f4b115a77a1fb4cd5c36aaeddc739b.
- Current state: four public legal URLs return HTTP 200 but contain zero substantive legal sections; visitor audit must remain red until factual EN/ES content passes.

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
| Residence/contact postal address and application of economic activity/LSSI | **Unknown** | Needs user/legal assessment; do not publish guessed location, address or tax ID | Owner review |
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

Only the following statements use facts directly confirmed by the user or the public purpose of the site. They are not a complete, publishable Privacy Policy or Legal Notice. The final copy must satisfy each page's 2–6 substantive-section contract without placeholders.

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

## 4. Publication gate — must be fail-closed

1. Confirm the owner-approved public details required by the applicable law, without publishing private residential identifiers in GitHub issues or public repositories.
2. Verify actual host and email-provider contractual entity, legal terms and data-processing terms, and the CMS/server log and analytics/cookie configuration.
3. Assess and confirm purpose, data categories, lawful basis, recipients/transfers and retention for each real processing activity. Resolve every PENDING item above; do not publish markers or example terms.
4. Final human/legal editorial review of all four EN/ES texts. Ensure 2–6 substantive plain-text sections per page with titles of 1–120 characters and bodies of 45–4000 characters.
5. Use the existing governed Gateway action legal.content.update for each of privacy-policy and legal-notice in EN/ES: Preview → Apply → stored/rendered Verify, with rollback when verification fails.
6. Re-run independently the full Phase 6 production visitor audit. Open Phase 7 only when four legal routes and all visitor-surface checks are proven green.

References: issue https://github.com/Emmakex/eduardoyauriluna-research/issues/315 ; Phase 6 production audit workflow https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/41993094728 .
