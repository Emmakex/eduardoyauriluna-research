# Site Architect #475.23 — existing production MCP read-only observation

## Goal

Obtain real Eduardo production **versions, capabilities, page and Media
inventory visibility** using the project's existing OVH Phase 2C MCP
connection, without creating credentials or entering WordPress admin.
This supplements, but does not replace, the native WordPress private
aggregate inventory of #475.22.

## Transport and permissions

The protected workflow
.github/workflows/site-architect-47523-live-readonly.yml uses **only**
the existing phase2d-production environment and its established
PHASE2D_MCP_TOKEN principal (including the original version suffix).
Its endpoint is pinned to:

    https://mcp.kairoseth.com/mcp/eduardo-research

This is the existing Eduardo reference tenant, not an IA Empleado,
Kairoseth website, Emmake or another client's WordPress instance.
The workflow runs on a protected main merge touching this microcut, or
via explicit workflow_dispatch for a later recheck. Secrets do not run
in pull request validation and do not appear in the uploaded report.

The client script has a fixed read-tool list:

- research_status
- research_versions
- research_capabilities
- site_pages_list
- site_navigation_inspect
- site_media_list

No tools ending in plan, apply, rollback, delete, create, upload,
credentials or execution are called. The client never opens a direct
production MySQL connection, invokes WP-CLI remotely, or modifies the
Theme/Plugin. Even if the existing principal is able to write, this
observer **does not execute a write**.

## Evidence security

Authenticated raw Inspector replies and the bearer config are placed in
an isolated temporary directory with umask 077 and removed in all cases
on completion. The token comes from the runner environment, not a
command-line argument, and is explicitly checked against the artifact.

Only scripts/site-architect-47523-sanitize-mcp.mjs may write the
final live-mcp-readback.json artifact. It emits a closed schema with:

- known tool success/unverified flags, runtime Plugin/Theme version,
  Theme contract presence and canonical Eduardo host match;
- a **count** of authenticated scope grants and write scopes
  (not names or connection IDs), a write-capability flag (not authority);
- public/native page and Media list counts and aggregate visibility
  statuses when readable; no titles, IDs, bodies, URLs or Media filenames;
- observed time, exact source commit and explicit remaining gates.

The strict validator rejects extra root keys or nested fields,
secret-like tokens, connection identities, false release certification,
claims of database-complete inventory, and unsupported tool receipts.
Offline tests verify both redaction and fail-closed behavior.

## Allowed outcomes

- READBACK_INCOMPLETE: MCP connection failed, returned an error,
  or lacked minimum coherent version/host data.
- VERSION_DRIFT_REVIEW: Remote Site Architect version is not the
  tested 0.11.1. **Do not auto-upgrade or overwrite.**
- MCP_VERSION_CAPABILITIES_ONLY: Real remote status, version and
  capability endpoints agree, but bounded pages/Media list counts are
  incomplete or inaccessible.
- MCP_PRIVATE_READBACK_OBSERVED: Actual remote versions/capabilities,
  Eduardos production hostname, list totals for Pages and Media were
  observed via the existing MCP. This still **does not** attest a
  complete MySQL census, source entrypoint SHA parity, every REST scope,
  design pixel fidelity or fully reversible production cutover.

A green GitHub Actions job means its requests and report sanitizer ran
as designed, **not** that the release outcome is passing or the
production Theme is fully governable.

If the environment requires a GitHub deployment approval or the
existing principal has expired, the job must remain blocked or produce
incomplete evidence. Never expand scopes, rotate credentials or
silently substitute an alternate tenant/host to make the report green.

## Next decision

Compare the obtained live MCP version/contract and inventories against
the #475.22 isolated native WordPress report and #475.21 public HTTP
survey. A native private readback from actual Eduardo WordPress is
still needed for DB-level provenance. Preserve the live Site Architect
0.11.1 installation until all release gates are independently green.
