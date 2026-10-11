# Production operating playbook

## Purpose

This document defines the normal production workflow for `eduardoyauriluna.com` after M1–M8 acceptance.

Operating chain:

`Eduardo → ChatGPT/operator → scoped Research Manager → WordPress / Research Theme → rendered verification`

The authenticated Research Manager is the normal remote mutation gateway. WordPress Admin remains a local bootstrap/security surface. Normal operation does not require generic WordPress REST writes, SSH, direct database edits, arbitrary PHP/SQL/filesystem access, or password sharing.

## Editorial cadence

Default cadence: **one bilingual Research Note per week**.

A Research Note is editorial/research-direction content. It must not be presented as a completed scientific result unless evidence exists for that claim.

Recommended rotation across the current Research Lines:

1. Autonomous AI agents for business processes.
2. Human–AI collaboration and responsible autonomy.
3. Digital transformation and AI adoption in SMEs.
4. AI-mediated information discovery and generative search.

The rotation is a planning device, not a requirement to publish unsupported claims.

## Bilingual Insight workflow

For every new EN/ES Research Note:

1. Inventory existing Insights first to avoid duplication.
2. Create EN and ES records as `draft`.
3. Read back title, excerpt and content to catch encoding/editorial issues.
4. Attach each language version to a verified Research Line in the same language.
5. Verify the relation operation.
6. Publish EN and ES with separate bounded `insight-update` operations.
7. Pair the two published Insights through the bounded Insight pairing lifecycle.
8. Verify stored and rendered state.
9. Run per-Insight SEO/GEO inspection.
10. Require HTTP 200, canonical, HTML language, hreflang, `og:url`, JSON-LD and expected `Article` schema checks to pass.
11. Re-run aggregate SEO/GEO readiness.
12. Confirm both URLs are discoverable through the public sitemap and are not blocked by robots or `X-Robots-Tag: noindex`.

Do not continue past a failed Apply/Verify by repeating the mutation blindly. Inspect the exact failure first.

## Research objects and evidence

Research Outputs, Projects, Software and Datasets are created only when real supporting evidence exists.

Research Lines may describe an active or planned research agenda, but the site must distinguish clearly between:

- research direction or planned work;
- work in progress;
- completed and verified outputs;
- peer-reviewed or externally validated results.

Do not invent publications, affiliations, projects, datasets, software artefacts, identifiers or completed findings to fill a content slot.

## Discovery acceptance after structural changes

After adding a new public Insight or structured research entity:

- aggregate SEO/GEO readiness remains `ready=true` and `optimization_complete=true`;
- blocking and advisory counts remain zero unless a documented exception is accepted;
- the public resource returns HTTP 200;
- canonical and hreflang are correct;
- expected schema is present;
- the resource appears in the active WordPress sitemap;
- robots rules do not globally block crawling;
- no unexpected `noindex` response header is present.

## Minimum scope profiles

Use the smallest practical scope set for the session. Add `design.read` when running the current full production preflight because that harness reads design state/readiness.

### Page/content session

- `site.read`
- `site.diagnostics`
- `pages.read`
- `pages.write`
- `operations.apply`
- `operations.rollback`

Add `seo.read` / `seo.write` only when an SEO mutation is required.

### Editorial Insight session

- `site.read`
- `site.diagnostics`
- `insights.read`
- `insights.write`
- `translations.read`
- `translations.write`
- `operations.apply`
- `operations.rollback`

### Research-object session

- `site.read`
- `site.diagnostics`
- `research.read`
- `research.write`
- `evidence.read`
- `evidence.write`
- `translations.read`
- `translations.write`
- `operations.apply`
- `operations.rollback`

### Design session

- `site.read`
- `site.diagnostics`
- `design.read`
- `design.write`
- `operations.apply`
- `operations.rollback`

### Full Phase 2 preflight profile

The accepted Phase 2 production scope set is:

- `site.read`
- `site.diagnostics`
- `pages.read`
- `pages.write`
- `insights.read`
- `insights.write`
- `research.read`
- `research.write`
- `evidence.read`
- `evidence.write`
- `translations.read`
- `translations.write`
- `seo.read`
- `seo.write`
- `design.read`
- `operations.apply`
- `operations.rollback`

Keep `identity.write`, `credentials.manage`, `connections.write`, `media.write`, `design.write`, `destructive_delete.execute`, `external_publish.execute` and `software_update.execute` disabled by default. `design.write` is enabled only for a deliberate bounded design session.

## Capability contract and drift audit

The Manager source currently exposes this supported scope registry:

- `site.read`, `site.diagnostics`
- `pages.read`, `pages.write`
- `insights.read`, `insights.write`
- `research.read`, `research.write`
- `evidence.read`, `evidence.write`
- `translations.read`, `translations.write`
- `seo.read`, `seo.write`
- `media.read`, `media.write`
- `connections.read`, `connections.write`
- `design.read`, `design.write`
- `operations.apply`, `operations.rollback`
- `identity.write`, `credentials.manage`
- `external_publish.execute`, `software_update.execute`, `destructive_delete.execute`

Validate production against the live authenticated `/capabilities` response with:

```bash
export ERM_BASE_URL='https://eduardoyauriluna.com'
php scripts/p2-capabilities-audit.php \
  --profile=phase2 \
  --evidence=tmp/p2-capabilities-audit.json
```

The audit must pass before considering the operating-hardening scope contract accepted. It checks:

- the live supported-scope registry matches the source contract;
- all scopes required by the selected operating profile are granted;
- dangerous-by-default scopes are not granted;
- `/capabilities`, `/readiness` and `/diagnostics` expose their expected base scope contract;
- mutation transport still declares exact Plan → Apply → Status → Verify → Rollback, stale-revision protection and idempotency.

The script reads `ERM_TOKEN` only from the local environment and never writes the bearer credential to evidence.

## Credential handling

- Keep the production bearer token only in the local environment as `ERM_TOKEN`.
- Never paste the bearer token into chat, commits, config files, evidence files or issue comments.
- Revoke and replace the credential after any exposure risk.
- Rotate production credentials periodically.
- Prefer short-lived operational sessions and least privilege.

## Acceptance trail

For each production mutation retain the local Plan / Apply / Verify evidence under `tmp/` or the established ignored evidence path.

Record meaningful Phase 2 checkpoints in GitHub issue #128 without secrets.

The audit trail should allow an operator to correlate:

- intent/reason;
- plan ID;
- operation ID;
- stored verification;
- rendered verification;
- final discovery/SEO state.

## Current production baseline — 2026-10-11

- Phase 2 production credential and preflight accepted.
- EN/ES Home and About/Profile content baseline established.
- Four bilingual Research Lines are published and paired.
- No Research Output, Project, Software or Dataset is published without supporting evidence.
- First bilingual Research Note is published as Insights #41 (EN) and #42 (ES), related to Research Lines #29 and #30 and paired EN/ES.
- Per-Insight rendered SEO/GEO verification is green, including canonical, hreflang, JSON-LD and `Article` schema.
- Aggregate SEO/GEO readiness is green with zero blocking and zero advisory findings.
- `robots.txt` is crawlable, `wp-sitemap.xml` is active, and both first Insight URLs are present in the public sitemap.
- `scripts/p2-capabilities-audit.php` provides the live least-privilege capability drift check required to finish P2.6 acceptance.
