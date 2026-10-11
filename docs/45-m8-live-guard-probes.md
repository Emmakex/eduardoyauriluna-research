# M8 — live idempotency and stale-plan guard proofs

These probes complement `scripts/m8-manager-client.php`. They prove two M2 safety properties on the real site without introducing a generic WordPress transport.

## Idempotency

```bash
export ERM_BASE_URL='https://eduardoyauriluna.com'
export ERM_TOKEN='erm1_...'
php scripts/m8-manager-guard-probes.php idempotency \
  --config=tmp/m8-live-acceptance.json \
  --evidence=tmp/m8-idempotency.json
```

The probe reads the bounded Theme design contract, chooses one safe alternate preset and sends the **same design Preview request twice with the same request ID**, using a fresh nonce/timestamp for transport validity. The second response must:

- return HTTP 200;
- expose `idempotent_replay=true`;
- return the exact same `plan_id` as the first Preview.

No Apply call occurs, so this proof does not mutate the real site.

## Stale plan rejection

Required scopes: `design.read`, `design.write`, `operations.apply`, `operations.rollback`.

```bash
php scripts/m8-manager-guard-probes.php stale-design \
  --config=tmp/m8-live-acceptance.json \
  --apply \
  --evidence=tmp/m8-stale-design.json
```

The probe:

1. stores the exact baseline design state;
2. chooses two Theme-owned safe alternatives from one bounded dimension;
3. creates Preview A and Preview B from the same baseline;
4. applies B;
5. proves applying A is rejected with HTTP 409 `stale_revision`;
6. verifies B;
7. rolls B back;
8. reads `/design` again and requires exact equality with the original baseline.

If an exception occurs after B has been applied, the probe attempts an emergency rollback before returning failure evidence.

## Combined guard proof

```bash
php scripts/m8-manager-guard-probes.php all \
  --config=tmp/m8-live-acceptance.json \
  --apply \
  --evidence=tmp/m8-guards.json
```

`ERM_TOKEN` is never accepted from the JSON config. TLS verification is mandatory, redirects are disabled, routes are explicitly allowlisted, and evidence is written under ignored `tmp/` with secret-shaped fields redacted.

These proofs do not replace the Page, Insight, SEO/GEO, design readiness, read-only and revocation acceptance in `docs/44-m8-live-site-acceptance.md`; they add live evidence for retry safety and stale-state protection before issue #97 can be closed.
