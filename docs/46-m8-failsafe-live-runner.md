# M8 fail-safe live runner

## Why this exists

The first real remote Page mutation on `eduardoyauriluna.com` proved that Apply and stored semantic verification worked, but rendered verification failed on the HTML route language. The original M8 client asserted the Verify response before reaching its configured rollback. A Verify failure could therefore leave an applied reversible operation pending a manual rollback.

The production acceptance path must not depend on a successful Verify to reach rollback.

## Authoritative live command

For any M8 command that may use `--apply`, run the fail-safe launcher:

```bash
php scripts/m8-manager-safe.php <command> --config=<path> --apply --evidence=<path>
```

The launcher executes `scripts/m8-manager-client.php` unchanged. If the client exits non-zero after a successful Apply and the evidence contains no successful rollback for that operation, the launcher uses the existing scoped Research Manager rollback endpoint to attempt an emergency rollback.

No new server mutation surface is introduced.

## Guardrails

- `ERM_TOKEN` remains environment-only.
- `ERM_BASE_URL` must be HTTPS.
- TLS peer and host verification stay enabled.
- redirects stay disabled.
- emergency rollback is limited to operation IDs returned by a successful Apply recorded in the current evidence file.
- supported rollback routes remain bounded to the existing generic, SEO/GEO and design operation endpoints.
- evidence augmentation contains request IDs and responses but never writes the bearer token.
- the original acceptance failure remains a failure even when emergency rollback succeeds; rollback safety does not convert a failed verification into a pass.

## Route-language regression

The live Page failure also established an important frontend rule: the Theme route runtime is authoritative for document language. The unprefixed English surface must render `<html lang="en">`; `/es/...` must render `<html lang="es">`, even when the global WordPress site locale is Spanish.

`Research M8 Route Language Quality` reproduces this with a clean WordPress installation configured with `WPLANG=es_ES`, then checks both raw HTML and the Manager's rendered verifier for Home EN and ES.
