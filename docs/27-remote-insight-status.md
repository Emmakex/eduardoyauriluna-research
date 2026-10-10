# Remote Manager M4 — bounded Insight publication-state transitions

## Purpose

This slice completes the basic publication control required for ChatGPT-governed Research Insights without weakening the generic WordPress mutation contract.

The Manager must be able to publish and unpublish a managed Research Insight, but it must **not** turn generic `post_status` into a remotely writable WordPress field.

## Decision

Existing Insight publication state is handled as a specialised `Insight Editor` mode inside the shared application service.

The generic `Eduardo_Research_Manager_Plan` rule for `post_status` remains unchanged and continues to allow only the existing Theme-owned Page remediation case.

This intentionally separates:

- a bounded Research Insight publication transition;
- unrestricted WordPress post-status mutation.

The second remains prohibited.

## Remote operation

The existing bounded remote operation remains:

`insight-update`

A publication transition uses a payload containing **only** `status`:

```json
{
  "operation": "insight-update",
  "payload": {
    "post_id": 123,
    "changes": {
      "status": "publish"
    },
    "reason": "Publish reviewed Research Insight"
  }
}
```

Allowed target values:

- `draft`
- `publish`

Any other status is rejected.

## Separate Preview requirement

`status` cannot be mixed with title, excerpt, content, language or Insight type in the same Preview.

For example, this is rejected:

```json
{
  "changes": {
    "status": "publish",
    "title": "New title"
  }
}
```

Publication is a meaningful external-state transition and must therefore remain independently reviewable and independently reversible.

Content can be changed in one `insight-update`; publication can then be planned as a second exact operation.

## Managed-resource boundary

Before preparing the status Preview, the shared Insight service inspects the target.

A post is eligible only if it is explicitly managed as a Research Insight, including a valid `_research_insight_type` under the active Theme contract.

Therefore:

- arbitrary WordPress posts cannot be published through this surface;
- invalid or unmanaged posts are rejected before Preview;
- the API does not expose a generic `post_status` endpoint.

## Preview and stale protection

The specialised Preview records:

- Insight post ID;
- current state;
- requested state;
- full managed Insight baseline checksum;
- editorial-review risk;
- exact transition intent.

Apply recomputes the managed Insight checksum immediately before mutation.

If title, excerpt, content, language, Insight type or status changed after Preview, Apply fails with the existing stale-Preview error and the operator must prepare a fresh plan.

## Apply

Apply is reached only through the existing remote M2 confirmation gate.

The shared Insight Editor:

1. verifies the target baseline;
2. creates a bounded rollback snapshot;
3. changes only `post_status` for that managed Insight;
4. verifies the requested status semantically;
5. rolls back automatically if verification fails.

The snapshot uses the existing snapshot store and a rollback-compatible `post_field` record internally. This does **not** make `post_status` an allowed generic Plan action; it is an internal recovery record produced only after the specialised Insight checks pass.

## Verify

Stored verification uses the same `Insight_Resource::verify()` service as the other M4 Insight operations.

### Draft → publish

A published Insight can additionally run rendered verification through the Research Theme.

The rendered verifier checks the public response and its existing discovery/Article signals.

### Publish → draft

A draft is deliberately not expected to have a public rendered surface.

Rendered verification therefore returns a successful bounded skip:

- `verified = true`;
- `skipped = true`;
- `reason = draft-or-non-public`.

This prevents the system from interpreting absence of a public page as a failure after a deliberate unpublish operation.

## Rollback

Status snapshots are compatible with the existing `Insight_Editor -> Executor` rollback path.

Rollback restores the exact previous publication state:

- publish rollback restores draft;
- unpublish rollback restores publish.

## Capability discovery

`/research-manager/v1/capabilities` advertises:

- `status` in the Insight update-field contract;
- allowed status values `draft` and `publish`;
- separate Preview required;
- generic post-status contract unchanged.

The remote mutation name remains `insight-update`; no generic `post-status` operation is introduced.

## Acceptance

Fresh-WordPress CI proves:

1. capability discovery exposes the bounded status contract;
2. status mixed with content changes is rejected;
3. unsupported statuses are rejected;
4. draft → publish Preview does not mutate stored state;
5. publish Apply uses the existing remote confirmation lifecycle;
6. published stored + rendered verification succeeds;
7. publish rollback restores draft;
8. target-specific stale protection rejects Apply after an external edit;
9. publish → draft succeeds;
10. rendered verification for draft is safely skipped;
11. unpublish rollback restores publish;
12. cleanup can still roll the Manager-created Insight back to absence.

## M4 remaining work after this slice

The basic Insight lifecycle is then available remotely:

- inspect;
- create;
- edit bounded content;
- publish;
- unpublish;
- verify;
- rollback.

Remaining M4 product work includes:

- scheduling if required for the final editorial workflow;
- taxonomy/authorship controls where required;
- relationships/internal-link operations;
- remote EN/ES translation pairing;
- final M4 acceptance against the complete editorial contract.

Real `eduardoyauriluna.com` execution remains M8 acceptance. Advanced SEO/GEO mutation remains M6.
