# Site Control MCP — commercial core

Commercial MCP control core with `eduardoyauriluna.com` as the first reference tenant and Research Manager as the first bounded adapter.

The product is designed so additional customers can be provisioned without forking the codebase.

## Current surface — v0.9.0 read platform

The current MCP surface is read-only and exposes 22 bounded tools:

- `research_status`, `research_versions`, `research_capabilities` — `site.read`;
- `site_pages_list`, `site_page_inspect` — `pages.read`;
- `site_insights_list`, `site_insight_inspect` — `insights.read`;
- `site_research_objects_list`, `site_research_object_inspect`, `site_research_lines_list`, `site_research_line_inspect` — `research.read`;
- `site_translations_list`, `site_translation_inspect` — `translations.read`;
- `site_evidence_inventory`, `site_evidence_identity`, `site_evidence_list`, `site_evidence_inspect` — `evidence.read`;
- `site_navigation_inspect` — `navigation.read`;
- `site_media_list`, `site_media_inspect`, `site_media_diagnostics`, `site_media_diagnostic_inspect` — `media.read`.

Research Manager remains the first adapter. The MCP caller never supplies a Manager route, backend URL or arbitrary WordPress resource.

### Pages

- `GET /research-manager/v1/pages`;
- `GET /research-manager/v1/pages/{registered-key}?language={enabled-language}`.

### Insights

- `GET /research-manager/v1/insights` with optional `language`;
- `GET /research-manager/v1/insights/{positive-post-id}`.

### Research Objects + Lines

- `GET /research-manager/v1/research-objects` with optional closed `kind` and enabled `language`;
- `GET /research-manager/v1/research-objects/{kind}/{positive-post-id}`;
- `GET /research-manager/v1/research-lines` with optional enabled `language`;
- `GET /research-manager/v1/research-lines/{positive-post-id}`.

Object kinds are closed to `output`, `project`, `software`, `dataset`.

### Translations + Evidence

Translations are closed to structured Research post types and optionally an enabled tenant language. Evidence reads are closed to the supported evidence groups and bounded record IDs. Identity is inspection-only in the current surface; no `identity.write` tool is exposed.

### Navigation

- `GET /research-manager/v1/navigation`.

Commercial permission is `navigation.read`. The Research Manager adapter independently requires its backend navigation read contract (`pages.read`) and only exposes registered Theme navigation targets; arbitrary URLs/menu proxying remain disabled.

### Media

- `GET /research-manager/v1/media` with bounded `page`, `per_page`, `search`, and closed `mime_type` filters;
- `GET /research-manager/v1/media/{positive-attachment-id}`;
- `GET /research-manager/v1/media/diagnostics` with scan limit `1..500`;
- `GET /research-manager/v1/media/diagnostics/{positive-attachment-id}`.

Closed MIME filters: `image`, `audio`, `video`, `pdf`, `application/pdf`. The backend does not expose filesystem paths and the MCP does not expose upload, delete, binary replacement or remediation apply operations in this phase.

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

Legacy configurations default to only `site.read`; every additional module requires explicit commercial activation.

## Multi-tenant and security boundary

- shared routing is opt-in with `MCP_SHARED_ROUTING=true`;
- shared endpoint: `/mcp/{tenant-id}`;
- principal → tenant mapping is deny-by-default;
- backend and principal credentials are server-side secret references;
- rate/usage isolation is `principal × tenant`;
- typed commercial capabilities gate every tool invocation;
- language, kinds, IDs, pagination and module-specific filters are bounded before adapter transport;
- no arbitrary `wp/v2`, SQL, shell, filesystem, backend URL or generic route proxy;
- access and entitlement denials are auditable without token material.

For private/reference deployment:

```bash
export ERM_BASE_URL=https://eduardoyauriluna.com
export ERM_READ_TOKEN='...'
export MCP_TENANT_ID=eduardo-research
export MCP_TENANT_ENTITLEMENTS=site.read,pages.read,insights.read,research.read,translations.read,evidence.read,navigation.read,media.read
npm run dev
```

Health:

```bash
curl -s http://127.0.0.1:8787/healthz
```

## Roadmap

Phase 2B.1 next closes SEO/GEO/design inspection, exact capability mapping and production read acceptance.

Broad writes remain behind:

`Preview/Plan → explicit Apply → Verify → Rollback`

Write credentials remain separate and least-privilege.

See `docs/57-chatgpt-mcp-bridge.md`, `docs/58-commercial-mcp-platform.md` and issue #223.
