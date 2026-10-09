# Research Manager — EN/ES record pairing

## Scope

This milestone links existing English and Spanish records as translation equivalents. It does **not** generate translated prose and does not infer that two records are equivalent from similar titles or content.

Supported record types:

- `research_output` — Publication / Research Output
- `research_project` — Research Project
- `research_software` — Research Software
- `research_dataset` — Research Dataset
- `post` — Research Insight

Research Lines are intentionally outside this first Manager pairing service even though the Theme can resolve localized Research Line routes. They can be added later with their own verified-line semantics.

## Theme contract

The Theme owns the public translation runtime through:

- `_research_language` (`en` or `es`);
- `_research_translation_en`;
- `_research_translation_es`;
- `eduardo_research_translation_post_id()`;
- `eduardo_research_record_translation_url()`.

A public counterpart is valid only when it:

- exists;
- is published;
- has the same WordPress post type;
- has the requested opposite language.

When a valid pair exists, the Theme emits reciprocal singular `hreflang` links, English `x-default`, and the Open Graph alternate locale signal.

## Pairing invariants

`Eduardo_Research_Manager_Translation_Pairing` requires:

1. two different existing records;
2. a supported and identical `post_type` on both sides;
3. both records published before pairing;
4. exactly one `en` and one `es` record;
5. neither side already paired with a different counterpart.

The stored relationship is bilateral:

- EN record: `_research_translation_es = <ES ID>`;
- ES record: `_research_translation_en = <EN ID>`.

A conflicting existing relationship is never overwritten. The service returns `research_manager_translation_collision` and makes no mutation.

## Risk policy

### Structured research objects

Publications, Projects, Software and Datasets are identity-bearing research records. Asserting that two such records are translations of the same research object is evidence-sensitive.

Their pair/unpair plans therefore include Manager-owned translation evidence metadata and become `evidence-required`. Apply requires:

- `evidence_confirmed=true`;
- a non-empty `evidence_reference`.

The evidence reference is stored in `_eduardo_research_translation_evidence` on both records as a Manager audit trail. The Theme does not use this value for rendering.

### Insights

Insights are editorial records. Pairing/unpairing two published EN/ES Insights is `editorial-review`, not an academic evidence claim. The plan includes an unchanged bounded editorial field as a risk anchor so the standard Manager risk model remains explicit without altering Insight content.

## Operations

The service is exposed as:

`Eduardo_Research_Manager::translations()`

Primary methods:

- `inspect($post_id)` — current language/counterpart state;
- `build_pair_plan($first_id, $second_id, $intent, $context)` — bilateral pair plan;
- `verify_pair($first_id, $second_id)` — validates both stored links and the Theme translation resolver;
- `build_unpair_plan($post_id, $intent, $context)` — clears the bilateral relationship when reciprocal;
- `verify_unpaired($post_id)` — confirms the local counterpart slot is empty.

All writes run through the existing checksummed `Preview → Apply → Verify → Rollback` executor. Rollback restores both translation slots and Manager audit metadata to their exact prior state.

## Non-goals

This milestone does not:

- translate titles, excerpts or body content;
- create a Spanish/English record automatically;
- infer equivalence from text similarity;
- silently replace a previous translation relationship;
- pair unpublished records;
- pair records with different WordPress resource types;
- change slugs or publication status.

## Rendered verification

CI verifies the relationship through the real public HTTP response after Apply. Both directions must expose:

- their own canonical URL;
- current-language `hreflang`;
- the counterpart-language `hreflang` pointing to the paired record;
- English `x-default`;
- valid Open Graph and JSON-LD signals already enforced by the rendered verifier.
