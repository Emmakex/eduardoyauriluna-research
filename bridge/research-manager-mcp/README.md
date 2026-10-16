# Site Control MCP — commercial core

Reusable commercial MCP control core. `eduardoyauriluna.com` is the first reference tenant and Research Manager is the first bounded adapter; additional customers are provisioned without forking the codebase.

## Current surface — v0.10.0 read platform

The MCP is read-only and exposes 32 bounded tools:

- `research_status`, `research_versions`, `research_capabilities` — `site.read`;
- Pages — 2 tools under `pages.read`;
- Insights — 2 tools under `insights.read`;
- Research Objects/Lines — 4 tools under `research.read`;
- Translations — 2 tools under `translations.read`;
- Evidence/identity inspection — 4 tools under `evidence.read`;
- Navigation — 1 tool under `navigation.read`;
- Media inventory/diagnostics — 4 tools under `media.read`;
- SEO/GEO diagnostics/indexability/actions/readiness — 7 tools under `seo.read`;
- Design state/variants/readiness — 3 tools under `design.read`.

Every tool is additionally subject to the connected backend's own scope checks. Research Manager SEO/GEO reads require `site.diagnostics + seo.read`; Design requires `design.read`; Navigation currently maps to the Manager's bounded `pages.read` navigation contract.

## SEO/GEO boundary

Read routes are fixed to `/seo-geo/site`, `/seo-geo/resource`, `/seo-geo/resource/actions`, `/seo-geo/indexability`, `/seo-geo/indexability/resource`, `/seo-geo/internal-links`, and `/seo-geo/readiness`.

Managed resource types are closed to `page|insight|line|output|project|software|dataset`. Pages require registered `key + language`; Research records require a positive `postId`. Internal-link scans are bounded to `1..500` and aggregate readiness sampling to `1..5` per type. No arbitrary metadata, HTML or WordPress route editor exists.

## Design boundary

Read routes are fixed to `/design`, `/design/variants`, and `/design/readiness`. Current dimensions include bounded palette, typography, density, hero/section/card/collection variants, accessibility, responsive and reduced-motion readiness. No arbitrary CSS, font URL, HTML, class name or token-name surface exists.

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

Effective permission is:

`tenant entitlement ∩ principal capability ∩ backend authorization`

Shared mode routes through `/mcp/{tenant-id}` with deny-by-default principal→tenant authorization, per-principal/per-tenant usage boundaries, secret references, and non-secret audit events.

No tool accepts arbitrary backend URLs, generic routes, `wp/v2`, SQL, shell, filesystem access, arbitrary uploads or direct DB operations.

For the private/reference deployment, activate only the modules needed by the tenant, for example:

```bash
export ERM_BASE_URL=https://eduardoyauriluna.com
export ERM_READ_TOKEN='...'
export MCP_TENANT_ID=eduardo-research
export MCP_TENANT_ENTITLEMENTS=site.read,pages.read,insights.read,research.read,translations.read,evidence.read,navigation.read,media.read,seo.read,design.read
npm run dev
```

Health endpoint: `/healthz`.

## Next gate

Phase 2B.1 code breadth is complete after this slice. Before writes, the remaining gates are:

1. exact tool→commercial capability→backend scope manifest/CI proof;
2. production acceptance with temporary least-privilege read credentials;
3. revoke the acceptance credentials.

Phase 2B.2 then adds bounded `Preview/Plan → explicit Apply → Verify → Rollback` with separate write/elevated credentials.

See `docs/57-chatgpt-mcp-bridge.md`, `docs/58-commercial-mcp-platform.md` and issue #223.
