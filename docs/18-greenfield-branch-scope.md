# Branch scope — feature/research-manager-greenfield-mode

This branch intentionally contains the scenario boundary plus the first non-mutating blueprint compilation slice.

It does not yet execute a complete blueprint. Page compilation currently classifies known Theme Pages as `create`, `already-matching` or `blocked` and delegates creation-plan construction to the existing Page Resource service.

This keeps the first merge small enough to establish the correct architecture before adding blueprint Apply orchestration and academic resource compilation.
