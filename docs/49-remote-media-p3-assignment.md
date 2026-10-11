# Phase 3 — Bounded Media assignment and editorial integration

## Purpose

P3.3 lets the authenticated Research Manager relate an **existing image attachment** to a supported Theme-controlled resource without exposing arbitrary WordPress metadata, layout markup, uploads or filesystem access.

The Theme remains the rendering authority. The Manager selects only the bounded `primary` Media slot and the Theme decides where and how that slot is rendered.

## Supported targets

The assignment target is one of:

- `page` — a controlled Research preset Page key plus language; requires `pages.write` for mutation and `pages.read` for reads/verification;
- `insight` — an explicitly managed Research Insight post; requires `insights.write` / `insights.read`;
- `research` — a Research Line, Output, Project, Software or Dataset; requires `research.write` / `research.read`.

Arbitrary WordPress posts are not accepted as Research targets.

## Supported Media slot

P3.3 exposes exactly one Theme-owned slot:

`primary`

It accepts existing WordPress **image attachments** only. Other attachment types, arbitrary slot names and arbitrary post-meta keys are rejected.

Storage is bounded:

- controlled Pages use a language-specific Theme option owned by the Research preset;
- Insights and Research records use the fixed private relationship key `_eduardo_research_primary_media_id`.

The remote caller cannot choose either storage mechanism or key.

## Remote lifecycle

Authenticated namespace: `research-manager/v1`.

### Inspect current relationship

`GET /media/assignments`

The target is supplied through bounded query parameters, for example:

```text
/media/assignments?type=insight&post_id=41&slot=primary
/media/assignments?type=page&key=contact&language=en&slot=primary
/media/assignments?type=research&kind=project&post_id=77&slot=primary
```

Required scopes:

- `media.read`;
- the target-specific read scope.

Inspection is read-only and returns only the public assignment contract: target identity, resource URL/language, slot, current attachment ID, a safe attachment summary when assigned, and the target revision used by Preview/Apply protection. Internal storage mechanism, private storage key, target service record and semantic scope implementation are not exposed.

### Plan

`POST /media/assignments/plan`

Example:

```json
{
  "payload": {
    "target": {
      "type": "insight",
      "post_id": 41
    },
    "attachment_id": 123,
    "slot": "primary"
  }
}
```

Required scopes:

- `operations.apply`;
- `media.write`;
- the target-specific semantic write scope.

Preview is non-mutating. It records both a target-resource revision and an attachment revision.

### Apply

`POST /media/assignments/plans/{plan_id}/apply`

Requires explicit `{"confirm": true}` and the same write boundary as Plan.

Apply refuses stale Preview state if either:

- the target resource changed; or
- the image attachment changed.

A successful Apply creates an exact relationship rollback snapshot and records post-Apply target and attachment revisions.

### Verify

`POST /media/assignments/operations/{operation_id}/verify`

Default verification includes rendered verification.

Required scopes:

- `site.diagnostics`;
- `media.read`;
- the target-specific read scope.

Stored verification checks the exact relationship. Rendered verification fetches the target URL and requires the Theme marker:

```html
<figure data-research-media-slot="primary" data-attachment-id="123">…</figure>
```

The marker is emitted only by the Theme helper that owns the primary Media slot.

### Rollback

`POST /media/assignments/operations/{operation_id}/rollback`

Required scopes:

- `operations.rollback`;
- `media.write`;
- the target-specific semantic write scope.

Rollback requires explicit confirmation and restores the exact previous relationship, including the absence of a prior assignment.

Rollback is blocked with `409 stale_revision` if either the target or assigned Media changed after Apply. This prevents a historical operation from overwriting newer editorial or Media state.

Stored plan/operation routes also preserve connection ownership: a different Research Manager connection cannot read or mutate another connection's assignment lifecycle state.

## Theme rendering

The Theme renders the same bounded relationship on:

- the localized Home surface;
- controlled Research preset Pages;
- managed Insights;
- Research Lines, Outputs, Projects, Software and Datasets.

No assignment can inject HTML, CSS, Gutenberg blocks or arbitrary classes. Presentation remains in the Theme, including responsive image markup and the dedicated `research-media.css` presentation layer.

## Capability discovery

Authenticated `/capabilities` exposes `media_control.assignment`, including:

- supported target families;
- target-specific read/write scope mapping;
- `primary` as the only slot;
- image-only attachment boundary;
- exact lifecycle base path including `inspect`;
- inspect scopes (`media.read` + target read);
- dual-resource stale protection;
- rendered verification;
- idempotency;
- `arbitrary_post_meta = false`;
- `layout_authority = theme`.

## Credential policy

The accepted Phase 2 production 17-scope credential remains unchanged.

P3.3 is exercised with a deliberate Media-scoped session containing only the Media and target scopes required for the requested operation. Routine Phase 2 operations do not receive `media.write` by default.

## Acceptance contract

Fresh-WordPress CI must prove:

- the read-only assignment inspection route and all mutation lifecycle routes exist;
- assignment inspection requires both `media.read` and the target-specific read scope;
- inspection does not leak private storage implementation details;
- `media.write` and target-specific semantic write scopes are independently enforced;
- Page, Insight and Research target families resolve through their bounded Manager resources;
- Preview does not mutate relationships;
- request-id idempotency is preserved;
- Apply requires explicit confirmation;
- Theme helper emits the exact assignment marker;
- rendered verification recognises the Theme-owned marker;
- target changes after Apply block Rollback;
- attachment changes after Apply block Rollback;
- exact prior relationship is restored after a safe Rollback;
- no arbitrary layout/post-meta/upload/filesystem surface is introduced.
