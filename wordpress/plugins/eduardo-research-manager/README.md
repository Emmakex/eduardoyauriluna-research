# Research Manager

Independent WordPress control plane for the Eduardo Research Theme. The Theme remains the public layout, route, SEO/GEO, Schema and accessibility authority.

## Current version

`0.9.0`

The Manager now covers:

- checksummed `Preview → Apply → Verify → Rollback`;
- Theme-owned Page hydration/reversible creation;
- EN/ES Insights;
- evidence-aware Publications, Projects, Software and Datasets;
- HTTP-level rendered frontend verification.

## Evidence discipline

Academic and research claims require explicit evidence confirmation plus a non-empty evidence/source reference before Apply. Stored-state verification and public-render verification are intentionally separate: the Manager can confirm what was persisted while the Theme still decides what is safe to expose.

## Rendered frontend verification

`Eduardo_Research_Manager_Rendered_Verifier` verifies the actual HTTP response produced by WordPress after a mutation.

For Theme-owned pages it checks:

- HTTP 200;
- canonical URL;
- correct EN/ES `<html lang>`;
- current-language `hreflang`;
- EN/ES alternates plus `x-default`;
- Open Graph URL;
- presence of JSON-LD;
- optional expected/forbidden rendered text.

For Research records it additionally checks the expected Schema type:

- Research Line / Project → `CreativeWork`;
- Dataset → `Dataset`;
- Software → `SoftwareSourceCode`;
- Publication → Theme-selected `CreativeWork`, `ScholarlyArticle`, `Report`, etc.

The verifier derives evidence-sensitive forbidden values from private storage. If a DOI, publication type or review status exists privately without its public verification flag, rendered verification requires that raw claim to remain absent from public HTML/Schema.

Rendered HTTP verification is exposed separately through `Eduardo_Research_Manager::rendered()`. It is not executed synchronously inside `Apply`, avoiding self-request deadlocks on single-worker PHP environments. The intended workflow is:

```text
Preview → Apply → stored Verify → rendered Verify → keep/rollback decision
```

## Resource services

- `pages()` — Theme-defined page models/slots and reversible page creation.
- `insights()` — bounded editorial notes.
- `outputs()` — publications/research outputs with academic metadata and evidence gates.
- `projects()` — structured research projects.
- `software()` — research software, release/repository/DOI metadata.
- `datasets()` — research datasets, access/provenance/methodology/DOI metadata.
- `rendered()` — HTTP-level public result verification.

Research objects share an internal executor registry for creation, stored-state verification and provenance-safe rollback, while each resource keeps its own validation contract.

## Mutation boundaries

Generic mutations remain narrow. Dedicated resource creation actions do not grant arbitrary WordPress publishing control. Snapshots are bounded, non-autoloaded and one-shot for rollback.

## Admin surface

WordPress → Tools → Research Manager exposes readiness/control-plane state. Resource UX is added only on top of verified services; raw arbitrary-write controls remain absent.

## Next implementation blocks

1. Readiness remediation plans wired to Preview/Apply.
2. EN/ES record pairing and translation operations.
3. External academic connectors behind explicit authorization and evidence gates.
