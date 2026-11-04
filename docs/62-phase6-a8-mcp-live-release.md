# Phase 6 — A8 legal MCP production release handoff

Status: **READY FOR OPERATOR DISPATCH, NOT DEPLOYED** (2026-11-04).

## Scope

This handoff is exclusively for the Eduardo research website. The Phase 6 legal editor is already installed in live WordPress as Research Manager **0.11.1**, alongside Theme **1.1.3**, but the separately deployed MCP still advertises **96** tools. A8 legal tools are absent and fail closed. The intended Research catalog is **101** tools.

Production facts:
- Gateway readback: `cmd-phase6-versions-readback-20261104-01` → Manager 0.11.1, Theme 1.1.3, compatible research preset v6.
- Gateway A8 discovery: `cmd-phase6-legal-a8-tool-describe-20261104-04` → failed (the required tool is not present).
- Gateway runtime inventory: `cmd-phase6-a8-runtime-inventory-20261104-05` → 96 tools, no `site_legal_content_*` tools.
- No legal content has been written through A8.

## Immutable published image

Publish run: `41336615242`, source commit `585ebc117c88c6b4ca9f3df5e244fea28b8c1abc`.

Exact image reference, retrieved from publish artifact `13095466536`:

```text
ghcr.io/emmakex/site-control-mcp@sha256:994a3f84f6dd984eb7f73bb45c6f349675c326dd77a7e7ceafa18e4968a194f5
```

## Existing credential posture: preserve

The Phase 2C cutover explicitly **revoked** `MCP_ACCEPTANCE_TOKEN` and removed the temporary isolation tenant. Do not recreate either one. The updated `Phase 2C OVH Deploy` workflow now uses the pre-existing `phase2d-production` `PHASE2D_MCP_TOKEN` research-ops principal for read-only acceptance. SSH deployment continues to use the protected `production` environment and strict known-host checks.

## Operator dispatch after exact-head merge certification

Run with an authorized GitHub CLI session (do not paste secrets in command arguments):

```bash
gh workflow run "Phase 2C OVH Deploy" \
  --repo Emmakex/eduardoyauriluna-research \
  -f action=deploy \
  -f image_ref='ghcr.io/emmakex/site-control-mcp@sha256:994a3f84f6dd984eb7f73bb45c6f349675c326dd77a7e7ceafa18e4968a194f5' \
  -f public_host=mcp.kairoseth.com \
  -f reference_tenant_id=eduardo-research
```

An optional `denied_tenant_id` may only reference an **existing, verified** tenant that the research-ops principal cannot access. Never invent or re-provision an acceptance tenant.

## Mandatory production acceptance

- Immutable digest validation and strict SSH identity.
- Healthy Docker container and public HTTPS `/healthz` on the unchanged shared edge.
- Separate phase2d-production job: authenticated MCP handshake, **101** tools including all four `site_legal_content_*`, `research_status`, `research_versions`, `research_capabilities`, bounded read operations, anonymous rejection, unknown-tenant non-enumeration.
- If the external acceptance does not pass, a dedicated protected production job rolls back to the recorded previous digest.
- Never consider an image build, artifact or workflow dispatch to be evidence of successful production deployment. Require terminal success and a new Gateway v2 inventory/readback.

## Next action after acceptance

Only then execute bounded `legal.content.update` operations via Gateway v2 with Preview → Apply → rendered Verify and rollback on failure. Any Privacy text must still be grounded in real public contact details and actual data processing/cookies/providers. Do not fabricate these facts to force a passing audit. Issue #315 Phase 6 remains open until the public Privacy and Legal surfaces pass.
