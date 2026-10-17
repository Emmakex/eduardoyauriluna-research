# Phase 2C — Remote MCP production acceptance

## Purpose

This is the acceptance protocol for the commercial MCP layer after the Research Manager backend has already been accepted in production under issue #201.

Do **not** repeat Manager-only M3–M7 acceptance. The goal here is to prove that the deployed HTTPS MCP runtime preserves the already-accepted Manager contracts through outer authentication, tenant routing, commercial entitlements and the bounded MCP tool surface.

## Preconditions

Before any remote acceptance:

- deploy one immutable accepted MCP image behind the Phase 2C Caddy ingress;
- use HTTPS with a publicly valid certificate;
- enable `MCP_REQUIRE_PERSISTENT_TELEMETRY=true` and a persistent telemetry volume;
- configure the Eduardo reference tenant through server-side secret references;
- create a temporary outer principal bearer scoped only to the acceptance tenant/capabilities;
- for write acceptance, create temporary least-privilege Research Manager write/elevated credentials only for the domains actually exercised;
- keep the existing #201 production baseline as the authoritative backend acceptance evidence.

## Gate 1 — remote read + runtime acceptance

Run from a trusted shell with Node >= 22.19, `jq`, `curl` and network access to the deployed endpoint:

```bash
export MCP_SERVER_URL='https://<public-host>/mcp/eduardo-research'
export MCP_TOKEN='<temporary-outer-principal-bearer>'
export MCP_EXPECTED_ROUTING=shared
export MCP_EVIDENCE_DIR="$PWD/tmp/phase2c-mcp-remote-acceptance"

bash scripts/phase2c-mcp-remote-acceptance.sh
```

The runner must prove:

- public `/healthz` is healthy, Phase 2C, shared routing and persistent telemetry is active;
- health does not expose credentials, principal inventory or tenant inventory;
- anonymous access to the selected tenant returns `401 authentication_failed`;
- MCP initialize succeeds;
- `tools/list` is exactly the accepted 96-tool bounded catalog and contains the critical A1–A7 read/write tools;
- safe `research_status`, `research_versions`, `research_capabilities`, Navigation and rendered Design readiness reads work through the commercial MCP layer;
- emitted evidence does not contain outer bearer or Research Manager credential patterns.

`MCP_SKIP_REFERENCE_READS=true` exists only so repository CI can validate the runner against an isolated contract target. It is **not** valid for production acceptance.

## Gate 2 — shared tenant isolation

Provision a second test tenant/principal boundary before enabling general shared writes. The Eduardo acceptance principal must not be granted that second tenant.

Run Gate 1 with:

```bash
export MCP_DENIED_TENANT_URL='https://<public-host>/mcp/<second-tenant-id>'
bash scripts/phase2c-mcp-remote-acceptance.sh
```

The runner must additionally prove:

- anonymous existing and nonexistent tenant paths return the same `401` body;
- an authenticated Eduardo principal receives the same `403 tenant_access_denied` body for an existing-but-ungranted tenant and a nonexistent tenant;
- knowing a tenant ID never grants access;
- the result evidence records `two_tenant_isolation_checked: true`.

## Gate 3 — representative reversible MCP writes

The Manager backend already has accepted production breadth in #201. Phase 2C write acceptance therefore proves the **MCP transport and authorization layer**, not the underlying business logic again.

Use temporary least-privilege credentials and execute representative reversible lifecycles through the deployed MCP endpoint:

1. inspect the current baseline through the relevant MCP read tool;
2. call the bounded `*_plan` tool with a reversible fixture/registered state;
3. retain the returned Manager `plan_id` as evidence;
4. call the corresponding `*_apply` with the exact plan ID and `confirm:true` only;
5. call `*_verify` (and `rendered:true` where the domain requires rendered verification);
6. call `*_rollback` with the exact operation ID and `confirm:true`;
7. re-read the resource and prove the exact pre-operation baseline is restored;
8. inspect persistent telemetry for the same outer request correlation without secret material.

Never send a replacement mutation payload during Apply. Never use arbitrary URLs, CSS/HTML, WordPress REST routes, filesystem paths, SQL or shell access as part of acceptance.

### Domain policy

- **Insights / Research Objects / Translations / Evidence:** use disposable reversible fixtures already sanctioned by the #201 Manager policy; do not touch verified academic evidence or canonical identity unless an elevated test is explicitly required.
- **SEO/GEO:** execute a live remediation only when current diagnostics naturally expose a legitimate deterministic target. Do not manufacture an SEO defect to satisfy a checkbox.
- **Media:** the underlying Manager ingest/metadata lifecycle is already accepted in #201. Through MCP, use a disposable accepted-format fixture and safe rollback; do not repeat direct Manager acceptance.
- **Navigation:** use only registered Theme targets and restore the exact current navigation baseline.
- **Design:** use only registered presets/variants and restore the exact current design baseline. #201 already accepted palette, typography, density and component-variant behavior at the Manager layer.

## Gate 4 — revocation proof

After all remote acceptance evidence is captured:

1. revoke the temporary Research Manager write/elevated acceptance credentials;
2. replace/revoke the temporary outer MCP principal bearer according to `bridge/research-manager-mcp/OPERATIONS.md`;
3. roll/recreate the MCP container because the current environment-backed secret resolver is startup-resolved;
4. prove the old credentials no longer authorize the relevant path;
5. prove the retained production credentials continue to satisfy health/read acceptance;
6. record only credential labels/IDs and revocation timestamps — never secret values.

## Evidence and completion

Store sanitized acceptance evidence outside the public web root. At minimum retain:

- immutable MCP image tag/digest;
- public hostname and certificate acceptance time;
- `health.json`, `initialize.json`, `tools-list.json`, safe read outputs and `result.json` from the runner;
- representative write plan/operation IDs and Verify/Rollback results;
- baseline restoration proof;
- second-tenant isolation proof;
- temporary credential revocation proof;
- corresponding persistent audit/request-correlation records without bearer material.

Only then close the remaining production boxes in issue #245 and the live OVH deployment item in `docs/59-phase-2c-full-bounded-write-and-ovh.md`.
