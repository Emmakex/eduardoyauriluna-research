# Remote Manager M5 — Research Lines

## Purpose

This M5 slice exposes first-class Research Lines through the same ChatGPT-governed Manager model used for Pages, Insights and Research Objects.

`ChatGPT → authenticated Research Manager → shared Line Editor → Research Line → Theme → Verify`

The remote layer delegates creation, update and rollback to the existing evidence-aware `Eduardo_Research_Manager_Line_Editor`. It does not expose arbitrary post fields or metadata.

## Remote surface

Read:
- `GET /research-manager/v1/research-lines`
- `GET /research-manager/v1/research-lines/{post_id}`

Exact mutation lifecycle:
- `POST /research-manager/v1/research-lines/plan`
- `GET /research-manager/v1/research-lines/plans/{plan_id}`
- `POST /research-manager/v1/research-lines/plans/{plan_id}/apply`
- `GET /research-manager/v1/research-lines/operations/{operation_id}`
- `POST /research-manager/v1/research-lines/operations/{operation_id}/verify`
- `POST /research-manager/v1/research-lines/operations/{operation_id}/rollback`

Operations:
- `line-create`
- `line-update`

## Bounded fields

The Manager controls only the Line contract:
- status / slug / title / excerpt / content;
- language;
- evidence status;
- research status;
- central question;
- ordering;
- topics;
- methods.

## Security and evidence

Read uses `research.read`. Mutations require the generic operation scope plus `research.write`.

Creation remains evidence-required under the existing shared Plan contract. Remote payloads pass `evidence_confirmed` and `evidence_reference` into the Line Editor; the REST gateway does not implement an alternative evidence policy.

The lifecycle preserves:
- Preview without mutation;
- plan expiry;
- explicit confirmation;
- request-id idempotency;
- source revision protection;
- Line Editor target checksum stale protection;
- stored semantic verification;
- optional rendered verification;
- snapshot-backed rollback;
- audit correlation.

Draft/non-public Lines are represented explicitly as a safe rendered-verification skip rather than pretending to be publicly rendered.

## Fresh WordPress acceptance

The M5 Line quality gate proves:
1. capability discovery;
2. evidence-blocked creation without confirmation/reference;
3. evidence-confirmed creation;
4. idempotent planning;
5. remote inspection and inventory;
6. updates for evidence status, research status, central question, topics and methods;
7. stale Preview rejection after an external target mutation;
8. stored verification;
9. safe rendered handling for draft Lines;
10. update rollback;
11. creation rollback to absence baseline.

## M5 status after this slice

Once this slice is green and merged, the five primary Research resource families are remotely operable at repository/CI level:
- Research Lines;
- Publications / Outputs;
- Projects;
- Software;
- Datasets.

M5 still remains open for translation pairing, deeper provenance/evidence read surfaces, representative public rendered schema verification, relationship acceptance and aggregate M5 acceptance. Real-site proof remains M8.
