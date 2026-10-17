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

## Reverse proxy boundary

The example Compose file publishes the container only on host loopback (`127.0.0.1`). Put the HTTPS reverse proxy/ingress in front of that listener. Configure `MCP_ALLOWED_HOSTS` and `MCP_ALLOWED_ORIGIN_HOSTS` for the public bridge hostname before exposing the service.

`GET /healthz` is intentionally unauthenticated and contains no Manager credential. It is used by the container healthcheck and deployment smoke tests.

## Deployment and rollback

Use immutable image tags or digests. `compose.production.example.yml` requires both `MCP_IMAGE` and `MCP_IMAGE_TAG` so a deployment is explicit.

Deploy:

```bash
MCP_IMAGE=<registry/image> MCP_IMAGE_TAG=<new-tag> docker compose -f compose.production.example.yml up -d
```

Rollback is the same operation with the previously accepted immutable tag:

```bash
MCP_IMAGE=<registry/image> MCP_IMAGE_TAG=<previous-tag> docker compose -f compose.production.example.yml up -d
```

Do not rebuild an old tag during rollback. Keep the previous accepted image available until the new deployment has passed health and MCP acceptance checks.
