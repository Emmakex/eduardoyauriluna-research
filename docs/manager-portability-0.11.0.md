# Manager 0.11.0 — Portable WordPress control plane

## Goal

Make the existing Research Manager installable on a normal WordPress site without the Eduardo Research Theme while preserving all accepted Research behavior when that Theme is present.

The first external acceptance target is `emmake/nuevaweb`, operated from the separate ChatGPT project **Nueva web Emmake**.

## Product profiles

### `generic-wordpress`

Available on any supported WordPress installation. It exposes only universal, bounded capabilities:

- authenticated remote status/version/capability discovery;
- read-only site diagnostics;
- read-only Site Baseline Audit;
- remote credential issue/rotation/revocation and audit trail;
- MCP tenant registration through the existing shared gateway.

Research-specific mutations must not be advertised as available.

### `research-theme`

Activated automatically when the Eduardo Research Theme contract is present and compatible. It keeps the complete v1 Research Manager capability set and all previously accepted Plan → Apply → Verify → Rollback lifecycles.

## Compatibility rule

The existing `Eduardo_Research_Manager_*` PHP class names and stored option keys remain stable in 0.11.x. Product portability is introduced as a compatibility layer; no risky namespace migration is part of this release.

## Site Baseline Audit v1

The generic baseline is read-only and returns bounded evidence grouped into:

1. WordPress/runtime and environment;
2. active theme and plugin inventory summaries;
3. HTTPS/canonical site URLs and indexability setting;
4. front-page and permalink configuration;
5. content inventory by public post type/status;
6. REST availability and native sitemap declaration;
7. basic SEO/GEO signals that can be established safely from WordPress state;
8. remote connection/security state;
9. profile-specific capability availability.

Network-heavy Lighthouse, Core Web Vitals, Axe/WCAG, DNS/TLS/header crawling, Search Console and analytics remain external-observability adapters and are reported as `not_measured` until those probes are connected. The Manager must never fabricate these metrics.

## External onboarding acceptance

For `emmake/nuevaweb` the acceptance sequence is:

1. install ZIP;
2. activate plugin successfully under an unrelated theme;
3. detect `generic-wordpress` profile;
4. create a least-privilege `site.read + site.diagnostics` credential;
5. add a new MCP tenant entry for the site;
6. confirm `/mcp/{tenant}` isolation;
7. call status, capabilities and baseline audit from the MCP gateway;
8. confirm no Research write capability is advertised;
9. persist sanitized acceptance evidence;
10. only after read-only acceptance, decide which generic write modules should be added.

## Safety invariants

- No arbitrary WordPress REST proxy.
- No arbitrary PHP, SQL, shell or filesystem execution.
- No Research resource mutation on a generic site.
- Existing Research tenant behavior remains backward compatible.
- Audit is read-only and requires `site.diagnostics`.
- Credentials remain site-specific, scoped, revocable and generation-bound.
