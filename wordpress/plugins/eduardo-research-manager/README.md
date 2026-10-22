# Site Architect

**Site Architect** is the Greenfield WordPress architecture and governance control plane used by this repository.

It is designed for sites that we build from zero under a known, deterministic architecture. It is not the Brownfield discovery product; that role belongs to the separate `Emmakex/Site-Manager` project.

## Product model

```text
Client / Site Identity
→ Blueprint
→ Site Profile
→ Theme Contract
→ Site Model
→ Site Architect governance
→ WordPress
```

For `eduardoyauriluna.com`, the current implementation is:

```text
Eduardo Yauri Blueprint
→ Research Profile
→ Eduardo Research Theme Contract
→ Research Site Model
→ Site Architect
→ WordPress production
```

The Research specialization remains intentional. Eduardo-specific identity and content belong to the instance blueprint/site state; reusable Greenfield execution logic must not depend on embedded Eduardo-specific literals when a clean contract boundary exists.

## Greenfield principles

Site Architect assumes that we control the site architecture from the beginning. Therefore it may safely know and enforce:

- the Theme contract;
- the supported pages and structured content slots;
- languages and routing contract;
- Research resources and relationships for the Research Profile;
- bounded Design and component variants;
- navigation targets;
- SEO/GEO surfaces;
- media ownership;
- mutation schemas and capability boundaries.

This deterministic knowledge is a product strength, not a portability defect.

The normal mutation lifecycle remains:

```text
Preview → Apply → Verify → Rollback
```

Material mutations must remain bounded, auditable, stale-state protected and fail-closed.

## Site Architect versus Site Manager

```text
Site Architect / Greenfield
known architecture → build → optimize → govern

Site Manager / Brownfield
unknown existing site → discover → understand → adapt → govern
```

Both products evolve independently. Neither is the predetermined replacement for the other. Reusable patterns may be compared or extracted only after both tracks have meaningful real-world evidence.

## Current production role

Site Architect is the authoritative WordPress governance plugin for `eduardoyauriluna.com` while this repository remains the accepted production control plane.

Current path:

```text
Eduardo
→ ChatGPT
→ GitHub connector
→ GitHub Actions / Command Gateway
→ production MCP (`eduardo-research`)
→ Site Architect
→ WordPress
→ Verify
→ sanitized result
→ ChatGPT
```

## Compatibility identifiers

The product name changed from **Research Manager** to **Site Architect** without performing a destructive technical rename.

The following legacy identifiers are intentionally retained for production compatibility until a dedicated migration is designed and accepted:

- plugin directory `eduardo-research-manager`;
- main file `eduardo-research-manager.php`;
- PHP classes `Eduardo_Research_Manager_*`;
- constants `EDUARDO_RESEARCH_MANAGER_*`;
- WordPress option/meta/transient keys using `eduardo_research_*` or `eduardo_research_manager_*`;
- text domain `eduardo-research-manager`;
- REST namespace `/wp-json/research-manager/v1`;
- request headers and protocol identifiers already used by production integrations;
- the legacy WP-CLI command `research-manager greenfield`.

New human-facing documentation, UI and commands should use **Site Architect**. The canonical WP-CLI command is now:

```text
wp site-architect greenfield
```

The legacy command remains an alias so existing automation does not break.

## Current version

`0.11.0`

The version number is not bumped merely by repository naming work. A release/version change should happen with the next packaged and tested Site Architect build.

## Safety boundaries

Site Architect must not expose:

- arbitrary PHP, JavaScript, SQL, shell or filesystem execution;
- arbitrary REST proxying;
- arbitrary WordPress option/meta mutation;
- arbitrary CSS/class injection;
- arbitrary navigation URLs;
- caller-selected backend routes or MCP tools;
- mutation completion without required verification;
- bypasses around evidence-sensitive Research validation.

The controlled Greenfield architecture is deliberate.
