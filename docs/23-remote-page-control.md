# Remote Page control — M3

## Purpose

M3 extends the authenticated Research Manager bridge from site-level Greenfield control to bounded Theme-owned Page control without exposing a generic WordPress administration proxy.

The remote caller can inspect the Research Page inventory, inspect a specific EN/ES structured Page model, request SEO/GEO rendered diagnostics, update only Theme-declared slots, and recreate a missing contractual Page.

## Read surface

- `GET /research-manager/v1/pages`
  - inventory of Theme-owned Page contracts;
  - existence and WordPress Page ID;
  - EN/ES availability;
  - allowed structured slots;
  - stored/effective slot values.
- `GET /research-manager/v1/pages/{key}?language=en|es`
  - exact structured Page state for one language.
- `GET /research-manager/v1/pages/{key}/seo-geo[?language=en|es]`
  - stored Page contract state;
  - rendered canonical/hreflang/language/OG/JSON-LD verification;
  - diagnostics associated with that Page.

All read routes reuse the M1 authentication, scope, timestamp, nonce, rate-limit and audit-capable gateway. No WordPress credentials are exposed.

## Mutation operations

M3 adds two bounded operation types to the existing M2 operation lifecycle:

### `page-slots-update`

Payload:

```json
{
  "operation": "page-slots-update",
  "payload": {
    "key": "contact",
    "language": "en",
    "slots": {
      "lead": "..."
    },
    "reason": "..."
  }
}
```

Rules:

- Page key must belong to the active Research preset.
- Language must belong to the active Research contract.
- Only slots exposed by the Theme model are accepted.
- Values pass through the existing Page Resource normalisation.
- Preview is produced by the existing Page Editor.
- Apply reuses the exact prepared Page Editor plan.
- Page Editor checksum protection and the remote source revision both reject stale state.
- Stored verification reuses Page Resource semantic verification.
- Rendered verification reuses the existing rendered verifier.
- Rollback uses the Page Editor / Executor snapshot.

### `page-create`

Payload:

```json
{
  "operation": "page-create",
  "payload": {
    "key": "legal-notice",
    "reason": "..."
  }
}
```

Rules:

- Only a Page present in the active Theme contract may be created.
- Creation is rejected if the Page already exists.
- Preview generates a provenance-bound creation plan but does not mutate WordPress.
- Apply creates only the contractual Page shape through the existing Executor.
- Stored verification checks slug, role, model, publication state, route, front-page behaviour where applicable, and Manager provenance.
- Rollback deletes only the Page carrying the exact Manager creation provenance token; an unrelated slug occupant is never deleted.

## EN/ES model

Research Pages use one Theme-owned WordPress Page surface with language-specific structured slot stores and language-specific routes. M3 therefore treats EN and ES as independent structured content states under one Page contract. Updating ES must not mutate EN and vice versa.

## Exact operation lifecycle

Both Page operations use the M2 lifecycle:

`Plan → explicit confirmation → Apply → stored Verify → optional rendered Verify → Rollback`

The plan remains connection-bound, expires after 15 minutes, carries an exact source revision and Theme contract fingerprint, and is protected by request-ID idempotency.

## Security boundary

M3 does **not** expose:

- arbitrary post meta;
- arbitrary WordPress options;
- arbitrary Page IDs/slugs outside the active preset;
- raw SQL, PHP, filesystem or shell execution;
- generic WordPress REST proxying;
- unrestricted CSS or template editing.

The unit of remote control is the Research Manager Page capability, not WordPress internals.

## Acceptance

The M3 CI acceptance runs against a clean WordPress instance and proves:

1. authenticated Page inventory and inspection;
2. Preview does not mutate Page state;
3. an EN structured slot can be applied, stored-verified, rendered-verified and rolled back exactly;
4. an ES structured slot can be changed independently without contaminating EN and rolled back;
5. a contractual missing Page can be previewed, created, verified and removed again through rollback;
6. Page SEO/GEO rendered inspection returns a verified result;
7. unknown Theme slots are rejected;
8. audit correlation is present and the bearer token is absent.

Real `eduardoyauriluna.com` mutation remains an M8 acceptance item until the authenticated Manager is deployed on the live site.
