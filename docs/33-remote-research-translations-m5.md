# Remote Manager M5 — structured Research translations

## Purpose

M5c exposes EN/ES translation pairing for the structured Research resource families already governed by the Manager:

- Research Lines;
- Publications / Research Outputs;
- Projects;
- Research Software;
- Datasets.

Insights are intentionally excluded from this surface because they already have the dedicated M4 Insight pairing gateway.

The operating chain remains:

`ChatGPT → authenticated Research Manager → shared Translation Editor → structured Research resources → Theme → Verify`

## Remote surface

Read:
- `GET /research-manager/v1/research-translations?post_type={type}&language={en|es}`
- `GET /research-manager/v1/research-translations/{post_id}`

Exact lifecycle:
- `POST /research-manager/v1/research-translations/plan`
- `GET /research-manager/v1/research-translations/plans/{plan_id}`
- `POST /research-manager/v1/research-translations/plans/{plan_id}/apply`
- `GET /research-manager/v1/research-translations/operations/{operation_id}`
- `POST /research-manager/v1/research-translations/operations/{operation_id}/verify`
- `POST /research-manager/v1/research-translations/operations/{operation_id}/rollback`

Supported operations:
- `translation-pair`
- `translation-unpair`

## Scope model

Read requires `translations.read`.

Pair/unpair planning and Apply require both:
- `operations.apply`
- `translations.write`

Verification requires:
- `site.diagnostics`
- `translations.read`

Rollback requires:
- `operations.rollback`
- `translations.write`

This allows translation authority to be revoked independently from general Research content mutation.

## Shared translation contract

The remote gateway does not reimplement translation semantics. It delegates Preview, Apply and Rollback to the existing `Translation Editor` and pairing service.

The shared contract requires:
- both records use the same supported Research post type;
- one record is English and the other Spanish;
- both records are published before pairing;
- Research Lines are evidence-verified before pairing;
- an existing translation slot cannot be overwritten by a different record;
- the relationship is bilateral;
- structured resources carry `_eduardo_research_translation_evidence` on both sides;
- target state is protected by a joint baseline checksum;
- post-Apply verification confirms both directions and Theme translation resolution.

## Safety

The M5 gateway rejects normal WordPress posts and every post type outside the five structured Research families.

It preserves:
- exact Preview → Apply → Verify → Rollback;
- connection ownership;
- plan expiry;
- request-id idempotency;
- explicit confirmation;
- source revision and target baseline stale protection;
- collision protection;
- evidence reference propagation;
- stored bilateral verification;
- optional rendered verification of both resources;
- snapshot-backed rollback;
- audit correlation;
- `arbitrary_wordpress_proxy = false`.

## Capability discovery

`/capabilities` exposes `research_translation_control` with:
- milestone `M5`;
- allowed structured post types;
- pairing/unpairing operations;
- EN/ES + published requirements;
- Research Line evidence rule;
- evidence-reference support;
- collision protection;
- exact lifecycle;
- idempotency and stale Preview guarantees;
- rendered verification;
- explicit notice that Insights use the dedicated M4 gateway.

## Fresh WordPress acceptance

The quality gate exercises all five structured resource types and proves:
1. `translations.write` is independently required;
2. generic WordPress posts are rejected;
3. capability discovery advertises the bounded M5 contract;
4. pair Preview is non-mutating;
5. repeated request IDs are idempotent;
6. EN/ES pairing applies bilaterally;
7. evidence reference is stored on both structured records;
8. stored and rendered verification pass;
9. candidate/inspection surfaces expose the relationship;
10. stale unpair Preview is rejected after target drift;
11. unpair works and its rollback restores the pair;
12. rolling back each original pair restores the unpaired baseline.

## M5 status after this slice

After M5c, remote Manager control exists for:
- Research Lines;
- Outputs;
- Projects;
- Software;
- Datasets;
- EN/ES translation pairing for all five families.

M5 remains open for deeper evidence/provenance inspection, representative relationship/public-schema acceptance and the final aggregate M5 workflow. Real-site validation remains M8.
