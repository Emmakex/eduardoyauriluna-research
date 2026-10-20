# Generic WordPress → Site Manager → MCP onboarding

This runbook is the portable onboarding path for an unrelated WordPress site.

The first acceptance target is `emmake/nuevaweb`, operated from the separate ChatGPT project **Nueva web Emmake**.

## Identity model

Use one explicit tenant per managed WordPress site and one explicit outer MCP principal per ChatGPT operating context when practical.

First acceptance identities:

```text
tenant:    emmake-nuevaweb
principal: nuevaweb-emmake-chatgpt
endpoint:  https://mcp.kairoseth.com/mcp/emmake-nuevaweb
```

Do not reuse `eduardo-research`, `phase2d-github`, Research acceptance principals, or their bearer credentials.

## 1. Install Manager 0.11.0

Install the CI-produced artifact `research-manager-0.11.0.zip` in WordPress and activate it.

Expected result under an unrelated theme:

```text
Tools → Site Manager
profile = generic-wordpress
```

The Research admin surfaces must not be exposed.

## 2. Create the site credential

In **Tools → Site Manager**, generate the connection token.

The generic onboarding surface fixes the Manager scopes to exactly:

```text
site.read
site.diagnostics
```

Copy the raw token once. WordPress stores only its hash.

For the first Emmake test, store the raw token on the MCP host as:

```text
TENANT_EMMAKE_NUEVAWEB_READ_TOKEN=<raw Manager token>
```

Do not commit it to Git or place it inside tenant-registry JSON.

## 3. Add the MCP tenant

Append this definition to `MCP_TENANTS_JSON`:

```json
{
  "id": "emmake-nuevaweb",
  "label": "Emmake Nueva Web",
  "adapter": "research-manager",
  "baseUrl": "https://REPLACE-WITH-NUEVAWEB-URL",
  "readTokenEnv": "TENANT_EMMAKE_NUEVAWEB_READ_TOKEN",
  "languages": ["es"],
  "entitlements": ["site.read", "diagnostics.read"]
}
```

If the site exposes another language, add it explicitly after validating the site configuration.

## 4. Add the project principal

Create an independent outer MCP bearer and store it on the MCP host as:

```text
PRINCIPAL_NUEVAWEB_EMMAKE_TOKEN=<strong independent bearer>
```

Append this definition to `MCP_PRINCIPALS_JSON`:

```json
{
  "id": "nuevaweb-emmake-chatgpt",
  "bearerTokenEnv": "PRINCIPAL_NUEVAWEB_EMMAKE_TOKEN",
  "tenants": ["emmake-nuevaweb"],
  "capabilities": ["site.read", "diagnostics.read"]
}
```

Effective MCP authorization remains the intersection of tenant entitlements and principal capabilities.

## 5. Deploy/reload MCP

Deploy the accepted immutable MCP image containing the portable tool registration. Preserve persistent telemetry and the shared Kairoseth edge.

No site-specific source fork is required.

## 6. Acceptance from project “Nueva web Emmake”

Connect that project to:

```text
https://mcp.kairoseth.com/mcp/emmake-nuevaweb
```

using only `PRINCIPAL_NUEVAWEB_EMMAKE_TOKEN` as the outer MCP bearer.

Run, in order:

1. `research_status`
2. `research_versions`
3. `research_capabilities`
4. `site_baseline_audit`

Expected capability profile:

```text
product_profile = generic-wordpress
writes_enabled = false
effective_supported_scopes = [site.read, site.diagnostics]
mutation_transport.available = false
```

The baseline must return WordPress-state evidence and mark external DNS/TLS/headers, Lighthouse/CWV, Axe/WCAG, Search Console, analytics and external search visibility as `not_measured` until those probes are connected.

## 7. Tenant-isolation negative test

Using the `nuevaweb-emmake-chatgpt` principal, access to `/mcp/eduardo-research` must be denied.

Using a Research-only principal, access to `/mcp/emmake-nuevaweb` must also be denied unless that tenant was explicitly granted.

## 8. Closeout evidence

Persist only sanitized evidence:

- Manager version/profile;
- tenant ID and principal ID;
- scopes/capabilities (never raw tokens);
- status/capability response summary;
- Site Baseline Audit output;
- tenant-isolation result;
- immutable MCP image digest;
- timestamps and request/operation correlation IDs where available.

Only after this read-only acceptance should generic WordPress write capabilities be designed or enabled.
