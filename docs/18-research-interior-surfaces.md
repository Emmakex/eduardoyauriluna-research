# Research interior surfaces

## Purpose

The Research Theme owns the public composition of all controlled pages. After the native EN/ES runtime, the next milestone replaces generic evidence cards on the core profile pages with dedicated academic interior layouts.

The implementation remains reusable: no Eduardo-specific academic claim is hard-coded into the Theme.

## Controlled layouts

### About / Perfil

Evidence groups, in order:

1. `profile`
2. `affiliations`
3. `identifiers`

The page is represented as `ProfilePage` in Schema.org and points to the canonical `Person` entity as its `mainEntity`.

### Research / Investigación

Evidence groups, in order:

1. `research_lines`
2. `research_questions`
3. `methods`

The page is represented as `CollectionPage`. It links directly to Publications, Projects, Software and Datasets so that the research narrative connects to concrete outputs.

### CV / CV

Evidence groups, in order:

1. `experience`
2. `education`
3. `affiliations`
4. `awards`

The public CV remains evidence-first. Missing records render an explicit verification-ready state instead of invented biography, education or awards.

### Contact / Contacto

Evidence groups, in order:

1. `contact`
2. `identifiers`

Only verified contact/profile records are rendered as factual information.

### Legal surfaces

Privacy and legal-notice pages use a Theme-owned legal layout. Owner-specific legal text is stored separately per locale and is not fabricated by the Theme. Until verified legal content is supplied, the page exposes a clear pending-verification state.

## Multilingual contract

Every layout definition has native English and Spanish labels/descriptions. Evidence translation continues to use the existing localized evidence contract (`translations.es` or `_es` fields), while verification status remains language-independent.

The default language remains English. Spanish pages use the `/es/` namespace and retain their own canonical/hreflang semantics.

## Evidence rule

`verified` records may render publicly.

`unverified`, `needs-review`, missing and unknown states do not render as academic facts.

An empty section is not an error: it is an intentional visual state showing that the semantic structure exists but evidence is not ready for publication.

## Visual system

The interior system extends the Academic Intelligence design language with:

- indexed semantic sections;
- compact evidence timelines/cards;
- visible verification states;
- contextual cross-links between research surfaces;
- responsive one-column layouts on small screens;
- reduced-motion compatibility.

The CSS is isolated in `assets/interior-surfaces.css` so the base Theme remains maintainable.

## SEO/GEO semantics

Controlled page schema types are conservative:

- About: `ProfilePage`
- Research, Publications, Projects, Software, Datasets, Insights: `CollectionPage`
- CV, Contact, Legal: `WebPage`

Verified research-line names may populate `Person.knowsAbout`. Unverified research lines never enter structured data.

## Manager boundary

The future Research Manager will hydrate the same evidence groups and locale-specific options. It must not own the public layout. The Theme remains independently functional and continues to enforce the same verified-only rendering rule.
