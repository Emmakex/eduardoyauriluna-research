# Research Manager / Site Manager

Portable WordPress control plane by Emmake / Kairoseth.

## Current version

`0.11.0`

Version 0.11 introduces a compatibility layer that lets the same plugin run safely in two automatic product profiles without renaming the already accepted internal PHP classes or stored option keys.

## Product profiles

### `generic-wordpress`

Activated automatically when the Eduardo Research Theme contract is not present.

This profile is deliberately read-only and portable. It provides:

- local **Tools → Site Manager** onboarding;
- a production credential fixed to `site.read + site.diagnostics`;
- token generation/rotation/revocation with the raw token shown once;
- authenticated `status`, `versions`, `capabilities`, `readiness` and `diagnostics`;
- `GET /wp-json/research-manager/v1/audit/baseline`;
- a portable Site Baseline Audit over WordPress/runtime/theme/plugin/content/indexability/routing/discovery/remote state;
- MCP-side bounded external measurement of DNS, TLS, HTTPS status/redirects, selected security/cache headers, public HTML SEO signals, `robots.txt`, the native WordPress sitemap and basic synthetic HTTP transport timing;
- explicit `not_measured` states for Lighthouse/Core Web Vitals, rendered Axe/WCAG, Search Console, analytics and external search visibility until those dedicated probes/connectors are connected.

Research-only REST routes are removed from the exposed endpoint inventory in this profile. Generic installations do not advertise or permit Research mutations.

### `research-theme`

Activated automatically when the compatible Eduardo Research Theme contract is present.

This keeps the complete accepted Research Manager control plane:

- checksummed `Preview → Apply → Verify → Rollback`;
- Theme-owned Page hydration and bounded slot/state mutation;
- EN/ES Insights and translations;
- Research Lines;
- evidence-aware Publications, Projects, Software and Datasets;
- Navigation, Media, SEO/GEO and Design surfaces;
- HTTP-level rendered frontend verification;
- scoped authenticated remote bridge and audit trail;
- v1 production-accepted mutation lifecycles.

The Site Baseline Audit is also available to Research tenants after the Manager side is upgraded to a version exposing `/audit/baseline`; it uses the same `diagnostics.read` + `site.diagnostics` boundary.

## Remote namespace

`/wp-json/research-manager/v1`

Universal read endpoints:

- `GET /status` — `site.read`;
- `GET /versions` — `site.read`;
- `GET /capabilities` — `site.read`;
- `GET /readiness` — `site.diagnostics`;
- `GET /diagnostics` — `site.diagnostics`;
- `GET /audit/baseline` — `site.diagnostics`.

The generic profile exposes only those routes. The Research profile expands the namespace with the previously accepted bounded Research resources and write lifecycles.

## Authentication and request protection

Remote credentials are site-specific and bound to a local WordPress administrator. The raw bearer token is shown only when generated or rotated; WordPress persists only its password hash and a non-secret prefix.

Authenticated calls require:

- `Authorization: Bearer <token>`;
- `X-Research-Manager-Request-Id`;
- `X-Research-Manager-Nonce`;
- `X-Research-Manager-Timestamp`.

The bridge enforces revocable/rotatable credentials, explicit scopes, local administrator capability validation, HTTPS outside local/development environments, timestamp validity, nonce replay protection, rate limiting, idempotency primitives and bounded audit records without bearer tokens or credential hashes.

## MCP gateway

The shared Site Control MCP remains multi-tenant and deny-by-default:

```text
ChatGPT project → mcp.kairoseth.com/mcp/{tenant-id} → Site Control MCP → scoped Manager credential → WordPress
```

Manager 0.11 adds MCP tool `site_baseline_audit`. The outer MCP principal must have commercial capability `diagnostics.read`; the site Manager credential must include `site.diagnostics`. Diagnostic baseline data never piggybacks on `site_capabilities`, which remains a `site.read` surface.

The external probe accepts no arbitrary target. Its origin comes only from the configured tenant base URL; it probes fixed discovery paths and follows only bounded HTTPS redirects that remain on the configured hostname.

Tenant configuration remains explicit. Each tenant has its own base URL, Manager token secret reference, product profile, languages and commercial entitlements. Principals independently declare which tenants and capabilities they may access.

For `profile=generic-wordpress`, the MCP tool inventory is intentionally limited to:

- `site_status`;
- `site_versions`;
- `site_capabilities`;
- `site_baseline_audit`.

## First portable acceptance target

The first unrelated WordPress acceptance target is `emmake/nuevaweb`. Its installation, connection and operational testing are intentionally performed from the separate ChatGPT project **Nueva web Emmake** so the acceptance does not depend on Research-project conversation context.

Acceptance sequence:

1. install the 0.11.0 ZIP;
2. activate under the unrelated Emmake theme/site;
3. confirm profile `generic-wordpress`;
4. generate the fixed `site.read + site.diagnostics` credential from Tools → Site Manager;
5. register a dedicated MCP tenant with `profile=generic-wordpress`;
6. register a dedicated project principal and verify tenant isolation;
7. confirm `tools/list` exposes exactly the four generic tools;
8. call status/capabilities and `site_baseline_audit` from MCP;
9. verify internal WordPress evidence plus external DNS/TLS/HTTP/HTML/robots/sitemap evidence;
10. persist sanitized acceptance evidence;
11. only then define future generic write modules.

## Safety boundaries

- No arbitrary WordPress REST proxy.
- No arbitrary PHP, SQL, filesystem or shell execution.
- No Research mutation capability on a generic site.
- Existing Research behavior remains backward compatible.
- Site Baseline Audit is read-only.
- Credentials remain scoped, revocable and generation-bound.
- External probes are tenant-bound, same-host, HTTPS-only, timeout-bounded and response-size-bounded.
- Browser CWV/accessibility/Search Console/analytics measurements are never fabricated; unavailable probes remain `not_measured`.
