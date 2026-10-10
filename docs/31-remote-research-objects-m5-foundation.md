# Remote Manager M5 — Research Object foundation

## Purpose

M5 extends the ChatGPT-governed Manager from Pages and Insights to structured academic/research entities while preserving the same rule:

`ChatGPT → authenticated Manager → bounded shared service → WordPress Research resource → Research Theme → Verify`

This first M5 slice covers the four object families already implemented by the shared `Object Editor`:

- Publications / Research Outputs (`output` / `research_output`)
- Research Projects (`project` / `research_project`)
- Research Software (`software` / `research_software`)
- Research Datasets (`dataset` / `research_dataset`)

Research Lines and translation pairing remain separate M5 slices because they already have dedicated shared editors and evidence rules.

## Remote surface

Read:

- `GET /research-manager/v1/research-objects`
- `GET /research-manager/v1/research-objects/{kind}/{post_id}`

Exact mutation lifecycle:

- `POST /research-manager/v1/research-objects/plan`
- `GET /research-manager/v1/research-objects/plans/{plan_id}`
- `POST /research-manager/v1/research-objects/plans/{plan_id}/apply`
- `GET /research-manager/v1/research-objects/operations/{operation_id}`
- `POST /research-manager/v1/research-objects/operations/{operation_id}/verify`
- `POST /research-manager/v1/research-objects/operations/{operation_id}/rollback`

Supported operations:

- `object-create`
- `object-update`

The gateway delegates creation/update/rollback to the existing shared `Eduardo_Research_Manager_Object_Editor`. It does not expose arbitrary post types, arbitrary metadata or a generic WordPress proxy.

## Scope model

Read requires:

- `research.read`

Planning/Applying requires both:

- `operations.apply`
- `research.write`

Rollback requires both:

- `operations.rollback`
- `research.write`

Verification requires:

- `site.diagnostics`
- `research.read`

This keeps the generic operation permission and the research-domain permission independently revocable.

## Evidence gate

All Research Object creation is evidence-sensitive under the existing Manager Plan contract. Evidence-sensitive updates remain governed by the same shared rules.

Remote payloads therefore carry:

- `evidence_confirmed`
- `evidence_reference`

The gateway does not invent a second evidence policy. It passes these values into `Object Editor`, which builds the same shared mutation plan used by the local Manager.

A Preview can exist while Apply is blocked. For an evidence-required plan:

- `risk = evidence-required`
- `confirmation_class = evidence-explicit`
- `apply_allowed = false` until the shared evidence gate is satisfied

This is intentional: ChatGPT may inspect and explain a planned academic change without being able to apply an unsupported claim.

## Safety and exactness

The M5 foundation keeps:

- plan expiry;
- connection ownership;
- request-id idempotency;
- explicit Apply confirmation;
- source revision fingerprint;
- Object Editor baseline checksum for target-level stale Preview protection;
- shared semantic verification;
- optional rendered verification;
- snapshot-backed rollback;
- audit correlation;
- bounded supported kinds and fields.

For non-public/draft Research Objects, rendered verification succeeds as an explicit safe skip with `draft-or-non-public`; it never pretends the resource is publicly rendered.

## Capability discovery

`/capabilities` exposes `research_object_control` with:

- milestone `M5`;
- supported kinds and their fields;
- inventory/inspection support;
- exact operations;
- evidence-gate contract;
- lifecycle semantics;
- transport base;
- stale/idempotency guarantees;
- explicit `arbitrary_wordpress_proxy = false`.

## Fresh-WordPress acceptance

The M5 foundation quality gate proves:

1. `research.write` is required independently of generic Apply permission;
2. unsupported object kinds are rejected;
3. an evidence-required creation plan cannot Apply without evidence;
4. evidence-confirmed creation works for Output, Project, Software and Dataset;
5. repeated request IDs are idempotent;
6. each created object can be inspected remotely;
7. stored verification passes;
8. draft rendered verification is safely skipped;
9. bounded updates work for all four object kinds;
10. stale target Preview is rejected;
11. inventory returns the four managed families;
12. update rollbacks restore prior state;
13. creation rollbacks restore the initial absence baseline.

## Still pending in M5

This slice does **not** complete M5. Next slices cover:

- Research Lines remote control through the existing Line Editor;
- explicit evidence/provenance read and mutation semantics beyond the foundation gate;
- Research Object relationships in real academic scenarios;
- EN/ES translation pairing through the existing Translation Editor;
- public rendered verification for representative object schema types;
- aggregate M5 acceptance.

## Status boundary

A green M5 foundation CI run means the four Research Object families are implemented and verified on fresh WordPress through the remote Manager. It does not mean those operations have already been performed on the live `eduardoyauriluna.com` site; real-site proof remains M8.
