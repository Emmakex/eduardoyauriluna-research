# Phase 6 — Evidence-bound Legal/Privacy Content Review (EN/ES)

> STATUS: INTERNAL REVIEW ONLY — NOT AUTHORISED FOR LIVE PUBLICATION.
> This document is not a substitute for verified contractual processing facts or legal review. Nothing in it constitutes a WordPress content Apply or rendered Verify.

## Scope and authority

- Website: https://eduardoyauriluna.com — Eduardo Research, Site Architect Greenfield.
- Live website changes are only allowed through the governed Manager/Gateway lifecycle; the Theme renders the site. Site Manager is a separate Brownfield product and out of scope.
- Last privacy infrastructure observation: GitHub Actions Phase 6 production run 42010871067 on 2026-11-05, main commit 8c92eed83f2bed7d177b08724950646eab802c89. Historical Hostinger DNS/CDN evidence: run 41993094728.
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
| Public site has two anonymous GET search forms (insights EN/ES), and no visible contact forms | **Observed on 16-page HTML snapshot; user additionally confirmed no contact/lead forms** | Describe bounded direct-entry observation and retain a failing regression guard; no assertion about all forms globally | GitHub run 41899408356, owner confirmation 2026-11-05 |
| A Chromium snapshot of eight routes saw no external network hosts, no cookies or localStorage keys | **Observed in bounded anonymous browser test** | May cite in engineering evidence only; cannot claim no tracking exists in all sessions | GitHub run 41902152110 |
| Chromium saw the WordPress sessionStorage key wpEmojiSettingsSupports | **Observed** | Technical inventory; do not misrepresent it as absence of browser storage | GitHub run 41902152110 |
| Initial HTML contains a www.googletagmanager.com preconnect hint | **Observed**, not evidence of loaded analytics | Do not infer GTM tags, analytics, consent or third-party transfers from the hint | GitHub run 41899408356 |
| Server access/security logs: fields, purpose and retention | **Hostinger documentation confirms its dashboard exposes IP, timestamp, resource, browser and other access-log details, but the exact active WordPress account and retention remain unknown** | Describe as a technical risk/processing category only; do not present actual log retention as verified | https://www.hostinger.com/support/5650167-how-to-use-the-traffic-section-on-hostinger-dashboard-at-hostinger/ |
| Incoming legal-contact email routing for emmake.com | **Public Google MX verified and actual receipt by hola@emmake.com observed in connected Gmail account** (2026-11-05); Google Workspace subscription/CDPA incorporation, account retention rules and transfer configurations are still unverified | May state the public legal contact receives mail and uses Google mail infrastructure, **not** infer contractual processor or retention arrangements | Production run 42010871067; connected Gmail search for `to:hola@emmake.com` (metadata only; no message contents exported); https://knowledge.workspace.google.com/admin/domains/set-up-mx-records-for-google-workspace |
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

## 3D. Phase 6 Privacy data-flow evidence and editorial draft — 2026-11-05

### Reproducible production facts

- [PR #448](https://github.com/Emmakex/eduardoyauriluna-research/pull/448) added a bounded, **read-only** MX query for `emmake.com` to the existing Phase 6 public hosting observer; it does not inspect any mailbox. Merged as `8c92eed83f2bed7d177b08724950646eab802c89`.
- [Production audit #42010871067](https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/42010871067): DNS/MX observer success. Exact public mail route is `aspmx.l.google.com` (priority 1), `alt1.aspmx.l.google.com` and `alt2.aspmx.l.google.com` (priority 5), `alt3.aspmx.l.google.com` and `alt4.aspmx.l.google.com` (priority 10). Google documentation identifies these as legacy-compatible Google Workspace MX hosts, but this does **not** prove a particular mailbox exists, a Workspace contract is effective or which legal entity processes it.
- WordPress public HTTPS continues responding through a Hostinger-linked `hcdn` edge, with nameservers `lunar.dns-parking.com` / `solar.dns-parking.com`. Rotating public A/AAAA CDN IPs are not contractual origin evidence.
- The same run: independent contract, mobile, localized 404, anonymous HTTP, Chromium browser and DNS/MX jobs all **success**. The independent visitor gate correctly **fails on /privacy-policy/** because EN and ES Privacy have **zero** substantive rendered sections. Legal Notice EN and ES are populated. Overall workflow is **failure** until Privacy EN/ES is factually complete.
- Hostinger's [public traffic documentation](https://www.hostinger.com/support/5650167-how-to-use-the-traffic-section-on-hostinger-dashboard-at-hostinger/) identifies access-log fields and server-side analytics capabilities (including visitor IP and requested URL). Its [DPA](https://www.hostinger.com/legal/dpa) explains processing obligations for covered services, but neither document proves the exact contract, retention or country for this WordPress account.
- Google's [Workspace privacy compliance instructions](https://knowledge.workspace.google.com/admin/compliance/privacy-compliance-and-records-for-google-workspace-and-cloud-identity) explain its Cloud Data Processing Addendum (CDPA). The account's agreement, applicable legal entity, retention policy and data-region choices require confirmation; MX cannot prove them.
- AEPD [information duty](https://www.aepd.es/derechos-y-deberes/conoce-tus-derechos/derecho-de-informacion) requires transparent purposes, lawful bases, recipient categories/transfers, retention criteria and rights; [legitimate-interest basis](https://www.aepd.es/preguntas-frecuentes/2-tus-obligaciones-como-responsable-del-tratamiento/5-bases-legitimadoras-del-tratamiento/FAQ-0214-cuales-son-las-bases-de-legitimacion-para-el-tratamiento-de-datos) must be assessed/documented, not casually asserted.

### Candidate Spanish Privacy Policy structure — NOT FOR A8 APPLY

The text below is an **editorial proposal**, not a statement that all indicated activity/configuration has been factually confirmed. Each item has a release condition that must be resolved before production publication.

1. **Responsable y contacto (verified).** El responsable del tratamiento de datos personales relacionado con eduardoyauriluna.com es Eduardo Jose Yauri Luna, que actúa como persona física. Para consultas de privacidad o para ejercer sus derechos puede escribir a hola@emmake.com.
2. **Datos de navegación y funcionamiento (proposed; validate site logs).** La visita a una web puede generar datos técnicos de conexión, como dirección IP, fecha y hora, página solicitada y tipo de navegador. El servicio técnico puede utilizarlos para servir contenidos, mantener el funcionamiento y detectar incidencias de seguridad. **REQUIERE VALIDAR:** qué campos registra realmente esta cuenta de alojamiento, quién accede, para qué y durante cuánto tiempo.
3. **Comunicaciones voluntarias por correo (proposed; validate mailbox).** Si una persona escribe voluntariamente al correo público del sitio, los datos incluidos en su mensaje y los metadatos necesarios para gestionarlo se utilizarían para atender y responder la comunicación. **REQUIERE VALIDAR:** recepción y acceso reales al buzón, ausencia/presencia de otros usos, base jurídica aplicable y política efectiva de archivo/supresión.
4. **Finalidades y bases jurídicas (proposed; legal/controller review).** La entrega segura de los contenidos y la gestión de comunicaciones académicas pueden constituir finalidades diferenciadas de tratamiento. Su legitimación debe fundamentarse en una base del artículo 6 del RGPD adecuada a cada finalidad; el interés legítimo exige una valoración documentada. **REQUIERE VALIDAR:** bases concretas y, en su caso, prueba de ponderación y otros usos.
5. **Servicios técnicos, destinatarios y transferencias (partially observed, contractual facts pending).** Los DNS y la entrega pública del sitio muestran infraestructura Hostinger; los registros MX públicos de emmake.com apuntan a los servidores de correo de Google. **REQUIERE VALIDAR:** entidades contratadas, contratos de encargo y subencargados, regiones aplicadas y transferencias internacionales, si las hay, antes de publicar afirmaciones sobre destinatarios.
6. **Conservación, derechos y tecnologías de navegador (partially verified).** El plazo de conservación de cada categoría de datos debe quedar determinado o expresado mediante criterios verificables. Los interesados pueden ejercer sus derechos legalmente aplicables y reclamar ante la AEPD a través de los canales oficiales. Una muestra anónima de ocho páginas no observó cookies, pero sí la clave de sesión `wpEmojiSettingsSupports`; esa muestra no demuestra ausencia de otros almacenamientos o tratamientos. **REQUIERE VALIDAR:** criterios reales de conservación, configuración de cookies/analítica y vías efectivas de atención de derechos.

### English Privacy Policy structure — NOT FOR A8 APPLY

1. **Controller and contact (verified).** The controller of personal data processing related to eduardoyauriluna.com is Eduardo Jose Yauri Luna, acting as an individual. Privacy questions and data protection rights requests may be sent to hola@emmake.com.
2. **Technical access and operation (proposed; verify logs).** Visiting a website may generate technical connection data, including IP address, time, requested page and browser type. Technical services may use these details to serve content, maintain reliable operation and investigate security issues. **MUST VERIFY:** fields actually recorded by this hosting account, access, purposes and retention.
3. **Voluntary email correspondence (proposed; verify mailbox).** When someone voluntarily contacts the website's public email address, information included in the message and metadata needed to process it would be used to handle and reply to the enquiry. **MUST VERIFY:** the receiving mailbox, actual handling, lawful basis and archiving/deletion arrangements.
4. **Purposes and lawful bases (proposed; controller/legal review).** Secure website delivery and management of academic enquiries are separate potential processing purposes. Each must be matched to an appropriate lawful basis under GDPR Article 6. Legitimate interests require a documented assessment. **MUST VERIFY:** each basis and relevant balancing tests, plus other actual uses.
5. **Technical providers, recipients and transfers (partially observed; contracts pending).** Public DNS and CDN delivery point to Hostinger infrastructure, while emmake.com public MX records route inbound mail to Google's servers. **MUST VERIFY:** contracted legal entities, processor/subprocessor terms, applicable data regions and any international transfers before final recipient claims.
6. **Retention, rights and browser technologies (partially verified).** A documented retention duration or factual retention criterion is required for each data category. Individuals may exercise their applicable data protection rights and complain to the Spanish Data Protection Agency. A bounded anonymous-browser sample found no cookies but did observe the `wpEmojiSettingsSupports` session storage key; this does not prove all sessions are cookie-free. **MUST VERIFY:** real retention settings, cookies and analytics configuration, and procedures for rights requests.

### Exact blockers for Privacy publication

- **Hostinger account evidence:** production site linked to exact hosting plan and contracting legal entity, processor/DPA applicability, server/CDN access and error log categories and current retention configuration. Avoid sharing personal account IDs, public residential address or log contents.
- **Mail routing/account evidence:** confirm that hola@emmake.com is a mailbox/alias forwarding into a real account under Google-managed mail, its controlling contractual party, Workspace CDPA applicability, configured retention, and storage/data-region details. Do not ask for raw messages.
- **Controller decisions:** document the actual purpose and lawful basis for reply emails and technical access/security, necessary retention criteria, recipient categories and international transfer posture. Only then replace **REQUIERE VALIDAR / MUST VERIFY** with precise reviewed statements and use A8.
- **Release invariant:** never submit drafts containing unresolved approval flags through `legal.content.update`. Only A8 Preview → Apply → rendered Verify on complete, owner-reviewed ES/EN Privacy content can close the remaining 2 of 4 legal resources.

## 3E. Fail-closed Privacy release authorization — 2026-11-05

- Active mailbox delivery to `hola@emmake.com` is now observed in the connected primary Gmail account. This was a read-only account-specific search; no email body, sender, subject, account identifiers, attachments or message IDs are copied to the engineering ledger. Google MX public DNS independently corroborates mail-routing infrastructure. The legal contractual entity, CDPA, retention and location remain unverified.
- `config/phase6-privacy-release-evidence.json` now records a **closed** release decision matrix. It identifies which facts are owner-confirmed, observed-only or still pending. The configuration explicitly sets `release_authorized=false` and both languages to `draft`.
- `scripts/phase6-privacy-release-gate.py` validates that all nine processing and owner-approval requirements have verified provenance and that **both** exact EN/ES drafts have approved status. It also rejects unresolved editorial placeholders. This is an internal factual guard, not a substitute for a GDPR/legal review.
- `scripts/phase6-legal-content-runner.py` calls the guard **only for `privacy-policy`** before any MCP Preview / Apply, and writes a sanitized terminal failure if authorization is incomplete. `legal-notice` is unaffected; the two successfully verified live notices remain untouched.
- This gate cannot by itself confirm the accuracy of a human assertion. Every release status must be reviewed against evidence; never flip a `pending` / `observed` flag to `verified` without checking the underlying configuration and obtaining the website owner's documented approval.
- No privacy content is published or represented as accepted by this cut. Its expected CI outcome while blocked is a **successful gate self-test** showing `release_ready=false`, not a false production-privacy certification.

## 3F. Academic site — no direct visitor-data collection contract (2026-11-05)

- The owner has confirmed that `eduardoyauriluna.com` is an academic website **without contact, account-registration or lead-capture forms**. This does **not** mean zero personal data: server/IP access logging and voluntary messages sent to the authorized public mailbox are separate possible processing activities.
- An earlier 16-page anonymous public HTML inspection found two same-site **GET search forms** on Insights EN/ES, not contact forms. Search requests can still appear in web server access logs, including query strings; therefore do not claim that searches cannot produce personal or identifiable information.
- The independent production inventory now **fails closed** if any of these direct-collection signals appear in the rendered public HTML: POST/other-than-GET form, external form action, email/telephone/password/file input, textarea, or non-search text/number/date entry field. It allows simple same-origin GET search fields, without logging visitor-submitted values. A regression fixture verifies search is permitted and lead capture is rejected.
- This check covers only the 16 fixed anonymous HTML page snapshots; it does not prove there is no JavaScript-injected form after interaction or any form on unvisited/authenticated pages. Browser and technical observations remain separate evidence.
- In the final ES/EN Privacy copy, prefer a plain statement that **this website does not ask visitors to submit data through contact or registration forms** and acknowledge server-connection records and voluntary email correspondence. Avoid the false categorical statement **"no se recogen ni conservan datos"**.
- Retention language must describe actual practice: GDPR Article 13 accepts a real, determinable retention *criterion* when an exact fixed period cannot be given, but neither "as long as needed" nor an invented number of days substitutes for reviewing the owner's actual practices and providers. See https://www.aepd.es/derechos-y-deberes/conoce-tus-derechos/derecho-de-informacion and https://knowledge.workspace.google.com/admin/gmail/advanced/manage-email-storage-with-auto-delete-settings.

## 3G. Exact, minimal owner/account evidence needed for Privacy release (2026-11-05)

The no-contact-form evidence narrows Privacy to **technical delivery logs** and **optional emails initiated outside the website**. It does **not** justify a universal claim that no visitor data is ever processed. This is a minimal accountable checklist, not a request for private logs, messages, passwords, account identifiers or tax documents.

**A. Hosting administrator — production site only**
1. In the actual account for `eduardoyauriluna.com`, check **hPanel → Websites → Manage site → Traffic → Access logs**. Hostinger [documents](https://www.hostinger.com/support/5650167-how-to-use-the-traffic-section-on-hostinger-dashboard-at-hostinger/) that HTTP requests and IPs can appear here.
2. Establish the real **log retention/rotation policy** and provider identity for this hosting plan using account support or contractual settings. **Do not equate the seven-day *filter/view range* with a seven-day storage period.** Confirm if site-specific application/PHP logs are enabled and how long they are kept, without copying entries.
3. Check for any analytics/tracking scripts beyond the bounded anonymous-browser observations. Absence of observed cookies on eight fixed routes is not global proof.

**B. Workspace administrator — only the mailbox receiving `hola@emmake.com`**
1. Identify the actual mailbox/alias and whether received academic emails are currently retained manually or under an organization-wide policy. Connected Gmail delivery was verified, but mailbox contents should not be copied into engineering records.
2. Inspect **Admin Console → Apps → Google Workspace → Gmail → Compliance → Email and chat auto-deletion**, plus Google Vault retention if used. [Google's instructions](https://knowledge.workspace.google.com/admin/gmail/advanced/manage-email-storage-with-auto-delete-settings?hl=es) explain configurable periods and exceptions. **Do not assume the 30-day Trash period is the regular inbox retention period.**
3. Record only the **policy outcome** (e.g. actual retention period or understandable determinable criteria, deletion/archival procedure and relevant exceptions), the processor/transfer posture and internal evidence reference, not personal emails or protected account details.

**C. Controller review and bilingual release**
1. Confirm that replies to voluntary academic contact are the only public mailbox purpose for this site; determine and document the legal basis and the period/criterion actually applied to such enquiries.
2. Confirm the technical-purpose basis for hosting security/delivery, categories of recipients/providers and international transfers if applicable. Do not name specific legal entities or fixed periods without evidence.
3. Compare complete **Spanish + English** text. It must plainly say **no contact/registration or lead-capture forms**, distinguish GET site search from voluntary external email, and disclose relevant technical access data, real retention criteria, rights and controller contact.
4. The [AEPD guidance](https://www.aepd.es/guias/informe-politicas-de-privacidad-adaptacion-rgpd.pdf) rejects indeterminate retention expressions such as *«mientras exista un interés mutuo»*; the visitor must be able to understand approximately how long data is kept. Keep `config/phase6-privacy-release-evidence.json` **fail-closed** until all substantive requirements have real evidence and the owner has approved both languages.
5. Then and only then, use the existing **A8 Preview → Apply → rendered Verify** on the two Privacy resources, followed by the independent Phase 6 visitor audit. The publication guard and checks are not a legal opinion.

## 4. Publication gate — must be fail-closed

1. Confirm the owner-approved public details required by the applicable law, without publishing private residential identifiers in GitHub issues or public repositories.
2. Verify actual host and email-provider contractual entity, legal terms and data-processing terms, and the CMS/server log and analytics/cookie configuration.
3. Assess and confirm purpose, data categories, lawful basis, recipients/transfers and retention for each real processing activity. Resolve every PENDING item above; do not publish markers or example terms.
4. Final human/legal editorial review of all four EN/ES texts. Ensure 2–6 substantive plain-text sections per page with titles of 1–120 characters and bodies of 45–4000 characters.
5. Legal Notice EN and ES were applied and rendered-verified as documented in §3C. **Only Privacy Policy ES and EN remain to publish** through the governed Gateway action legal.content.update: Preview → Apply → stored/rendered Verify, with rollback if verification fails.
6. Re-run independently the full Phase 6 production visitor audit. Open Phase 7 only when four legal routes and all visitor-surface checks are proven green.

References: issue https://github.com/Emmakex/eduardoyauriluna-research/issues/315 ; Phase 6 production audit workflow https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/41993094728 .
