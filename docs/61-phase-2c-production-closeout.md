# Phase 2C — production closeout

Date: 2026-10-18

Status: **COMPLETED**

This document records the final production closeout for Phase 2C of the commercial Research Manager MCP.

## Accepted production runtime

- Public MCP: `https://mcp.kairoseth.com`
- Reference tenant: `eduardo-research`
- Research Theme: `1.0.0`
- Research Manager: `0.10.6`
- Bounded MCP catalog: `96` tools
- MCP image: `ghcr.io/emmakex/site-control-mcp@sha256:e55f801dd6ee0c41a38c66e164e7206738f235dd2632cb15f5c24f05bf241144`
- Shared routing: enabled
- Persistent telemetry: enabled
- Public HTTPS health: accepted

## Production acceptance

The deployed commercial MCP accepted the full Phase 2C operating surface without repeating the already-accepted Manager-only baseline from issue #201.

Accepted MCP production domains:

- Insights — PASS
- Research Objects — PASS
- Translations — PASS
- Evidence — PASS
- Media — PASS
- Navigation — PASS, including exact baseline restoration
- Design — PASS
- SEO/GEO — `SKIP_LEGITIMATE`; production exposed no naturally auto-remediable finding, so no defect was fabricated solely for acceptance

The live read/isolation gate also proved:

- public `/healthz` through the shared Kairoseth edge;
- outer authentication requirement;
- MCP protocol handshake;
- exact bounded catalog `96/96`;
- safe Eduardo reference-tenant reads;
- deny-by-default second-tenant isolation and non-enumeration.

## Recorded evidence

### Read/isolation

- Workflow run: `39151784425`
- Acceptance artifact: `phase2c-ovh-acceptance-39151784425`
- Artifact ID: `12145835766`
- ZIP SHA-256: `d7d61f8afc694d2ecfeda8d6dfd6d57711171ccb0e17678afe069031972b0b33`

### Navigation reversible write

- Plan: `erm-nav-plan-b1b4b2dfba8f6b3d75-03f95ff7`
- Apply operation: `erm-nav-op-f00674dbd54643338b408794eef7a8df`
- Rendered Verify: PASS
- Rollback: PASS
- Baseline revision: `fcec9b6e5c65894525e368c699730b6b14407b26e97039a0069dfd3e090e14ff`
- Final revision: `fcec9b6e5c65894525e368c699730b6b14407b26e97039a0069dfd3e090e14ff`
- `baseline_restored=true`

### SEO/GEO conditional production gate

- Workflow run: `39158535685`, attempt 2
- Job: `117767394769`
- Result: `SKIP_LEGITIMATE`
- Artifact: `phase2c-mcp-seo-acceptance-39158535685`
- Artifact ID: `12147901823`
- ZIP SHA-256: `c57aac6ad409831a74ff8729110493821c1bb936086daabcd3b58b8218e21f8f`

## Production credential cutover

The temporary Phase 2C acceptance posture was replaced by the normal production posture:

- outer principal `phase2c-acceptance` replaced by `research-ops`;
- new normal production Manager READ credential validated before cutover;
- new normal production Manager WRITE credential validated before cutover;
- runtime recreated using the accepted immutable image;
- old outer acceptance bearer rejected with HTTP `401`;
- new `research-ops` bearer initialized `eduardo-research` with HTTP `200`.

## Credential revocation and hygiene proof

Final closeout proved all of the following after revoking the old acceptance Manager sessions:

- old Manager READ credential rejected: HTTP `401`;
- old Manager WRITE credential rejected: HTTP `401`;
- old outer acceptance bearer rejected: HTTP `401`;
- `research-ops` Eduardo initialize: HTTP `200`;
- runtime recreate after isolation cleanup: PASS;
- public health after recreate: PASS;
- `research-ops` Eduardo initialize after cleanup: HTTP `200`;
- removed isolation tenant fails closed: HTTP `403`;
- `phase2c-acceptance` principal absent;
- `phase2c-isolation` tenant absent;
- acceptance/isolation secret references absent;
- secret-bearing closeout backups removed;
- GitHub `production` environment secret `MCP_ACCEPTANCE_TOKEN` removed;
- no repository-level `MCP_ACCEPTANCE_TOKEN` remained.

## Final architecture state

Phase 2C closes with one bounded MCP product/runtime, one immutable production image, authenticated HTTPS, tenant/principal authorization, server-side Manager credentials, least-privilege read/write separation, persistent telemetry, reversible bounded writes, and no generic WordPress REST/SQL/shell/filesystem control surface.

`docs/59-phase-2c-full-bounded-write-and-ovh.md` remains the implementation plan and `docs/60-phase-2c-live-ovh-handoff.md` remains the deployment handoff. This document is the authoritative final closeout record.
