# Research Theme evidence rendering contract

## Purpose

The Research Theme must be able to render verified academic and professional evidence without depending on the future Research Manager. The Manager will later become the controlled writer and verifier, but the Theme owns the public read contract.

## Storage contract

The Theme reads the WordPress option `eduardo_research_evidence` as an associative array of evidence groups.

Each group contains zero or more records. A record may contain fields such as:

- `id`
- `title`
- `label`
- `value`
- `summary`
- `url`
- `status`
- source/provenance fields added later by the Manager

The Theme does not require all optional fields. Public rendering escapes values according to context.

## Evidence states

The initial public states are:

- `verified`: eligible for public Theme rendering and verified schema enrichment.
- `unverified`: stored but not rendered as a factual academic claim.
- `needs-review`: stored but not rendered as a factual academic claim.

Unknown or missing status behaves as unverified.

## Initial groups

The first Theme read contract supports:

- `profile`
- `affiliations`
- `research_lines`
- `methods`
- `experience`
- `education`
- `awards`
- `contact`
- `identifiers`

This is a read contract, not a final Manager data model. The Manager may later add provenance, timestamps, reviewer state and rollback metadata while preserving these public semantics.

## Surface mapping

- About: `profile`, `affiliations`
- Research: `research_lines`, `methods`
- CV: `experience`, `education`, `affiliations`, `awards`
- Contact: `contact`, `identifiers`
- Home: selected verified `research_lines` and `identifiers`

Collection pages for publications, projects, software and datasets continue to use their structured WordPress content types.

## SEO/GEO behavior

Only verified identifier URLs may populate `Person.sameAs`.

Only verified affiliation records may populate `Person.affiliation`.

Research outputs remain conservative: the default schema type is `CreativeWork`; a more specific output type and DOI require their dedicated verified metadata flags.

## Manager boundary

The Theme:

- reads evidence;
- filters by public evidence state;
- renders semantic frontend surfaces;
- emits verified structured data.

The future Research Manager will:

- gather/import evidence;
- attach provenance;
- move records through review states;
- preview mutations;
- apply verified changes;
- verify rendered results;
- maintain rollback snapshots.

This preserves the Theme-first architecture while giving the future Manager an explicit, testable target contract.
