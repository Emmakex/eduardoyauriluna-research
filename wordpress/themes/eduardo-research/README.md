# Eduardo Research Theme

Greenfield WordPress implementation of the `research` preset derived from the SEO/GEO Theme architecture.

## Non-negotiable architecture

- Theme owns public page composition.
- Gutenberg does not own controlled page layouts.
- Structured semantic slots hydrate Theme templates.
- Long-form editorial/research bodies may use the editor only inside bounded Theme-owned surfaces.
- Publications, projects, software and datasets are first-class structured content types.
- Evidence-sensitive academic claims must be verified before Manager hydration.
- Manager is a later control plane: Preview -> Apply -> Verify -> Rollback.
- Theme must remain independently functional without Manager.

## Initial implementation

`0.1.0` establishes the installable Theme shell, `research` preset contract, semantic home model, theme-owned frontend, accessible landmarks and structured research content types.

Next implementation slice: page resolver + Theme-owned templates for About, Research, Publications, Projects, Software, Datasets, CV, Insights and Contact; then native SEO/GEO runtime inheritance and Research Manager control plane.
