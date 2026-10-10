# Next implementation slice

After this branch is green and merged:

1. Add `apply_pages()` to the blueprint compiler/orchestrator.
2. Require zero `blocked` Page operations before batch execution.
3. Apply only the plans already produced by `Page Resource` through `Executor`.
4. Return snapshot IDs and created Page IDs for verification/rollback.
5. Re-run compilation after Apply and require all Page operations to become `already-matching` (or `hydrate` when structured slots are declared).
6. Add structured Page slot hydration to the blueprint schema.
7. Only then extend the compiler to Insights and academic records.

This preserves microphase development and keeps CI as the gate between each write-capable increment.
