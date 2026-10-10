# Remote Manager M4 — bounded Insight create/update

## Purpose

This slice connects the existing Research Insight application services to the authenticated Remote Manager operation lifecycle so ChatGPT can create and edit managed Research Insights without becoming a generic WordPress post proxy.

The canonical path is:

`ChatGPT intent -> Remote Manager plan -> Preview -> explicit confirmation -> Apply -> Verify -> optional rendered Verify -> Rollback`

## Supported remote operations

### `insight-create`

Payload:

```json
{
  "operation": "insight-create",
  "payload": {
    "data": {
      "title": "...",
      "slug": "...",
      "excerpt": "...",
      "content": "...",
      "language": "en",
      "insight_type": "research_note",
      "status": "draft"
    },
    "reason": "..."
  }
}
```

Creation delegates to `Eduardo_Research_Manager_Insight_Editor::preview_create()` and `apply_preview()`.

The existing shared Theme/Manager contract remains authoritative for:

- EN/ES language;
- valid Insight type;
- title/excerpt/content bounds;
- unique slug;
- provenance token;
- creation state limited to `draft` or `publish`.

A created resource is resolved by Manager provenance and can be rolled back safely through the snapshot produced by the shared Executor.

### `insight-update`

Payload:

```json
{
  "operation": "insight-update",
  "payload": {
    "post_id": 123,
    "changes": {
      "title": "...",
      "excerpt": "...",
      "content": "...",
      "language": "es",
      "insight_type": "explainer"
    },
    "reason": "..."
  }
}
```

The update surface is deliberately bounded to the fields already supported by the shared Insight Editor:

- `title`;
- `excerpt`;
- `content`;
- `language`;
- `insight_type`.

Changing the status of an **existing** Insight is not part of this slice. That requires an explicit extension of the central Plan contract and remains the next M4 sub-slice.

## Managed-resource boundary

A WordPress `post` is not automatically a Research Insight.

`Insight_Resource::inspect()` now requires `_research_insight_type` to exist and contain a valid Theme editorial type. Therefore a normal WordPress post cannot be inspected or mutated through the Research Insight Manager simply because it uses the core `post` post type.

This rule is critical for the product boundary:

`Research Manager != unrestricted WordPress post proxy`

## Safety and consistency

Both operations reuse M1/M2 controls:

- scoped authenticated credential;
- request metadata and anti-replay nonce;
- request-ID idempotency;
- plan expiry;
- source revision fingerprint;
- Theme contract fingerprint;
- explicit confirmation;
- operation persistence;
- audit correlation;
- stored-state verification;
- rendered verification;
- rollback snapshot.

`insight-update` also retains the Insight Editor's target-specific baseline checksum. This means an external change to the Insight after Preview rejects Apply even when the site-wide revision fingerprint itself has not changed.

## Rendered verification

For a published Insight, rendered verification delegates to `Rendered_Verifier::verify_record()` and therefore checks the Theme-owned frontend, including the Article/schema and core discovery signals already enforced by the Research Theme.

For a draft Insight, rendered verification is considered not applicable and returns a successful skipped result rather than pretending the draft has a public frontend.

## Capability discovery

`/research-manager/v1/capabilities` exposes:

- `insight-create`;
- `insight-update`;
- the exact M2 lifecycle;
- valid creation states;
- update field allowlist;
- explicit notice that existing-status transition is still pending a later M4 slice.

## Acceptance

Fresh-WordPress CI proves:

1. M4 operations are discoverable;
2. an ordinary WordPress post is rejected as unmanaged;
3. `insight-create` Preview does not mutate WordPress;
4. request-ID replay returns the idempotent response;
5. Apply without confirmation is blocked;
6. a published Insight can be created through the remote lifecycle;
7. stored and rendered verification pass;
8. an approved multi-field update can be planned/applied/verified;
9. update rollback restores the previous editorial state;
10. a stale target-specific Preview is rejected after an external edit;
11. creation rollback deletes only the Manager-owned created Insight.

## Still pending in M4

After this slice, M4 still needs:

- explicit existing Insight `draft <-> publish` transition through the central Plan contract;
- scheduling;
- taxonomy/authorship controls where required by the product contract;
- relationships/internal-link operations;
- remote EN/ES translation pairing;
- final M4 rendered/editorial acceptance;
- real-site acceptance on `eduardoyauriluna.com` under M8.

Advanced metadata/canonical/schema remediation remains M6.
