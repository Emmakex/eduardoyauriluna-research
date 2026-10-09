# Theme-owned frontend — Gutenberg boundary

## Decision

The `research` preset follows the same core philosophy as the SEO/GEO Theme: **the Theme owns the public frontend**.

WordPress is the content/data runtime and routing layer. Gutenberg is not the layout authority for preset pages.

We must not spend development time trying to make Theme-controlled pages coexist with arbitrary Gutenberg layouts, block patterns, page-builder markup or user-created visual composition.

---

## 1. Frontend authority

For every Theme-controlled Research surface, the SEO/GEO Theme owns:

- page structure;
- semantic HTML hierarchy;
- sections and ordering;
- grid/layout;
- responsive behaviour;
- design tokens;
- typography;
- navigation;
- reusable components;
- accessibility behaviour;
- SEO/GEO output;
- Schema/academic metadata;
- internal-link presentation;
- empty/loading/fallback states.

A WordPress Page record may exist for routing, publication state, translation mapping and Theme contract resolution, but its Gutenberg layout does not define the rendered page.

---

## 2. Preset pages are structured Theme models

Theme-controlled pages are rendered from preset contracts and structured slots.

Example:

```text
WordPress Page: Research
        ↓
Theme resolves page_key = research
        ↓
model_id = research-hub-v1
        ↓
structured slots + research entities
        ↓
Theme template/components
        ↓
Rendered frontend
```

The normal path is **not**:

```text
WordPress Page
        ↓
Gutenberg blocks / patterns
        ↓
Frontend layout
```

---

## 3. Gutenberg policy

### Controlled preset pages

For pages such as:

- Home
- About
- Research
- Publications index
- Projects index
- Software index
- Datasets index
- CV
- Contact

Gutenberg must not be used as the page-layout engine.

Preferred implementation:

- disable or hide the block editor where it would create false expectations;
- expose Theme/Manager structured fields instead;
- render the page through the Theme contract/model;
- do not require `post_content` to produce the expected frontend.

### Structured output singles

For Publication, Project, Research Software and Dataset records, the Theme owns the single template and metadata presentation.

A bounded rich-text/body field may exist for narrative content, but it renders **inside a Theme-defined slot** and cannot redefine the page layout.

### Insights / editorial content

Gutenberg may be used as a **content editor** for long-form editorial body copy when useful.

Even there:

- header/hero/byline/metadata/navigation/related content/CTA remain Theme-owned;
- Gutenberg controls body content only;
- block styles must be normalized by the Theme;
- arbitrary full-page layout blocks are not part of the contract.

This distinction is intentional: Gutenberg can edit content without becoming the frontend architecture.

---

## 4. Manager relationship

The future Research Manager hydrates and manages the Theme's structured slots; it does not generate Gutenberg layouts.

```text
Research Blueprint
      ↓
Research Content Kit
      ↓
Manager structured hydration
      ↓
Theme slots/models
      ↓
Theme-owned frontend
```

Until the Manager exists, the Theme must still render correctly from WordPress structured data/default preset configuration.

---

## 5. Why this boundary exists

This prevents:

- layout drift between installations;
- Gutenberg/theme CSS conflicts;
- broken responsive layouts caused by arbitrary blocks;
- SEO/schema divergence between visually similar pages;
- duplicated component logic;
- page-by-page manual rebuilding;
- Manager hydration fighting with `post_content`;
- dependency on a visual builder for reproducible presets.

It also ensures that improving the reusable `research` preset improves every Research installation consistently.

---

## 6. Data belongs in structured sources

Research data must live in the appropriate structured model, for example:

- researcher identity → Researcher Profile;
- research lines → Research Line model;
- publications → Publication CPT/model;
- projects → Research Project CPT/model;
- software → Research Software CPT/model;
- datasets → Dataset CPT/model;
- academic identifiers → identifier fields;
- Theme page copy → structured model slots / Content Kit hydration;
- long-form editorial body → bounded rich-text content where explicitly supported.

Do not encode academic metadata in arbitrary Gutenberg blocks and then scrape it back out for SEO or connections.

---

## 7. Implementation rule for `research`

Every Research template must answer these questions before implementation:

1. Which Theme contract/page key owns this surface?
2. Which structured model supplies it?
3. Which slots are required?
4. Which data comes from CPT/taxonomy/entity models?
5. Which fields require verified evidence?
6. Which SEO/GEO/academic metadata does the Theme render?
7. Is any free-form body content allowed? If yes, in which bounded Theme slot?

If the answer to layout is "Gutenberg blocks", the implementation is outside the intended architecture unless explicitly approved as an exception.

---

## Definition of compliance

The Research frontend is compliant when:

- preset pages render correctly with empty/minimal `post_content`;
- layout does not depend on Gutenberg patterns;
- Theme components determine visual structure;
- structured content can be hydrated without rewriting page markup;
- Gutenberg, where enabled, is limited to bounded editorial content;
- removing/disabling the block editor from controlled pages does not break the public frontend;
- the future Manager can mutate structured slots without fighting arbitrary block markup.
