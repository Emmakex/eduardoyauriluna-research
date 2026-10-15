# Research Manager MCP Bridge

MCP bridge for the single-site Research Manager that controls `eduardoyauriluna.com`.

## Phase 1

This first slice is deliberately read-only. It exposes exactly three MCP tools:

- `research_status`
- `research_versions`
- `research_capabilities`

There is no arbitrary REST/path proxy. The bridge only calls the fixed Research Manager v1 routes `/status`, `/versions`, and `/capabilities`.

## Runtime

Requirements:

- Node.js 22+
- a least-privilege Research Manager credential with `site.read`

Install and run:

```bash
cd bridge/research-manager-mcp
npm install
export ERM_BASE_URL=https://eduardoyauriluna.com
export ERM_READ_TOKEN='...'
npm run dev
```

Health check:

```bash
curl -s http://127.0.0.1:8787/healthz
```

MCP endpoint:

```text
http://127.0.0.1:8787/mcp
```

Use MCP Inspector against that Streamable HTTP endpoint for local protocol/tool smoke testing.

## Security boundary

- `ERM_READ_TOKEN` is server-side only and must never be sent to an MCP client.
- The bridge generates the Research Manager request ID, nonce, timestamp, and bearer header itself.
- The client surface is an explicit tool allowlist; there is no caller-controlled Manager route.
- Loopback is the default bind address.
- Binding outside loopback requires a distinct `MCP_BEARER_TOKEN`.
- `MCP_ALLOWED_HOSTS` constrains Host headers.
- Browser-origin requests are rejected unless their hostname is explicitly listed in `MCP_ALLOWED_ORIGIN_HOSTS`.
- Error objects expose typed status/code/request IDs but do not echo Manager credentials.

For a private/on-premises deployment, prefer Secure MCP Tunnel rather than exposing this listener directly. For a public remote MCP deployment, put the service behind HTTPS and a production authentication layer; static bearer protection is only the Phase 1 bridge boundary, not the final write-capable authentication design.

## Write roadmap

Write tools are intentionally not part of 0.1.0. Phase 2 will add bounded Manager workflows while preserving the accepted Manager semantics:

`Preview → explicit Apply → Verify → Rollback`

Write credentials will be separate from the read credential and scoped only to the tool groups actually enabled.

See `docs/57-chatgpt-mcp-bridge.md` and issue #223.
