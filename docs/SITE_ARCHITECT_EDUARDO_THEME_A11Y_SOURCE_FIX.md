# #475.30 — Eduardo Research Theme accessibility source correction

## Evidence, not speculation

Actual browser audit #475.29 is recorded in GitHub Actions run
43638760962, artifact 14130443369. It visited 24 canonical Theme routes
at 1440×900 and 390×844. Of 32 public HTTP 200 viewport observations,
22 had serious/critical axe-core findings. Analysis reduced these to two
**repeatable source defects**:

1. \`button-name\` critical in every visible mobile menu (16 mobile public
   observations): in \`assets/research-navigation.css\` the
   \`.research-menu-toggle-label\` was set to \`display:none\` below
   520px, removing the translated menu text from the accessibility
   tree. The header button still has \`aria-expanded\`, and
   \`assets/research-navigation.js\` already updates the label on
   open/close.
2. \`color-contrast\` serious in desktop/mobile Research and Legal
   sections (12 observations): the small \`.research-section-number\`
   was \`#6F7F7C\` on near-white backgrounds, below the WCAG AA
   4.5:1 contrast threshold for small text.

## Surgical change

The first selector now uses an established visually-hidden technique:
1px clipped absolute text, kept in the accessibility tree and
available as the accessible button name, while preserving icon-only
mobile appearance. No header markup, script, responsive dimensions,
navigation target or content is changed.

The second selector now uses \`#536763\`, achieving contrast above
4.5:1 on the Theme's base \`#F5F5F0\`, surface \`#FCFCFA\` and white.
No palette dimensions or custom arbitrary CSS authority were added to
Site Architect. The Theme's CSS-hashed enqueue strategy will
invalidate cached files when they are eventually deployed.

New Chromium/axe source fixture checks real computed CSS for mobile
widths 390/480, verifies that menu text remains a programmatic label,
and rejects the original \`button-name\`/\`color-contrast\` violations.
Existing Theme/Plugin clones still run in isolated CI to guard layout,
SEO, Theme contract and reversible operations.

## Deployment boundary

This commit changes **version-control Theme CSS source only**. It does
NOT install, push, upload or update the Theme running on
eduardoyauriluna.com, which remains installed at 1.1.3 until a
separately reviewed Theme release/cutover. Avoid pretending that an
audit of the existing live production now has zero accessibility issues.
Before cutover, prepare an explicit versioned Theme release candidate
and perform Site Architect-governed validation, backup and rollback;
Site Architect is not authorized to execute arbitrary remote CSS writes.

No academic claims, CV, privacy text, WordPress plugin source,
capabilities or connection permissions are modified.

Release no-go remains until post-deployment **real browser evidence**
shows these errors absent, desktop/mobile visuals are reviewed, and
all other WordPress native inventory / product independence / reversible
production write gates are closed.
