# Remote Research Evidence / Provenance — M5d

Status: repository implementation slice for the ChatGPT-governed Research Manager. Real `eduardoyauriluna.com` validation remains M8.

## Purpose

M5d exposes the existing Evidence Editor through the authenticated Manager gateway so academic identity and evidence/provenance can be governed from ChatGPT without editing WordPress options directly.

The operating path is:

`ChatGPT → authenticated Research Manager → Evidence Editor → controlled option plans → Verify / provenance readback → Rollback`

The remote gateway is an adapter. It does not replace the Evidence Editor and does not expose a generic WordPress option API.

## Remote scopes

M5d adds two bounded scopes:

- `evidence.read` — read academic identity, evidence groups, records and provenance.
- `evidence.write` — prepare/apply/rollback evidence-record mutations.

Academic identity is treated as higher impact. `identity-update` additionally requires `identity.write`; possession of `evidence.write` alone is intentionally insufficient.

Apply and Rollback continue to require the generic lifecycle scopes:

- `operations.apply`
- `operations.rollback`

Verify requires `site.diagnostics` plus `evidence.read`.

## Read surface

Base: `/research-manager/v1/research-evidence`

- `GET /research-evidence` — academic identity summary plus evidence groups/counts.
- `GET /research-evidence/identity` — current stored academic identity and evidence metadata.
- `GET /research-evidence/{group}` — records for one supported Evidence Editor group.
- `GET /research-evidence/{group}/{record_id}` — inspect one exact record.

Supported groups are inherited from the shared Evidence Editor:

- `profile` → About
- `affiliations` → About, CV
- `identifiers` → About, Contact
- `research_questions` → Research
- `methods` → Research
- `experience` → CV
- `education` → CV
- `awards` → CV
- `contact` → Contact

The Manager returns this surface mapping as provenance context; M5d does not claim rendered-page verification for those surfaces. Representative rendered/public-schema acceptance is a separate final M5 item.

## Mutation operations

The remote Plan endpoint accepts exactly:

- `identity-update`
- `evidence-create`
- `evidence-update`
- `evidence-delete`

No arbitrary option name, raw meta key, PHP callback, SQL or WordPress object mutation is accepted.

### Identity update

Payload:

- `name`
- `evidence_confirmed`
- `evidence_reference`

Requirements:

- `evidence.write`
- `identity.write`
- `operations.apply` for Plan/Apply
- explicit Apply confirmation
- evidence confirmation/reference through the shared evidence-required gate

### Evidence create/update

Payload:

- `group`
- optional `record_id` for update
- bounded `data`
- `evidence_confirmed`
- `evidence_reference`

The shared Evidence Editor remains authoritative for record normalization. Supported record content includes its bounded text/date/organization fields, summary, URL, status and optional ES translation object.

### Evidence delete

Payload:

- `group`
- `record_id`
- `evidence_confirmed`
- `evidence_reference`

Deletion is evidence-gated and snapshot-backed. It is not connected to the broader destructive-delete scope because it deletes one controlled Evidence Editor record, not an arbitrary WordPress resource.

## Exact lifecycle

Every mutation follows:

`Plan → Preview → explicit confirmation → Apply → Verify → Rollback`

Properties:

- Plan TTL: 15 minutes.
- Request-ID idempotency at the REST transport.
- Plan ownership tied to the remote connection identity.
- Evidence Editor resource baseline checksum checked again at Apply.
- A changed identity/evidence store after Preview produces stale-Preview rejection.
- Apply delegates to `Evidence_Editor::apply_preview()`.
- Rollback delegates to the existing snapshot/executor path.
- The remote operation stores enough private baseline state to verify exact rollback; that baseline state is removed from public API responses.

## Provenance readback

Verify returns provenance alongside semantic stored-state verification.

For evidence records it includes:

- group
- stable record ID
- verified/unverified/absent status
- evidence reference
- verified timestamp when present
- Theme surfaces affected by that evidence group

For academic identity it includes:

- resource = identity
- evidence reference
- verified timestamp
- About/Contact surface mapping

This provenance data is operational evidence for ChatGPT and the audit trail. It is not a claim that the public Theme surface has already been rendered and inspected; final M5 public-schema acceptance remains separate.

## Security boundaries

M5d deliberately enforces:

1. `evidence.read` and `evidence.write` are independent.
2. `evidence.write` does not grant `identity.write`.
3. Identity scope is checked when the plan is created, when it is applied, and when it is rolled back.
4. The local execution user must still have `manage_options`.
5. No endpoint accepts arbitrary WordPress option keys.
6. REST does not write the evidence options directly; the shared Evidence Editor creates the Plan and the shared Executor applies it.
7. Plan/operation responses never expose private rollback baseline state.
8. Audit records correlate request ID, connection, operation, group/record and evidence reference without credential secrets.

## CI acceptance

`Research Manager Remote M5 Evidence Quality` installs a clean WordPress with the Research Theme and Manager and proves:

- `evidence.write` scope enforcement;
- independent `identity.write` enforcement;
- capability discovery for the new scopes and M5 evidence surface;
- authenticated inventory/identity inspection;
- non-mutating evidence Preview;
- request-ID idempotency;
- explicit confirmation gate;
- evidence create + Verify + provenance readback;
- stale Preview rejection after external evidence-store drift;
- evidence update + Verify;
- evidence delete + Verify;
- reverse-order rollback delete → update → create to the exact initial evidence baseline;
- identity update + provenance Verify + exact rollback;
- final identity and evidence options exactly match their starting baseline.

## Remaining M5 after this slice

After M5d merges, M5 still requires:

1. representative remote relationship/public-schema acceptance across Research resources;
2. one aggregate fresh-WordPress M5 operating acceptance covering Lines, Objects, translations, evidence/provenance and rendered/schema checks.

Real operations against `eduardoyauriluna.com` remain M8 and must not be claimed from repository/CI evidence alone.
