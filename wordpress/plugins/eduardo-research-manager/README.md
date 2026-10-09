# Research Manager

Independent WordPress control plane for the Eduardo Research Theme. The Theme remains the public layout, route, SEO/GEO, Schema and accessibility authority.

## Current version

`0.10.0`

The Manager now covers:

- checksummed `Preview → Apply → Verify → Rollback`;
- Theme-owned Page hydration/reversible creation;
- EN/ES Insights;
- evidence-aware Publications, Projects, Software and Datasets;
- HTTP-level rendered frontend verification;
- contract-driven readiness remediation plans.

## Evidence discipline

Academic and research claims require explicit evidence confirmation plus a non-empty evidence/source reference before Apply. Stored-state verification and public-render verification remain separate: the Manager verifies persistence while the Theme decides public exposure.

## Readiness remediation

`Eduardo_Research_Manager_Remediation` turns safe diagnostic failures into the same checksummed mutation plans used by the rest of the control plane.

Readiness remains compatible with the diagnostics contract: `ready=true` means **zero failing checks**. Warnings stay visible in the catalog and are classified as deterministic auto-fix candidates, manual review, or configuration-required conditions instead of silently blocking the whole control plane.

It supports:

- recreating a missing Theme-owned Page from the active Research preset;
- repairing `_eduardo_research_role` / `_eduardo_research_model` drift on an existing published Page;
- restoring the native EN/ES language configuration from the preset;
- preparing one plan for a specific readiness check;
- preparing a deduplicated safe batch of deterministic repairs;
- rerunning diagnostics after Apply to verify the repaired checks;
- full rollback through the existing snapshot/executor path.

The remediation service deliberately refuses unsafe or ambiguous automation:

- an existing Page in draft/private state is **manual review**; it is never silently published;
- a malformed evidence store is **manual review**;
- missing post types or an incompatible Theme are **configuration required**;
- WordPress core front-page routing is reported but remains outside the generic option-write contract in 0.10.0.

This keeps auto-remediation limited to values that can be reconstructed exactly from the active Research Theme contract.

## Rendered frontend verification

`Eduardo_Research_Manager_Rendered_Verifier` verifies actual HTTP responses after mutations: HTTP status, canonical, EN/ES language, hreflang/x-default, Open Graph URL, JSON-LD, expected Schema type and evidence-sensitive non-leakage.

Intended workflow:

```text
Diagnostics → Remediation Preview → Apply → stored Verify → rendered Verify → keep/rollback
```

## Resource services

- `pages()` — Theme-defined page models/slots and reversible page creation.
- `insights()` — bounded editorial notes.
- `outputs()` — publications/research outputs with academic metadata and evidence gates.
- `projects()` — structured research projects.
- `software()` — research software, release/repository/DOI metadata.
- `datasets()` — research datasets, access/provenance/methodology/DOI metadata.
- `rendered()` — HTTP-level public result verification.
- `remediation()` — diagnostic catalog, single-check plans, safe batches and post-Apply verification.

Research objects share an internal executor registry for creation, stored-state verification and provenance-safe rollback, while each resource keeps its own validation contract.

## Mutation boundaries

Generic mutations remain narrow. Dedicated resource actions do not grant arbitrary WordPress publishing control. Snapshots are bounded, non-autoloaded and one-shot for rollback.

## Admin surface

WordPress → Tools → Research Manager exposes readiness/control-plane state. Resource UX is added only on top of verified services; raw arbitrary-write controls remain absent.

## Next implementation blocks

1. EN/ES record pairing and translation operations.
2. Admin workflow UX over the verified resource services.
3. Manager 1.0 release hardening.
4. External academic connectors behind explicit authorization and evidence gates.
