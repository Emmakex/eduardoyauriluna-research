# Phase 2C — Full bounded write surface and OVH deployment

## Objective

Extend the reusable commercial MCP so an approved MCP client can operate the accepted Research site end-to-end through Research Manager without routine WordPress Admin, SSH, WP-CLI, arbitrary REST, direct database access or filesystem access.

This phase preserves one product and one codebase for both:

- shared multi-tenant managed service in OVH;
- private single-tenant managed deployments using the same image.

## Accepted starting point

Phase 2B is complete:

- commercial tenant/adapter/entitlement foundation accepted;
- read surfaces accepted;
- Page-slot Plan → Apply → Verify → Rollback production lifecycle accepted;
- baseline restoration proven;
- temporary read/write credentials revoked and live revocation proven;
- local bridge cleaned up after acceptance.

## Workstream A — bounded write domains

### A1 — Insights

- [x] expose bounded Insight create/draft plan;
- [x] expose bounded Insight edit/update plan;
- [x] expose Research relationships;
- [x] expose EN/ES structured pairing where supported;
- [x] expose scheduling/status lifecycle;
- [x] exact Apply/Verify/Rollback;
- [x] least-privilege credential mapping;
- [x] local tests/CI;
- [ ] reversible production acceptance;
- [ ] credential revocation proof.

Implementation evidence:

- base Insight lifecycle: PR #246 → `22b3874d1ad9a62296e629366e174b06501577a6`;
- EN/ES pair/unpair lifecycle: PR #247 → `aea77cd02ff9791dceedef604dc5e915d5c7bfe8`;
- MCP bounded catalog after A1: 46 tools;
- typecheck, unit tests, build, bounded-surface guard and WP-CLI fallback PASS on PR #247.

### A2 — Research objects

- [x] Research Lines lifecycle;
- [x] Outputs/Publications lifecycle;
- [x] Projects lifecycle;
- [x] Research Software lifecycle;
- [x] Datasets lifecycle;
- [x] relationships and verified Research-Line constraints;
- [x] exact Apply/Verify/Rollback;
- [x] local tests/CI;
- [ ] representative reversible production acceptance.

Implementation evidence:

- Research Line + shared Research Object lifecycle: PR #248 → `35404e22ca4e961feb60fdb3f11f7e71b47dd9fd`;
- dedicated Manager gateways preserved for `/research-lines/*` and `/research-objects/*`;
- evidence confirmation/reference gate preserved for evidence-sensitive writes;
- strict per-kind MCP schemas for Output, Project, Software and Dataset;
- Object update intentionally excludes `status` and `slug`, matching the Manager contract;
- exact Line/Object plan and operation ID domains plus operation-domain preflight;
- MCP bounded catalog after A2: 56 tools;
- typecheck, unit tests, build, bounded-surface guard and WP-CLI fallback PASS on PR #248.

### A3 — Translations + Evidence

- [x] structured translation pair/unpair lifecycle;
- [x] bounded evidence create/update/delete where permitted;
- [x] evidence/provenance preconditions preserved;
- [x] identity-sensitive actions remain elevated and separately permissioned;
- [x] local tests/CI;
- [ ] reversible production acceptance.

Implementation evidence:

- Translations + Evidence + canonical Identity Profile lifecycle: PR #249 → `e124a0e4bbfa777249059f704202d425d71bbb2c`;
- structured Research translations use the dedicated `/research-translations/*` gateway while Insight pairing remains on its A1 gateway;
- ordinary Evidence exposes create/update/delete only and rejects identity mutations during domain preflight;
- canonical identity changes use only the dedicated `/identity-profile/*` gateway and the elevated `identity.write` capability;
- Identity rollback requires both `identity.write` and `operations.rollback` commercially;
- translation/evidence/identity plan and operation ID domains remain distinct;
- evidence confirmation/reference and provenance boundaries are preserved;
- MCP bounded catalog after A3: 71 tools;
- typecheck, 100/100 unit tests, build, bounded-surface guard and WP-CLI fallback PASS on PR #249.

### A4 — SEO/GEO writes

- [x] expose deterministic bounded remediation plans;
- [x] refuse fabricated/non-legitimate remediation targets;
- [x] Apply only exact stored remediation plans;
- [x] stored/rendered Verify;
- [x] safe Rollback where Manager supports it;
- [x] local tests/CI;
- [ ] production acceptance only against a legitimate diagnostic target.

Implementation evidence:

- bounded SEO/GEO remediation lifecycle: PR #250 → `136155c38dd32f3fa155a16b3933d9e495f9040d`;
- only current Manager diagnostics classified `auto_remediable` may be planned;
- generic `seo-remediate` and specialized `search-indexability` ID domains are preserved;
- exact stored-plan Apply plus operation-domain preflight prevent payload/domain substitution;
- no arbitrary SEO metadata, HTML, WordPress option or URL mutation surface is exposed;
- MCP bounded catalog after A4: 75 tools;
- typecheck, unit tests, build, bounded-surface guard and WP-CLI fallback PASS on PR #250.

### A5 — Media

- [x] expose bounded Media ingest/update planning;
- [x] exact Apply/Verify/Rollback;
- [x] checksum/provenance preservation;
- [x] local tests/CI;
- [ ] reversible production acceptance.

Implementation evidence:

- bounded Media ingest + metadata lifecycle: PR #251 → `4276966569c11535be9d74af3aaf5e6956c53695`;
- ingest accepts only bounded JPEG, PNG, WebP or PDF payloads and preserves the Manager 10 MB limit;
- image alt text is mandatory before transport; the Manager remains authoritative for MIME, SHA-256 and provenance verification;
- private staged ingest can be cancelled before Apply, and exact stored-plan Apply prevents payload substitution;
- ingest Rollback remains constrained to unchanged Manager provenance and unused Manager-owned attachments;
- metadata writes are limited to title, alt text, caption and description with exact plan/operation-domain preflight;
- no arbitrary URL or filesystem-path ingest surface is exposed;
- Media assignment remains on its dedicated Theme-owned target/slot lifecycle and is not conflated with ingest/metadata;
- MCP bounded catalog after A5: 84 tools;
- typecheck, unit tests, build, 84-tool bounded-surface guard and WP-CLI fallback PASS on PR #251.

### A6 — Navigation

- [x] expose registered Theme navigation planning;
- [x] exact Apply;
- [x] rendered Verify;
- [x] Rollback/exact restoration;
- [x] local tests/CI;
- [ ] reversible production acceptance.

Implementation evidence:

- bounded Theme Navigation lifecycle: PR #252 → `faae5f485c571ea7717f3ebb9cd91ac7460ab9d2`;
- only registered `primary` / `footer` locations and registered Research Theme page targets are accepted;
- arbitrary URLs and generic WordPress menu mutation are not exposed;
- exact stored-plan Apply and plan/operation-domain preflight are preserved;
- MCP Verify requires rendered verification and delegates EN/ES rendered navigation checks to Research Manager;
- MCP bounded catalog after A6: 88 tools;
- typecheck, unit tests, build, 88-tool bounded-surface guard and WP-CLI fallback PASS on PR #252.

### A7 — Design

- [x] expose palette lifecycle;
- [x] expose typography lifecycle;
- [x] expose density lifecycle;
- [x] expose registered component variants;
- [x] forbid arbitrary CSS/HTML/classes/page-builder payloads;
- [x] responsive/accessibility/readiness Verify;
- [x] Rollback/exact restoration;
- [x] local tests/CI;
- [ ] reversible production acceptance.

Implementation evidence:

- bounded Theme Design lifecycles: PR #253 → `c819a127c043c7e722ac3edb98ecdc9255ee5b64`;
- preset writes are restricted to Theme-owned palette, typography and density enums;
- variant writes are restricted to registered hero, sections, cards and collections enums;
- no arbitrary CSS, HTML, classes, font URLs, content-order mutation or page-builder payload is exposed;
- Research Manager remains authoritative for palette accessibility, safe compiled CSS, bounded body classes and semantic/content-order invariants;
- aggregate Design readiness retains responsive/mobile, reduced-motion and rendered EN/ES checks;
- exact preset/variant plan and operation domains prevent cross-domain reuse;
- MCP bounded catalog after A7: 96 tools;
- typecheck, unit tests, build, 96-tool bounded-surface guard and WP-CLI fallback PASS on PR #253.

## Workstream B — cross-domain MCP guarantees

- [x] every write tool has an exact commercial capability;
- [x] every write tool has an exact backend-scope mapping;
- [x] read/write/elevated boundaries remain least privilege;
- [x] Apply cannot alter the stored plan payload;
- [x] stale revision/expired plan rejection is preserved;
- [x] operation-domain preflight prevents cross-domain plan/operation reuse;
- [x] idempotency is tested;
- [x] audit/request correlation is preserved;
- [x] no arbitrary backend URL/route escape hatch is introduced;
- [x] full typecheck/tests/build/surface guard green.

Implementation evidence:

- cross-domain guarantee suite: PR #254 → `799fc39e53282489340c23eafd7be70656dfc334`;
- all 96 tools remain covered by the authoritative commercial-capability and backend-scope catalog;
- write clients are forced through the Research Manager namespace with separate write credentials, request ID, nonce, timestamp, bounded timeout and redirect refusal;
- Apply transports send confirmation only against Manager-stored plans, never replacement mutation payloads;
- the source-wide guard rejects generic WordPress REST writes and generic backend URL/route/raw-request escape hatches;
- existing per-domain suites remain authoritative for stale/expired plan handling, operation-domain preflight, idempotency and reversible semantics;
- principal/tenant/entitlement and usage boundaries remain ahead of MCP dispatch.

## Workstream C — OVH managed runtime

### C1 — common production image

- [x] package `bridge/research-manager-mcp` as one production Docker image;
- [x] no customer-specific source forks;
- [x] runtime mode selected by configuration;
- [x] health endpoint retained;
- [x] controlled service deployment/rollback.

Implementation evidence:

- common hardened image: PR #255 → `3f7ff2676c63dd5279cc8afd6634d9da386d09e0`;
- one multi-stage Node 22 image runs as non-root and contains compiled runtime plus production dependencies only;
- image build runs typecheck, full unit suite and build before the runtime stage;
- the same built image passes single-tenant and shared multi-tenant smoke tests;
- Compose example uses read-only root filesystem, bounded tmpfs, dropped capabilities and `no-new-privileges`;
- deployment/rollback uses explicit immutable image tags/digests; no customer-specific build is required.

### C2 — HTTPS ingress

- [ ] deploy behind HTTPS reverse proxy/ingress in OVH;
- [x] private mode authenticated `/mcp` endpoint;
- [x] shared mode authenticated `/mcp/{tenant-id}` endpoints;
- [x] no public tenant enumeration;
- [x] health/status surfaces reveal no secrets.

Implementation evidence:

- repository-side HTTPS ingress contract: PR #256;
- Caddy exposes only `/healthz`, `/mcp` and `/mcp/*`; the application remains on an internal-only Docker backend network;
- ingress keeps separate egress for ACME certificate issuance/renewal and redirects HTTP to HTTPS;
- CI proves private authentication forwarding through TLS and shared routing through the same ingress topology;
- anonymous existing/nonexistent tenant paths return the same `401`; authenticated ungranted/nonexistent tenant paths return the same `403`;
- shared `/healthz` exposes routing state but no tenant IDs;
- live OVH hostname/DNS/public certificate and remote acceptance remain intentionally open.

### C3 — outer authentication

- [x] MCP client/principal authentication separated from backend Manager credentials;
- [x] authenticated principal registry/workspace identity;
- [x] principal → allowed tenant mapping;
- [x] deny-by-default cross-tenant behavior;
- [x] capability grant intersection enforced per request.

Implementation evidence:

- outer principal bearer credentials are distinct from read/write/elevated Research Manager credentials;
- shared principals have explicit tenant allowlists and capability grants;
- effective capability requires both tenant entitlement and principal capability;
- unknown tenant references are rejected at configuration load, duplicate bearer credentials are rejected, and cross-tenant requests fail closed;
- tenant authorization, entitlement and rate/usage behavior are covered by the cross-domain and tenant-authz suites.

### C4 — secrets and audit

- [x] server-side secret references only;
- [x] read/write/elevated backend credentials scoped per tenant;
- [ ] persistent audit/usage telemetry;
- [ ] secret rotation/revocation procedure;
- [ ] tenant-safe logs and metrics.

Current boundary:

- tenant/principal registry payloads contain secret references rather than secret values;
- environment-backed secret resolution fails closed and can be replaced by another `SecretResolver` implementation;
- access and entitlement logs are structured and exclude bearer material, but durable/tamper-resistant persistence is still required before external commercial launch;
- usage accounting is currently process-local and must become durable for managed production.

## Deployment modes

### Shared managed

One MCP platform in OVH serves multiple isolated customers. Each customer has a separate tenant, secrets, entitlements, principal grants, rate/usage boundary and audit correlation.

### Private managed

The same image is dedicated to one customer/tenant, normally in our OVH infrastructure. Private deployment does not add generic admin powers and preserves the same bounded tools and verification lifecycle.

### Enterprise/customer infrastructure

The same image and adapter contracts may run in customer infrastructure when required.

## Definition of Done

Phase 2C is complete when the normal accepted Research Manager operating surface needed for routine site management is available through typed MCP tools with the correct Plan/Apply/Verify/Rollback or domain-equivalent bounded lifecycle, and every implemented write domain has passed local/CI and appropriate production acceptance without weakening the Research Manager boundary.

OVH production readiness is complete when the same product image can be deployed either as shared multi-tenant or private single-tenant, through authenticated HTTPS, with server-side secrets, principal/tenant isolation, persistent audit, and remote acceptance from an approved MCP client.
