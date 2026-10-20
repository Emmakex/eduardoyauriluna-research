# Manager portability acceptance checklist

Target: first unrelated WordPress installation (`emmake/nuevaweb`) operated from the separate ChatGPT project **Nueva web Emmake**.

- [ ] Plugin activates without Eduardo Research Theme.
- [ ] Product profile resolves to `generic-wordpress`.
- [ ] `/wp-json/research-manager/v1/status` works with `site.read`.
- [ ] `/wp-json/research-manager/v1/versions` works with `site.read`.
- [ ] `/wp-json/research-manager/v1/capabilities` advertises only compatible surfaces.
- [ ] `/wp-json/research-manager/v1/audit/baseline` works with `site.diagnostics`.
- [ ] Baseline never invents Lighthouse/CWV/a11y/Search Console/analytics measurements.
- [ ] Research-specific write routes are not registered on a generic site.
- [ ] Stable and temporary credentials remain revocable/generation-bound.
- [ ] MCP tenant can be added without changing code.
- [ ] MCP tenant isolation denies another tenant principal.
- [ ] `site_baseline_audit` returns the WordPress baseline through MCP.
- [ ] Existing `eduardo-research` tenant regression remains green.
- [ ] Installable ZIP is produced with deterministic version metadata.
