# Academic Connections — Implementation Plan v1

This document converts the Researcher preset connection requirements into implementation contracts and validation steps.

## Principles

1. **Verified identifiers over name matching.** Never claim an external author profile only because a name matches.
2. **Read before write.** Initial integrations should prefer read/reconcile paths before enabling external mutations.
3. **Explicit publishing confirmation.** External publication/deposit actions require a deliberate user action.
4. **No Google Scholar scraping dependency.** Scholar support is based on profile linkage, import tools and indexable scholarly pages.
5. **Secrets stay outside Git.** Credentials are injected through environment or secure WordPress configuration.
6. **Every external value has provenance.** Store provider and last-sync timestamp alongside imported metadata.
7. **Local data is never overwritten silently.** Reconciliation must show conflicts.

---

## Shared connection interface

Each adapter should expose a normalized contract:

```text
get_status()
get_capabilities()
validate_configuration()
connect()              # where authentication applies
sync_preview()
apply_sync(selection)  # only after preview/selection
health_check()
disconnect()
```

Optional methods:

```text
lookup(identifier)
search(query)
create_draft(payload)
publish(external_id)   # elevated permission + explicit confirmation
```

### Normalized status object

```json
{
  "provider": "orcid",
  "status": "connected",
  "mode": "oauth",
  "identifier": "...",
  "last_sync_at": "...",
  "last_success_at": "...",
  "last_error": null,
  "capabilities": []
}
```

---

# 1. ORCID

## v1 objective

Obtain and store an authenticated ORCID iD, read public researcher data and reconcile public works with local publications.

Official references:

- https://info.orcid.org/what-is-orcid/services/public-api/
- https://info.orcid.org/documentation/api-tutorials/api-tutorial-get-and-authenticated-orcid-id/
- https://info.orcid.org/what-is-orcid/services/member-api/

## Authentication

Preferred: OAuth using ORCID Public API credentials.

Environments:

- sandbox for development;
- production only after callback and token handling are validated.

## Local fields

- orcid_id
- orcid_verified_at
- access scope metadata
- last_sync_at
- last_sync_status

Do not persist client secret in WordPress post meta or frontend-readable options.

## Read mapping

Candidate public fields:

- person name
- biography
- keywords
- websites
- external identifiers
- employment/education where public
- works where public

Imported data must be marked as external and shown in a reconciliation preview before replacing local curated copy.

## v1 acceptance tests

- [ ] Sandbox credentials validate.
- [ ] OAuth callback rejects invalid state.
- [ ] Authenticated ORCID iD is stored.
- [ ] Public profile can be fetched.
- [ ] Missing/private fields do not break sync.
- [ ] Works can be listed for reconciliation.
- [ ] Local fields are not overwritten without selection.
- [ ] Disconnect removes local token material but does not erase curated profile data.

## Write policy

Disabled in v1.

Writing to records is reserved for a future Member API integration with explicit user permissions.

---

# 2. Google Scholar

## v1 objective

Make scholarly output pages indexable and connect the public Scholar profile once it exists.

Official reference:

- https://scholar.google.com/intl/en-us/scholar/inclusion.html

## Integration mode

`manual_link + import_only + academic_meta`

There is no core scraping adapter.

## Publication page requirements

- unique stable URL per work;
- visible title;
- visible author list;
- abstract or scholarly content where appropriate;
- accessible PDF link when publication rights allow;
- Highwire-style citation meta fields;
- crawlable canonical URL;
- no content hidden behind scripts that prevent indexing.

## Supported imports

- BibTeX
- CSV
- manual DOI entry with Crossref hydration

## v1 acceptance tests

- [ ] Scholar profile ID can be stored manually.
- [ ] Profile URL is rendered in researcher identity links.
- [ ] Scholarly pages emit `citation_title`.
- [ ] One `citation_author` tag is emitted per author.
- [ ] Optional citation fields are omitted rather than fabricated.
- [ ] Each publication has one stable canonical URL.
- [ ] No Scholar scraping code exists in core.

---

# 3. Zenodo

## v1 objective

Read/link existing records and store DOI information safely. Use sandbox before any publishing workflow.

Official reference:

- https://developers.zenodo.org/

## Environments

- sandbox: `sandbox.zenodo.org`
- production: `zenodo.org`

## v1 read/link capabilities

- search/fetch record;
- link local research output to Zenodo record;
- hydrate DOI and archive URL;
- record version/concept DOI when provided;
- show last validation timestamp.

## v1 acceptance tests

- [ ] Sandbox token is read from secure configuration.
- [ ] Existing record can be fetched.
- [ ] DOI is validated and stored.
- [ ] Local output can be linked without external mutation.
- [ ] Invalid or revoked token produces a recoverable connection error.

## v2 publish flow

```text
Local output
  → Validate required metadata
  → Preview Zenodo payload
  → Explicit Create Draft action
  → Upload files
  → Preview remote draft
  → Explicit Publish action
  → Receive DOI
  → Persist DOI + external record ID
  → Audit event
```

A normal WordPress `Save` or `Publish` action must never automatically publish to Zenodo.

---

# 4. OpenAlex

## v1 objective

Confirm an author entity, retrieve author/work metadata and support citation/discovery metrics with provenance.

Official reference:

- https://help.openalex.org/api/

## Author matching flow

```text
Search candidate
  → show candidate IDs + works + institutions
  → human confirms correct author
  → persist OpenAlex Author ID
```

No automatic claim from exact-name match.

## v1 acceptance tests

- [ ] Candidate authors can be searched.
- [ ] A candidate is not stored until confirmed.
- [ ] Confirmed author can be fetched by ID.
- [ ] Works can be reconciled by DOI first, normalized title second.
- [ ] Metrics show source and `retrieved_at` timestamp.
- [ ] Missing OpenAlex author is treated as expected state for new researchers.

---

# 5. Crossref

## v1 objective

Use DOI as a high-confidence metadata hydration path for scholarly works registered with Crossref.

Official reference:

- https://www.crossref.org/documentation/retrieve-metadata/rest-api/

## Lookup flow

```text
DOI entered
  → Normalize DOI
  → Determine registration agency when needed
  → Query Crossref work endpoint
  → Show metadata preview
  → User accepts selected fields
  → Persist values + provenance
```

## v1 acceptance tests

- [ ] Valid Crossref DOI returns metadata.
- [ ] Invalid DOI produces human-readable error.
- [ ] Non-Crossref DOI is not treated as absent research output.
- [ ] Author order is preserved.
- [ ] Existing manually curated field values are not silently overwritten.
- [ ] Production requests support a configured contact email for polite access.

---

# 6. GitHub Research Software

## v1 objective

Allow explicitly selected repositories to become citable Research Software entities.

## Selection rule

Nothing is automatically labelled research software.

## Candidate metadata

- repository URL
- name
- description
- license
- latest release
- tags/topics
- CITATION.cff presence
- linked Zenodo archive

## v1 acceptance tests

- [ ] GitHub account is linked.
- [ ] User selects repository explicitly.
- [ ] Selected repo can populate Research Software metadata preview.
- [ ] CITATION.cff is detected where present.
- [ ] Non-selected repositories never appear in public Research Software pages.

---

# Connection health UI

Each provider card should show:

```text
Provider
Status
Identifier
Mode
Capabilities
Last successful sync
Last error
[Check] [Sync preview] [Configure/Disconnect]
```

Status semantics:

- `not_configured` — no connection information;
- `connected` — configuration works;
- `degraded` — provider available but some capability failed;
- `error` — current configuration cannot perform minimum health check.

Do not present `manual_link` providers as API-connected.

---

# Data conflict strategy

For every imported field classify:

- **same** — local and remote normalized values agree;
- **local_only** — no remote value;
- **remote_only** — local empty, remote available;
- **conflict** — both contain different values.

UI actions:

- Keep local
- Use remote
- Merge (only on supported array fields)
- Ignore

Apply is explicit and logged.

---

# Provenance model

Imported data should carry:

- provider
- provider_identifier
- retrieved_at
- original value/hash where useful
- local editor decision

This allows future refreshes to distinguish imported metadata from manually curated text.

---

# Security checklist

- [ ] OAuth state/nonce validation.
- [ ] Secrets not committed to Git.
- [ ] Tokens not exposed to client-side JS unless protocol strictly requires it.
- [ ] External-write actions require capability + elevated permission.
- [ ] Callback URLs use HTTPS in production.
- [ ] Sensitive logs redact tokens/secrets.
- [ ] Disconnect revokes/deletes local token material where possible.
- [ ] Rate limits and provider errors are handled without retry storms.

---

# Implementation order

## Sprint A — zero-risk metadata foundation

1. Academic identifier storage
2. Crossref DOI lookup
3. Google Scholar academic meta renderer
4. Manual Scholar profile link
5. GitHub selected-repository model

## Sprint B — researcher identity integration

6. ORCID sandbox OAuth
7. ORCID public profile read
8. ORCID works reconciliation

## Sprint C — research infrastructure

9. Zenodo sandbox read/link
10. OpenAlex author confirmation
11. OpenAlex works/metrics reconciliation

## Sprint D — controlled external writing

12. Zenodo draft creation
13. Zenodo file upload
14. Explicit Zenodo publication flow
15. Evaluate ORCID Member API only if access/business case exists

---

# Current status

- Functional contracts: **defined**
- Example configuration: **defined**
- Provider capabilities: **defined**
- Validation checklist: **defined**
- Reusable Theme/Manager implementation: **pending**
- Production credentials: **not configured**
- Real researcher external identifiers: **not configured yet**
