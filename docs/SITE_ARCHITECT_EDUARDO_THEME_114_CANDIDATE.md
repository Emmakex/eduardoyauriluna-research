# Site Architect #475.31 — Eduardo Theme 1.1.4 release candidate

**Scope:** exclusively the governed Research Theme for eduardoyauriluna.com.
Source of truth: issue #475, installed Site Architect 0.11.1.
Do not generalize this Theme or move Site Manager into this repository.

## Why this cut exists

Real #475.29 Chromium evidence recorded 48 viewport visits and 32 screenshots.
There were 22 viewport observations with two recurring WCAG A/AA findings.
#475.30 corrected source for the mobile accessible menu name and small section
number contrast, but the production Theme remained 1.1.3. It would be unsafe
to present changed 1.1.3 source as the already-installed 1.1.3 release.

## Candidate contract

- Source Theme header is **1.1.4**. Production remains **1.1.3**.
- Existing Research Theme Release Quality installs the candidate in a **fresh
  disposable WordPress instance**, tests ownership, UI copy, sitemap and
  academic evidence controls, then builds the Theme ZIP.
- The ZIP is deterministic with sorted, fixed-timestamp files; the workflow
  performs two byte-identical builds, archive integrity, safe-path and secret
  checks, validates the two corrected CSS sources, and exports a SHA-256
  checksum and per-file provenance manifest tied to exact PR head.
- Site Architect 0.11.1 Plugin is **not** packaged, updated or deployed.
- The existing #475.30 Chromium/axe fixture independently verifies the
  Theme CSS source fixes. That is **not** a live site WCAG certification.
- The artifact is explicitly marked **candidate_not_deployed**. Neither a
  successful PR nor merge installs it anywhere.

## Release no-go until production-safe rehearsal

Before an installation, establish the approved live WordPress read-only
inventory (#475.22), review visual screenshots, editorial CV/legal content,
existing MCP mutable authority, and full backup/restore plus reversible
Site Architect-governed deploy/Verify/Rollback plan. Preserve installed
versions and content until these gates are independently evidenced.

After authorized deployment only, rerun the exact 24-route desktop/mobile
public browser observer and inspect the screenshot artifact and a11y findings.
Do not claim the two live issues resolved beforehand.
