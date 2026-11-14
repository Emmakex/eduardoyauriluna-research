# Site Architect #475.22 — private read-only WordPress inventory (Eduardo only)

## What is now measurable

The exact independently rebuilt Site Architect 0.11.1 in the disposable
Research WordPress installation is inspected by
scripts/site-architect-475-private-readonly-inventory.php. Every SELECT runs
inside a MySQL START TRANSACTION READ ONLY boundary; the script issues ROLLBACK
and then writes the redacted JSON evidence to local /tmp only.

The output contains:
- Site Architect product name, version, active state and SHA256 of the installed
  plugin entrypoint file (not the binary or plugin source);
- active Theme slug, version, contract ID/version/compatibility and languages;
- each Theme-owned Page key/language with status, contract alignment and counts
  of allowed versus stored content slots, without content or post IDs;
- aggregate WordPress Page, Research resource, Post and Media counts by status,
  Media counts by image/application/other type and Theme menu item counts;
- safe remote capability posture: aggregate scope counts, active-connection
  booleans and active temporary connection count. Neither scope names nor
  credential IDs, tokens, hashes, users or request details are exported.

The validator scripts/site-architect-475-validate-private-inventory.py
rejects unknown sensitive report fields, credential leaks, missing required
records, incorrect contract, missing plugin, non-read-only DB transaction or
inconsistency with the HTTP clone report. CI also runs offline negative tests.

## Product versus site authority

The plugin source-export acceptance still runs in an isolated clone on CI.
That is proof of the product. To prove the actual WordPress installation,
the report MUST be generated separately in the real production WordPress
runtime, with the origin=production host guard; *a clone report does not
count as production private readback*.

There are no available connected WordPress credentials/authorized WP-CLI
commands in this tool environment. It would be incorrect to claim
production private state has been inspected. The script cannot be run
remotely by publishing a public URL or using the production HTTP crawler.

When authorized WP-CLI access to the real installation exists, a trusted
operator can copy the audited script to a private path, then invoke:

    SA47522_ORIGIN=production \
    SA47522_AUTHORIZE_PRODUCTION_READONLY=1 \
    SA47522_OUTPUT=/tmp/sa-47522-production-private.json \
      wp eval-file /path/to/site-architect-475-private-readonly-inventory.php

Run it against the site's actual WordPress directory and a trusted site
administrator environment. Output remains under /tmp with mode 0600; review
the redacted report and remove it after an authorized secure transfer.
Do not publish the JSON, copy credentials into CI, enable debug endpoints,
create a public WordPress route, or broaden Site Architect permissions.

The script fails closed if the real WordPress home is not exactly
https://eduardoyauriluna.com, if the explicit production switch is missing
or if the database cannot start a read-only transaction. It does not
execute Preview/Apply, change content or media, issue tokens or revoke
existing connections.

## Release status

This microcut proves the *observer* is reproducible and safe in isolated
WordPress. It does not mark private production inspection as complete.
Keep production inventory, visual parity, editorial CV/legal readiness,
separate product repository and reversible cutover all OPEN in
docs/SITE_ARCHITECT_EDUARDO_RELEASE_GATES.md.


## #475.23 — Existing protected production MCP readback, strictly read-only

The repository already has an OVH Phase 2C Eduardo-reference MCP and a
phase2d-production principal used by its accepted deployment workflow.
The new guarded read-only workflow and report contract are detailed in
docs/SITE_ARCHITECT_MCP_READONLY_READBACK.md. Its six fixed existing
read tools require no new WordPress tokens, endpoints or plugin mutation.

**Acceptance split:** offline sanitizer and CI can pass before merge; the
live production MCP result may be observed only after the protected main
push job runs (or a separate authorized workflow_dispatch). Green CI alone
does NOT certify that live access, the installed Plugin version or its
private resource totals match the independent source export.

#475.22 native MySQL private inventory, visual mobile/desktop, doctoral CV
content, legal review, external private source repository and reversible
cutover remain open until separately evidenced. A successful MCP inventory
is not a full underlying WordPress/Media database audit.
