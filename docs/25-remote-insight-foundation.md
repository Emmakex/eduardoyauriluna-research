# Remote Manager M4 — Insight editorial foundation

## Status

This document defines the first repository-level slice of M4 from the canonical ChatGPT-governed Manager roadmap.

The objective is to make Research Insights a first-class remotely inspectable managed surface before exposing Insight mutation types through the exact M2 operation lifecycle.

## Implemented in this slice

### Existing shared editorial service remains authoritative

This slice deliberately keeps the existing `Eduardo_Research_Manager_Insight_Resource` and `Insight_Editor` mutation contract intact.

The Manager already supports bounded Insight creation through the shared service with `draft` or `publish` as creation states. Existing update mutations remain limited to the currently approved editorial fields:

- title;
- excerpt;
- content;
- language;
- Insight type.

Publication-state transitions for an existing Insight are **not** opened in this foundation PR, because the central `Eduardo_Research_Manager_Plan` contract currently reserves `post_status` mutation for Theme-owned Page structural remediation.

That guardrail is intentional. The next M4 slice must extend the central mutation contract explicitly for Research Insights instead of bypassing it in REST.

### Authenticated remote read surface

The Manager exposes bounded authenticated Insight reads under the existing versioned namespace:

- `GET /research-manager/v1/insights`
- `GET /research-manager/v1/insights/{post_id}`
- `GET /research-manager/v1/insights/{post_id}/seo-geo`

The inventory accepts the optional `language=en|es` filter and returns only posts managed as Research Insights.

The individual inspection endpoint delegates to the existing Insight Editor/Resource service.

The SEO/GEO inspection returns:

- stored Insight state;
- bounded stored-readiness checks;
- current translation relationship inspection;
- rendered frontend verification for published Insights;
- an explicit boundary that advanced SEO/GEO optimisation remains M6.

Draft Insights are never treated as publicly rendered resources.

### Capability discovery

`/capabilities` advertises the M4 Insight surface without falsely claiming remote mutations that have not yet been connected to M2.

It exposes:

- inventory support;
- inspection support;
- EN/ES language support;
- allowed editorial types;
- valid Insight creation states (`draft`, `publish`);
- rendered SEO/GEO inspection;
- translation inspection;
- `remote_mutations = next-m4-slice`.

## Acceptance

A dedicated fresh-WordPress CI acceptance proves:

1. canonical Research Greenfield baseline installs successfully;
2. a draft Insight can be created through the shared Manager editor and verified;
3. a published Insight can be created through the shared Manager editor and verified;
4. the authenticated remote inventory exposes both managed records;
5. authenticated individual inspection returns the same managed state;
6. draft SEO/GEO inspection does not pretend the draft is publicly rendered;
7. a published Insight passes rendered `Article` verification;
8. creation rollback removes both Manager-owned Insights by snapshot/provenance.

## Explicit non-goals of this slice

This slice does not yet expose `insight-create` or `insight-update` as remote M2 operation types. Those mutations belong to the next M4 slice and must use the existing exact lifecycle:

`Plan -> Apply -> Verify -> Rollback`

The next mutation slice must explicitly extend the central Plan contract for any approved publication-state transition; it must not route around that contract.

This slice also does not yet implement:

- remote translation pair/unpair mutations;
- relationships/internal-link authoring;
- scheduling;
- advanced metadata/canonical/schema remediation, which remains M6;
- real `eduardoyauriluna.com` mutation, which remains M8 acceptance.

## Product rule

Insight work must converge on one shared Manager service. REST is an adapter, not a second editorial engine. Existing mutation guardrails stay authoritative until deliberately extended with tests.
