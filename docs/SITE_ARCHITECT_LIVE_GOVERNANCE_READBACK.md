# Site Architect #475.26 — authenticated, bounded governance of Eduardo's actual Theme

## Scope

This is the next **production read-only acceptance** after #475.23–#475.25.
The previously certified public HTTP audit covers 24 localized routes, but
does not prove a *private editable Theme Page* is inspectable. The existing
authenticated \`site_pages_list\` includes 12 registered Page keys with EN/ES
availability, Theme contract alignment and allowed/stored/effective slot data.
Earlier #475.23 evidence disclosed only the total count (12), losing the
information required for production governance acceptance.

#475.26 observes that data privately and redacts it into **24 EN/ES safe
record summaries** without emitting slot values, titles, post IDs, draft
content or any arbitrary URLs. It also invokes all 24 pre-existing
\`site_page_inspect\` read tools to distinguish a listed resource from an
actual *per-language inspectable* resource.

## Allowed Page contract

The exact current Theme 1.1.3 source at
\`wordpress/themes/eduardo-research/inc/research-preset.php\` declares:

- \`home\`, \`about\`, \`research\`, \`publications\`, \`projects\`,
  \`software\`, \`datasets\`, \`cv\`, \`insights\`, \`contact\`,
  \`privacy-policy\`, \`legal-notice\`
- \`en\` and \`es\` languages (12 x 2).
- Navigation \`primary\` and \`footer\` contain only registered Theme
  keys, no arbitrary URLs.

The \`site_page_inspect\` request key and language are **constant**,
never taken from remote text or an arbitrary caller. The script
refuses unknown tools and sends requests only to the existing
\`https://mcp.kairoseth.com/mcp/eduardo-research\` tenant under the
same protected GitHub environment/principal as #475.23.

The six additional read-only tools, declared readOnlyHint by the real
MCP server in \`bridge/research-manager-mcp/src/server.ts\`, are:

- \`site_design_inspect\`
- \`site_design_variants\`
- \`site_design_readiness\` with \`rendered=true\`
- \`site_seo_geo_site\`
- \`site_seo_geo_indexability\`
- \`site_media_diagnostics\`

No plan/apply/verify/rollback/create/update/delete or credentials tool
is called. The Site Architect code in WordPress and the Theme are not
changed, no update ZIP is needed and no scope is added.

## Safe output and decision

The protected job stores raw MCP replies only in a temporary runner
directory with umask 077, then removes them. Only a CLOSED schema
\`live-governance-readback.json\` is published with:

- public registered Page key/language, existence, status, flags for
  contract alignment and published status, and **counts** of allowed
  and stored content slots, never their names or values;
- total Page-language availability and contract alignment counts;
- navigation primary/footer **counts** and the no-arbitrary-URL flag;
- true/false that Design, variants, responsive Design readiness,
  aggregate SEO, SEO indexability and Media diagnostics were returned;
- precise timestamps and source SHA; no other private data.

A green job means the read-only operation completed and the report
passed schema/sensitive-field validation. Even
\`MCP_GOVERNANCE_COVERAGE_OBSERVED\` means **coverage of inspection
endpoints**, NOT proof that each Page is editorially finished or visually
pixel-perfect, that Media inventory is the complete underlying WordPress
database, or that a production cutover/rollback was performed.

If a Page cannot be inspected or the contract is mismatched, record
\`MCP_GOVERNANCE_PARTIAL\` or an explicit open flag. The script does not
publish private error details, try to fix the Page, or enable write
permissions. Follow-up work uses actual reported mismatches.

## Explicit remaining no-go gates

The native WordPress private MySQL observer (#475.22) has been proven
only in the isolated clone. Its production readback is still NOT
certified. Other open release gates are desktop/mobile visual acceptance,
the doctoral CV, legal content review, independent product source repo,
and a reversible cutover rehearsal. No other client/Theme is supported
by this evidence until Eduardo has passed all gates.
