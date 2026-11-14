# Eduardo Research Site Architect #475.29 — real public browser evidence

## Goal and strict target

The 0.11.1 Site Architect standalone plugin and Theme 1.1.3 have passed
independent export/clone testing, and live MCP inspection covers all 24
Theme Page-language variants. This does not prove that the site looks
correct in a real browser.

The new Playwright job runs on **eduardoyauriluna.com only**. It captures
the actual public WordPress/Theme UI at **1440 × 900** (desktop) and
**390 × 844** (mobile), always in reduced-motion/light presentation.
It checks the exact **12 Theme Page keys × EN/ES (24 routes)** at each
viewport, **48 public page/viewport visits total**. No arbitrary URL
or tenant input is accepted.

The six page categories that are not the subject of a private content
review are not assumed to be complete: screenshots show only what was
already public, not draft posts. No credentials, cookies, wp-admin,
WordPress REST write calls, or mutation endpoints are used. Requests
outside the exact public site host are blocked, including off-host
fonts/assets, so screenshots are deterministic and may use fallback
fonts; designers should compare typography against the actual
production browser as well.

## Checks and artifacts

For every HTTP 200 route, the job records **counts and booleans only**:

- existence of Theme header, visible main, primary navigation and footer;
- one H1, expected HTML language, safe internal navigation links and
  visible skip link;
- document horizontal overflow pixels, missing image alt attributes;
- mobile menu aria-expanded transitions and opened-nav visibility;
- axe-core WCAG 2/2.1 A/AA findings (impact and rule IDs only, no node
  text, private content or HTML);
- public desktop/mobile viewport screenshot from the actual site.

The observed status for each of the 24 routes is retained. Empty
Publications, Projects, Software and Datasets currently have a
**verified zero-object Research inventory** (#475.27), explaining
eight HTTP 404 pages EN+ES. A future HTTP 200 in these collections must
be checked as a real page, not marked a regression. Any unexpected
non-200 on the other 16 public URLs is a **quality finding**, not
a cue to fabricate content.

The redacted JSON must contain exactly 48 unique route/viewport rows,
closed expected fields and recomputed totals. Unknown fields, private
data, forged release status or a missing route are rejected.

Download the GitHub Actions artifact
\`site-architect-47529-eduardo-public-visual-evidence\` from the run;
screenshots are intended **for actual human visual review**, including
space, typography, contrast, alignment, links, breakpoints and legal
presentation, not for automatic acceptance. A green GitHub workflow
only proves the **observer executed and its evidence is safe**. Even
zero axe violations would not establish full WCAG conformance or
editorial quality.

## Explicit non-certifications

- Source code's independent distribution has been certified via a
  GitHub Actions ZIP, but creation/access to the actual
  \`Emmakex/site-architect\` repository remains unconfirmed.
- A clone MySQL readonly transaction is not a native inventory of
  actual production; WP-CLI access to the real WordPress runtime is
  required and is not available through the existing authenticated MCP.
- The protected MCP principal has mutable scopes; visual audits do not
  exercise mutation, Verify or Rollback.
- Doctoral CV and legal texts require real editorial approval; do
  not invent awards/publications/accreditations.
- Public HTTP/visual evidence **cannot** be treated as final release
  certification or as a replacement for actual authenticated Site
  Architect operations on the installed Theme.

Production installed Site Architect and Research Theme are untouched.
