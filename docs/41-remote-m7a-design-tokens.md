# M7a — bounded Theme design tokens from ChatGPT

Status: implementation / CI acceptance

M7 begins by exposing the existing Research Theme visual language as a bounded contract instead of allowing ChatGPT or the Manager to write arbitrary CSS.

## Operating model

`Eduardo → ChatGPT → authenticated Manager design gateway → Theme design preset contract → generated CSS custom properties → Research Theme`

The Theme remains the frontend authority.

## Remote scopes

Read:
- `design.read`

Write:
- `design.write`
- `operations.apply` for Apply
- `operations.rollback` for Rollback

These scopes were already reserved by the M1 credential model.

## Endpoints

- `GET /research-manager/v1/design`
- `POST /research-manager/v1/design/plan`
- `GET /research-manager/v1/design/plans/{plan_id}`
- `POST /research-manager/v1/design/plans/{plan_id}/apply`
- `GET /research-manager/v1/design/operations/{operation_id}`
- `POST /research-manager/v1/design/operations/{operation_id}/verify`
- `POST /research-manager/v1/design/operations/{operation_id}/rollback`

## Controlled dimensions

M7a exposes exactly three dimensions.

### Palette

Preset keys:
- `researcher`
- `ocean-lab`
- `graphite-academic`

Each palette resolves to a fixed set of Theme CSS custom properties. Every exposed palette is checked against the Theme contrast contract for ink, body text, metadata grey, accent, inverse surface text and mint-on-dark usage.

No arbitrary hex entry is accepted remotely in M7a. That prevents a remote operation from silently breaking contrast/accessibility.

### Typography

Preset keys:
- `modern-sans`
- `academic-mixed`
- `humanist-sans`

Only local/system font stacks are emitted. Remote font URLs, `@font-face`, `@import` and arbitrary font-family strings are not supported.

### Density

Preset keys:
- `compact`
- `balanced`
- `airy`

Density controls only contracted layout variables:
- shell gutter;
- section vertical spacing;
- grid gap;
- shared card radius.

Responsive values remain clamp-based and Theme-owned.

## Theme contract

`inc/research-design.php` owns:
- contract version;
- current design state;
- preset definitions;
- safe token compilation;
- palette contrast checks;
- generated design CSS;
- public design contract discovery.

Stored state:

`eduardo_research_design`

Shape:

```json
{
  "palette": "researcher",
  "typography": "modern-sans",
  "density": "balanced"
}
```

Unknown dimensions or unknown preset values are rejected/fallback-safe at Theme read time and rejected by the Manager mutation gateway.

## Safety boundary

M7a does not permit:
- arbitrary CSS;
- arbitrary token names;
- arbitrary colors;
- external font URLs;
- arbitrary font stacks;
- JavaScript;
- Theme file editing;
- raw WordPress option editing;
- direct stylesheet replacement.

The Manager operates only exact preset keys through its dedicated design lifecycle.

## Lifecycle

`Inspect → Plan → explicit Apply → Verify → Rollback`

Preview is non-mutating and returns:
- before/after preset state;
- changed dimensions;
- resulting safe tokens;
- palette accessibility report.

Apply is blocked when:
- the plan belongs to another connection;
- confirmation is absent;
- the plan expired;
- Theme design state changed after Preview;
- the target palette no longer satisfies the accessibility contract.

Verify checks:
- exact stored state;
- revision fingerprint;
- palette accessibility;
- generated CSS contains no `url(`, `@import` or script injection surface.

Rollback refuses to overwrite a Theme design state that changed after Apply.

## M7a repository/CI definition of done

Fresh WordPress must prove:
1. Theme design contract is available;
2. every published palette passes the contrast contract;
3. `design.read` is enforced;
4. capability discovery exposes bounded design control;
5. arbitrary CSS dimension is rejected;
6. unknown preset values are rejected;
7. Preview does not mutate state;
8. Apply requires explicit confirmation;
9. palette + typography + density apply together exactly;
10. Verify confirms safe generated CSS and accessibility;
11. Theme-rendered token CSS contains the expected selected values;
12. stale Preview cannot overwrite a later design change;
13. Rollback restores the exact baseline.

## Remaining M7 work

M7a intentionally does not close M7. Next slices:
- M7b: bounded section/component variants;
- M7c: aggregate responsive/accessibility/rendered acceptance and final M7 readiness.

Real `eduardoyauriluna.com` design operation remains part of M8 live-site acceptance.
