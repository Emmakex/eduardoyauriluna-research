# Remote Research Relationships + Public Schema — M5e

Status: repository/CI acceptance slice for the ChatGPT-governed Research Manager. Real `eduardoyauriluna.com` validation remains M8.

## Decision

M5e does **not** introduce another remote relationship API.

The bounded relationship contract already exists end to end:

- Research Lines are first-class `research_line` records.
- Research Outputs, Projects, Software and Datasets expose `line_ids` through their shared Object Editor contract.
- Manager Plan normalization accepts only verified, published Research Lines in the same language.
- The Theme stores object relations in `_research_line_ids`.
- Public Theme helpers filter relations through the same verified/language-aware contract.
- Object pages render their verified Research Lines.
- Research Line pages discover and render related Research Objects.
- JSON-LD expresses the relationship in both directions.

Therefore the correct M5e work is to prove the existing contract from the remote Manager transport through the public Theme output rather than add a parallel mutation surface.

## Relationship contract

A remote Research Object may reference a Research Line only when the target line is:

1. a real `research_line` post;
2. published;
3. evidence status `verified`;
4. owned by the same language as the Research Object.

The relationship is rejected during Plan normalization when any of those conditions fail.

This means a ChatGPT instruction cannot create a factual academic relationship merely by supplying an arbitrary WordPress ID.

## Public human rendering

For each related Research Object, the Theme must render a Research context section containing the verified Research Line and its permalink.

For each Research Line, the Theme must derive related public Research Objects through the shared `_research_line_ids` graph and render them in the Research network section.

The relation is therefore bidirectional at the public presentation layer even though the stored authority remains the Object → Line IDs.

## Public machine-readable rendering

M5e requires the native Theme JSON-LD to expose the same verified graph.

### Research Object → Research Line

The object schema node uses:

`about: [{ "@id": "<line permalink>#research-line" }]`

Expected representative schema types:

- verified journal Publication / Output → `ScholarlyArticle`
- Project → `CreativeWork`
- Software → `SoftwareSourceCode`
- Dataset → `Dataset`

Each node must also expose its language and public URL identity.

### Research Line → Research Objects

The Research Line schema node uses:

`subjectOf: [{ "@id": "<object permalink>#research-object" }, ...]`

The acceptance requires all four related object families to appear.

## SEO identity

The same public object query must emit:

- self canonical;
- current-language hreflang;
- matching Open Graph URL;
- `inLanguage` in the JSON-LD object node.

M5e does not replace M6. It only proves that the already-supported Research relation is represented consistently in the current native SEO/GEO runtime. Generic metadata remediation remains M6.

## Fresh-WordPress acceptance

`Research Manager Remote M5 Relationships Schema Quality` installs clean WordPress + the Research Theme + Manager and then:

1. creates a verified/public English Research Line through the remote Manager;
2. proves a nonexistent/unverified Line ID is rejected by Object Plan;
3. proves an English Line cannot be attached to a Spanish Research Object;
4. creates one published Output, Project, Software and Dataset through the remote Manager, each related to the verified Line;
5. verifies the stored remote `line_ids` contract;
6. verifies the Theme public relation helper accepts exactly that Line;
7. renders the object Research context HTML and checks the Line ID/permalink;
8. renders canonical/hreflang/OG metadata;
9. renders and parses native Theme JSON-LD;
10. verifies each object has the expected schema type and `about` reference to the Line;
11. verifies Software schema exposes its repository URL;
12. verifies the Line reverse query discovers all four objects;
13. renders the Line related-object HTML and confirms all four public objects;
14. renders the Line JSON-LD and confirms `subjectOf` references all four objects;
15. rolls back all remote object creations and finally the Research Line creation.

No direct relation writes are used to create the accepted graph: the relationship is created only through the authenticated Manager object operation and its evidence-aware Plan contract.

## Remaining M5 after this slice

Once M5e is green and merged, only the final aggregate M5 fresh-WordPress operating acceptance remains before M5 can be marked repository/CI complete.

That aggregate must combine:

- Research Lines;
- Publications / Outputs;
- Projects;
- Software;
- Datasets;
- structured EN/ES translations;
- Evidence / Provenance;
- verified relationships;
- public schema/rendered checks;
- rollback back to baseline.

The real `eduardoyauriluna.com` remains an M8 proof and must not be inferred from this CI acceptance.
