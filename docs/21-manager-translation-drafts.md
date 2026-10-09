# Research Manager — safe translation drafts

## Purpose

After EN/ES record pairing, the next translation-control capability is the safe creation of a missing opposite-language record.

This capability creates a **draft shell**. It is not an automatic translation engine.

The Manager must never infer or copy evidence-sensitive academic claims merely because an English/Spanish counterpart is being prepared.

## Supported source records

- Publication / Research Output (`research_output`)
- Research Project (`research_project`)
- Research Software (`research_software`)
- Research Dataset (`research_dataset`)
- Research Insight (`post`)

The source must:

- exist;
- be published;
- use the Research `en` or `es` language contract;
- not already store a counterpart in the opposite-language translation slot.

## Explicit target identity

The operator must supply:

- a target-language title;
- optionally a target-language slug (otherwise derived from the supplied title).

The source title is **not copied**. This prevents an English title from silently becoming the Spanish title or vice versa.

The created record always starts as:

- opposite language to the source;
- `draft` status;
- Manager-owned creation provenance token.

## Zero-copy rule for structured research objects

For Publications, Projects, Software and Datasets, the draft service does not copy:

- excerpt/body;
- DOI or DOI verification flags;
- publication/release dates;
- review/publication state;
- authors or affiliations;
- Research Line relations;
- project question, role, partner, funding or methods;
- software repository/archive/documentation metadata, license or programming languages;
- dataset repository, formats, access level, methodology, provenance, ethics notes or size;
- any other evidence-sensitive structured claim.

Those fields remain empty until a separate reviewed/evidence-confirmed hydration operation supplies them.

Structured research draft creation remains `evidence-required` because even a draft creates a new identity-bearing research record with an explicit translated title.

## Insight drafts

Insight creation is `editorial-review` rather than an academic evidence claim.

The draft may inherit only the bounded Insight classification (`_research_insight_type`). It does not copy excerpt or body prose. Translation text must be supplied deliberately in a later editorial operation.

## Pending provenance

The source stores a Manager-only pending token:

- English source awaiting Spanish draft: `_eduardo_research_translation_draft_es`
- Spanish source awaiting English draft: `_eduardo_research_translation_draft_en`

The value is the creation token of the Manager-owned target draft, not the target post ID.

This gives three useful properties:

1. creation and source provenance can live in one atomic Manager plan;
2. the target can be resolved after WordPress assigns its post ID;
3. Rollback can remove the created target and restore the source pending token exactly.

A source cannot create a second pending draft while its current token still resolves to an existing record.

If a token is stale because its target was removed outside the Manager, it does not block a new draft. The next valid creation plan atomically replaces that stale token; rolling that plan back restores the exact previous stale token. No separate metadata-delete privilege is required.

## Public translation boundary

A pending translation draft is **not** a public translation pair.

The service does not write `_research_translation_en` or `_research_translation_es`. While the target remains a draft:

- `eduardo_research_translation_post_id()` must not resolve it as a public counterpart;
- no singular counterpart `hreflang` is emitted from this draft workflow;
- no `x-default` relationship is changed.

After review, an explicit publication operation must make the target public. Only then can the already-implemented Translation Pairing service establish the bilateral Theme-native relation.

## Service API

`Eduardo_Research_Manager::translation_drafts()` exposes `Eduardo_Research_Manager_Translation_Draft`.

Primary operations:

- `inspect($source_id)` — source/pending-draft state;
- `build_creation_plan($source_id, $target_title, $target_slug, $intent, $context)` — atomic draft + source provenance plan;
- `verify($source_id, $expected_token)` — verifies target type/status/language/provenance and zero-copy constraints.

All writes use the existing checksummed `Preview → Apply → Verify → Rollback` executor.

## Non-goals

This milestone does not:

- translate any prose automatically;
- copy academic metadata into the target;
- publish the target;
- pair a draft publicly;
- infer a target title;
- overwrite an existing public counterpart;
- create multiple simultaneous pending counterparts for one source/language;
- add a generic metadata-delete action;
- bypass evidence requirements for structured research objects.

The following microphase can add an explicit reviewed publication transition for Manager-owned translation drafts; public pairing remains a separate final action after publication.
