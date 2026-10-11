# Phase 3 — Remote Media metadata lifecycle

## Purpose

P3.2 adds the first bounded write capability for WordPress Media attachments. It builds on the read-only Media contract from P3.1 and deliberately limits mutation to editorial metadata that can be reviewed, verified and rolled back without exposing filesystem or upload primitives.

This slice does **not** upload files, replace binaries, edit attachment paths, delete Media, expose arbitrary attachment meta, or create a generic WordPress REST proxy.

## Supported operation

`media-metadata-update`

Supported fields are exactly:

- `title`;
- `alt_text`;
- `caption`;
- `description`.

Unknown fields are rejected with `400 validation_failed`.

Additional boundaries:

- `title` cannot be empty;
- `alt_text` is accepted only for image attachments;
- text values are sanitized and length-bounded;
- the target must resolve to a WordPress attachment with inherited attachment status;
- no file path, upload, replacement, deletion or arbitrary meta field is accepted.

## Dedicated remote lifecycle

Authenticated namespace: `research-manager/v1`.

### Plan

`POST /media/metadata/plan`

Required scopes:

- `operations.apply`;
- `media.write`.

Body:

```json
{
  "operation": "media-metadata-update",
  "payload": {
    "attachment_id": 123,
    "changes": {
      "title": "Research diagram",
      "alt_text": "Diagram showing the evaluated AI workflow",
      "caption": "Research workflow overview",
      "description": "Supporting visual for the research note."
    }
  }
}
```

Plan is observational only. It stores a bounded Preview, the expected metadata and a checksum of the managed attachment state. The plan expires after 15 minutes.

### Read plan

`GET /media/metadata/plans/{plan_id}`

Requires `media.read`.

### Apply

`POST /media/metadata/plans/{plan_id}/apply`

Required scopes:

- `operations.apply`;
- `media.write`.

Apply requires `{"confirm": true}`. Before mutation, the stored attachment checksum is compared with the current managed state. If Media changed after Preview, Apply returns `409 stale_revision` and no metadata mutation is performed.

A successful Apply creates a rollback snapshot containing only the bounded metadata state and verifies the changed fields immediately.

### Read operation

`GET /media/metadata/operations/{operation_id}`

Requires `media.read`.

### Verify

`POST /media/metadata/operations/{operation_id}/verify`

Required scopes:

- `site.diagnostics`;
- `media.read`.

Stored verification checks both:

- exact field values;
- exact post-Apply attachment revision.

P3.2 does not claim a Theme-owned rendered target for an unattached Media metadata edit. If rendered verification is requested, the lifecycle records a successful bounded skip with the reason `media-metadata-has-no-theme-owned-rendered-target-until-assignment`. Rendered resource verification belongs to P3.3 when Media assignment is introduced.

### Rollback

`POST /media/metadata/operations/{operation_id}/rollback`

Required scopes:

- `operations.rollback`;
- `media.write`.

Rollback requires explicit confirmation and restores the exact pre-Apply values for title, alt text, caption and description. The restored state is verified and compared with the original source revision.

## Idempotency

Plan, Apply, Verify and Rollback use the existing Remote Manager request-id/fingerprint store. Repeating the same mutation request with the same request ID and same body returns the stored result rather than applying it again. Reusing a request ID with different material is rejected by the shared idempotency guard.

## Capability discovery

Authenticated `/capabilities` extends `media_control` with:

- `write_available = true`;
- `write_scope = media.write`;
- `operations = [media-metadata-update]`;
- the exact metadata field allowlist;
- dedicated lifecycle base path;
- required Apply and Rollback scopes;
- stale attachment revision protection;
- idempotency support;
- explicit `arbitrary_upload_proxy = false`;
- explicit `destructive_delete = false`;
- explicit `arbitrary_wordpress_proxy = false`.

## Credential policy

The accepted Phase 2 production credential remains the exact 17-scope profile and is not expanded with Media scopes by default.

Media work uses a deliberate Media-scoped credential/session. A full P3.2 mutation session needs only the scopes required for the requested actions, typically:

- `site.read` for capability discovery;
- `site.diagnostics` for verification;
- `media.read`;
- `media.write`;
- `operations.apply`;
- `operations.rollback`.

`media.write` should not be granted to routine Phase 2 operations that do not need Media mutation.

## Acceptance contract

Fresh-WordPress CI must prove:

- all dedicated Media metadata lifecycle routes exist;
- `operations.apply` and `media.write` are independently enforced for Plan/Apply;
- `operations.rollback` and `media.write` are independently enforced for Rollback;
- Preview is non-mutating;
- identical Plan request IDs replay idempotently;
- stale attachment state blocks Apply with `409 stale_revision`;
- explicit confirmation is required;
- all four supported metadata fields Apply and Verify correctly;
- Rollback restores the exact source revision;
- unsupported fields and non-attachment targets are rejected;
- P3.1 Media read/safety acceptance remains green.
