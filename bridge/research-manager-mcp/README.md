# Site Control MCP — commercial core

Commercial MCP control core with `eduardoyauriluna.com` as the first reference tenant and Research Manager as the first bounded adapter.

The product is designed so additional customers can be provisioned without forking the codebase.

## Current surface — v0.7.0 Pages + Insights + Research read

The current MCP surface is read-only and exposes eleven bounded tools:

- `research_status` — `site.read`;
- `research_versions` — `site.read`;
- `research_capabilities` — `site.read`;
- `site_pages_list` / `site_page_inspect` — `pages.read`;
- `site_insights_list` / `site_insight_inspect` — `insights.read`;
- `site_research_objects_list` / `site_research_object_inspect` — `research.read`;
- `site_research_lines_list` / `site_research_line_inspect` — `research.read`.

Research Manager remains the first adapter. The MCP caller never supplies a Manager route, backend URL or arbitrary WordPress resource.

### Pages

- `GET /research-manager/v1/pages`;
- `GET /research-manager/v1/pages/{registered-key}?language={enabled-language}`.

### Insights

- `GET /research-manager/v1/insights` with optional `language`;
- `GET /research-manager/v1/insights/{positive-post-id}`.

### Research Objects

- `GET /research-manager/v1/research-objects` with optional closed `kind` and enabled `language`;
- `GET /research-manager/v1/research-objects/{kind}/{positive-post-id}`.

The object kind is a closed enum: `output`, `project`, `software`, `dataset`. Unsupported kinds are rejected before transport.

### Research Lines

- `GET /research-manager/v1/research-lines` with optional enabled `language`;
- `GET /research-manager/v1/research-lines/{positive-post-id}`.

Research Manager independently enforces backend `research.read` and its active Research preset/resource contracts.

## Commercial architecture

```text
MCP client / workspace
  → authenticated principal
  → authorized tenant
  → commercial entitlement gate
  → registered adapter
  → bounded customer-side manager/connector
  → customer system
```

Effective permission is always:

`tenant entitlement ∩ principal capability ∩ backend authorization`

Legacy configurations default to only `site.read`; Pages, Insights and Research modules require explicit commercial activation.

## Multi-tenant and security boundary

- shared routing is opt-in with `MCP_SHARED_ROUTING=true`;
- shared endpoint: `/mcp/{tenant-id}`;
- principal → tenant mapping is deny-by-default;
- backend and principal credentials are server-side secret references;
- rate/usage isolation is `principal × tenant`;
- typed commercial capabilities gate every tool invocation;
- language, kind and IDs are bounded before adapter transport;
- no arbitrary `wp/v2`, SQL, shell, filesystem, backend URL or generic route proxy;
- access and entitlement denials are auditable without token material.

For private/reference deployment:

```bash
export ERM_BASE_URL=https://eduardoyauriluna.com
export ERM_READ_TOKEN='...'
export MCP_TENANT_ID=eduardo-research
export MCP_TENANT_ENTITLEMENTS=site.read,pages.read,insights.read,research.read
npm run dev
```

Health:

```bash
curl -s http://127.0.0.1:8787/healthz
```

## Roadmap

Phase 2B.1 continues with translations/evidence, navigation/media and SEO/GEO/design inspection.

Broad writes remain behind:

`Preview → explicit Apply → Verify → Rollback`

Write credentials remain separate and least-privilege.

See `docs/57-chatgpt-mcp-bridge.md`, `docs/58-commercial-mcp-platform.md` and issue #223.
