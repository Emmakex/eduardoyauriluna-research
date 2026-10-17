# Production operations

## Persistent audit and usage telemetry

Managed production deployments must set:

```text
MCP_TELEMETRY_PATH=/var/lib/site-control-mcp/events.jsonl
MCP_REQUIRE_PERSISTENT_TELEMETRY=true
```

The production Compose examples mount `/var/lib/site-control-mcp` on a dedicated persistent volume while keeping the application root filesystem read-only.

Each MCP access decision is appended as one JSON object per line with:

- generated outer `request_id`;
- principal ID;
- tenant ID;
- allowed / denied / rate-limited outcome;
- bounded request path;
- current principal × tenant minute counters.

Entitlement denials are written with the same request correlation ID plus capability and denial reason. Bearer credentials and Research Manager credentials are never fields in these records.

When persistent telemetry is required, startup fails if no telemetry path is configured or the destination cannot be opened. If a later write to the telemetry destination fails, a request is not allowed to continue as an unaudited operation.

The JSONL file is an operational persistence boundary, not the final enterprise SIEM/archive. Back up or ship the volume to durable external storage according to retention requirements before deleting or rotating it.

## Research Manager credential rotation

Read, write and elevated Manager credentials are separate secret references. Rotate them independently and retain least privilege.

1. Create a replacement Research Manager credential with exactly the scopes required by the credential profile being rotated.
2. Update the server-side secret value referenced by the tenant configuration. Do not embed the secret in `MCP_TENANTS_JSON`, source control, Compose YAML or logs.
3. Recreate or roll the MCP container using the same accepted image so `EnvSecretResolver` loads the new value.
4. Confirm `/healthz` and persistent telemetry health.
5. Exercise the smallest appropriate MCP acceptance path for that credential profile. For a write credential use a reversible bounded Plan → Apply → Verify → Rollback fixture; for read-only use a read surface; for elevated use only the specific elevated acceptance fixture.
6. Revoke the old Research Manager credential at the Manager side.
7. Confirm the old credential is no longer accepted and the new credential continues to pass the intended MCP path.
8. Record the rotation/revocation event in the operational change record without storing either secret value.

The current environment secret resolver is startup-resolved; it does **not** hot-reload an environment variable inside a running process. A container recreate/rolling replacement is therefore part of the current rotation procedure.

## Outer principal bearer rotation

Outer MCP principal credentials are separate from all Manager credentials.

1. Generate a replacement principal bearer value.
2. Update the server-side environment secret referenced by `MCP_PRINCIPALS_JSON` (or `MCP_BEARER_TOKEN` in private legacy mode).
3. Recreate/roll the MCP container.
4. Verify the replacement bearer can reach only its explicitly granted tenant(s) and capabilities.
5. Verify the previous bearer is rejected.
6. Confirm persistent audit records contain principal/tenant/request correlation but neither old nor new bearer material.

## Telemetry volume rotation

Stop or roll the writer before moving the active JSONL file. Preserve ownership for UID/GID `10001`, file mode `0600`, and directory privacy. Never expose the telemetry volume through the MCP or ingress surface.

For managed production, prefer shipping completed telemetry segments to an external append-only or tamper-evident store before local retention cleanup.
