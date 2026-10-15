# Research Manager v1 — production closeout

## Status

This document records the final production-closeout contract for the single-site Research Manager v1 used on `eduardoyauriluna.com`.

The functional v1 production acceptance has been exercised through the authenticated Manager boundary. The final closeout release is **Research Manager 0.10.4 + Research Theme 1.0.0**. Version 0.10.4 does not add a new website operation; it reconciles the truthful `/capabilities` closeout state after the completed M8 acceptance and adds the final read-only acceptance runner.

The final gate after deploying 0.10.4 is read-only: `/status`, `/versions` and `/capabilities` with a temporary credential granting exactly `site.read`. No production content mutation is required for this gate.

## Accepted operating boundary

The accepted v1 is a **single-site** control plane for `eduardoyauriluna.com`.

Normal managed operation follows:

```text
Eduardo → ChatGPT/operator → authenticated Research Manager → shared Manager services
        → WordPress state → Research Theme → rendered site → verification
```

Normal operation does **not** require:

- SSH;
- a WordPress administrator password shared with ChatGPT;
- direct database writes;
- direct filesystem writes;
- arbitrary PHP/shell execution;
- a generic unrestricted `wp/v2` write proxy;
- arbitrary Theme URLs/CSS/PHP;
- an unrestricted page builder.

WordPress Admin remains the local security/bootstrap/fallback surface. WP-CLI and SSH remain optional bootstrap, infrastructure, support and recovery transports, not the normal managed path.

## Production acceptance completed before 0.10.4

The following representative production slices have passed through temporary least-privilege credentials and restored their baselines after reversible tests:

- authenticated read-only status/diagnostics/audit and credential revocation behavior;
- Navigation Preview → Apply → rendered Verify → Rollback;
- Media metadata/assignment acceptance and diagnostics;
- M7 bounded Design typography, density and registered variants;
- M4 Insight update, EN/ES pairing, scheduling, publication state and verified Research-Line relation;
- M5 Research Line lifecycle integrated with the M4 relation proof;
- M5 representative Research Object lifecycle;
- M5 Evidence lifecycle using an explicitly temporary, unverified, non-academic fixture;
- M5 structured EN/ES translation pair/unpair using explicitly temporary Research Software fixtures.

The 0.10.3 Object acceptance also verified the corrected semantic HTTP 404 contract for an object removed by rollback. All temporary M5 fixtures were removed and the production baseline was restored.

## Conditional production slices

Two acceptance items are intentionally conditional:

### M3 Page create/remediate

Do not manufacture a missing or broken contract Page merely to exercise `page-create` or `page-remediate` on production. Repository/CI coverage proves the bounded implementation. A live mutation is required only if a legitimate missing/broken Page naturally exists.

With the current healthy Greenfield Page contract, the absence of such a target is **not an open v1 product gap**.

### M6 SEO/GEO deterministic remediation

ADR-016 remains authoritative: do not create an SEO/GEO defect merely to prove remediation. Repository/CI coverage proves supported deterministic remediation. A production mutation is required only when diagnostics naturally expose a legitimate deterministic remediation target.

An already-ready site therefore satisfies the production acceptance condition without fabricating a defect.

## Final `/capabilities` contract

Research Manager 0.10.4 must advertise:

- `manager_v1_development.status = production-accepted`;
- `production_acceptance_status = complete`;
- `production_acceptance_frozen = false`;
- production pair Theme `1.0.0` + Manager `0.10.4`;
- `site_scope = single-site`;
- `future_multiclient_saas_in_v1 = false`;
- `normal_operation_ssh_required = false`;
- `normal_operation_wordpress_credentials_shared = false`;
- `arbitrary_wordpress_rest_proxy = false`;
- local Admin shared-service parity;
- the complete bounded v1 surface inventory.

The source contract continues to require registered Theme targets only and rejects arbitrary CTA URLs.

## Final read-only production proof

Use `scripts/m8-v1-final-capabilities-production-acceptance.php` with a temporary credential granting exactly:

```text
site.read
```

The runner performs only authenticated GET requests to:

```text
/status
/versions
/capabilities
```

It verifies:

- Manager 0.10.4 and Theme 1.0.0;
- Greenfield mode and `eduardoyauriluna.com` canonical host;
- EN/ES active languages and compatible Theme preset;
- exact one-scope temporary credential;
- supported-scope registry without drift;
- v1 Plan/Apply/Verify/Rollback transport declarations;
- production-closeout state;
- complete v1 surface inventory;
- no-normal-bypass flags;
- bounded Theme Page targets.

After PASS, revoke the temporary credential. The already-completed generic revocation acceptance remains the proof that a revoked Manager credential returns authentication failure.

## v1 versus future productisation

The following are **not part of completed v1** and must not be used to redefine v1 as incomplete:

- multi-client SaaS control center;
- fleet/customer tenant management;
- billing/subscriptions;
- generic arbitrary Theme support;
- generic WordPress administration proxying;
- external academic publication without a separately elevated future contract.

Those are future productisation tracks built on top of the accepted single-site Manager architecture. They require their own product requirements, threat model, tenancy model and acceptance plan.

## Closeout rule

Issue #201 may close when all of the following are true:

1. 0.10.4 quality/package and Greenfield bundle CI are green;
2. the exact installable 0.10.4 artifact is deployed on `eduardoyauriluna.com`;
3. the final read-only capabilities runner passes;
4. the temporary final credential is revoked;
5. #201 is reconciled to the evidence above, including the conditional M3/M6 rule and the separation of v1 from future SaaS/multi-client work.
