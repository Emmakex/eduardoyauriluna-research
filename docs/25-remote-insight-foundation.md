# Remote Manager M4 — Insight editorial foundation

## Status

This document defines the first repository-level slice of M4 from the canonical ChatGPT-governed Manager roadmap.

The objective is to make Research Insights a first-class managed surface before exposing remote mutation types through the exact M2 operation lifecycle.

## Implemented in this slice

### Shared editorial service

`Eduardo_Research_Manager_Insight_Resource` now treats publication state as part of the bounded Insight contract.

Supported creation/update states are intentionally limited to:

- `draft`
- `publish`

The service rejects unsupported states instead of forwarding arbitrary WordPress post statuses.

This matters because publication must remain a shared Manager capability. The Remote Manager must not invent a second publication implementation that differs from the local Admin/editor path.

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
- bounded `draft` / `publish` states;
- rendered SEO/GEO inspection;
- translation inspection;
- `remote_mutations = next-m4-slice`.

## Acceptance

A dedicated fresh-WordPress CI acceptance proves:

1. canonical Research Greenfield baseline installs successfully;
2. Insight creation Preview does not bypass the shared service;
3. a draft Insight can be created and verified;
4. the authenticated remote inventory exposes it;
5. authenticated individual inspection returns the same managed state;
6. draft SEO/GEO inspection does not pretend the post is publicly rendered;
7. the shared editor can Preview and Apply `draft -> publish`;
8. a published Insight passes rendered `Article` verification;
9. publication rollback restores the exact draft state;
10. creation rollback removes the Manager-owned Insight by snapshot/provenance.

## Explicit non-goals of this slice

This slice does not yet expose `insight-create` or `insight-update` as remote M2 operation types. Those mutations belong to the next M4 slice and must use the existing exact lifecycle:

`Plan -> Apply -> Verify -> Rollback`

It also does not yet implement:

- remote translation pair/unpair mutations;
- relationships/internal-link authoring;
- scheduling beyond bounded draft/publish;
- advanced metadata/canonical/schema remediation, which remains M6;
- real `eduardoyauriluna.com` mutation, which remains M8 acceptance.

## Product rule

Insight work must converge on one shared Manager service. REST is an adapter, not a second editorial engine.
