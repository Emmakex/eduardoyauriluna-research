# Greenfield review checklist

Before merge:

- Greenfield remains the default Research scenario.
- Existing content cannot auto-switch the Manager into Migration.
- Greenfield orchestration refuses to run when Migration is explicitly active.
- Blueprint validation accepts only known top-level resource groups and Theme-supported languages.
- Blueprint preview performs no writes.
- Page creation plans are produced by `Page Resource`, not duplicated in the compiler.
- Existing contract drift is blocked rather than silently adopted.
- No Gutenberg layout ownership is introduced.
- New PHP files pass syntax checks.
- Existing Manager integration CI remains green.
