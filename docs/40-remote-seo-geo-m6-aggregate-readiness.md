# M6 final — aggregate SEO/GEO readiness and residual actions

Status: implementation / CI acceptance

This slice closes the repository/CI portion of M6 by giving the ChatGPT-governed Manager one bounded read-only answer to the operational question:

> What is blocking SEO/GEO readiness now, what is only an optimisation, and which existing Manager gateway should be used next?

The aggregate report does not add a new mutation engine. It composes the already accepted M6a/M6b/M6c services and routes every correction back through existing bounded gateways.

## Endpoint

Authenticated read-only endpoint:

`GET /research-manager/v1/seo-geo/readiness`

Required scopes:

- `site.diagnostics`
- `seo.read`

Optional query:

`per_type_limit=1..5`

Default is one representative managed record per resource family.

## Inputs composed by the report

The report combines:

1. Manager site diagnostics and deterministic remediation classification;
2. global search indexability, robots policy and sitemap state;
3. the internal Research Line relationship graph;
4. representative SEO/GEO action-planner results for:
   - Home Page EN;
   - Home Page ES;
   - managed Insights when present;
   - Research Lines when present;
   - Outputs when present;
   - Projects when present;
   - Software when present;
   - Datasets when present.

Published representative resources retain the normal rendered verification contract. Draft/future/private resources follow the existing non-public inspection behaviour.

## Output contract

Top-level operating fields:

- `ready`
- `optimization_complete`
- `blocking_findings`
- `blocking_count`
- `advisory_count`
- `residual_actions`
- `site`
- `indexability`
- `internal_links`
- `representative`

`ready=true` means:

- Manager/site diagnostics have no blocking failure;
- indexability/robots/sitemap policy is ready;
- representative resources contain no failed SEO/GEO checks;
- representative inspection produced no errors.

Advisories do not make the site operationally blocked. They remain visible as optimisation work.

`optimization_complete=true` is deliberately stricter: the report must be ready and have no advisories or residual actions.

## Residual action classes

Every pending item is classified into one of four groups.

### `safe_remediation`

No editorial/evidence input is required, but Apply is still never automatic.

Examples:

- accidental global `blog_public=0` → dedicated M6 `search-indexability` lifecycle;
- an existing diagnostics finding already classified auto-remediable by the Manager.

### `structured_input`

A bounded existing gateway can perform the correction after ChatGPT/operator supplies the required structured value.

Examples:

- missing Page lead;
- missing Insight content field;
- missing translation counterpart where evidence is not required.

### `evidence_required`

The bounded correction changes an academic/research claim and requires evidence confirmation/reference before Apply.

Examples:

- Research Line relation on an Output;
- DOI verification;
- authorship/publication metadata;
- Research Line methods/topics/question changes.

### `manual_review`

The Manager can identify the finding but cannot safely auto-derive a deterministic mutation from current evidence/contracts.

The report therefore makes the residual boundary explicit instead of pretending all SEO/GEO findings can be fixed automatically.

## Mutation boundary

The aggregate report is strictly read-only.

It must never:

- change WordPress options;
- write arbitrary metadata;
- inject links into HTML;
- modify robots directives directly;
- create a second sitemap system;
- invent academic evidence;
- auto-apply any suggested operation.

Its operating rule is:

`inspect → classify → recommend existing bounded gateway → operator/ChatGPT supplies required input/evidence → existing Plan/Apply/Verify/Rollback → rerun readiness`

## Representative sampling

The report is intentionally bounded rather than crawling every public URL on every request.

Default sample:

- Home EN + Home ES;
- up to one most recently modified managed record per Research resource family.

The caller may request up to five records per family.

Full-site route/URL verification can remain a separate scheduled/deployment quality function. The Manager's interactive aggregate endpoint must remain safe for ordinary WordPress hosting.

## M6 repository/CI definition of done

M6 is complete at repository/CI level when fresh WordPress proves in one session:

1. the aggregate endpoint requires `seo.read`;
2. capability discovery exposes aggregate readiness as read-only;
3. a deliberate global indexability failure appears as a blocking safe remediation;
4. a published Research Output without a verified Research Line appears as an evidence-required residual action;
5. existing M6 indexability Plan/Apply corrects the global failure;
6. existing M5 Research Object Plan/Apply corrects the Research graph relation;
7. representative rendered checks pass under the existing rendered-verification contract;
8. the rerun aggregate report has zero blocking findings and `ready=true`;
9. the residual-action count decreases after the bounded corrections;
10. rollbacks restore both the relation and indexability baselines;
11. the historical Manager/Theme CI matrix remains green.

Real deployment and operation against `eduardoyauriluna.com` is still M8 and must not be claimed by this repository/CI milestone.
