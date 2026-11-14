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


## #475.24 — Finding protected push runs without publishing private read data

The GitHub integration can retrieve ordinary PR CI, GitHub commit statuses,
and the jobs/artifacts of a **known** run ID, but it does not directly list
the protected \`main\` push-triggered workflow runs. This is an observability
gap, not evidence that the previously configured MCP principal is broken.

The existing protected workflow now has a tiny independent **report-pending**
job using the GitHub-provided token and GitHub Status API. It posts the exact
\`main\` SHA context \`site-architect/eduardo-mcp-readback\` with state
\`pending\` and its own Actions run URL. This job has **no access to the MCP
environment secret**. While GitHub environment approval is waiting, the
status remains \`pending\`, never "passed".

An **observed-production** job still uses the existing protected
\`phase2d-production\` environment, its unchanged existing principal, and
the six read-only MCP operations. No write operations or WordPress access
are added.

Finally a separate **report-outcome** job obtains only the sanitized
\`live-mcp-readback.json\` from the same run, validates its closed schema
and posts one of four coarse commit statuses:
- \`success\`: an actual coherent MCP read of versions + Pages/Media
  lists occurred, explicitly **not** full DB or cutover certification;
- \`failure\`: incomplete read, version drift or only partial private data;
- \`error\`: protected job failed/cancelled, missing or invalid evidence;
- \`pending\`: protected job has not reported yet or needs approval.

The status carries a direct known GitHub Actions run URL, so authorized
GitHub tools can inspect that run and its **redacted-only artifact** without
guessing run IDs or exporting credentials. The reporter's token has only
\`contents:read\` and GitHub \`statuses:write\` scoped to this repository,
never new WordPress/MCP scopes. New offline tests reject fabricated cutover
certification and untrusted status data.

**Important:** the first **verifiable live observation** is the latest
\`main\` commit status after #475.24 merges. Until that status is observed,
private production Site Architect version, actual read access and resource
counts remain unconfirmed. If the workflow is pending on protected-environment
approval, request approval through the existing GitHub control; do not
reuse another tenant or modify permissions.
