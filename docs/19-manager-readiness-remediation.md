# Research Manager — readiness remediation

## Purpose

Diagnostics already identifies structural drift in a Research WordPress installation. Readiness remediation converts a narrow subset of those findings into the same checksummed mutation workflow used elsewhere by the Manager:

`Diagnostics → Preview → Apply → Verify → Rollback`

The remediation layer is structural only. It does not repair academic claims, evidence, identifiers, publications, project facts or other content that requires human/source verification.

## Auto-remediable findings

### Missing Theme-owned Page

When a required Page from the active Research preset is missing, remediation delegates to the Page Resource creation contract. The created Page must match the preset slug, role and model, and carries the normal Manager provenance token so rollback can delete only the Page created by that plan.

### Theme-owned Page drift

For an existing Page resolved by the active preset, remediation may restore only:

- `post_status` to `publish`;
- `_eduardo_research_role` to the preset role;
- `_eduardo_research_model` to the preset model.

`post_status` is not opened as a generic Manager field. The mutation plan accepts `post_status=publish` only when the target is an existing Theme-owned Research Page resolved from the active preset.

The structural Page role/model are not academic evidence claims. This is deliberately distinct from metadata such as `_research_role` on a Research Project, which remains evidence-sensitive.

### Static Research Home routing

Remediation may restore:

- `show_on_front = page`;
- `page_on_front = <contracted Research Home ID>`.

These WordPress core options remain unavailable to generic Manager option writes. The plan validator only accepts the exact Research Home assignment when that contracted Page exists.

### Native language contract

Remediation may restore the Theme-native language option to:

- default: `en`;
- enabled languages: the languages exposed by the active Research preset (currently `en`, `es`).

The option remains fully snapshot/rollback capable.

## Manual-only findings

The Manager intentionally refuses automatic remediation for:

- inactive or incompatible Research Theme contract;
- missing Research post-type registrations;
- malformed evidence store;
- any finding requiring academic/source review;
- arbitrary WordPress pages, posts or core options outside the Research contract.

These return a `research_manager_remediation_manual_only` error instead of manufacturing a mutation plan.

## Service API

`Eduardo_Research_Manager::remediation()` exposes `Eduardo_Research_Manager_Remediation`.

Primary operations:

- `inspect()` — returns current non-passing readiness findings and whether each is auto-remediable;
- `build_plan($check_id)` — translates one current diagnostic finding into a bounded Research Manager plan;
- `verify($check_id)` — reruns diagnostics and confirms that the selected check now passes.

There is intentionally no blind `fix_all()` operation. Each diagnostic finding remains an explicit unit that can be previewed, applied, verified and rolled back independently.

## Safety invariants

- Remediation plans use the existing checksummed Plan and Executor.
- No evidence gate is bypassed.
- No generic arbitrary `post_status` publishing is introduced.
- No generic arbitrary `show_on_front` or `page_on_front` mutation is introduced.
- Missing Page rollback deletes only Manager-owned resources carrying the creation token.
- Existing state is restored from snapshots during rollback.
