# CI expectations

The new Greenfield workflow is intentionally lightweight: it verifies PHP syntax and static architectural invariants. The repository's existing clean-WordPress Manager workflow remains the integration authority for Theme/Manager behavior.

Runtime assertion fixtures added under `tests/` are prepared for integration into that clean WordPress harness; they are not presented as standalone PHP unit tests because they require WordPress bootstrap and the active Research Theme contract.
