# Site Architect — Greenfield Client Website Lifecycle

## Status

Canonical product workflow for **Site Architect**, the Greenfield system for websites that we design and build from zero under deterministic WordPress contracts.

This document describes the reusable lifecycle that the Eduardo research site is helping us prove in production. It does **not** convert this repository into a multi-client repository: `Emmakex/eduardoyauriluna-research` remains exclusively the implementation and governance source for **eduardoyauriluna.com** and Eduardo Jose Yauri Luna's research/doctoral web presence.

Site Architect and Site Manager remain independent tracks:

```text
Site Architect = Greenfield
conocemos → construimos → optimizamos → gobernamos

Site Manager = Brownfield
 descubrimos → entendemos → adaptamos → gobernamos
```

## Product principle

Site Architect is not a generic WordPress editor and not an unrestricted automation proxy.

It is the **architecture compiler and governance control plane** for websites that we build from zero.

The desired result for every new Greenfield website is:

```text
client
+
vertical contract
+
instance blueprint
+
theme contract / preset
=
reproducible, optimized and governable website
```

The system should know the architecture because we deliberately created it. It should therefore prefer deterministic contracts over runtime discovery.

## Responsibility model

A complete Site Architect installation separates four responsibilities.

### 1. Site Architect engine

Owns reusable execution and governance mechanics:

- authentication and scopes;
- Plan / Preview;
- Apply;
- Verify/readback;
- snapshots and Rollback;
- idempotency;
- stale-state protection;
- bounded mutation records;
- audit;
- readiness reporting;
- capability reporting.

The engine must not contain a client's exact name, domain, biography, services, page copy or arbitrary customer-specific rules.

### 2. Greenfield Theme Contract

Owns deterministic frontend architecture:

- active Theme/preset id and version;
- pages and routes;
- languages and default language;
- structured page models and slots;
- navigation targets;
- design tokens and supported variants;
- component contracts;
- media roles;
- SEO/GEO surfaces;
- frontend authority;
- storage compatibility rules when needed.

The Theme Contract is allowed to know the architecture because Site Architect created that architecture.

### 3. Vertical Contract / Profile

Owns domain semantics for a class of websites.

Examples:

```text
Research
Corporate
Professional Services
Healthcare
Tourism
Ecommerce
Real Estate
Legal
Education
```

A vertical can define bounded resources and validation rules. Examples:

```text
Research:
research lines
outputs
projects
software
datasets

Healthcare:
services
treatments
doctors
locations
FAQs
articles
```

Vertical specialization is intentional Greenfield knowledge, not accidental hardcoding.

### 4. Instance Blueprint

Owns the concrete customer/site data:

- organization/person name;
- canonical domain;
- business purpose;
- languages selected for the instance;
- locations;
- contact/legal facts;
- actual pages/resources requested;
- customer content/default copy;
- verified identifiers/evidence;
- instance-specific configuration.

The reusable engine must not invent these values as final fallbacks.

## End-to-end lifecycle

```text
Client / business brief
        ↓
ChatGPT — primary operating surface
        ↓
Site Architect
        ↓
Instance Blueprint
        ↓
Vertical Contract + Theme Contract
        ↓
Clean WordPress provisioning
        ↓
Compile desired site state
        ↓
Plan
        ↓
Preview
        ↓
Apply
        ↓
Verify / readback
        ↓
Production website
        ↓
Continuous governance and optimization
```

## Phase 1 — Client intake

A new project begins from business requirements, not from WordPress implementation details.

Typical input:

```text
Company: Clínica Dental X
Domain: clinicadentalx.com
Sector: dental
Location: Barcelona
Languages: ES / CA
Goals:
- acquire patients
- implants
- orthodontics
- emergencies
- local SEO
```

At this point Site Architect should not create pages immediately. It first creates or validates an **Instance Blueprint**.

## Phase 2 — Instance Blueprint

Example target shape:

```json
{
  "site": {
    "name": "Clínica Dental X",
    "canonical_domain": "https://clinicadentalx.com",
    "purpose": "Patient acquisition"
  },
  "languages": ["es", "ca"],
  "business": {
    "sector": "dental",
    "location": "Barcelona"
  },
  "pages": [
    "home",
    "clinic",
    "implants",
    "orthodontics",
    "emergencies",
    "blog",
    "contact",
    "privacy",
    "legal"
  ]
}
```

The important architectural rule is:

```text
client data → Blueprint
not → reusable engine constants
```

The Eduardo reference implementation follows this same direction with its active Research blueprint.

## Phase 3 — Select the vertical contract

The Blueprint is paired with a vertical/profile.

Example:

```text
Clínica Dental X
        ↓
Healthcare Profile
        ↓
Dental preset/resources
```

The vertical contract defines what domain resources and validations exist. It should not grant unrestricted WordPress access.

A resource becoming known to a registry does not automatically authorize mutation. The security/mutation layer remains fail-closed.

## Phase 4 — Select and validate the Theme Contract

Site Architect selects a known compatible Theme/preset.

Example:

```text
Site Architect Theme
preset = healthcare
version = N
frontend_authority = theme
```

The Theme Contract can define a Home page such as:

```text
Home
├── Hero
├── Services
├── Featured treatments
├── Team
├── Reviews
├── FAQ
└── CTA
```

Site Architect therefore operates with semantic targets such as:

```text
page = home
slot = hero
```

It does not need Brownfield-style DOM/plugin discovery to guess where the Hero is.

## Phase 5 — Provision clean WordPress

Greenfield provisioning starts from a controlled WordPress baseline.

Target pipeline:

```text
Provision WordPress
↓
Install/activate compatible Theme
↓
Install/activate Site Architect
↓
Select preset/profile
↓
Configure permalink baseline
↓
Configure languages
↓
Create structural resources
↓
Assign roles/models
↓
Verify baseline
```

WP-CLI/SSH may be used for bootstrap/recovery/development, but the normal governance path should remain server-side and controlled.

## Phase 6 — Compile desired site state

The combination of:

```text
Blueprint
+
Vertical Contract
+
Theme Contract
```

is compiled into a desired state.

Examples:

```text
Home must exist
Services must exist
Implants must exist
Orthodontics must exist
Contact must exist
Home must be the static front page
Navigation must reference registered targets only
Languages must match the active contract
```

Compilation produces a bounded mutation plan. It does not write directly to WordPress.

## Phase 7 — Plan and Preview

Every meaningful mutation follows:

```text
Plan
→ Preview
→ Apply
→ Verify
```

Preview exposes the intended delta before mutation, for example:

```text
CREATE page: home
CREATE page: clinic
CREATE page: implants
CREATE page: orthodontics
CREATE page: contact
SET front_page: home
SET languages: es, ca
SET navigation: registered targets only
```

Preview should also expose risk, authorization requirements and the baseline revision used for stale-state protection.

## Phase 8 — Apply

Apply executes only operations accepted by the closed contract.

Representative operation families:

```text
create_page
update_page
create_domain_resource
update_navigation
assign_media
update_seo_geo
update_design
```

Site Architect must never become an unrestricted channel for:

- arbitrary PHP or JavaScript;
- arbitrary REST proxying;
- arbitrary SQL;
- arbitrary shell/filesystem access;
- arbitrary WordPress option/meta mutation;
- arbitrary CSS/classes;
- arbitrary navigation URLs;
- destructive deletion without an explicit bounded contract;
- bypassing evidence, stale-state, idempotency or verification requirements.

## Phase 9 — Verify/readback

A successful write is not sufficient.

After Apply, Site Architect reads WordPress state back and checks the relevant semantic contract.

Typical checks:

```text
resource exists
slug matches contract
role matches contract
model matches contract
publication state matches
route exists
stored content matches expected state
navigation target is correct
Theme recognizes the resource
runtime domain matches declared canonical domain when required
```

The operating rule is:

```text
APPLY
↓
READBACK
↓
VERIFY
```

If verification fails and the operation is reversible, Site Architect restores the previous snapshot.

## Phase 10 — Structured content creation

Once the structural website exists, ChatGPT can operate Site Architect at semantic level.

Example request:

```text
Optimize/create the implants page for Barcelona.
```

The Theme Contract can expose slots such as:

```text
hero-title
hero-copy
benefits
procedure
faq
cta
```

ChatGPT creates the proposed content. Site Architect validates slot ownership, language, type, length and allowed targets before generating a Preview.

Then:

```text
Preview
→ Apply
→ Verify
```

The caller does not need to edit raw WordPress HTML to operate the page.

## Phase 11 — SEO / GEO

SEO/GEO is part of the governed website model, not a disconnected manual afterthought.

For a registered page/resource, bounded operations may govern:

- title/meta description;
- canonical;
- indexability;
- structured data/schema;
- internal links;
- breadcrumbs;
- OpenGraph/social metadata;
- FAQ/entity data where supported;
- local/organizational/research entity information appropriate to the vertical.

External evidence or analytics may produce an optimization proposal, but WordPress mutation still follows Preview → Apply → Verify.

## Phase 12 — Design governance

Design changes are semantic and bounded.

Supported dimensions can include:

```text
palette
typography
density
hero variant
section variant
card variant
collection variant
```

The Theme Contract defines the available values.

Site Architect should not use arbitrary generated CSS as the normal governance mechanism.

A request such as:

```text
Make the site feel more premium.
```

should become a bounded Design Preview over supported variants, followed by Apply and Verify.

## Phase 13 — Media governance

Media follows the same deterministic lifecycle:

```text
ingest
→ checksum/provenance
→ metadata
→ assignment to registered media role
→ verify
```

Example semantic instruction:

```text
Assign this image to the Orthodontics hero.
```

Site Architect resolves:

```text
page = orthodontics
media_role = hero
```

rather than performing an unbounded search-and-replace over WordPress.

## Phase 14 — Navigation governance

Navigation must point to registered targets from the active site contract.

Example:

```text
Home
Clinic
Treatments
├── Implants
├── Orthodontics
└── Cosmetic dentistry
Blog
Contact
```

Arbitrary caller-provided URLs are not normal Greenfield navigation targets unless a contract explicitly supports and validates them.

Navigation mutations remain reversible and verified.

## Phase 15 — Launch readiness

Before production launch, Site Architect should expose one coherent readiness result.

Representative checks:

```text
Theme contract           PASS
Instance Blueprint       PASS
Pages                    PASS
Navigation               PASS
Languages                PASS
SEO/GEO                  PASS
Structured data          PASS
Media                    PASS
Design                   PASS
Legal/privacy            PASS
Declared canonical       PASS
Runtime domain           PASS
Indexability             PASS
Performance              PASS
```

The exact checks depend on the selected vertical and Theme Contract.

A launch is ready only when required checks are satisfactory. Repository state alone is not launch evidence.

## Phase 16 — Continuous operation after launch

Site Architect remains installed and becomes the website governance layer.

The normal interface can be ChatGPT/conversation.

Example:

```text
Eduardo/operator
→ ChatGPT
→ governed command/gateway
→ Site Architect
→ WordPress
→ Verify
→ sanitized result
→ ChatGPT
```

Examples of high-level operations:

```text
Create a new service page.
Publish an article.
Update the Home CTA.
Change the hero variant.
Assign a new image.
Improve SEO for a page.
Add a registered navigation item.
```

The caller should not have to manually execute all underlying WordPress steps.

## Phase 17 — Measurement and optimization loop

Site Architect can later consume findings from external measurement systems through controlled integrations, for example:

- Google Search Console;
- analytics;
- Semrush/SEO data;
- conversion signals;
- approved business data sources.

The loop is:

```text
Website
↓
Measurement / search performance
↓
Opportunity analysis
↓
ChatGPT
↓
Site Architect Preview
↓
Apply
↓
Verify
↓
Measure again
```

External measurement can suggest what to change; it does not bypass the Site Architect mutation contract.

## Normal operating architecture

```text
                         ChatGPT
                            │
                  strategy / instructions
                            │
                            ▼
                   ┌────────────────┐
                   │ SITE ARCHITECT │
                   └────────────────┘
                            │
          ┌─────────────────┼─────────────────┐
          ▼                 ▼                 ▼
 Greenfield Theme      Vertical/Profile   Instance Blueprint
 Contract              Contract
          │                 │                 │
          └─────────────────┼─────────────────┘
                            ▼
                         PLAN
                            │
                         Preview
                            │
                          Apply
                            │
                    Readback / Verify
                            │
                         Rollback
                      when required
                            │
                            ▼
                        WordPress
                            │
                            ▼
                         Website
```

## ChatGPT as operating surface

The intended user experience is not to expose every internal microstep.

ChatGPT is the high-level operating surface; Site Architect is the execution and governance engine; WordPress is the live runtime/content state; the Theme is the deterministic frontend authority.

The user can therefore express intent such as:

```text
Create the new Orthodontics page and optimize it for local SEO.
```

Internally Site Architect may need multiple governed operations, but the user should see a compact proposal/result rather than manually operating every low-level step.

Human approval remains available where risk, evidence, commercial policy or irreversible consequences require it.

## Current reference implementation vs target product lifecycle

The Eduardo Research implementation already proves important pieces of this lifecycle, including deterministic Theme contracts, Blueprint-driven instance data, structured resources, bounded navigation/design/media/content operations, Preview/Apply/Verify/Rollback patterns, readiness and remote governance.

Not every generic client-creation phase described here is fully productized yet. This document is therefore both:

1. the architectural contract for how Site Architect should behave as it becomes reusable; and
2. the benchmark against which extraction/generalization work should be evaluated.

A future reusable Site Architect package must be extracted only after the current Greenfield implementation has sufficient evidence. It must not weaken Research-specific behavior in this repository merely to appear generic.

## Repository boundary

This repository remains authoritative only for:

```text
Eduardo Jose Yauri Luna
eduardoyauriluna.com
Research Profile
Eduardo instance Blueprint
current Site Architect Greenfield reference implementation
```

Other customer Blueprints/sites must not be added here as production customers.

Reusable Site Architect patterns may later move to a dedicated product repository under an explicitly approved extraction plan.

## Definition of success

Site Architect succeeds when a new website can move through:

```text
brief
→ blueprint
→ profile/theme contract
→ provision
→ compile
→ Preview
→ Apply
→ Verify
→ launch
→ continuous optimization
```

while remaining:

- reproducible;
- fast;
- SEO/GEO ready;
- semantically governable;
- secure and fail-closed;
- reversible where appropriate;
- observable and auditable;
- operable primarily from ChatGPT without turning ChatGPT into an unrestricted WordPress proxy.
