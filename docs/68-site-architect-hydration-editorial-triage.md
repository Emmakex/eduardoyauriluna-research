# Site Architect — editorial and doctoral Hydration triage (#462)

**Status:** Review only; no Apply, no public mutation, no Greenfield bulk hydrate.

**Authority:** Eduardo research site `eduardoyauriluna.com` only. Site Architect / Greenfield, not Site Manager / Brownfield.

## Accepted evidence and limitations

- [Protected canonical Preview #458](https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/42092412193): 25 Hydration operations, 17 `change`, four phases, `public_site_apply=not_executed`, `launch_approval=not_granted`. Its stored Plan expired on 2026-11-05T19:32:26Z; **never Apply it**.
- [Protected, read-only Theme inventory #460](https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/42093553646): exact Blueprint SHA256 `11a9c908affae5a1636a42ebf440eb223c8fa2002faecbb45d71a8f1e00c0b6c`; 12 pages and 24 EN/ES variants all structurally Theme-aligned. Sixteen page-language combinations have stored slot deltas (28 individual slot-value differences), plus one identity operation **inferred**, not directly inspected.
- Controlled content sources: `content/research-statement/research-statement-v1.md` (working, planned research, no affiliation or completed studies) and `content/cv/academic-cv-source.md` (10+ years professional practice; no evidenced peer-reviewed publications, formal research projects, research software or datasets yet).
- Historical readbacks: `commands/gateway-v2/results/cmd-v2-resource-page-home-{en,es}-20261028-01.json`, `...about-{en,es}-20261028-01.json`, `...contact-{en,es}-20261028-01.json`, and `cmd-phase5-cv-{en,es}-inspect-20261101-{08,09}.json`. They establish historical public-theme slot values, **not guaranteed current values**; must be matched against the live Theme inventory before any focused Plan.
- The previous #460 audit did not export actual live copy. Editorial classifications below are **provisional** unless an exact archived-to-live comparison verifies no drift.

## 28 slot-level decisions

Codes: **KEEP LIVE** = retain historically observed copy; **ADOPT BLUEPRINT** = preferred instance Blueprint copy, only after fresh Plan; **NEW COPY** = targeted bounded copy edit after review; **DEFER** = no new Plan/Apply until evidence and UX conditions are met.

| Page | Slot | EN | ES | Rationale / gate |
|---|---|---|---|---|
| Home | `hero-eyebrow` | KEEP LIVE | KEEP LIVE | Historical “Researcher profile” / “Perfil investigador” is accurate, distinct, and does not claim completed research. Cosmetic Blueprint synonym is not a reason to rewrite. |
| Home | `research-lines-intro` | NEW COPY | NEW COPY | Need explicit **planned / developing agenda** rather than wording that implies finished investigations. Align precisely with four research directions in the research statement. |
| Home | `selected-outputs-intro` | KEEP LIVE | KEEP LIVE | Historical copy says outputs will appear when available and verified; Blueprint wording can misleadingly imply existing outputs. |
| About | `eyebrow` | KEEP LIVE | KEEP LIVE | Cosmetic naming difference; preserve current researcher-profile label. |
| About | `lead` | ADOPT BLUEPRINT | ADOPT BLUEPRINT | Blueprint describes an **applied research agenda**, not institutional position, and grounds it in 10+ years of documented professional experience. Check live text and source wording first. |
| About | `sections` | KEEP LIVE | KEEP LIVE | Historical sections “Research focus / Professional background” (EN) and corresponding ES do not expose unverified academic identifier links. Do not add “Academic profiles” until active and evidenced. |
| CV | `lead` | KEEP LIVE | KEEP LIVE | Historical lead accurately positions professional experience as relevant to a research agenda; Blueprint is a longer near-equivalent. |
| CV | `sections` | KEEP LIVE | KEEP LIVE | Existing “Identity and research profile” is more precise than the proposed replacement. Actual degree/affiliation/output entries require independent evidence; section headings are not proof. |
| Contact | `sections` | KEEP LIVE | KEEP LIVE | Historical “Research enquiries” / “Consultas de investigación” gives clearer purpose; “Academic profiles” must stay hidden/unlinked if identifiers are pending. |
| Publications | `eyebrow` | DEFER | DEFER | Neither source text nor historic record establishes verified publications. Cosmetic change cannot justify surfacing empty collection. |
| Publications | `lead` | DEFER | DEFER | Future-facing placeholders are not research outputs; preserve empty collection suppression. |
| Projects | `lead` | DEFER | DEFER | Professional technical projects must not be relabeled as formal research projects without evidence. |
| Software | `lead` | DEFER | DEFER | Production code products are not automatically citable research software. |
| Datasets | `lead` | DEFER | DEFER | No verified published datasets. |

**Decision tally:** 14 KEEP LIVE + 2 ADOPT BLUEPRINT + 2 NEW COPY + 10 DEFER = **28 slot decisions** (14 distinct slot targets × 2 languages). Four already aligned page families (Research, Insights, Privacy Policy, Legal Notice) remain untouched.

## Draft bilingual copy (not published)

**Home / research-lines-intro EN**

> The developing research agenda explores four complementary directions: governable autonomous AI agents for business processes; human–AI collaboration and responsible autonomy; AI adoption and digital transformation in SMEs; and information discovery mediated by generative AI.

**Home / research-lines-intro ES**

> La agenda de investigación en desarrollo explora cuatro líneas complementarias: agentes autónomos de IA gobernables para procesos empresariales; colaboración humano–IA y autonomía responsable; adopción de IA y transformación digital en pymes; y descubrimiento de información mediado por IA generativa.

**About / lead EN (preferred Blueprint)**

> Eduardo Jose Yauri Luna develops an applied research agenda around AI-enabled business systems, drawing on more than ten years of professional experience in digital strategy, web technologies, automation and software-based business systems.

**About / lead ES (preferred Blueprint)**

> Eduardo Jose Yauri Luna desarrolla una agenda de investigación aplicada sobre sistemas empresariales habilitados por IA, apoyada en más de diez años de experiencia profesional en estrategia digital, tecnologías web, automatización y sistemas empresariales basados en software.

None of these drafts claims doctoral enrollment, university affiliation, a peer-reviewed paper, an awarded grant or completed study.

## Before any new Plan/Preview

1. **Live baseline:** use read-only `site_pages_list` and compare only historical Home, About, CV, Contact stored Theme slots. If any expected value has drifted, **re-open editorial assessment**, do not force historical copy.
2. **Identity:** the 17th change is an arithmetic inference from the Preview, not an independently verified identity-profile diff. Obtain bounded read-only direct canonical Identity inspection before considering any identity write. `site_evidence_identity` is evidence identity, not automatically the canonical Identity Profile store.
3. **Evidence and UX:** avoid displaying empty collections or nonworking academic identifiers; respect live navigation, SEO/GEO authority, EN/ES parity and Theme-owned frontend. Keep claims in the working/research-interest tense.
4. **Execution:** choose **one focused slot change per language** for a new bounded Plan/Preview after verifying current state. Treat every preview as non-authorizing; **Apply remains prohibited absent a separate explicit approval**. No bulk Greenfield Apply, rollback or migration operations in this review.

## Result boundary

The recommendations are editorial **triage**, not live content certification or publication approval. Site Architect code/Theme/preset and live WordPress remain unchanged by this report.
