# Phase 2D — ChatGPT/GitHub Command Bridge

## Purpose

Phase 2C proved that the production MCP can safely govern WordPress through the bounded Research Manager surface. Phase 2D closes the remaining operator-experience gap: allow a natural-language instruction in ChatGPT to become a real, verified WordPress operation without requiring ChatGPT Business/Enterprise write-capable custom MCP access.

The preferred universal path is a GitHub command bridge owned by Kairoseth.

## Core operating model

Primary universal path:

```text
Eduardo / Kairoseth operator
        ↓
ChatGPT
        ↓
GitHub connector
        ↓
Kairoseth Command Gateway / GitHub Actions
        ↓
production outer MCP principal for the selected tenant
        ↓
https://mcp.kairoseth.com/mcp/{tenant}
        ↓
Research Manager
        ↓
WordPress + Theme
        ↓
Verify result
        ↓
GitHub sanitized result
        ↓
ChatGPT reports the concrete outcome
```

Direct ChatGPT → custom MCP remains an optional channel when the active OpenAI workspace supports write/modify actions. It is not required for the commercial managed-service model.

## Key commercial clarification

The customer does **not** need:

- a ChatGPT subscription;
- a ChatGPT Business workspace;
- a GitHub account;
- access to the Kairoseth repository;
- access to the MCP bearer;
- Research Manager credentials;
- WordPress/server credentials for normal managed operation.

ChatGPT and GitHub are operator-side infrastructure owned and controlled by Kairoseth.

For a managed customer, the control path is therefore:

```text
Customer request
      ↓
Kairoseth operator / support / future Kairoseth UI
      ↓
ChatGPT + GitHub Command Gateway
      ↓
customer tenant in the shared MCP
      ↓
customer Research Manager
      ↓
customer WordPress
```

A future customer-facing portal may submit the same command schema directly to the Command Gateway. The MCP and WordPress contracts do not change.

## Multi-tenant model

Each customer is an isolated MCP tenant, for example:

```text
/mcp/eduardo-research
/mcp/acme
/mcp/client-b
/mcp/client-c
```

Each tenant keeps its own:

- WordPress base URL;
- Manager READ credential reference;
- Manager WRITE credential reference;
- optional elevated credential reference;
- language set;
- entitlements;
- principal allowlist;
- audit and usage correlation.

The GitHub bridge must never turn tenant selection into an unrestricted URL. The command selects a registered tenant ID only; the MCP remains authoritative for tenant routing and authorization.

## Command Gateway responsibility

The GitHub layer is a controlled execution bus, not a second WordPress API.

Its responsibilities are:

1. accept a strict structured command;
2. validate tenant and action against an allowlist;
3. map the command to an existing bounded MCP lifecycle;
4. load only the outer MCP credential needed for that execution;
5. call the tenant MCP endpoint;
6. execute Plan → Apply → Verify, with Rollback where required;
7. persist only sanitized result/evidence;
8. expose the result back to ChatGPT through GitHub.

It must not expose:

- arbitrary MCP tool name passthrough from untrusted payloads;
- arbitrary backend URLs;
- generic REST calls;
- PHP, shell, SQL or filesystem operations;
- raw Manager credentials;
- raw MCP bearer values;
- unrestricted WordPress options;
- arbitrary HTML/CSS/code execution.

## Initial command envelope

The first implementation may use a versioned JSON contract such as:

```json
{
  "version": "1",
  "tenant": "eduardo-research",
  "action": "insight.create",
  "requested_by": "chatgpt",
  "payload": {
    "title": "Hola mundo",
    "language": "es",
    "status": "draft",
    "content": "Hola mundo. Esta es la primera entrada creada y gestionada mediante Research Manager MCP."
  }
}
```

The envelope is intent, not an MCP transport payload. A trusted command mapper converts `insight.create` into the exact MCP tools and schemas.

## Initial action registry

Start with low-risk routine operations already accepted in production:

```text
insight.create
insight.update
page.update
seo.remediate
media.update
navigation.update
design.update
```

Later actions may be added only by explicit registry change and tests.

The initial registry must exclude by default:

```text
identity.update
credentials.manage
connections.write
external_publish.execute
software_update.execute
destructive_delete.execute
```

## Result contract

A successful execution should write a sanitized machine-readable result, for example:

```json
{
  "status": "success",
  "tenant": "eduardo-research",
  "action": "insight.create",
  "verified": true,
  "resource": {
    "post_id": 1234,
    "title": "Hola mundo",
    "state": "draft"
  },
  "correlation": {
    "plan_id": "<sanitized-plan-id>",
    "operation_id": "<sanitized-operation-id>",
    "request_id": "<sanitized-request-id>"
  }
}
```

No secret values are ever written to issues, commits, artifacts or chat.

## Secret model

### Manager credentials

Remain server-side in OVH exactly as in Phase 2C. They are never copied into GitHub or ChatGPT.

### Outer MCP credentials

For managed operation, GitHub Actions may use an outer MCP bearer stored as a protected GitHub Environment secret. Prefer a dedicated automation principal rather than reusing an interactive operator credential when productized.

The principal must have:

- explicit tenant allowlist;
- explicit commercial capabilities;
- no elevated identity/destructive/external-publish privileges unless separately approved;
- routine rotation and revocation procedure.

### ChatGPT

ChatGPT receives no bearer value. It only creates/updates the structured command resource through the GitHub connector and reads sanitized execution output.

## GitHub repository strategy

For the first Eduardo/reference implementation, the existing repository may host the command runner while the contract stabilizes.

For commercial operation, the preferred architecture is one centralized Kairoseth command-gateway repository with tenant-aware protected environments, rather than requiring one GitHub repository or one ChatGPT installation per customer.

Customer-specific repositories are optional for special isolation/compliance arrangements, not the default requirement.

## Approval and execution policy

The bridge must preserve the safety semantics already accepted in the MCP:

- mutation intent is explicit;
- exact stored plans are applied;
- Verify is mandatory for successful completion;
- reversible operations keep exact Rollback support;
- stale revision and idempotency behavior remain authoritative in Research Manager/MCP;
- the GitHub runner must fail closed on unknown tenant, unknown action, schema mismatch, MCP error or failed verification.

High-impact actions remain separately gated even if technically available in the MCP.

## Primary Phase 2D acceptance

From this ChatGPT conversation, without terminal or WordPress Admin:

User instruction:

```text
crea una entrada inicial de hola mundo
```

Expected path:

```text
ChatGPT
→ GitHub command
→ Actions command runner
→ /mcp/eduardo-research
→ site_insight_create_plan
→ site_insight_apply
→ site_insight_verify(rendered=true)
→ sanitized result in GitHub
→ ChatGPT reports success
```

Expected WordPress state:

```text
Title: Hola mundo
Language: es
Status: draft
Content: Hola mundo. Esta es la primera entrada creada y gestionada mediante Research Manager MCP.
```

Acceptance requires a real created draft and verified readback/rendered state. A generated command file alone is not acceptance.

## Commercial operating scenarios

### Managed service

The customer asks Kairoseth for a change. Kairoseth uses its own ChatGPT/operator environment and Command Gateway. The customer requires no ChatGPT or GitHub account.

### Kairoseth customer portal

A future Kairoseth UI sends the same versioned command envelope to the Command Gateway. ChatGPT becomes optional as an operator copilot, while the execution architecture remains identical.

### Direct customer ChatGPT MCP

Optional for customers/workspaces that support custom MCP writes. Their client can connect directly to their allowed MCP tenant. This is an additional channel, not a prerequisite of the managed product.

### Private / enterprise

The same command contract can target a private managed MCP deployment or customer-hosted MCP, with the same bounded tools and security model.

## Implementation sequence when work resumes

1. Define `command-v1` JSON schema and strict action registry.
2. Implement one GitHub Actions command runner for `insight.create` only.
3. Create a dedicated least-privilege GitHub automation outer principal for `eduardo-research`.
4. Store its bearer only in the protected GitHub environment.
5. Add sanitized result/evidence output.
6. Execute the `Hola mundo` acceptance end-to-end from ChatGPT.
7. Add idempotency/retry/duplicate-command protection.
8. Add remaining low-risk actions one domain at a time.
9. Add multi-tenant routing tests with a denied second tenant.
10. Only then productize customer onboarding and future Kairoseth portal submission.

## Non-goals for Phase 2D

- rebuilding Research Manager;
- adding another generic WordPress API;
- exposing the full MCP catalog through unvalidated arbitrary action names;
- requiring customers to purchase ChatGPT Business;
- requiring customers to maintain GitHub repositories;
- creating a separate MCP codebase per customer;
- bypassing existing MCP tenant/capability controls.

## Definition of done

Phase 2D is complete when a natural-language instruction in the Kairoseth operator ChatGPT can create a structured GitHub command, GitHub Actions can execute the corresponding bounded lifecycle against the selected production MCP tenant, the real WordPress result is verified, and ChatGPT can read and report the sanitized result — with no terminal, WordPress Admin or customer ChatGPT/GitHub dependency during normal managed operation.
