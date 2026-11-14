# Site Architect 0.11.1 — independent distribution candidate (#475.16)

This packages the **complete, installed Site Architect 0.11.1 codebase** from
`wordpress/plugins/eduardo-research-manager`. It does not create a new engine.

## Boundary: packaged != generalized

The ZIP uses the WordPress technical directory `eduardo-research-manager` to
preserve the existing plugin entry, hooks, options, REST routes, snapshots,
and rollback behavior. Its visible WordPress plugin name remains
**Site Architect**.

The distributable contains the entire real plugin including its Research
vertical and typed Core/Theme seams. It **does not** contain the Eduardo
Theme, site Blueprint, personal content or production credentials.

The manifest distinguishes three explicitly bounded capabilities:

- `research-theme`: the operational Research Theme profile; the existing
  gates still determine which writes are actually permitted.
- `generic-wordpress`: existing read-only portable profile. Do not
  interpret packaging as an authorization to write to arbitrary WordPress sites.
- `custom-greenfield-theme`: **not yet certified**. The Theme contract,
  services and write capabilities must be proven independently.

The production site `eduardoyauriluna.com` remains on the installed 0.11.1
plugin. This ZIP is **candidate only**, not a cutover nor a rename/reinstall
instruction.

## Reproducible build

```bash
python3 scripts/build-site-architect-distribution.py \
  --source-commit "$(git rev-parse HEAD)"
python3 scripts/test-site-architect-distribution.py
```

Outputs are placed in `dist/site-architect/`:

- `site-architect-0.11.1-research-candidate.zip` — complete WordPress plugin
- `.zip.sha256` sidecar — integrity check
- `.manifest.json` — Git SHA, exact per-file SHA-256 inventory and bounded
  supported-profile claims

Source and ZIP are tested byte-for-byte, with deterministic ZIP metadata.
GitHub Actions creates these build artifacts from the exact pull-request
revision, then installs **the generated ZIP**, not the repository source
directory, into isolated WordPress instances to test the Research Theme
profile and the fail-closed generic profile. No site instances are deployed.

## Remaining release gates

1. Complete CI and isolated WordPress ZIP acceptance.
2. Produce an external **Site Architect repository/release** independent of
   this Eduardo-only engineering repository, with the same verified source
   inventory and explicit Research vertical delivery.
3. Verify full Theme Research clone parity against the accepted production
   behavior and safe rollback/cutover, without repeating unnecessary live
   acceptances.
4. Only then approve a production cutover. Second-Theme full governance is
   a later, separate acceptance.

Never claim the candidate is already a fully generic, multi-Theme write engine.
