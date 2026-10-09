# Research object relationship contract

## Purpose

The Research Theme uses `research_line` as the single public source of truth for Research Lines. Publications, projects, software and datasets connect to one or more verified Research Lines through a stable metadata contract.

This keeps the public research graph explicit while preserving the project rule that unsupported claims must never be rendered.

## Relationship field

Supported object types:

- `research_output`
- `research_project`
- `research_software`
- `research_dataset`

Each object may store:

- `_research_line_ids`: ordered/unique array of WordPress post IDs pointing to `research_line` records.

The Theme exposes a relation publicly only when the target Research Line:

1. exists;
2. is a published `research_line` post;
3. has `_research_evidence_status=verified`;
4. belongs to the same language as the source object.

Invalid, unpublished, unverified or cross-language targets are ignored at render time.

## Bidirectional graph

Only one direction is stored: research object -> Research Line.

The reverse direction is derived by query:

- Research Line -> related Publications
- Research Line -> related Projects
- Research Line -> related Software
- Research Line -> related Datasets

Objects that share a verified Research Line can also be surfaced as related research objects. No duplicate reverse relation data is stored.

## Public rendering

Object single pages expose a `Research context` / `Contexto de investigación` section linking to verified Research Lines.

Research Line single pages expose a `Research network` / `Red de investigación` section populated from related research objects.

Related-object cards reuse the Theme-owned collection-card contract so Publications, Projects, Software and Datasets keep consistent semantics and styling.

## SEO / GEO

For structured data:

- research objects reference verified Research Lines through Schema `about` relationships;
- Research Lines may reference linked research objects through `subjectOf`;
- `Person.knowsAbout` is sourced from verified first-class Research Lines only;
- `/research.json` and `/es/research.json` expose the same verified graph.

The Theme never generates relation semantics from an unverified Research Line.

## Legacy migration

The former `eduardo_research_evidence.research_lines` array is deprecated.

On upgrade, the Theme performs a one-time migration:

1. verified legacy Research Lines become first-class `research_line` posts;
2. explicit Spanish translations become paired Spanish `research_line` posts;
3. the original array is copied to `eduardo_research_legacy_research_lines_v1`;
4. `research_lines` is removed from the active evidence store;
5. unrelated evidence groups remain untouched.

Unverified legacy Research Lines are preserved in the backup but are not converted into public Research Line objects.

## Future Research Manager contract

The Manager should write relationships by updating `_research_line_ids` on the source research object. It must not store a second reverse mapping.

Expected workflow:

1. Preview proposed relation changes.
2. Verify language and target evidence status.
3. Apply `_research_line_ids`.
4. Verify frontend, Schema and discovery output.
5. Roll back to the previous ID array if verification fails.

This keeps the Theme autonomous while giving the Manager a small, deterministic mutation surface.
