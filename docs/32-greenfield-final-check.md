# Final pre-PR checks

- Branch starts at current documented `main` Greenfield/Migration scenario decision.
- No legacy branch code is merged into this feature branch.
- New services are additive and existing Resource Service contracts are reused.
- Page compiler call signature matches `Page Resource::build_creation_plan(string $key, string $intent = '')`.
- Existing aligned Page detection uses `page_id` and `contract_aligned` from Page Resource inspection.
- New workflow syntax-checks every PHP test fixture in the Manager tests directory.
