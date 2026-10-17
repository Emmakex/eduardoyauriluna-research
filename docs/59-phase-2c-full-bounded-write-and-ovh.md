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

- [ ] expose deterministic bounded remediation plans;
- [ ] refuse fabricated/non-legitimate remediation targets;
- [ ] Apply only exact stored remediation plans;
- [ ] stored/rendered Verify;
- [ ] safe Rollback where Manager supports it;
- [ ] local tests/CI;
- [ ] production acceptance only against a legitimate diagnostic target.

### A5 — Media

- [ ] expose bounded Media ingest/update planning;
- [ ] exact Apply/Verify/Rollback;
- [ ] checksum/provenance preservation;
- [ ] local tests/CI;
- [ ] reversible production acceptance.

### A6 — Navigation

- [ ] expose registered Theme navigation planning;
- [ ] exact Apply;
- [ ] rendered Verify;
- [ ] Rollback/exact restoration;
- [ ] local tests/CI;
- [ ] reversible production acceptance.

### A7 — Design

- [ ] expose palette lifecycle;
- [ ] expose typography lifecycle;
- [ ] expose density lifecycle;
- [ ] expose registered component variants;
- [ ] forbid arbitrary CSS/HTML/classes/page-builder payloads;
- [ ] responsive/accessibility/readiness Verify;
- [ ] Rollback/exact restoration;
- [ ] local tests/CI;
- [ ] reversible production acceptance.

## Workstream B — cross-domain MCP guarantees

- [ ] every write tool has an exact commercial capability;
- [ ] every write tool has an exact backend-scope mapping;
- [ ] read/write/elevated boundaries remain least privilege;
- [ ] Apply cannot alter the stored plan payload;
- [ ] stale revision/expired plan rejection is preserved;
- [ ] operation-domain preflight prevents cross-domain plan/operation reuse;
- [ ] idempotency is tested;
- [ ] audit/request correlation is preserved;
- [ ] no arbitrary backend URL/route escape hatch is introduced;
- [ ] full typecheck/tests/build/surface guard green.

## Workstream C — OVH managed runtime

### C1 — common production image

- [ ] package `bridge/research-manager-mcp` as one production Docker image;
- [ ] no customer-specific source forks;
- [ ] runtime mode selected by configuration;
- [ ] health endpoint retained;
- [ ] controlled service deployment/rollback.

### C2 — HTTPS ingress

- [ ] deploy behind HTTPS reverse proxy/ingress in OVH;
- [ ] private mode authenticated `/mcp` endpoint;
- [ ] shared mode authenticated `/mcp/{tenant-id}` endpoints;
- [ ] no public tenant enumeration;
- [ ] health/status surfaces reveal no secrets.

### C3 — outer authentication

- [ ] MCP client/principal authentication separated from backend Manager credentials;
- [ ] authenticated principal registry/workspace identity;
- [ ] principal → allowed tenant mapping;
- [ ] deny-by-default cross-tenant behavior;
- [ ] capability grant intersection enforced per request.

### C4 — secrets and audit

- [ ] server-side secret references only;
- [ ] read/write/elevated backend credentials scoped per tenant;
- [ ] persistent audit/usage telemetry;
- [ ] secret rotation/revocation procedure;
- [ ] tenant-safe logs and metrics.

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
