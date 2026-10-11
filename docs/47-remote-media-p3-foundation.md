# Phase 3 — Remote Media read foundation

## Purpose

Phase 3 begins by removing a remaining normal-operation WordPress Admin bypass: attachment discovery and Media metadata inspection.

The first slice is deliberately **read-only**. It does not upload files, mutate attachment metadata, expose filesystem paths, delete Media, or create a generic WordPress Media REST proxy.

## Remote endpoints

Authenticated namespace: `research-manager/v1`.

### `GET /media`

Requires `media.read`.

Bounded query parameters:

- `page` — 1-based result page;
- `per_page` — clamped to 1–50;
- `search` — sanitized title/content search, capped at 100 characters;
- `mime_type` — one of `image`, `audio`, `video`, `pdf`, `application/pdf` or empty/all.

The response contains pagination metadata and safe attachment records only.

### `GET /media/{id}`

Requires `media.read`.

Only WordPress attachment resources with inherited attachment status are accepted. Other post types and missing IDs return `404 media_not_found`.

## Safe record contract

The Manager may return:

- attachment ID;
- title;
- alt text;
- caption;
- description;
- MIME/media type;
- public source URL;
- parent resource ID;
- attachment status;
- uploaded/modified timestamps;
- image width/height when known;
- bounded readiness flags for public URL, parent relation, image alt and image dimensions.

The Remote Media surface must not return:

- absolute server/upload paths;
- `_wp_attached_file` or equivalent private storage metadata;
- credentials/secrets;
- arbitrary attachment meta;
- raw filesystem information.

## Capability discovery

Authenticated `/capabilities` exposes:

- `read_endpoints.media = media.read`;
- `read_endpoints.media_item = media.read`;
- `media_control.inventory = true`;
- `media_control.inspection = true`;
- `media_control.write_available = false`;
- explicit safe fields and bounded filters;
- `filesystem_paths_exposed = false`;
- `arbitrary_upload_proxy = false`;
- `arbitrary_wordpress_proxy = false`.

## Scope model

This slice does not change the accepted Phase 2 production credential. `media.read` is granted only for a deliberate Media inspection session.

The future P3.2 write slice must be separate and must require `media.write` plus the normal exact lifecycle scopes. A read credential must never imply write access.

## Fresh-WordPress acceptance

`Research Manager Remote Media Foundation Quality` proves on a clean WordPress installation that:

1. both Media routes exist;
2. a credential without `media.read` receives `403 scope_denied`;
3. a credential with `media.read` can discover and inspect a known image attachment;
4. the capability contract remains explicitly read-only;
5. bounded MIME filtering works and unsupported filters are rejected;
6. non-attachment resources are rejected;
7. responses do not leak the WordPress upload filesystem path or `_wp_attached_file` metadata.

## Next slice

P3.2 adds bounded attachment metadata mutation for title, alt text, caption and description using exact Plan → Apply → Verify → Rollback, stale-state protection and the independent `media.write` semantic scope.
