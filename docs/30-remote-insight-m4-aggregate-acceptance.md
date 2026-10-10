# Remote Manager M4 — aggregate Insight acceptance

## Purpose

M4 is the complete remote editorial operating boundary for managed Research Insights.

The primary operating model remains:

`ChatGPT → authenticated Research Manager → exact Manager services → Research Theme → rendered WordPress state → Verify`

M4 is considered complete at repository/CI level only when the complete workflow works as one continuous sequence on a fresh WordPress installation. Individual feature tests remain necessary but are not sufficient by themselves.

## Included M4 capabilities

The aggregate acceptance requires all of the following to coexist without bypassing the Manager contract:

1. authenticated Insight inventory and inspection;
2. bounded Insight creation;
3. bounded editorial update;
4. Research Line relations using verified same-language Research Lines;
5. Theme-derived internal Research links;
6. safe scheduled publication using native WordPress `future` state;
7. explicit draft/publish transitions;
8. rendered `Article` verification for public Insights;
9. safe rendered skip for draft/future content;
10. EN/ES Insight creation and bilateral translation pairing;
11. SEO/GEO stored-state inspection;
12. exact Preview → Apply → Verify → Rollback semantics;
13. complete reverse-order rollback to the original baseline.

## Deliberate scope exclusions

### Generic categories and tags

M4 does not add a generic category/tag management surface.

Reason: the current Research Theme editorial contract is driven by structured Insight type, language, Research Line relations, translation pairing and Theme-owned rendering. Generic WordPress taxonomy administration is not required to operate the Research site from ChatGPT today.

If the Theme later introduces a first-class controlled taxonomy contract, it must be added as a bounded Manager capability rather than exposing generic WordPress taxonomy mutation.

### Generic author administration

M4 does not add arbitrary WordPress author assignment or user management.

Reason: authorship is an identity/evidence concern and must not be opened merely to complete an editorial CRUD checklist. If multi-author academic attribution becomes a Research Theme contract, it belongs behind a dedicated identity/evidence-aware Manager service.

## Aggregate CI scenario

The fresh-WordPress acceptance performs this exact sequence:

1. apply the canonical Greenfield Research blueprint;
2. create an authenticated Manager connection;
3. discover the complete M4 Insight capability set;
4. create an English draft remotely;
5. verify the draft is intentionally non-public;
6. update title/excerpt/content and add a verified same-language Research Line relation;
7. verify stored relation state and Theme-owned internal Research link rendering;
8. schedule the draft for future publication;
9. verify native scheduled state and safe non-public rendered handling;
10. rollback scheduling to the prior draft state;
11. publish the English Insight through the bounded status transition;
12. verify the rendered `Article` contract;
13. create a published Spanish counterpart through the remote Manager;
14. pair EN/ES through the dedicated pairing lifecycle;
15. verify both stored and rendered bilingual state;
16. inspect inventory and SEO/GEO state through authenticated read surfaces;
17. rollback pairing, Spanish creation, English publication, English editorial update and English creation in reverse order;
18. verify that the created Insights no longer exist and the initial baseline is restored.

## Completion rule

Passing this acceptance means:

- **implemented in repository:** yes;
- **verified on fresh WordPress CI:** yes;
- **validated on `eduardoyauriluna.com`: no, not yet.**

Real-site operation remains a separate acceptance milestone. A green M4 CI run must never be reported as proof that the production/research domain has already been operated from ChatGPT.

## Next milestone

After M4 repository/CI completion, development proceeds to M5: remote Research Object operation for Publications, Projects, Software and Datasets using the existing bounded Object Editor and evidence-aware contracts.
