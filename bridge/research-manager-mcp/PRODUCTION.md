# Research Manager MCP production image

The production artifact is one immutable container image for both supported deployment modes. Customer-specific source forks are not part of the operating model.

## Build

From the repository root:

```bash
docker build -t research-manager-mcp:<immutable-tag> bridge/research-manager-mcp
```

The build stage runs TypeScript typecheck, the complete unit test suite and the production build before pruning development dependencies. The runtime stage runs as UID/GID `10001`, exposes port `8787`, and contains only the compiled application plus production dependencies.

## Runtime modes

### Private / single tenant

Use the legacy single-tenant environment contract (`ERM_BASE_URL`, `ERM_READ_TOKEN`, optional `ERM_WRITE_TOKEN` / `ERM_ELEVATED_TOKEN`, tenant metadata and entitlements). Because the container listens on `0.0.0.0`, `MCP_BEARER_TOKEN` is required by the application in this mode.

### Shared multi-tenant

Set `MCP_SHARED_ROUTING=true`, provide `MCP_TENANTS_JSON` and `MCP_PRINCIPALS_JSON`, and provide all referenced Manager/principal secrets as separate environment variables. The same image serves `/mcp/{tenant-id}` and keeps tenant/principal authorization deny-by-default.

No secret belongs in the image, Compose file, tenant registry JSON or source repository.

## Persistent telemetry

Managed production must configure:

```text
MCP_TELEMETRY_PATH=/var/lib/site-control-mcp/events.jsonl
MCP_REQUIRE_PERSISTENT_TELEMETRY=true
```

Both production Compose examples mount `/var/lib/site-control-mcp` on a dedicated persistent volume while the rest of the application filesystem remains read-only. The container image provisions that mountpoint for UID/GID `10001`.

The service appends correlated access, entitlement and principal × tenant usage data as JSONL without bearer or Research Manager credential material. When persistent telemetry is required, the service fails startup without a configured/writable destination and refuses to continue an allowed request if a telemetry append later fails.

See `OPERATIONS.md` for retention, credential rotation and revocation procedures.

## Reverse proxy boundary

The direct production Compose example publishes the container only on host loopback (`127.0.0.1`) and remains useful for isolated/local operation.

For the Kairoseth managed OVH topology use `compose.ingress.example.yml`. Despite the historical filename, this file now deploys **only the MCP runtime** and joins it to the external Docker network `kairoseth-public-edge` with stable alias `kairoseth-mcp-runtime`. It does not publish host ports and does not deploy its own Caddy container.

Public TLS and routing are owned centrally by the Kairoseth shared edge. The public contract for `mcp.kairoseth.com` exposes only:

```text
GET /healthz
*   /mcp
*   /mcp/*
```

All other paths return `404` at the shared edge.

Configure `MCP_ALLOWED_HOSTS` and `MCP_ALLOWED_ORIGIN_HOSTS` for the public bridge hostname before exposing the service.

`GET /healthz` is intentionally unauthenticated and contains no Manager credential. It reports whether persistent telemetry is active and is used by the container healthcheck and deployment smoke tests.

The external network must exist before deploying:

```bash
docker network inspect kairoseth-public-edge >/dev/null
```

## Deployment and rollback

Use immutable image tags or digests. `compose.production.example.yml` and `compose.ingress.example.yml` require explicit accepted image inputs so a deployment is reproducible.

The Phase 2C OVH deployment workflow and `scripts/phase2c-ovh-deploy.sh` preserve immutable-digest deployment, health validation and automatic rollback. They no longer create or manage a service-specific public ingress; the Kairoseth shared edge must already be running.

For direct/local deployment:

```bash
MCP_IMAGE_REF=<registry/image@sha256:digest> docker compose -f compose.production.example.yml up -d
```

Rollback is the same operation with the previously accepted immutable digest. Do not rebuild an old tag during rollback. Keep the previous accepted image available until the new deployment has passed health, telemetry and MCP acceptance checks. Preserve the telemetry volume across application image rollback unless the incident procedure explicitly requires otherwise.
