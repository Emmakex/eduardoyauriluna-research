# M4 — Research Insight relations and internal linking

## Decision

Research Insights do not store arbitrary internal URLs. The Manager relates an Insight to one or more verified Research Lines in the same language and the Theme derives the internal-link network from those structured relations.

Remote control reuses the existing `insight-update` exact lifecycle with the bounded field:

- `line_ids`

No new generic mutation transport is introduced.

## Storage boundary

Academic Research Objects continue to use `_research_line_ids`, whose mutation risk remains evidence-sensitive.

Research Insights use a separate editorial/navigation metadata key:

- `_research_insight_line_relations`

This prevents M4 editorial linking from weakening the evidence guardrails that apply to Publications, Projects, Software and Datasets.

## Validation

Every Insight relation target must be:

1. a `research_line` WordPress resource;
2. published;
3. marked with `_research_evidence_status=verified`;
4. in the same Research language as the Insight.

An Insight with existing relations cannot change language to a language in which those relations are invalid. Relations must be changed first.

## Theme rendering

For a managed Insight with valid Research Line relations the Theme renders:

1. a Research context section linking to each verified Research Line;
2. a Research network section containing public Research Objects that share those verified lines.

Therefore internal links remain deterministic, language-safe and derived from Research structure rather than manually embedded URLs.

Ordinary WordPress posts never gain this behavior merely by containing relation metadata. The Theme requires an explicit valid `_research_insight_type` marker.

## Remote lifecycle

`insight-update` provides the existing exact lifecycle:

`Plan / Preview → explicit Apply → Verify → Rollback`

The Insight Editor resource baseline now includes `line_ids`, so an external relation edit after Preview invalidates Apply with the existing stale-Preview guard.

Rollback restores the previous metadata state. Because Theme internal links are derived, restoring relation state also restores/removes the rendered relation sections automatically.

## Diagnostics

Insight SEO/GEO inspection exposes:

- related Research Line IDs;
- relation count;
- same-language verified status;
- Theme-owned internal-link rendering capability.

## Acceptance

Fresh-WordPress CI proves:

- capability discovery;
- wrong-language line rejection;
- unverified line rejection;
- Preview non-mutation;
- exact remote Apply;
- stored relation verification;
- Theme-rendered Research Line internal link;
- Theme-derived related Research Object link;
- SEO/GEO relation diagnostics;
- stale relation Preview rejection;
- rollback to the relation-free baseline and removal of derived sections.

## M4 boundary after this slice

With this slice, repository/CI M4 covers remote Insight inventory, inspection, create/update, draft/publish, EN/ES pairing, structured Research relations/internal links and SEO/GEO inspection.

Before closing M4, review whether scheduling, taxonomy or authorship are required by the Research Theme product contract. Do not add generic WordPress features merely to imitate wp-admin. Then run a final aggregate M4 acceptance. Real `eduardoyauriluna.com` operation remains a later real-site milestone.
