# M7c — final aggregate Theme design readiness

Status: implementation / CI acceptance

M7c closes repository/CI design control by composing the already bounded M7a design-token lifecycle and M7b component-variant lifecycle into one read-only readiness report. It does not create a third mutation engine.

## Operating model

`Eduardo → ChatGPT → authenticated Manager /design/readiness → M7a + M7b contracts → rendered Theme verification → residual actions`

All corrections continue through the existing exact gateways:

- `/design/plan` for palette / typography / density;
- `/design/variants/plan` for hero / sections / cards / collections;
- explicit Apply;
- Verify;
- Rollback.

The aggregate readiness endpoint never mutates state and never auto-applies a recommendation.

## Endpoint

`GET /research-manager/v1/design/readiness`

Scope: `design.read`.

Query:

- `rendered=1` (default): includes representative rendered Home verification in EN and ES;
- `rendered=0`: static Theme-contract inspection only.

## Aggregate checks

### M7a

- exact active palette / typography / density state;
- active palette WCAG contrast contract;
- bounded token CSS exists;
- no `url(`, `@import` or script surface;
- CSS digest returned for audit comparison.

### M7b

- exact active hero / sections / cards / collections state;
- exactly four Theme-owned bounded body classes;
- body-class naming contract;
- semantic markup unchanged;
- content order unchanged;
- no arbitrary HTML/classes/CSS;
- no section hiding;
- variant CSS safety digest.

### Responsive / accessibility

The report verifies the fixed Theme contract includes:

- fluid spacing through Theme-owned `clamp()` values;
- mobile variant guard;
- hover lift disabled on narrow layouts;
- `prefers-reduced-motion: reduce` handling;
- M7b reduced-motion safety declaration.

These are bounded code-level guarantees, not remotely supplied breakpoints or arbitrary CSS.

### Rendered representative surfaces

With `rendered=1`, the existing HTTP rendered verifier checks Research Home in EN and ES. M7c additionally requires both pages to expose:

- `eduardo-research-design-tokens` Theme style;
- `eduardo-research-component-variants` Theme style;
- every exact active Theme-owned variant body class.

Fresh-WordPress acceptance applies representative non-default M7a and M7b states, reruns the rendered report, and proves the heading/landmark/article/navigation/link sequence remains identical to the baseline. It then rolls both operations back and requires the exact original states and clean aggregate readiness.

## Residual actions

Findings are split into:

- `bounded_change`: can be corrected through an existing M7 design gateway, still requiring explicit Preview/Apply confirmation;
- `manual_review`: indicates a Theme/Manager contract, CSS, responsive or rendered deployment defect that must not be bypassed with arbitrary code.

Readiness never returns arbitrary CSS, HTML, classes or direct Theme-file mutation as an action.

## M7 repository/CI definition of done

Fresh WordPress must prove:

1. `design.read` protects aggregate readiness;
2. capabilities expose read-only M7c readiness;
3. static M7a/M7b contracts are clean;
4. active palette is accessible;
5. token and variant CSS are safe;
6. body classes are exact and bounded;
7. semantic/content-order safety contract is intact;
8. responsive and reduced-motion guards are present;
9. Home EN/ES render successfully with the exact current design state;
10. a representative non-default M7a state applies and verifies;
11. a representative non-default M7b state applies and verifies;
12. rendered semantic/heading/link order is unchanged by presentation state;
13. aggregate readiness stays green after those bounded changes;
14. reverse rollback restores exact M7a and M7b baselines;
15. final rendered aggregate readiness is green with zero blocking/residual repository actions;
16. historical Theme/Manager matrix remains green.

When this acceptance is green and merged, M7 is complete at repository/CI level.

## Boundary to M8

M7c does **not** claim that `eduardoyauriluna.com` has been operated live. M8 remains responsible for installing the supported Theme + Manager on the real site and proving ChatGPT-governed Page, Insight, SEO/GEO and bounded design operations, rendered verification, rollback, audit, read-only mode and revocation on the production target.
