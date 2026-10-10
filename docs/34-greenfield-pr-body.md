Research Manager Greenfield foundation.

- Makes Greenfield the explicit default operating scenario for the clean Research WordPress installation.
- Keeps Migration explicit and isolates legacy discovery/mapping capabilities from normal Research creation.
- Adds a Greenfield orchestration boundary over the existing Theme-bound Resource Services.
- Adds declarative blueprint validation/summarization and an initial Research blueprint example.
- Adds non-mutating Page blueprint compilation with `create`, `already-matching` and `blocked` classifications.
- Reuses Page Resource creation plans rather than duplicating WordPress mutations.
- Preserves Executor ownership for writes, snapshots, verification and rollback.
- Adds CI syntax/static guards and runtime assertion fixtures.
- Documents architecture, security boundary, acceptance criteria, non-goals and the next microphase.

No blueprint Apply is introduced in this PR. The next microphase will add Page Apply/verify/rollback only after this boundary is green and merged.
