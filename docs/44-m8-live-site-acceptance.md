# M8 — Real-site operating acceptance

## Status

M8 is the final acceptance milestone for the first ChatGPT-governed Research Manager MVP on `eduardoyauriluna.com`.

M1–M7 prove the control plane, bounded content operations, research objects, SEO/GEO and Theme design contracts in repository/CI. M8 proves the same path against the real site:

```text
Eduardo → ChatGPT/operator client → authenticated Research Manager → WordPress/Research Theme → rendered site → verification evidence
```

The acceptance client is `scripts/m8-manager-client.php`. It is deliberately **not** a generic WordPress REST client. It can call only an explicit allowlist of Research Manager routes.

## Security contract

The live bearer credential is supplied only through `ERM_TOKEN` in the process environment. It must never be stored in Git, a JSON config, a CI variable printed to logs, or an evidence artifact.

Production calls require HTTPS, TLS peer/host verification, no HTTP redirects, a fresh request ID, a fresh nonce and a current Unix timestamp. The client never records the Authorization header. Evidence redaction additionally removes token/password/secret-shaped values.

`--apply` is mandatory before any Apply call can execute. Without it, Page/Insight/SEO/Design commands stop after the exact Preview/plan. Acceptance mutations default to `rollback_after_verify=true` so the real execution path can be proven without leaving temporary state behind.

## Configuration

Copy `config/m8-live-acceptance.example.json` to a local ignored path, for example `tmp/m8-live-acceptance.json`, then replace every `REPLACE_...` value only after live preflight has been inspected.

Do not add a token field. Use:

```bash
export ERM_BASE_URL='https://eduardoyauriluna.com'
export ERM_TOKEN='erm1_...'
```

The token is generated once from **WordPress → Tools → Research Manager Remote** and stored only as a password hash by the Manager.

## Execution order

### 1. Static client contract

```bash
php scripts/m8-manager-client.php contract --config=config/m8-live-acceptance.example.json
```

This performs no network call and needs no token.

### 2. Live preflight

```bash
php scripts/m8-manager-client.php preflight \
  --config=tmp/m8-live-acceptance.json \
  --evidence=tmp/m8-preflight.json
```

Preflight reads live status, versions, capabilities, readiness, Page and Insight inventories, aggregate SEO/GEO readiness, design state/variants and rendered design readiness. It also checks the canonical domain, EN/ES contract, remote-enabled state and presence of the mutation transport.

### 3. Preview acceptance operations

Run each configured lifecycle without `--apply` first:

```bash
php scripts/m8-manager-client.php page --config=tmp/m8-live-acceptance.json
php scripts/m8-manager-client.php insight --config=tmp/m8-live-acceptance.json
php scripts/m8-manager-client.php seo --config=tmp/m8-live-acceptance.json
php scripts/m8-manager-client.php design --config=tmp/m8-live-acceptance.json
```

The design command may choose a safe alternate Theme preset automatically if `design.changes` is empty. It only chooses from the Theme-provided bounded contract and skips inaccessible palettes.

### 4. Real Apply + Verify + Rollback

After the Preview is approved:

```bash
php scripts/m8-manager-client.php full \
  --config=tmp/m8-live-acceptance.json \
  --apply \
  --evidence=tmp/m8-full.json
```

For generic Page/Insight operations the client uses the M2 lifecycle. SEO/GEO and design use their specialised bounded M6/M7 lifecycles. Rendered verification is requested where supported. If rollback is configured, the client verifies the restored design baseline and re-reads readiness after rollback.

A Page or Insight may instead use `rollback_after_verify=false` when the acceptance change is approved final content that should remain on the site.

## Read-only acceptance

From the local Remote Manager console, reduce the same connection to read scopes only (at minimum `site.read`; include diagnostics/design/SEO read scopes only when the desired read checks require them). Then run:

```bash
php scripts/m8-manager-client.php readonly-probe \
  --config=tmp/m8-live-acceptance.json \
  --evidence=tmp/m8-readonly.json
```

The probe must prove both:

1. `/capabilities` remains readable and reports `writes_enabled=false`;
2. a write-plan attempt is rejected with HTTP 403 `scope_denied` before any operation can be prepared or applied.

Restore the intended scopes locally after the proof.

## Revocation acceptance

From the local Remote Manager console, revoke the credential. With the old token still present only in the operator environment, run:

```bash
php scripts/m8-manager-client.php revoked-probe \
  --config=tmp/m8-live-acceptance.json \
  --evidence=tmp/m8-revoked.json
```

The old credential must receive HTTP 401 `authentication_failed`. Generate a fresh token only if continued remote operation is required.

## Audit evidence

The local **Research Manager Remote** screen shows the recent bounded audit trail. During M8, confirm that request IDs from the generated evidence files appear in the local audit list and that no bearer token or credential hash is displayed.

This local check is intentional: credential issuance, scope changes and revocation remain local security controls rather than remote self-escalation capabilities.

## Definition of Done mapping

M8 closes only after evidence proves:

- live supported Theme + Manager and canonical `eduardoyauriluna.com` state;
- authenticated status/capabilities and inventories without SSH;
- one real Page lifecycle with rendered verification;
- one real Insight lifecycle with rendered verification;
- one real SEO/GEO remediation lifecycle with rendered verification;
- one real bounded design lifecycle and aggregate rendered readiness;
- operation IDs/status retrieval and rollback;
- audit correlation with no secrets;
- read-only scopes blocking writes;
- local credential revocation immediately blocking the old token;
- no normal acceptance task requiring WordPress password sharing, SSH, direct DB access or arbitrary code execution.

Any routine outcome that still needs a bypass is recorded on issue #97 as a Manager backlog gap. M8 is not declared complete merely because the repository tests are green.

## Evidence handling

By default the client writes JSON to `tmp/`, already excluded by `.gitignore`. Evidence contains routes, request IDs, HTTP statuses and Manager responses needed to prove acceptance. Do not commit live acceptance artifacts until they have been reviewed for publication suitability; they may contain non-secret site content or internal operational metadata even though credentials are redacted.
