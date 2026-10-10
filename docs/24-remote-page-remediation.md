# Remote Page remediation — M3 completion

## Purpose

This milestone completes the repository-level M3 Page control boundary by allowing ChatGPT to repair a narrowly defined set of Page readiness findings through the authenticated Research Manager.

The operation is deliberately not a generic SEO plugin, WordPress repair endpoint or arbitrary metadata writer. It reuses the existing `Eduardo_Research_Manager_Remediation` and `Executor` services.

## Operation

`page-remediate` uses the existing remote operation transport:

`Plan → explicit confirmation → Apply → stored Verify → optional rendered Verify → Rollback`

Payload example:

```json
{
  "operation": "page-remediate",
  "payload": {
    "key": "contact",
    "check_id": "page-contact",
    "reason": "Repair the Contact Page Research contract"
  }
}
```

## Allowed remediation scope

For a target Page `{key}`, the remote bridge accepts only:

- `page-{key}` — the structural readiness finding for that exact Theme-owned Page;
- `front-page` — only when `{key}` is `home`.

The Page check may safely restore:

- published state;
- Research role metadata;
- Research model metadata;
- a missing contractual Page through the existing provenance-aware creation plan.

The Home routing check may safely restore:

- static front-page mode;
- the Research Home Page assignment.

Cross-resource findings such as `language-contract` are rejected by the Page remediation operation even though a separate local remediation service may support them.

## Inspection

`GET /research-manager/v1/pages/{key}/seo-geo` now returns:

- stored Page state;
- rendered canonical/hreflang/language/OG/JSON-LD verification;
- diagnostics related to the Page;
- bounded auto-remediation candidates;
- the supported mutation operation (`page-remediate`).

The response explicitly states that advanced metadata/canonical/schema optimisation belongs to M6.

## Why advanced SEO/GEO remediation remains M6

M3 establishes safe Page governance. It does not prematurely expose arbitrary title/meta/schema/canonical controls.

M6 will add deterministic SEO/GEO operations with their own contracts for:

- metadata;
- canonical and hreflang operations;
- schema/indexability;
- internal links;
- GEO/entity/direct-answer/provenance checks;
- readiness reruns and residual manual actions.

Keeping those concerns in M6 prevents M3 from becoming an unrestricted WordPress or SEO mutation surface.

## Safety properties

`page-remediate` inherits M1/M2 protections:

- site-specific revocable credential;
- scopes;
- request ID and idempotency;
- timestamp and replay protection;
- plan expiry;
- source revision fingerprint;
- Theme contract fingerprint;
- explicit Apply confirmation;
- operation status;
- audit correlation;
- exact snapshot rollback.

The operation additionally validates that the requested readiness check belongs to the target Page.

## Acceptance

The fresh-WordPress acceptance deliberately corrupts a Theme-owned Page role and proves:

1. the Page SEO/GEO inspection exposes the exact auto-remediation candidate;
2. a cross-resource remediation request is rejected;
3. Preview does not mutate WordPress;
4. Apply repairs the Theme Page contract;
5. stored verification passes;
6. rendered verification passes without returning HTML bodies;
7. rollback restores the deliberately broken source state and exact source revision;
8. a new exact plan can repair the Page again so the test finishes healthy;
9. audit correlation is present and the bearer secret is absent.

The remaining M3 acceptance item is the first real rendered `eduardoyauriluna.com` Page mutation originating from ChatGPT. That requires live deployment and is therefore completed together with M8 real-site acceptance, not claimed by repository CI.
