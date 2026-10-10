# Greenfield implementation notes

## Decisions

- `greenfield` is the default operating scenario for this Research product.
- Migration is explicit and is never inferred from the presence of existing records or diagnostics.
- Resource Services remain reusable and scenario-neutral where possible.
- `Eduardo_Research_Manager_Greenfield` is an orchestration boundary, not a second implementation of resource creation.
- `Eduardo_Research_Manager_Blueprint` validates declarative desired state; it does not scan WordPress to infer desired state.
- Gutenberg is not the layout source for the Research frontend.
- Existing validation, plan checksums, snapshots, verification and rollback remain safety infrastructure in both scenarios.

## Next code step

Compile the validated blueprint into bounded creation/hydration operations using the existing Resource Services, beginning with Theme-owned Pages. The compiler must be idempotent and must report `create`, `hydrate`, `already-matching` or `blocked` rather than silently guessing how to reconcile unknown legacy content.
