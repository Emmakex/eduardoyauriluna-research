# Phase 2C — live OVH deployment handoff

> **Status: COMPLETED — 2026-10-18.** The procedure below is retained as the production handoff record. Final acceptance and credential-hygiene evidence is recorded in `docs/61-phase-2c-production-closeout.md`.

## Purpose

This is the final production handoff for the commercial Research Manager MCP. Repository implementation, container hardening, persistent telemetry, remote acceptance runner, immutable publication and rollback automation are complete. Public HTTPS ingress is owned by the shared Kairoseth edge on `kairoseth-devops-01`; this MCP stack does not publish host ports 80/443 and does not deploy its own Caddy container.

Do not place raw Manager credentials, outer MCP bearer tokens, SSH private keys or tenant secret values in Git, issues, workflow inputs, Compose files or acceptance artifacts.

## Accepted immutable release

The original Phase 2C release contract remains machine-readable in `deploy/phase2c-release.json`.

- MCP source commit: `319ba800c612b758cd58b8a344496df93d96b2dc`
- MCP image: `ghcr.io/emmakex/site-control-mcp@sha256:e55f801dd6ee0c41a38c66e164e7206738f235dd2632cb15f5c24f05bf241144`
- MCP publish workflow run: `39055314467`
- MCP image-reference artifact: `12108593890`
- Accepted Caddy baseline: `2.11.7-alpine`
- Accepted Caddy image: `caddy@sha256:d8542f48d34a9cf4e4c11a478865229840e87e4c96ea3f439101f31a5d35f75f`
- Reference tenant: `eduardo-research`
- Expected MCP catalog: `96` tools

The Caddy digest remains useful provenance and is also the pinned baseline selected for the shared Kairoseth edge. Caddy lifecycle is owned centrally by `kairoseth-platform`, not by this deployment workflow.

## 1. Public hostname and DNS

Production hostname:

```text
mcp.kairoseth.com
```

The production hostname is live through the shared OVH/Kairoseth edge. `MCP_ALLOWED_HOSTS` matches the hostname.

The shared Kairoseth edge publishes only `/healthz`, `/mcp`, and `/mcp/*` for this hostname. All other paths return `404`.

## 2. GitHub `production` environment secrets

Deployment SSH secrets remain in the protected `production` environment as required by the deploy workflow.

The temporary `MCP_ACCEPTANCE_TOKEN` used by Phase 2C acceptance has been removed after successful credential cutover and revocation proof. It must not be recreated as a normal production credential.

The workflow never disables SSH host verification and never writes secret values into acceptance evidence.

## 3. Server-side `/opt/site-control-mcp/.env.production`

The file exists directly on the OVH host with private permissions and remains outside Git.

Required non-secret posture:

```text
MCP_SHARED_ROUTING=true
MCP_REQUIRE_PERSISTENT_TELEMETRY=true
MCP_TELEMETRY_PATH=/var/lib/site-control-mcp/events.jsonl
MCP_ALLOWED_HOSTS=mcp.kairoseth.com
MCP_TENANTS_JSON=<tenant registry containing secret references, never secret values>
MCP_PRINCIPALS_JSON=<principal registry containing bearer secret references, never bearer values>
```

The same environment file provides the referenced secret variables themselves. The Eduardo reference tenant keeps normal Manager read/write credentials separate and least privilege.

The temporary Phase 2C isolation tenant and acceptance principal were removed after acceptance. Normal production outer access now uses the dedicated `research-ops` principal.

## 4. Host prerequisites

The accepted OVH host provides:

- Docker Engine and `docker compose`;
- `/opt/site-control-mcp` deployment directory;
- outbound access required for GHCR pulls;
- external Docker network `kairoseth-public-edge`;
- shared `kairoseth-edge-caddy` owning host ports 80/443;
- shared-edge route `mcp.kairoseth.com -> kairoseth-mcp-runtime:8787`;
- private `.env.production`.

The MCP runtime joins `kairoseth-public-edge` with stable alias `kairoseth-mcp-runtime` and does not expose a host port.

## 5. Deployment contract

The accepted deployment used the exact immutable MCP image:

`ghcr.io/emmakex/site-control-mcp@sha256:e55f801dd6ee0c41a38c66e164e7206738f235dd2632cb15f5c24f05bf241144`

Reference tenant: `eduardo-research`.

The workflow stages only the non-secret MCP deployment contract. The host keeps `.env.production` and all referenced secrets server-side.

## 6. Automatic production gates — accepted

Production acceptance proved:

1. immutable MCP digest validation;
2. shared `kairoseth-public-edge` Docker network;
3. server-side production posture and private `.env.production` permissions;
4. MCP container health on OVH;
5. public HTTPS `/healthz` with Phase 2C, shared routing and persistent telemetry;
6. outer authentication requirement;
7. MCP protocol handshake;
8. exact bounded catalog `96/96` plus critical A1–A7 tools;
9. safe Eduardo reference-tenant reads through the commercial MCP layer;
10. anonymous tenant non-enumeration;
11. authenticated denial for the temporary second tenant, indistinguishable from an unknown tenant;
12. sanitized acceptance evidence without bearer/Manager secret material.

Primary read/isolation evidence:

- workflow run `39151784425`;
- artifact `phase2c-ovh-acceptance-39151784425`;
- artifact ID `12145835766`;
- ZIP SHA-256 `d7d61f8afc694d2ecfeda8d6dfd6d57711171ccb0e17678afe069031972b0b33`.

## 7. Reversible write acceptance — accepted

Representative and per-domain reversible writes were executed through the deployed MCP rather than repeating the accepted Manager-only baseline from issue #201.

Accepted production domains:

- Insights — PASS;
- Research Objects — PASS;
- Translations — PASS;
- Evidence — PASS;
- Media — PASS;
- Navigation — PASS with exact baseline restoration;
- Design — PASS;
- SEO/GEO — `SKIP_LEGITIMATE`, because production exposed no naturally auto-remediable finding and no defect was fabricated for acceptance.

Navigation proof retained exact baseline restoration:

- plan `erm-nav-plan-b1b4b2dfba8f6b3d75-03f95ff7`;
- operation `erm-nav-op-f00674dbd54643338b408794eef7a8df`;
- baseline and final revision `fcec9b6e5c65894525e368c699730b6b14407b26e97039a0069dfd3e090e14ff`;
- `baseline_restored=true`.

SEO/GEO conditional gate:

- workflow run `39158535685`, attempt 2;
- job `117767394769`;
- artifact ID `12147901823`;
- result `SKIP_LEGITIMATE`.

## 8. Credential revocation closeout — accepted

The closeout procedure completed successfully:

1. normal production Manager READ and WRITE credentials were validated before cutover;
2. temporary outer `phase2c-acceptance` was replaced by `research-ops`;
3. the startup-resolved runtime was recreated using the accepted immutable image;
4. old outer bearer was rejected with HTTP `401`;
5. old Manager READ and WRITE credentials were revoked and rejected with HTTP `401`;
6. `research-ops` continued to initialize `eduardo-research` with HTTP `200`;
7. temporary `phase2c-isolation` tenant was removed and the removed tenant failed closed with HTTP `403`;
8. acceptance/isolation secret references were removed;
9. secret-bearing closeout backups were removed;
10. GitHub `production` `MCP_ACCEPTANCE_TOKEN` was deleted; no repository-level copy remained.

## 9. Final status

Phase 2C is complete. Normal production access is now separated from temporary acceptance credentials, the immutable runtime remains healthy, and all acceptance-only credentials/configuration have been retired.

Authoritative final record: `docs/61-phase-2c-production-closeout.md`.
