# Site Architect #475.28 — targeted intermittent Page inspection recheck

Protected run #43634273968 observed Eduardo Site Architect 0.11.1 and
Theme 1.1.3, with 24/24 bilingual Page inspections aligned. Protected
run #43636519572 later observed 23/24, with **only**
\`privacy-policy/en\` unverified. The Research collection inventory
simultaneously returned all 12/12 queries correctly (all empty) and
Design/SEO/Media remained 6/6, so this may be an intermittent MCP read,
not a WordPress content regression.

A dedicated protected workflow now makes exactly **two MCP READ calls**:

1. \`research_status\` for Eduardo domain, Site Architect/Theme version,
   contract compatibility and identity;
2. \`site_page_inspect\` with CONSTANT
   \`{"key":"privacy-policy","language":"en"}\`.

Only the existing \`phase2d-production\` principal is used, exactly as
the previously accepted live governance workflow. It has no new
WordPress scope, no plugin installation, no writes, no page edits,
no arbitrary endpoints and no ability to choose another target.

The raw response (which may contain legally sensitive text and page
IDs) is kept in a private temporary runner folder and destroyed after
the test. The **only published evidence** is a closed-schema
\`sa47528-privacy-readback.json\` with a boolean for a valid site
identity, the fixed public Page key and language, \`publish\`/other
status, Theme contract alignment, counts of available/stored slots and
one of these precise outcomes:

- \`TARGET_PAGE_READBACK_CONFIRMED\`: the actual exact WordPress
  Page/EN read again succeeded, with publish status and contract
  alignment. This supports intermittent transport hypothesis; it does
  **not** establish a continuous uptime SLA or legal approval.
- \`PAGE_CONTRACT_DRIFT_REVIEW\`: the read succeeded but does not match
  the Theme contract.
- \`PAGE_PUBLICATION_STATE_REVIEW\`: the Page responds but its post
  status is not publish.
- \`READBACK_UNVERIFIED\`: failed/denied/time-out/malformed response,
  foreign runtime or incompatible versions. No conclusion that the
  WordPress page itself is absent.

The new workflow triggers only on its own isolated code changes to
protected \`main\`, or on deliberate manual rerun; it does not execute
from the PR, which runs only offline tests and GitHub actionlint.

All other Eduardo release gates remain unchanged: WordPress native
read-only DB inventory, mobile/desktop visual acceptance, verified
doctoral CV and legal editorial approval, least privilege, independent
product repository, and bounded production Preview/Apply/Verify/Rollback
rehearsal. The live web and its legal pages are not mutated.
