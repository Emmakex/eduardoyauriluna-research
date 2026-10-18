# Phase 2C — live OVH deployment handoff

## Purpose

This is the final production handoff for the commercial Research Manager MCP. Repository implementation, container hardening, persistent telemetry, remote acceptance runner, immutable publication and rollback automation are complete. Public HTTPS ingress is now owned by the shared Kairoseth edge on `kairoseth-devops-01`; this MCP stack does not publish host ports 80/443 and does not deploy its own Caddy container.

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

The Caddy digest remains useful provenance and is also the pinned baseline selected for the shared Kairoseth edge. Caddy lifecycle is now owned centrally by `kairoseth-platform`, not by this deployment workflow.

## 1. Public hostname and DNS

Production hostname:

```text
mcp.kairoseth.com
```

Create an explicit DNS record pointing this hostname to the OVH ingress host. The production workflow defaults `public_host` to this exact hostname, and `MCP_ALLOWED_HOSTS` in the server-side environment file must match it.

The shared Kairoseth edge publishes only `/healthz`, `/mcp`, and `/mcp/*` for this hostname. All other paths return `404`.

## 2. GitHub `production` environment secrets

Provision these values in the repository's protected `production` environment. Do not pass them as workflow inputs.

- `OVH_MCP_HOST` — SSH host/IP used only by the deployment runner.
- `OVH_MCP_USER` — restricted deployment user.
- `OVH_MCP_SSH_KEY` — private key for that deployment user.
- `OVH_MCP_SSH_KNOWN_HOSTS` — pinned known-host entry used with `StrictHostKeyChecking=yes`.
- `MCP_ACCEPTANCE_TOKEN` — temporary outer principal bearer used by the remote acceptance runner.

The workflow never disables SSH host verification and never writes these values into acceptance evidence.

## 3. Server-side `/opt/site-control-mcp/.env.production`

Create this file directly on the OVH host with mode `0600`. It must remain outside Git. The deploy script fails closed if group/world permissions exist.

Required non-secret posture:

```text
MCP_SHARED_ROUTING=true
MCP_REQUIRE_PERSISTENT_TELEMETRY=true
MCP_TELEMETRY_PATH=/var/lib/site-control-mcp/events.jsonl
MCP_ALLOWED_HOSTS=mcp.kairoseth.com
MCP_TENANTS_JSON=<tenant registry containing secret references, never secret values>
MCP_PRINCIPALS_JSON=<principal registry containing bearer secret references, never bearer values>
```

The same environment file also provides the referenced secret variables themselves. For the Eduardo reference tenant, keep Manager read/write/elevated credentials as separate variables and preserve least privilege. The acceptance principal must be explicitly allowed only for the intended tenant(s) and capabilities.

The live shared acceptance additionally requires a real second tenant ID that exists in the registry but is **not** granted to the acceptance principal. This is used only to prove deny-by-default isolation and non-enumeration.

## 4. Host prerequisites

Before dispatching production deployment, the OVH host must have:

- Docker Engine and `docker compose` available to the deployment user;
- `/opt/site-control-mcp` writable by the deployment path but not broadly writable;
- outbound access for GHCR image pulls;
- the external Docker network `kairoseth-public-edge` already created;
- the shared `kairoseth-edge-caddy` service already owning host ports 80/443;
- a shared-edge route `mcp.kairoseth.com -> kairoseth-mcp-runtime:8787`;
- the private `.env.production` described above.

GHCR access, when needed, must be configured on the host itself. Registry credentials must never be embedded in Compose or workflow inputs.

The MCP runtime joins `kairoseth-public-edge` with stable alias `kairoseth-mcp-runtime`. It does not expose any host port.

## 5. Dispatch contract

Run the `Phase 2C OVH Deploy` workflow with:

- `action`: `deploy`
- `image_ref`: `ghcr.io/emmakex/site-control-mcp@sha256:e55f801dd6ee0c41a38c66e164e7206738f235dd2632cb15f5c24f05bf241144`
- `public_host`: `mcp.kairoseth.com`
- `reference_tenant_id`: `eduardo-research`
- `denied_tenant_id`: the real second tenant not granted to the acceptance principal

There is no longer an `ingress_image_ref` workflow input. Public Caddy is versioned and deployed by the shared Kairoseth edge infrastructure.

The workflow stages only the non-secret MCP deployment contract. The host keeps `.env.production` and all referenced secrets server-side.

## 6. Automatic production gates

A successful deploy must pass all of these before it is accepted:

1. immutable MCP digest validation;
2. existence of the shared `kairoseth-public-edge` Docker network;
3. server-side production posture and private `.env.production` permissions;
4. MCP container health on the OVH host;
5. public HTTPS `/healthz` through the shared edge with Phase 2C, shared routing and persistent telemetry;
6. outer authentication requirement;
7. MCP protocol handshake;
8. exact bounded catalog `96/96` plus critical A1–A7 tools;
9. safe Eduardo reference-tenant reads through the commercial MCP layer;
10. anonymous tenant non-enumeration;
11. authenticated denial for a real second tenant, indistinguishable from an unknown tenant;
12. no configured outer bearer or Manager credential pattern in emitted acceptance evidence.

If public health or remote acceptance fails, the workflow invokes the recorded previous MCP digest and then fails the deployment run. A failed acceptance must not be re-labelled as a successful deploy.

## 7. Reversible write acceptance

After the live read/isolation gate passes, run representative reversible writes **through the deployed MCP**, not directly against Research Manager. Issue #201 is already the accepted Manager backend baseline and must not be repeated as Manager-only tests.

For each representative mutation:

1. inspect/read baseline through MCP;
2. create the bounded plan;
3. apply the exact stored plan with explicit confirmation;
4. verify stored/rendered state as required by the domain;
5. rollback the exact operation;
6. verify baseline restoration;
7. retain sanitized operation/request correlation evidence only.

SEO/GEO mutation remains conditional: do not manufacture a production defect merely to exercise remediation.

## 8. Credential revocation closeout

When remote read/isolation and reversible write acceptance are complete:

1. revoke the temporary outer `MCP_ACCEPTANCE_TOKEN` principal secret;
2. revoke any temporary Manager acceptance credentials created for the live gate;
3. recreate/restart the service because the current environment secret resolver is startup-resolved;
4. prove the old credentials are rejected;
5. record the sanitized revocation result in issue #245;
6. keep normal production credentials separate and least-privilege.

Only after these steps should the remaining Phase 2C production boxes be checked and issue #245 be closed.
