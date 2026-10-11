# Production credential rotation policy

## Purpose

This policy defines the normal lifecycle for the production Research Manager bearer credential used to operate `eduardoyauriluna.com` after Phase 2 acceptance.

The credential is an operational secret. It is not application content, configuration data or evidence, and it must never be committed to Git, pasted into chat, stored in issue comments or written into acceptance JSON.

## Routine rotation cadence

- Maximum routine lifetime: **90 days** from issuance.
- Rotate sooner when a narrower operational profile is sufficient or when an operator/security change makes the previous grant unnecessarily broad.
- The rotation clock resets when a new credential is issued and accepted.
- The first routine rotation after the Phase 2 credential issued on **2026-10-11** is due no later than **2027-01-09**. Ongoing tracking is recorded in issue #132.

## Immediate revoke-and-replace triggers

Revoke the active credential and issue a replacement immediately after any of the following:

- suspected or confirmed credential exposure;
- the bearer token is pasted into chat, an issue, a commit, a config file, a log or an evidence file;
- loss or compromise of the operator device that held the token;
- offboarding or a material access-control change affecting the operator;
- a material privilege-profile change where the old grant no longer represents least privilege;
- a credential/authentication implementation incident that could have affected token confidentiality or validation.

Do not wait for the 90-day routine deadline after an exposure-risk trigger.

## Rotation procedure

1. Issue a fresh Research Manager credential from the local WordPress security/admin surface.
2. Grant only the minimum scope profile required for the intended operating session. For the full Phase 2 production profile, use the accepted exact 17-scope set documented in `docs/15-production-operating-playbook.md`.
3. Load the new bearer value only into the local `ERM_TOKEN` environment variable.
4. Run the production capability audit. For the full Phase 2 profile:

   ```bash
   export ERM_BASE_URL='https://eduardoyauriluna.com'
   php scripts/p2-capabilities-audit.php \
     --profile=phase2 \
     --evidence=tmp/p2-capabilities-audit.json
   ```

5. Run the authenticated production preflight:

   ```bash
   php scripts/m8-manager-client.php preflight \
     --evidence=tmp/p2-production-preflight.json
   ```

6. Require both checks to pass before retiring the previous credential.
7. Revoke the previous credential in the same maintenance session once the replacement is accepted.
8. Record only non-secret evidence in GitHub: issuance/rotation date, scope profile, audit/preflight result and the tracking issue or maintenance reference. Never record the token itself.

## Transition boundary

A short overlap between old and new credentials is allowed only to verify the replacement. The old credential must be revoked in the same maintenance session after successful acceptance. Long-lived parallel production credentials are not the normal operating model.

## Least-privilege rule

Credential rotation is not a reason to restore broader grants. Each replacement must be re-evaluated against the current operating task and current `/capabilities` contract. Dangerous-by-default or unrelated scopes remain disabled unless a deliberate bounded maintenance session requires them.

## Phase 2 acceptance state

Phase 2 established the initial compliant lifecycle by:

- revoking the earlier M8 acceptance credential;
- issuing a fresh production credential;
- reducing it to the exact accepted 17-scope profile;
- validating the post-deploy semantic scope contract;
- completing a production preflight after the hardened Manager deployment.

Future rotations are ongoing operations and are tracked separately from the completed Phase 2 implementation milestone.
