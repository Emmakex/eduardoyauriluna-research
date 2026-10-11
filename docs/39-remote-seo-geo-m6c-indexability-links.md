# M6c — Remote SEO/GEO indexability, robots, sitemap and internal-link control

Status: implementation/CI slice for the ChatGPT-governed Research Manager. Live-site proof remains M8.

## Objective

M6c closes two SEO/GEO operating gaps that remained after M6a/M6b:

1. search-engine indexability and crawl policy must be observable and safely recoverable through the Manager;
2. internal Research graph gaps must be detectable and routed to the existing structured relation gateways rather than repaired by injecting arbitrary HTML links.

The control path remains:

`ChatGPT → authenticated Manager → diagnostics/plan → existing bounded service → WordPress/Theme → verification`

## Indexability contract

### Global search visibility

The Manager exposes WordPress `blog_public` as a readiness concern through the diagnostic check:

`search-indexability`

Expected production state:

`blog_public = 1`

If WordPress is configured to discourage search engines, the finding is a failure and is classified as safely auto-remediable. The shared Remediation service produces an exact option plan that changes only:

`blog_public → 1`

The remote M6 lifecycle therefore applies unchanged:

`Plan → confirmation → stale-state protection → Apply → Verify → Rollback`

The Manager does not expose a generic option editor.

### Theme robots policy

The Research Theme remains the authority for request-level robots behavior.

Canonical public documents are expected to remain indexable. Filter/search/exploration states are deliberately not independent documents and the Theme already applies:

`noindex,follow`

through `eduardo_research_exploration_robots()`.

M6c exposes that policy and verifies rendered public resources, but does not introduce arbitrary per-URL robots overrides.

### Rendered resource inspection

`GET /research-manager/v1/seo-geo/indexability/resource`

supports the same bounded resource types as M6:

- Page
- Insight
- Research Line
- Output
- Project
- Software
- Dataset

For public resources the Manager checks:

- HTTP response status;
- global WordPress search visibility;
- rendered `<meta name="robots">`;
- rendered `X-Robots-Tag`;
- whether `noindex` is present;
- final indexable/not-indexable conclusion.

The existing M6 resource inspector still verifies canonical, hreflang, language, Open Graph URL, JSON-LD and expected schema type. M6c complements that contract rather than replacing it.

Draft/non-public resources are not treated as public indexable documents.

## Sitemap contract

`GET /research-manager/v1/seo-geo/indexability`

reports:

- global search-engine visibility;
- WordPress core sitemap enabled state;
- presence of the Research Theme localized Page sitemap provider;
- presence of the Theme exploration robots policy.

The Theme retains sitemap ownership:

- native WordPress sitemaps remain the base;
- the Research localized provider contributes Spanish Theme-owned Page aliases;
- Research Lines remain sitemap-visible only when evidence-verified.

M6c does not implement a second sitemap system.

## Internal-link / Research graph diagnostics

`GET /research-manager/v1/seo-geo/internal-links`

inspects the public managed Research graph for:

- Insights;
- Publications / Outputs;
- Projects;
- Software;
- Datasets;
- evidence-verified public Research Lines.

The authoritative relationship remains the structured Research Line relation already used by the Theme.

A public record with at least one verified same-language Research Line is considered connected. That relation drives both:

- visible internal links rendered by the Theme;
- JSON-LD/entity graph relationships already verified in M5.

A record without a verified Line relation is returned as `advisory` with a bounded remediation descriptor.

### Insight remediation

Gateway:

`insights`

Operation:

`insight-update`

Structured field:

`line_ids`

### Research Object remediation

Gateway:

`research-objects`

Operation:

`object-update`

Structured field:

`line_ids`

Research Object relation changes remain evidence-gated and require an evidence reference.

## Explicit non-goals / safety boundaries

M6c does **not** expose:

- arbitrary meta robots editing;
- arbitrary `X-Robots-Tag` injection;
- arbitrary WordPress option editing;
- arbitrary sitemap URL insertion;
- arbitrary HTML internal-link insertion;
- direct content rewriting as a link-building mechanism;
- unrestricted SEO plugin field mutation.

Internal link remediation is always delegated to the existing structured resource gateways. The Theme derives the actual visible links and schema relationships.

## Remote endpoints added

Read-only, authenticated with `site.diagnostics + seo.read`:

- `GET /research-manager/v1/seo-geo/indexability`
- `GET /research-manager/v1/seo-geo/indexability/resource`
- `GET /research-manager/v1/seo-geo/internal-links`

Indexability remediation uses the existing M6 endpoints:

- `POST /research-manager/v1/seo-geo/remediation/plan`
- `POST /research-manager/v1/seo-geo/plans/{plan_id}/apply`
- `POST /research-manager/v1/seo-geo/operations/{operation_id}/verify`
- `POST /research-manager/v1/seo-geo/operations/{operation_id}/rollback`

## Fresh-WordPress acceptance

The M6c quality gate must prove in one clean WordPress installation:

### Indexability lifecycle

1. canonical Greenfield seed succeeds;
2. `seo.read` scope is independently enforced;
3. `blog_public` is deliberately changed to `0`;
4. Manager reports indexability/readiness failure;
5. `search-indexability` appears as an auto-remediable finding;
6. Preview does not mutate WordPress;
7. Apply changes only the bounded global indexability state;
8. stored verification passes;
9. sitemap/indexability readiness recovers;
10. rendered `index,follow` Page is considered indexable;
11. rendered `noindex,follow` Page is detected as not indexable;
12. rollback restores the original blocked baseline;
13. the test restores the canonical WordPress state before exit.

### Internal Research graph lifecycle

1. create a public evidence-confirmed Research Output with no Line relation through the M5 Object gateway;
2. M6c reports that record as unlinked/advisory;
3. M6c recommends `research-objects → object-update → line_ids` and requires evidence;
4. apply that relation through the existing M5 gateway;
5. re-run M6c and require the record to pass;
6. verify the reverse Research Line graph sees the related object;
7. rollback the relation and require the advisory state to return;
8. rollback the fixture creation and leave no residue.

## Completion boundary

M6c is complete at repository/CI level only when its fresh-WordPress acceptance passes and the historical Manager/Theme matrix remains green.

This does not prove production behavior on `eduardoyauriluna.com`. Live robots headers, sitemap accessibility, real internal Research relationships and a real SEO/GEO mutation initiated from ChatGPT are M8 evidence.

## Remaining M6 work

After M6c, the remaining repository/CI work should be kept narrow:

- aggregate SEO/GEO/GEO-readiness report across representative resources;
- residual/manual/evidence action reporting;
- final M6 aggregate acceptance proving diagnostics → source action/remediation → rendered verification → residual report in one session.

No generic SEO metadata editor is required to close M6.
