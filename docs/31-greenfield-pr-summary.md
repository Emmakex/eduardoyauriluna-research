# PR summary

## Why

Research starts from a clean WordPress installation. The Manager therefore needs a first-class Greenfield path rather than migration-style interpretation.

## What

- explicit Greenfield/Migration runtime mode;
- direct Greenfield orchestration over existing Resource Services;
- blueprint validation and summary;
- non-mutating Page blueprint compiler;
- CI/static guards and runtime assertion fixtures;
- architecture and scenario documentation.

## Safety

No blueprint writes are introduced in this slice. Existing Executor ownership is preserved. Contract drift is blocked rather than silently adopted.

## Next

Add Page blueprint Apply/verify/rollback orchestration after this branch is green and merged.
