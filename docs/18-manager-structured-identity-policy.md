# Research Manager — structured research identity policy

## Decision

The title of a structured research object is part of the identity of the claim represented by that object.

For these WordPress post types:

- `research_output`
- `research_project`
- `research_software`
- `research_dataset`

an update to `post_title` is classified as `evidence-required` by the Research Manager.

This is intentionally narrower than a global title rule. Theme-owned Pages and editorial Insights continue to classify title changes as `editorial-review`.

## Why

A publication title, project title, software title or dataset title is not merely presentation copy. It participates in identification, citation, discovery, canonical public presentation and machine-readable research metadata. Changing it can alter the identity presented to humans and crawlers even when DOI, dates and other metadata remain unchanged.

The Manager therefore treats a structured-object title change like other evidence-sensitive academic metadata.

## Workflow

A proposed title change still supports the normal control-plane sequence:

1. **Preview** — allowed without evidence so the operator can inspect the planned mutation.
2. **Apply** — blocked until `evidence_confirmed=true` and a non-empty `evidence_reference` are included in the checksummed plan.
3. **Verify** — confirms the stored title matches the plan.
4. **Rollback** — restores the prior title from the snapshot.

Changing evidence confirmation or the evidence reference changes the plan checksum and requires a new Preview.

## Non-goals

This policy does not make all prose evidence-sensitive. In particular:

- `post_excerpt` remains `editorial-review` unless the same plan also contains an evidence-sensitive mutation;
- `post_content` remains `editorial-review` for bounded narrative zones;
- Page and Insight title edits remain `editorial-review`;
- slug and generic publication-status mutation remain outside the existing generic update contract.

## Verification

CI must cover all four structured research post types and demonstrate that:

- title-only plans become `evidence-required`;
- Preview remains available before evidence confirmation;
- Apply is blocked without evidence;
- evidenced Apply succeeds;
- Rollback restores the previous title;
- normal Page/Insight titles and structured-object narrative edits are not accidentally over-gated.
