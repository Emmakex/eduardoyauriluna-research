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

- [ ] expose bounded Insight create/draft plan;
- [ ] expose bounded Insight edit/update plan;
- [ ] expose Research relationships;
- [ ] expose EN/ES structured pairing where supported;
- [ ] expose scheduling/status lifecycle;
- [ ] exact Apply/Verify/Rollback;
- [ ] least-privilege credential mapping;
- [ ] local tests/CI;
- [ ] reversible production acceptance;
- [ ] credential revocation proof.

### A2 — Research objects

- [ ] Research Lines lifecycle;
- [ ] Outputs/Publications lifecycle;
- [ ] Projects lifecycle;
- [ ] Research Software lifecycle;
- [ ] Datasets lifecycle;
- [ ] relationships and verified Research-Line constraints;
- [ ] exact Apply/Verify/Rollback;
- [ ] local tests/CI;
- [ ] representative reversible production acceptance.

### A3 — Translations + Evidence

- [ ] structured translation pair/unpair lifecycle;
- [ ] bounded evidence create/update/delete where permitted;
- [ ] evidence/provenance preconditions preserved;
- [ ] identity-sensitive actions remain elevated and separately permissioned;
- [ ] local tests/CI;
- [ ] reversible production acceptance.

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
