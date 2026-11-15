# Site Architect — Eduardo Research release gates

Status: **NOT RELEASE-CERTIFIED**. Authority: issue #475, governed site
`eduardoyauriluna.com`, Theme `eduardo-research`, existing Site Architect 0.11.1.

This file is the single graduation checklist. **Cloned contract acceptance and
independent source ZIP are not a live product cutover**. No other Theme/client
may be described as supported before Eduardo meets the release gates.

## Evidence matrix (updated 2026-11-15)

| Gate | Authority and measurable evidence | Status |
|---|---|---|
| Reproducible standalone product source | Exact source SHA, per-file hash manifest, byte-identical installable WP ZIP, isolated build outside the monorepo (#475.17) | **PASS: isolated** |
| Research baseline, 8 research lines and 25 hydration operations | Fresh isolated Research WP Preview/Apply/Verify/Idempotency/Rollback (#475.17) | **PASS: isolated** |
| Bounded Theme pages, design and navigation | 24 EN/ES inspections, allowed changes only, denied arbitrary input, actual state Rollback (#475.18) | **PASS: isolated** |
| Actual HTTP SEO/GEO and bilingual navigation | 14 HTTP 200 pages with metadata/HTML checks, 10 expected 404, rendered bilingual Navigation Verify and Rollback (#475.19) | **PASS: isolated; 10 pages not publicly ready** |
| Media binary, metadata and page assignment | Synthetic PNG, checksum/provenance, cross-connection denial, actual rendered HTTP assignment, unused-attachment Rollback (#475.20) | **PASS: isolated** |
| Least privilege REST | Actual WordPress REST permissions tested for absent bearer, read-only write denial, nonce replay and credential revocation (#475.20) | **PASS: isolated** |
| Production public HTTP and SEO | #475.21 fetches only 24 Theme-owned public URLs; evaluates real HTTP, canonical, alternate language, OG and JSON-LD; compares anonymous surface to clone manifest | **OBSERVATIONAL, not production ownership proof** |
| Production content/legal completeness | Review currently public content, ten clone-not-public surfaces, public legal pages, real CV and verifiable academic claims | **OPEN** |
| Production private plugin/DB/media inventory | Authenticated MCP + real Hostinger WP-CLI read-only transaction (#475.38): Site Architect 0.11.1, Theme 1.1.3, 24/24 EN/ES contract views aligned; bounded WP post/media census and connection counts; installed plugin SHA-256 differs from certified source, native role ownership and complete binary Media verification not evidenced | **PARTIAL — native census observed; source drift/roles OPEN** |
| Sensitive mutable authority / least privilege | Actual protected production report confirms all six high-impact authority grants including credentials, external publishing, software update, destructive delete, Apply and Rollback; no proof of bypass nor use | **OPEN — privilege review and authorized scope narrowing plan** |
| Screenshot/accessibility/mobile/desktop parity | Real browser desktop/mobile render on both languages, visual layout and interaction evidence from production + candidate | **OPEN** |
| External product source repository | Private `Emmakex/site-architect`; 146/146 source-export Git blob hashes identical; own CI 2/2 jobs successful on `9b2ef2f81fb4b204a5b553bab2fcd21c4ca11b14` | **PASS — independent CI #43697784377** |
| Cutover rehearsal and rollback | Isolated copy of real production backup, immutable before-state, reversible Apply/Verify, test rollback to byte/state baseline; scheduled live window after approval | **OPEN** |

## #475.35 — Evidence for independent product repository (2026-11-15)

- Private repository: [Emmakex/site-architect](https://github.com/Emmakex/site-architect).
- Exact Eduardo source SHA: `24ed067d1543833d123ac48e9b05546a11fd5e75` in `SOURCE_COMMIT`.
- Imported full standalone source: **137 original plugin files + 9 packaging/CI/provenance files = 146/146**, Git blobs identical to the certified source artifact, no Theme/Blueprint or credentials.
- Independent `main` source import commit: `9b2ef2f81fb4b204a5b553bab2fcd21c4ca11b14`.
- **Separate repository Actions run SUCCESS:** [Site Architect Standalone Product CI #43697784377](https://github.com/Emmakex/site-architect/actions/runs/43697784377): both jobs pass (complete product source/checksum/ZIP and clean WordPress/MySQL install with Research mutations denied under generic profile).
- Independent WordPress ZIP build artifact: `site-architect-0.11.1-standalone-installable-candidate`, Actions artifact `14154374970`.

**Only the external product-source independence gate changes to PASS.**
This does not certify generic Theme write authority, production private
WordPress data/media ownership, editorial/legal readiness, visual review, or
production cutover and rollback. Original installed Site Architect stays 0.11.1.

## #475.36 — Recovered ACTUAL protected MCP evidence (2026-11-15)

The #475.25–28 observations already **proved** authenticated, bounded private
production read access on 2026-11-14; the previous matrix wording
"no private read access" was stale. The source release gate was corrected
after retrieving the original **sanitized** protected GitHub Actions artifacts,
rather than assuming the existence of unverified admin/SSH/DB credentials.

| Evidence | Real production observation | Scope limit |
|---|---|---|
| [run #43634273968](https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/43634273968), artifact #14129556031 | Site Architect 0.11.1, Theme 1.1.3, `research` contract v6 compatible; 6/6 core MCP read tools, **24/24** EN/ES Page inspections aligned and published; navigation **5 primary + 2 footer**; design/SEO/Media diagnostic reads 6/6 | 12 Theme-owned Pages, not a complete WordPress `wp_posts` census |
| Same protected artifact | **1 managed Media resource**, 27 granted permission scopes, 16 write/mutable classified, writes enabled | Managed Media != all WP attachment binaries; high-risk flags explicitly confirmed 2026-11-15 (#475.37) |
| [run #43636519572](https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/43636519572), artifact #14130188988 | 12/12 scoped Research collection queries: **0 output, 0 project, 0 software, 0 dataset** across all/en/es | Excludes trash; bounded listing != full SQL audit |
| Same research run | One intermittent Page inspection was unverified (**23/24**, privacy-policy/en) | Not evidence of permanent drift |
| [run #43637411910](https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/43637411910), artifact #14129709441 | Independent targeted privacy-policy/en read **CONFIRMED**, published and contract aligned | Does not replace legal editorial approval |

Verified downloaded artifact ZIP SHA-256 fingerprints, respectively:
`5033c5ebb3b4ee4f1d4b4d918d4a7c992d374b9b7214a849be9d4e3cf3e0bf7d`,
`e5db8d833229f68daf497cffac3a3557f1f4545a3b0073f8b7a64b5ebbf7495c`,
`0226a9500320a2ca0cb2b16dc34ade889cc1b8dc9aecd84c9d1f59bf4de72ee2`.

**Scope and authorization safety:** 27 granted scopes were observed, of
which 16 classified as mutable. These numbers must not be called
least-privilege-certified. The targeted protected read-only capabilities
sanitizer now emits **presence booleans only** for six sensitive operation
classes (credential management, external publishing, software update,
destructive delete, Apply, Rollback), never names from the remote response,
connection identities, bearer headers, user IDs or WP content. Even a green
report is **not authorization** to exercise those operations. Scopes are not
changed, added or revoked by this audit.

**Still open:** authenticated *native* production WordPress WP-CLI on the
actual site, with read-only DB transaction and attachment/post census
using `scripts/site-architect-475-private-readonly-inventory.php`;
full plugin file checksum, role ownership, reviewed doctoral CV/legal text,
human visual sign-off and rehearsal against a real restored backup.
No presumed WP-CLI or site SSH credentials have been invented. No
production source, plugin, Theme or content mutation occurs.

## #475.37 — Verified six sensitive authority grants (2026-11-15)

Protected **real** Eduardo MCP run
[43700489721](https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/43700489721)
is **SUCCESS 3/3** after the source-only redacted capability patch #475.36.
The `site-architect-47523-live-mcp-redacted` artifact
[#14155724810](https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/43700489721/artifacts/14155724810)
was downloaded and parsed. Archive SHA-256:
`14a7dc795d7d8b98620bd15932c4aedf8dfda1f63c5bd33ce01997e682da26ca`.
Observed at `2026-11-15T09:45:14.016Z`.

All six **permission-presence** flags in the authenticated capability response
were `true`: `credential_admin`, `external_publish`, `software_update`,
`destructive_delete`, `operation_apply` and `operation_rollback`.
The source exposes these as **redacted booleans**, never raw remote scope
strings, credentials, identities, URLs, post bodies or media paths.
The live connection remains at **27 grants, 16 mutable by classification**
and `writes_enabled=true`. A grant's presence **does not establish** that a
particular endpoint exists, a write can bypass Preview/confirmation/contract
guards, or that any write was exercised. None were executed.

The same run reconfirmed actual Eduardo Theme Research:
`MCP_PRIVATE_READBACK_OBSERVED`, core reads **6/6**,
`MCP_GOVERNANCE_COVERAGE_OBSERVED` **24/24** language Views aligned,
primary nav **5**, footer **2**;
`BOUNDED_RESEARCH_INVENTORY_OBSERVED` **12/12** queries,
four Research collection types remain empty; one **managed Media** item,
not full WordPress attachment census.
Sanitizer explicitly reported `production_mutations_performed=false`,
`raw_mcp_payloads_exported=false` and
`native_db_full_census=false`. The independent WordPress/Theme clone
postmerge workflow [43700489689](https://github.com/Emmakex/eduardoyauriluna-research/actions/runs/43700489689)
also passed **3/3** jobs.

**Release blocker:** review and narrow the production principal's scope
according to approved user tasks and least privilege, ensuring an explicit
owner-authorized rollback path. Do **not** revoke/rotate/write credentials or
permission settings automatically, since the existing live Site Architect
connection is in use. The native database WP-CLI inventory and production
backup rehearsal remain separate NO-GO checks. Neither Site Architect
0.11.1 nor Theme 1.1.3 is deployed or changed by these read-only results.

## #475.38 — Native Hostinger WP-CLI production inventory (2026-11-15)

**Real operator-supplied WP-CLI output, not a disposable clone or an MCP
simulation.** Site `eduardoyauriluna.com` on its confirmed Hostinger
WordPress installation, observed UTC `2026-11-15T12:12:43+00:00`.
The read-only inventory script at GitHub commit
`0bb5cee6e6c3ce6b887c3684259d9db3652747fe`,
`scripts/site-architect-475-private-readonly-inventory.php`,
completed with explicit `origin=production`,
`read_only_transaction=true`, `site_host_verified=true`
and `certification=PRIVATE_READBACK_ONLY_NOT_CUTOVER`.
WP-CLI required PHP **8.5.12**; the SSH default PHP **7.4.33**
was incompatible with the installed plugin's PHP 8.1+ source syntax.
Neither production PHP settings nor WordPress source were changed.

| Native readback field | Observed |
|---|---|
| Active plugin | `Site Architect` **0.11.1**, active |
| Active Theme Contract | `eduardo-research` **1.1.3**, preset `research` v6, compatible, languages EN/ES |
| Theme Pages | **12** WP Page posts published; **24/24** Theme Page-language inspections exist, published and contract-aligned |
| Theme Home slots | EN **11/14** stored; ES **11/14** stored, remaining slots may resolve defaults (not automatically missing content) |
| Standard WP Posts | **2 publish**, **1 auto-draft** |
| Research lines | **8 publish** |
| Attachment rows | **1 image** attachment with status `inherit`, **0 application/other** in the inventoried WP post types |
| Navigation | **5 primary** items, **2 footer** |
| Authenticated remote connection | primary configured/enabled, credential present (no token exposed); **27 total granted scopes**, **16 classified mutable**, **1 active temporary connection** |

These counts reconcile the earlier live MCP evidence. **A Site Architect
managed-media listing is not necessarily the complete WordPress Media
library**, but the native `wp_posts` attachment census currently also
returned one image. The inventory queries the standard post types and
Site Architect Resource Registry types; it does not assert a census of
arbitrary third-party custom tables or uploads existing outside the
database. No raw posts, URLs, titles, authors, user IDs, file paths or
credential material were exported.

### Integrity gate — source mismatch, no automatic overwrite

The installed WordPress plugin entrypoint was hashed **on Hostinger**:

- Production PHP file SHA-256:
  `d3465fe6bc81e0ed88822d47ffcce4cee4298dbc94263230335817c6755ea643`
- Certified canonical entrypoint SHA-256 from GitHub
  `wordpress/plugins/eduardo-research-manager/eduardo-research-manager.php`
  (identical Git blob `513c608f27eff7ee98d2defcd97409ccd117f401`
  in both Eduardo main and standalone product main):
  `cc39419deb31f0fb0d581822ca5c8fe79cc1704bb7debfb563bd5ea84abd943f`

**SHA-256 DOES NOT MATCH**; simple CRLF/newline/BOM conversions of the
canonical source also do not match. This identifies **unexplained
entrypoint drift**, not confirmed malicious modification or a broken
running site. Version strings and 24/24 contract alignment alone cannot
establish source-byte parity. Do not replace the installed plugin or
change any live feature until a bounded read-only source diff establishes
the cause and a reversible upgrade plan is approved.

The validator
`scripts/site-architect-475-validate-private-inventory.py` now accepts
a canonical `--source-entrypoint` reference for production validation,
returns `DRIFT_REVIEW` when the reported source hash differs, and
**does not produce a production PASS without an exact source comparison**.
The broader cutover release remains NO-GO even when the file eventually
matches.

**Still open:** explain installed file drift (read-only comparison),
review temporary and high-risk permission grants before limiting access,
verify actual role-to-resource ownership, inspect uploaded binaries and
native backup/restore readiness; editorial CV/legal and human rendered
accessibility acceptance. Do not rotate credentials or modify WordPress
on the basis of this inventory.

## Read-only production survey

Source-of-truth Theme routes are generated by calling `eduardo_research_preset()`
in a disposable WP CLI environment. The auditor
`scripts/site-architect-475-production-readonly.py` only performs anonymous
HTTPS GET for the 12 pages × EN/ES at the exact domain
`eduardoyauriluna.com`; it rejects redirects to other hosts and never visits
admin/login endpoints. Tests are in
`scripts/test-site-architect-production-readonly.py`.

CI stores **status and metadata booleans only**, no HTML, cookies, headers
containing identity, private data, or media files. It records transport errors,
publishing differences, metadata failures and public editorial placeholders.
A network failure explicitly makes the survey **incomplete**, not passed.
This audit proves only the public observable surface and cannot claim what
plugin or credentials are active inside production WordPress.

The 10 clone HTTP 404 observations are **not** asserted as equivalent to
production: published content changes are an expected potential delta.
A public URL that differs from the clone is a review item, not permission to
delete/recreate/copy live content.

## Production release/cutover decision

**No-Go unless ALL of the following are independently evidenced:**

1. Private Site Architect repository exists, source/artifact hashes match, and
   its **own** CI plus isolated WP installation are green.
2. Production read-only private inventory confirms installed plugin, Theme
   Contract, version, routes/records, existing Media, capability boundaries,
   public SEO/GEO and ownership; no unreviewed drift.
3. Cloned copy of production state has passed all reversible mutation families
   (Theme pages, navigation, design, Media, Research content, SEO/GEO, credentials)
   with pre/post snapshots, restore validation and negative authorization tests.
4. Editorial/legal readiness has been approved explicitly: do not auto-publish
   legal drafts, fabricated academic claims, or contentless collections to
   manufacture a green coverage metric.
5. Human-reviewed desktop/mobile screenshots, keyboard focus, major links,
   language and contrast are accepted; page content and asset continuity checked.
6. A verified DB+uploads+plugin+Theme backup, a documented rollback command
   and maintenance window exist; canary deployment, Verify and rollback are
   bounded to Eduardo's approved site only.

Until then, **do not update, redeploy, replace, uninstall or broaden scopes**
on the active `eduardoyauriluna.com` installation. Preserving the working
Site Architect 0.11.1 installation is intentional. Existing technical plugin
slug/classes remain compatible and are not renamed during extraction.

**Next engineering action:** collect and reconcile read-only private
production state after public survey. Where access is unavailable, keep the
private gates explicitly OPEN. Do not interpret a public HTTP 200 as proof
of private operation authority or final delivery.

## First real production HTTP observation (2026-11-14 18:10 UTC)

GitHub Actions run 43624953472 queried exactly 24 routes read-only with zero
transport errors: **16 HTTP 200 with all SEO/HTML checks passing, 8 HTTP 404**.
The temporary Research clone from the *same* exact plugin export had 14 HTTP
200 and 10 HTTP 404. The only status differences are:

| Theme surface | Fresh clone | Real production | Interpretation |
|---|---|---|---|
| privacy-policy/en | 404 (draft) | 200 | Existing public Privacy page; preserve its state; legal review still needed |
| privacy-policy/es | 404 (draft) | 200 | Existing Spanish public Privacy page; preserve its state; legal review still needed |

The audit also detected **public CV placeholder markers in both EN and ES**.
These are explicitly **editorial readiness blockers**; do not fabricate
academic credentials, credentials verification, or completed doctoral work to
replace the placeholders. The remaining 22 route statuses matched the clone.

This read-only result is a public-surface comparison only: it does not prove
that production uses the source ZIP validated in CI, nor reveal its database,
private Media/REST connection state, full content parity or screenshot layout.
No changes have been made to the live Privacy pages, CV or Site Architect.


## #475.22 — Private WordPress readback, explicitly not live yet

The full read-only inventory observer is in
docs/SITE_ARCHITECT_PRIVATE_READONLY_INVENTORY.md.
The exported real Site Architect 0.11.1 plugin is audited in the disposable
WordPress clone inside a MySQL READ ONLY transaction: active plugin SHA/version,
Theme contract and 24 Page/language slots, aggregate post/Media records, and
only non-sensitive remote connection posture. The schema validator prohibits
credential/user/content leakage and matches clone HTML/visibility evidence.

**Status:** observer/clone acceptance can be certified by CI; *real Eduardo
private WordPress data is not yet read back*. Actual production requires
explicitly authorized, host-pinned, protected WP-CLI read-only invocation.
No CI secret or public WordPress endpoint is created. Never treat this
as permission to update/redeploy the already functioning installation.


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

## #475.26 — real bounded Theme governance observations

See docs/SITE_ARCHITECT_LIVE_GOVERNANCE_READBACK.md. The existing protected
Eduardo MCP read-only workflow now additionally inspects all 12 registered
Theme Page keys in EN+ES (24 direct per-language inspections), plus Design,
Design variants and rendered readiness, SEO/GEO site + indexability, and
Media diagnostics. It emits a closed-schema counts/flags-only sanitized JSON
without content, credential details or post IDs.

**Status before actual protected main run:** unverified. A green PR or clone
job proves only observer/sanitizer code. The real protected workflow artifact
must be inspected and any mismatch/partial response recorded. Full MySQL
inventory, actual mobile/desktop visual evaluation, editorial CV/privacy/legal
approval and reversible cutover remain OPEN even if all 24 read endpoints respond.

## #475.27 — Evidence for missing Research collections

The existing protected read-only Site Architect MCP observes all four
Research collection types in EN/ES with **12 total bounded listing calls**,
redacting all academic content and personal identifiers. See
`docs/SITE_ARCHITECT_EDUARDO_RESEARCH_COLLECTION_GATES.md` for the exact
Theme and WordPress semantics (EN default includes absent-language meta,
explicit filtered EN does not, and editor lists are capped at 100).

No-GO until actual production artifact identifies the published counts
and they are reconciled against a fresh HTTP survey. No speculative paper,
project, software or dataset creation. Nothing is installed or published
on Eduardo production. This is not a native SQL census or an end-to-end
mutable Site Architect acceptance.

## #475.28 — transient English Privacy Page inspection recheck

See `docs/SITE_ARCHITECT_EDUARDO_TARGETED_PRIVACY_READBACK.md`. After
#475.27's 12/12 Research object queries returned empty inventories, the
only non-green private governance inspection was `privacy-policy/en`
(23/24, compared with a prior real 24/24 success). A tiny protected
workflow now checks ONLY that precise Page plus the established Eduardo
runtime identity using existing MCP read tools, and publishes a
redacted status/slot-count-only report. Do not infer absence of legal
Page or cutover readiness until this report is actually observed.

## #475.29 — Desktop/mobile screenshot and WCAG observation

Public real-browser automated inspection is defined in
`docs/SITE_ARCHITECT_EDUARDO_VISUAL_ACCEPTANCE.md`. A dedicated Playwright
GitHub Actions job audits all 24 public Theme routes at desktop and mobile
(48 visits), takes screenshots only of HTTP 200 pages, records horizontal
overflow, H1, navigation, mobile menu interaction and axe-core A/AA rule IDs
without exporting private content. It does **not** certify visual design or
WCAG compliance: the actual screenshot artifact must be reviewed before
closing the visual gate. No production writes and no credentials needed.
