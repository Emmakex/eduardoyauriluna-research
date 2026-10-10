# Aggregate Remote Research Control — M5 completion acceptance

Status: final repository/CI acceptance contract for M5. Real `eduardoyauriluna.com` operating proof remains M8.

## Purpose

M5a–M5e prove individual bounded capabilities. The aggregate acceptance proves those capabilities compose inside one clean WordPress installation, one authenticated Manager connection and one reversible operating session.

The acceptance chain is:

`ChatGPT-style remote instruction → Research Manager gateway → shared services → structured Research resources → Theme public graph → Verify → reverse Rollback`

Passing this acceptance means M5 may be marked **repository + CI complete**. It does not mean the real Eduardo site has been deployed or operated remotely; that claim remains reserved for M8.

## Combined capabilities under test

The single connection receives only the scopes needed for the M5 operating session:

- `site.read`
- `site.diagnostics`
- `research.read`
- `research.write`
- `translations.read`
- `translations.write`
- `evidence.read`
- `evidence.write`
- `operations.apply`
- `operations.rollback`

No `identity.write`, generic destructive scope, credential-management scope, filesystem, SQL or arbitrary WordPress proxy is required.

The acceptance requires capability discovery for all four M5 remote surfaces:

- Research Lines
- Research Objects
- structured Research translations
- Evidence / Provenance

## Aggregate operating scenario

### 1. Evidence / provenance

Create one verified academic identifier through the Evidence gateway.

The record must:

- pass the evidence-required Plan/Apply contract;
- verify through the remote provenance lifecycle;
- expose its verified URL through the Theme `Person.sameAs` schema surface.

This proves evidence is not merely stored in a Manager silo: verified evidence can feed the public machine-readable research identity contract.

### 2. Bilingual verified Research Lines

Create one English and one Spanish Research Line through the remote Line gateway.

Both must be:

- published;
- evidence-verified;
- active;
- independently created through evidence-confirmed plans.

### 3. Research Objects

Create through the remote Object gateway:

- English Publication / Output;
- Spanish counterpart Publication / Output;
- English Project;
- English Research Software;
- English Dataset.

The EN objects relate only to the verified EN Line. The ES Output relates only to the verified ES Line.

The Publication classification is verified so its public schema may safely resolve to `ScholarlyArticle` rather than a fabricated classification.

### 4. Structured translations

Pair through the remote Translation gateway:

- EN Research Line ↔ ES Research Line;
- EN Publication ↔ ES Publication.

Both pairs must pass the structured translation evidence contract and stored Verify.

### 5. Public multilingual and research graph

The Theme output must then demonstrate composition of all M5 concerns:

- EN Publication self canonical;
- reciprocal EN/ES hreflang for the paired Publication;
- reciprocal hreflang for the paired Research Lines;
- Publication JSON-LD type `ScholarlyArticle`;
- Publication `about` reference to the verified EN Research Line;
- public `Person.sameAs` includes the evidence-backed academic identifier URL;
- EN Research Line `subjectOf` includes the EN Publication, Project, Software and Dataset;
- object human-facing Research context renders the related Line;
- Line human-facing Research network renders all four EN object families.

This is the M5 integration boundary between Manager state and Theme public semantics.

## Reverse rollback requirement

The aggregate acceptance must unwind dependencies in reverse order:

1. translation pair operations;
2. Research Object creations;
3. Research Line creations;
4. evidence creation.

The test then requires:

- every created Object absent;
- both created Lines absent;
- the created Evidence record absent;
- the complete evidence store exactly equal to its initial baseline.

A green acceptance therefore proves the session is reversible, not merely constructible.

## What M5 completion means

After this aggregate acceptance is green and merged, M5 is complete at repository/CI level for:

- Research Lines;
- Publications / Outputs;
- Projects;
- Research Software;
- Datasets;
- evidence/provenance;
- verified Line relationships;
- EN/ES structured translations;
- public relationship HTML;
- public JSON-LD relation graph;
- bounded multilingual SEO identity relevant to these Research resources;
- exact rollback of the aggregate operating session.

## What remains outside M5

M5 completion does not close the primary #97 execution issue.

Next milestones remain:

- **M6** — broader SEO/GEO diagnostics, deterministic remediation and post-remediation readiness.
- **M7** — bounded Theme design controls.
- **M8** — install/connect the real `eduardoyauriluna.com` and prove actual ChatGPT-originating operations, rendered verification, auditability, read-only mode and revocation.

No repository acceptance may be described as real-site deployment evidence.
