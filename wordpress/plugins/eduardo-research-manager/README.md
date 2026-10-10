# Research Manager

Independent WordPress control plane for the Eduardo Research Theme. The Theme remains the public layout, route, SEO/GEO, Schema and accessibility authority.

## Current version

`0.9.0`

The Manager now covers:

- checksummed `Preview → Apply → Verify → Rollback`;
- Theme-owned Page hydration/reversible creation;
- EN/ES Insights;
- evidence-aware Publications, Projects, Software and Datasets;
- HTTP-level rendered frontend verification;
- M1 authenticated, read-only remote bridge for the ChatGPT-governed operating model.

## M1 remote Manager bridge

The remote bridge is the first transport layer for the canonical operating path:

```text
Eduardo → ChatGPT → authenticated Research Manager → shared Manager services → WordPress / Research Theme
```

M1 deliberately exposes **read-only** operations. Remote mutations remain unavailable until M2 implements the exact Plan → Apply → Verify → Rollback operation lifecycle.

### REST namespace

`/wp-json/research-manager/v1`

Read endpoints:

- `GET /status` — `site.read`;
- `GET /versions` — `site.read`;
- `GET /capabilities` — `site.read`;
- `GET /readiness` — `site.diagnostics`;
- `GET /diagnostics` — `site.diagnostics`.

### Authentication and request protection

Remote credentials are site-specific and bound to a local WordPress administrator. The raw bearer token is shown only when generated or rotated; WordPress persists only its password hash and a non-secret prefix.

Authenticated calls require:

- `Authorization: Bearer <token>`;
- `X-Research-Manager-Request-Id`;
- `X-Research-Manager-Nonce`;
- `X-Research-Manager-Timestamp`.

The bridge enforces:

- revocable/rotatable credentials;
- explicit scopes;
- local administrator capability validation;
- HTTPS outside local/development environments;
- timestamp validity window;
- nonce replay protection;
- per-connection rate limiting;
- request/idempotency primitives for M2;
- bounded audit records that never contain bearer tokens or credential hashes.

### Local control

WordPress → Tools → Research Manager Remote provides the local security console for:

- generating a connection token;
- rotating it;
- enabling/disabling remote access;
- revoking the credential immediately;
- selecting scopes;
- viewing recent non-secret audit events.

No SSH access, WordPress administrator password sharing, arbitrary PHP/SQL/filesystem execution or generic WordPress REST proxy is part of the remote contract.

## Evidence discipline

Academic and research claims require explicit evidence confirmation plus a non-empty evidence/source reference before Apply. Stored-state verification and public-render verification are intentionally separate: the Manager can confirm what was persisted while the Theme still decides what is safe to expose.

## Rendered frontend verification

`Eduardo_Research_Manager_Rendered_Verifier` verifies the actual HTTP response produced by WordPress after a mutation.

For Theme-owned pages it checks:

- HTTP 200;
- canonical URL;
- correct EN/ES `<html lang>`;
- current-language `hreflang`;
- EN/ES alternates plus `x-default`;
- Open Graph URL;
- presence of JSON-LD;
- optional expected/forbidden rendered text.

For Research records it additionally checks the expected Schema type:

- Research Line / Project → `CreativeWork`;
- Dataset → `Dataset`;
- Software → `SoftwareSourceCode`;
- Publication → Theme-selected `CreativeWork`, `ScholarlyArticle`, `Report`, etc.

The verifier derives evidence-sensitive forbidden values from private storage. If a DOI, publication type or review status exists privately without its public verification flag, rendered verification requires that raw claim to remain absent from public HTML/Schema.

Rendered HTTP verification is exposed separately through `Eduardo_Research_Manager::rendered()`. It is not executed synchronously inside `Apply`, avoiding self-request deadlocks on single-worker PHP environments. The intended workflow is:

```text
Preview → Apply → stored Verify → rendered Verify → keep/rollback decision
```

## Resource services

- `pages()` — Theme-defined page models/slots and reversible page creation.
- `insights()` — bounded editorial notes.
- `outputs()` — publications/research outputs with academic metadata and evidence gates.
- `projects()` — structured research projects.
- `software()` — research software, release/repository/DOI metadata.
- `datasets()` — research datasets, access/provenance/methodology/DOI metadata.
- `rendered()` — HTTP-level public result verification.
- `remote_credentials()` — local remote identity/credential/scopes.
- `remote_audit()` — bounded non-secret remote audit trail.
- `remote_guard()` — timestamp, replay, rate and idempotency guards.
- `remote_rest()` — versioned read-only remote adapter over shared Manager services.

Research objects share an internal executor registry for creation, stored-state verification and provenance-safe rollback, while each resource keeps its own validation contract.

## Mutation boundaries

Generic mutations remain narrow. Dedicated resource creation actions do not grant arbitrary WordPress publishing control. Snapshots are bounded, non-autoloaded and one-shot for rollback.

The M1 remote bridge does not expose mutation routes. M2 must reuse these same application services rather than create an unrestricted remote WordPress administration path.

## Admin surfaces

- WordPress → Tools → Research Manager — readiness/control-plane state and structured site operations.
- WordPress → Tools → Research Manager Remote — local connection security, scopes, rotation/revocation and audit.

## Next implementation block

**M2 — remote operation lifecycle:** exact plan IDs, state fingerprints, plan expiry, Preview, exact-plan Apply, stale-state rejection, operation status, stored verification, rendered verification, rollback, retry/idempotency acceptance and audit correlation.
