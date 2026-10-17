# HTTPS ingress contract

The MCP application container is not exposed directly to the Internet. `compose.ingress.example.yml` places it only on the internal `mcp_backend` Docker network and exposes Caddy on ports 80/443.

## Production prerequisites

Provision one DNS hostname for the MCP service and point its A/AAAA records at the OVH ingress host. Set:

- `MCP_PUBLIC_HOST` to that hostname;
- `MCP_ALLOWED_HOSTS` in `.env.production` to the same hostname;
- `MCP_ALLOWED_ORIGIN_HOSTS` only when browser-origin requests are intentionally supported;
- `MCP_INGRESS_IMAGE` to an immutable Caddy image tag or digest;
- `MCP_IMAGE` / `MCP_IMAGE_TAG` to the accepted immutable MCP application image.

Caddy obtains and renews the public TLS certificate automatically when the hostname resolves publicly and ports 80/443 reach the ingress host. The ingress therefore has a separate egress-capable network for ACME while the MCP application remains reachable only on the internal backend network.

## Exposed paths

The proxy forwards only:

- `GET /healthz`;
- `/mcp` for private/single-tenant deployments;
- `/mcp/{tenant-id}` for shared deployments.

Other paths return `404` at the ingress and never reach the application. Authorization headers are preserved to the MCP application. Proxy buffering is disabled for the MCP upstream so streaming responses are not accumulated by the ingress.

HTTP is redirected to HTTPS by Caddy. Security response headers remove the server banner and add `nosniff`, `no-referrer`, and frame denial.

## Tenant non-enumeration

Shared routing authenticates the principal before disclosing whether a syntactically valid tenant ID exists. Therefore:

- anonymous requests to an existing or nonexistent `/mcp/{tenant-id}` both return the same `401 authentication_failed` response;
- an authenticated principal requesting either an existing-but-ungranted tenant or a nonexistent tenant receives the same `403 tenant_access_denied` response;
- shared `/healthz` does not list tenant IDs.

Knowing a tenant ID is never authorization.

## OVH rollout

Repository CI validates the Caddyfile, builds the production MCP image, exercises TLS locally with Caddy, proves HTTP→HTTPS redirect, verifies the private authenticated endpoint, and repeats the test in shared mode including tenant non-enumeration.

Live OVH acceptance still requires infrastructure-side actions: choose the public hostname, create DNS, install the accepted image/configuration on the OVH host, allow inbound TCP 80/443 (and UDP 443 if HTTP/3 is retained), and verify the externally issued certificate plus remote MCP acceptance. Do not mark the OVH deployment step complete until those live checks pass.
