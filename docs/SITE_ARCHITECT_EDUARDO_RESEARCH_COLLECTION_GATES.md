# Site Architect #475.27 — Why eight Eduardo collection routes return 404

## Scope and release question

Four collections in Eduardo Research Theme 1.1.3 are HTTP 404 in EN and
ES according to the last production HTTP audit:
\`publications\`, \`projects\`, \`software\`, \`datasets\`.
The same eight Page-language resources are privately inspectable through
Site Architect 0.11.1, marked \`publish\`, contract-aligned. They are
**not** necessarily the same thing as a populated collection.

The Theme rule in
\`wordpress/themes/eduardo-research/inc/research-preset.php\` is
\`eduardo_research_page_is_publicly_available(key, language)\`.
Its queries must find at least one **published, language-matching**
Research object in the correct post type. If none exists, the index
intentionally returns 404. It would be wrong to publish fabricated
doctoral papers or force Page status changes solely to remove those 404s.

## Existing read-only tools and actual WordPress behavior

The live protected MCP principal already has \`research.read\`; no
additional credential, WordPress scope, plugin update or new endpoint
is necessary. Existing tool \`site_research_objects_list\` has
\`readOnlyHint:true\`, input optional closed \`kind\` and \`language\`.
The four kind mappings are:

| Theme surface | MCP kind | WP post type |
| --- | --- | --- |
| publications | output | research_output |
| projects | project | research_project |
| software | software | research_software |
| datasets | dataset | research_dataset |

For each kind, the observer performs three reads: all, explicit EN and
explicit ES. Total **12 read-only calls**, no arbitrary user-supplied
kind, language, post IDs or content.

The actual
\`class-research-manager-object-editor.php\` uses
\`get_posts(posts_per_page=100,post_status=any)\`; that list omits Trash
and may truncate if 100 results are returned. Its filtered EN query
requires an explicit \`_research_language=en\` meta row.

**Important mismatch:** The Research Theme's **public** EN query accepts
either missing \`_research_language\` or explicit EN, whereas ES public
requires explicit ES. Therefore EN filtered counts alone can produce
false negatives; the unfiltered list's effective \`language\` values
must be compared against explicit EN. Even when all 12 calls succeed,
a group with 100 rows is **bounded only**, never certified complete.

## Sanitization, evidence and interpretation

The protected workflow keeps raw MCP responses in a private temporary
runner directory and securely removes it. A separate closed-schema
\`live-research-collection-readback.json\` contains only:

- fixed public collection key/type, counts by post status and language;
- explicit EN/ES filtered counts versus unfiltered effective-language;
- possible EN rows missing explicit language meta (computed difference);
- whether any published item is observed for each public EN/ES route;
- query coverage, 100-row truncation flags and release limitations.

Never emit academic titles, author identities, abstracts, content,
DOI, WP post IDs, slugs, dates, URLs, metadata or connections.

The response is a **count-based evidence signal**, not the native
database census. Its outcomes are:

- \`BOUNDED_RESEARCH_INVENTORY_OBSERVED\` means 12/12 validated responses,
  per-kind inventories smaller than the 100-item limit, and coherent
  language filters. This does not mean the site's public routes pass.
- \`RESEARCH_INVENTORY_PARTIAL\` means missing/denied/malformed reads,
  100+ possible posts, or contradictory language counts.

Only a **complete bounded inventory** with no published objects in
EN/ES can justify the explanation "currently intentionally empty"
for a 404 public collection. If published objects exist and the page
remains HTTP 404, investigate Theme routing, rewrite/indexability,
query and caching; do not silently assume a content gap.

### Pending after this cut

- Live HTTP survey needs rechecking at the observed timestamp, since
  earlier 404 statuses may change.
- Native WordPress private database inspection #475.22 in production
  has still NOT been run and independently checked for completeness.
- Proof that authorized writes can plan/apply/verify/rollback on real
  Eduardo safely is OPEN.
- Editorial CV, legal text and mobile/desktop visual acceptance remain
  OPEN. Do not create unverified publications, credentials or academic
  evidence.
- Independence of the full source repository
  \`Emmakex/site-architect\` is not yet certified.

The previously running Site Architect and Theme on
\`eduardoyauriluna.com\` remain untouched.
