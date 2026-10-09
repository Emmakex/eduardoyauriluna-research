# Research Manager

Independent WordPress control plane for the Eduardo Research Theme.

## Boundary

The Manager does **not** render the public frontend. The Research Theme remains the deterministic rendering, SEO/GEO, Schema and accessibility authority.

The Manager controls structured lifecycle operations:

- inspect the active Research preset contract;
- diagnose missing/misaligned resources;
- prepare structured mutation plans;
- preview stored-state changes;
- apply authorised mutations;
- verify persisted state;
- rollback reversible mutations from bounded snapshots.

## Foundation version

`0.1.0`

This version establishes the safe mutation and readiness kernel. It does not yet expose full resource-creation forms or external academic-provider writes.

## Diagnostics

`Eduardo_Research_Manager_Diagnostics` returns actionable checks rather than a score alone. Each failing/warning check maps to a next-action class such as:

- `create-resource`
- `hydrate`
- `auto-fix-candidate`
- `manual-review`
- `configuration-required`

The first contract covers:

- compatible Research Theme/preset;
- expected Theme-controlled pages;
- page role/model assignment;
- Research CPT registration;
- native language configuration;
- static Home routing;
- evidence-store shape.

## Mutation contract

Supported foundation actions are deliberately narrow:

- approved Research options;
- Theme/Research post metadata (`_eduardo_research_*`, `_research_*`);
- bounded post fields: title, excerpt, body content and menu order.

Publishing status, arbitrary WordPress options and arbitrary post fields are outside the foundation whitelist.

Every plan is checksummed. A target may appear only once in a plan.

## Evidence gate

Evidence-sensitive keys — including DOI, review status, output classification, academic identifiers, affiliations, awards, grants and the evidence store — are classified as `evidence-required`.

Preview remains possible so a proposed change can be inspected. Apply is blocked unless the plan was created with explicit `evidence_confirmed=true`; that confirmation is part of the checksummed plan payload.

This prevents a caller from previewing a harmless-looking plan and later changing its evidence status without invalidating the plan.

## Preview → Apply → Verify → Rollback

```text
intent + structured actions
          ↓
        Preview
          ↓
    evidence gate
          ↓
         Apply
          ↓
    stored-state Verify
          ↓
 rollback snapshot retained
```

Before Apply, the Manager records the previous supported state in a bounded, non-autoloaded WordPress option. If a write or verification fails, it attempts immediate restoration. An authorised user can also explicitly rollback a successful mutation once.

The snapshot store retains at most 25 recent mutations in this foundation.

## Admin surface

WordPress → Tools → Research Manager shows the contract/readiness report and mutation discipline. It intentionally does not expose raw write controls yet; mutation UX will be added resource-by-resource on top of the verified kernel.

## Next implementation blocks

1. Resource service for Theme-controlled Pages and structured slot hydration.
2. Insight creation/update service with bounded body content.
3. Publication and Research Project services using evidence-aware metadata schemas.
4. Rendered-frontend verification adapters.
5. Readiness remediation plans wired to Preview/Apply.
6. EN/ES resource pairing and translation operations.
7. External academic connectors only behind explicit authorization and additional gates.
