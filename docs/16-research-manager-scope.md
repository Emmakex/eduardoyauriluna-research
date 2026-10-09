# Research Manager functional scope

This document is the implementation checklist for the Research-specific extension of the existing SEO/GEO Manager.

## Core objective

Use the Manager to control the lifecycle of the WordPress Research site without rebuilding layouts manually.

The Manager must control structured content and let the Theme render it.

## Managed resource types

- Pages
- Posts / Insights
- Researcher Profile
- Research Lines
- Publications
- Research Projects
- Research Software
- Datasets
- Talks / Conference outputs
- Academic identifiers
- Media metadata
- Navigation/internal relationships
- Language mappings

## Required operations

### Create

- create missing preset pages;
- create posts and structured research outputs;
- assign role/page key/model ID;
- hydrate required slots;
- create initial language relationships;
- establish internal targets/relationships.

### Read / diagnose

- inspect Theme contract;
- inspect stored structured data;
- inspect rendered frontend;
- detect missing slots;
- detect unresolved links/environment leakage;
- detect orphan resources;
- detect missing metadata/evidence;
- calculate readiness.

### Update

- edit structured slots;
- edit entity/CPT fields;
- change navigation/relationships;
- update SEO/GEO fields through Theme-native authority;
- update translations;
- update media metadata;
- reconcile external metadata.

### Optimise

- prepare SEO fixes;
- prepare GEO/entity improvements;
- prepare internal-link improvements;
- prepare Academic SEO fixes;
- prepare content completeness improvements;
- prepare media/accessibility improvements;
- prepare language/canonical consistency fixes.

### Verify

- verify stored WordPress state;
- verify rendered HTML;
- verify canonical/meta/schema/citation tags;
- verify links;
- verify language alternates;
- verify readiness state after mutation.

### Rollback

Where a mutation is reversible, store enough provenance to restore the previous supported state.

## User experience principle

The Manager should present the user with resources and intentions, not raw WordPress implementation details.

Examples:

- “Create Research page” rather than “insert wp_posts row”;
- “Complete researcher identity” rather than “edit option/meta keys”;
- “Optimise publication metadata” rather than “edit head tags”;
- “Fix 4 internal links” rather than “search/replace HTML”.

## Next-action classes

Every diagnostic should map to a useful next action where possible:

- `create-resource`
- `hydrate`
- `optimise`
- `auto-fix-candidate`
- `manual-review`
- `evidence-required`
- `connect-provider`
- `verify-frontend`
- `configuration-required`

## Non-goals

- visual page building;
- arbitrary Gutenberg layout editing;
- duplicating Theme-native SEO ownership;
- auto-publishing unsupported academic claims;
- external writes without authorization;
- claiming rankings/citations/admission outcomes from readiness scores.

## Acceptance

Research Manager v1 is operational when we can create or modify a representative Page, Insight, Publication and Research Project from structured Manager operations, render each through the Theme, run SEO/GEO/academic diagnostics, preview/apply/verify the supported changes and obtain an updated readiness state without manually rebuilding frontend layout.
