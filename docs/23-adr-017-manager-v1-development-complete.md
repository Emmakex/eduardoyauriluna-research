# ADR-017 — Research Manager v1 development-complete contract

## Status

**Proposed by PR #206; becomes accepted when #205 closes with implementation CI green.**

This ADR is the normative implementation closeout for the v1 scope defined by `docs/22-chat-governed-manager-contract.md`. Where older roadmap wording describes one of the capabilities below as future or incomplete, this ADR supersedes that wording once accepted. It does not claim production acceptance; real-site acceptance remains frozen until issue #205 is closed and resumes under issue #201.

## Decision

Research Manager v1 is considered **development complete** only when all of the following are present in one merged release candidate and CI is green.

### Canonical researcher identity

- `eduardo_research_identity` is the Theme/Manager canonical identity source.
- Managed identity fields are `name`, English `headline`, and Spanish `headline_es`.
- The Theme forcibly renders the canonical name/headline instead of accepting stale free editorial identity slots.
- Identity mutations use Preview → Apply → Verify → Rollback, evidence confirmation, stale-preview protection and the elevated `identity.write` scope.
- Identity-critical academic identifiers also require `identity.write`.
- No arbitrary WordPress user/profile proxy is part of v1.

### Theme Pages

- Page structured slots remain Theme-owned and separate from Page state.
- Page title and publication state use a dedicated shared Page State lifecycle.
- Editing slots never publishes a Page implicitly.
- Contract Page slugs are immutable by design.
- The front page publication state is contract-locked.
- Local Admin and remote adapters reuse the same Page State service.

### Navigation

- `primary` and `footer` are Manager-controlled Theme navigation locations.
- Only registered Theme/page targets are valid.
- Arbitrary external URLs and generic WordPress menu proxying are excluded.
- Navigation uses Preview → Apply → Verify → Rollback with rendered verification and stale-preview protection.

### Insights

- Create/update covers title, slug, excerpt, content, language, editorial type and verified Research Line relationships.
- Publication state and RFC3339 scheduling are bounded lifecycles.
- EN/ES pairing is managed and reversible.
- Slug updates are allowed for managed Insights and are sanitized through the bounded editorial contract.

### Research Lines

- Create/update covers publication status, slug, title, excerpt, content, language, evidence status, research status, central question, order, topics and methods.
- Publication state remains bounded to the Research Line contract.
- Slug mutation is controlled and reversible.

### Research Objects

Publications/outputs, Projects, Software and Datasets support bounded creation/update with title, slug, publication state, content, language, their domain metadata, relationships, evidence constraints and rollback where supported.

### Resource inventory and operations

- Typed resource inventory covers Pages, Insights, Research Lines, Publications/Outputs, Projects, Software and Datasets.
- No generic `wp/v2` write proxy is introduced.
- Cross-lifecycle operation inventory exposes pending verification, verified, verification-failed and rolled-back states.
- Audit entries are whitelist-only and expose safe lifecycle metadata including operation/target, source/result revisions, verification and rollback status without secrets.

### Media

- Media inventory, metadata and assignment are Manager capabilities.
- Binary ingest is a managed shared domain service, not REST-owned business logic.
- Ingest enforces bounded MIME/size, checksum, private staging, explicit cancel, TTL cleanup, provenance, verification and safe rollback.
- Arbitrary URL ingest and arbitrary filesystem paths are excluded.

### Academic connections

- ORCID, Google Scholar, Crossref, Zenodo, OpenAlex and GitHub expose bounded non-secret connection visibility where supported.
- Existing safe read/preview adapters may be invoked remotely with `connections.read`.
- OAuth completion, secret/configuration writes and external publication are not normal v1 remote operations.

### SEO/GEO and design

- SEO/GEO inspection/readiness and deterministic supported remediation remain Manager-governed.
- The already-ready production rule remains ADR-016: do not manufacture a defect merely to exercise remediation.
- Design remains Theme-bounded: palette, typography, density and registered component/section variants; no arbitrary CSS/PHP execution.

### Operating boundary

Normal website operation must not require SSH or sharing a WordPress administrator password with ChatGPT. SSH/WP-CLI remain optional bootstrap, infrastructure, recovery and support transports. Local credential administration remains intentionally local.

## Final development gate

Before this ADR becomes accepted:

1. PR #206 implementation CI must be fully green.
2. `/capabilities` must truthfully advertise the final fields and boundaries above.
3. Issue #205 must have no unimplemented normal-v1 task.
4. The release candidate may then be packaged.
5. Only after those steps may issue #201 resume final production acceptance on `eduardoyauriluna.com`.

## Non-goals

This decision does not add:

- arbitrary WordPress REST proxying;
- arbitrary SQL/filesystem/shell/code execution;
- unrestricted WordPress user/profile mutation;
- external academic publishing without a separately elevated future contract;
- destructive generic delete operations;
- an unrestricted page builder.
