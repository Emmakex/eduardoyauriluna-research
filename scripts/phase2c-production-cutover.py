#!/usr/bin/env python3
"""Phase 2C production credential cutover helper.

Run on the OVH host. Reads exactly three secret values from stdin, one per line:
1) new Research Manager READ token
2) new Research Manager WRITE token
3) new outer MCP production bearer

The helper validates both Manager credentials against /capabilities before changing
anything, snapshots .env.production with mode 0600, replaces the Eduardo Manager
secrets, replaces only the temporary phase2c-acceptance principal with research-ops,
recreates the runtime, then proves the old outer bearer is rejected and the new one
can initialize MCP. If post-cutover verification fails, the environment snapshot is
restored and the runtime is recreated again.

No secret value is printed.
"""
from __future__ import annotations

import json
import os
import secrets
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

ENV_PATH = Path("/opt/site-control-mcp/.env.production")
BACKUP_PATH = Path("/opt/site-control-mcp/.env.production.pre-closeout")
COMPOSE_PATH = "/opt/site-control-mcp/compose.ingress.yml"
PROJECT_DIR = "/opt/site-control-mcp"
SERVICE = "research-manager-mcp"
PUBLIC_MCP = "https://mcp.kairoseth.com/mcp/eduardo-research"
PUBLIC_HEALTH = "https://mcp.kairoseth.com/healthz"

EXPECTED_READ_SCOPES = {
    "site.read",
    "site.diagnostics",
    "pages.read",
    "insights.read",
    "research.read",
    "evidence.read",
    "translations.read",
    "seo.read",
    "media.read",
    "connections.read",
    "design.read",
}

EXPECTED_WRITE_SCOPES = {
    "site.read",
    "site.diagnostics",
    "pages.read",
    "pages.write",
    "insights.read",
    "insights.write",
    "research.read",
    "research.write",
    "evidence.read",
    "evidence.write",
    "translations.read",
    "translations.write",
    "seo.read",
    "seo.write",
    "media.read",
    "media.write",
    "design.read",
    "design.write",
    "operations.apply",
    "operations.rollback",
}


def fail(message: str) -> "NoReturn":
    raise SystemExit(message)


def parse_env(text: str) -> dict[str, str]:
    env: dict[str, str] = {}
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {'"', "'"}:
            value = value[1:-1]
        env[key.strip()] = value
    return env


def replace_env_lines(original: str, replacements: dict[str, str], removals: set[str]) -> str:
    seen: set[str] = set()
    output: list[str] = []
    for raw in original.splitlines():
        stripped = raw.strip()
        if not stripped or stripped.startswith("#") or "=" not in raw:
            output.append(raw)
            continue
        key = raw.split("=", 1)[0].strip()
        if key in removals:
            continue
        if key in replacements:
            output.append(f"{key}={replacements[key]}")
            seen.add(key)
        else:
            output.append(raw)
    for key, value in replacements.items():
        if key not in seen:
            output.append(f"{key}={value}")
    return "\n".join(output).rstrip() + "\n"


def manager_capabilities(base_url: str, token: str) -> dict[str, Any]:
    request_id = "phase2c-preflight-" + secrets.token_hex(8)
    nonce = secrets.token_hex(16)
    request = urllib.request.Request(
        base_url.rstrip("/") + "/capabilities",
        method="GET",
        headers={
            "Authorization": "Bearer " + token,
            "X-Research-Manager-Request-Id": request_id,
            "X-Research-Manager-Nonce": nonce,
            "X-Research-Manager-Timestamp": str(int(time.time())),
            "Accept": "application/json",
            "User-Agent": "phase2c-production-cutover/1.0",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        raise RuntimeError(f"Manager credential validation returned HTTP {exc.code}") from exc
    if not isinstance(payload, dict) or payload.get("ok") is not True:
        raise RuntimeError("Manager credential validation returned an unexpected payload")
    data = payload.get("data")
    if not isinstance(data, dict):
        raise RuntimeError("Manager capabilities payload is missing data")
    return data


def require_scopes(label: str, data: dict[str, Any], expected: set[str]) -> None:
    granted_raw = data.get("granted_scopes")
    if not isinstance(granted_raw, list):
        raise RuntimeError(f"{label} credential did not expose granted_scopes")
    granted = {str(item) for item in granted_raw}
    missing = sorted(expected - granted)
    if missing:
        raise RuntimeError(f"{label} credential is missing required scopes: {', '.join(missing)}")


def recreate_runtime() -> None:
    subprocess.run(
        [
            "docker",
            "compose",
            "--project-directory",
            PROJECT_DIR,
            "-f",
            COMPOSE_PATH,
            "up",
            "-d",
            "--force-recreate",
            SERVICE,
        ],
        check=True,
    )


def wait_health() -> None:
    last_error = ""
    for _ in range(30):
        try:
            request = urllib.request.Request(PUBLIC_HEALTH, headers={"User-Agent": "phase2c-production-cutover/1.0"})
            with urllib.request.urlopen(request, timeout=10) as response:
                payload = json.loads(response.read().decode("utf-8"))
            if (
                isinstance(payload, dict)
                and payload.get("ok") is True
                and payload.get("phase") == "phase2c"
                and payload.get("routing") == "shared"
                and payload.get("persistent_telemetry") is True
            ):
                return
            last_error = "unexpected health payload"
        except Exception as exc:  # noqa: BLE001
            last_error = type(exc).__name__
        time.sleep(2)
    raise RuntimeError(f"public health did not recover: {last_error}")


def mcp_initialize_status(token: str) -> int:
    body = json.dumps(
        {
            "jsonrpc": "2.0",
            "id": 1,
            "method": "initialize",
            "params": {
                "protocolVersion": "2025-06-18",
                "capabilities": {},
                "clientInfo": {"name": "phase2c-closeout", "version": "1.0"},
            },
        }
    ).encode("utf-8")
    request = urllib.request.Request(
        PUBLIC_MCP,
        data=body,
        method="POST",
        headers={
            "Authorization": "Bearer " + token,
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "User-Agent": "phase2c-production-cutover/1.0",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            response.read()
            return int(response.status)
    except urllib.error.HTTPError as exc:
        exc.read()
        return int(exc.code)


def main() -> int:
    values = [sys.stdin.readline().rstrip("\n") for _ in range(3)]
    if len(values) != 3 or any(not value for value in values):
        fail("Expected three non-empty secrets on stdin")
    new_read, new_write, new_outer = values

    if not (new_read.startswith("erm1_") or new_read.startswith("ermt1_")):
        fail("READ credential does not look like a Research Manager token")
    if not (new_write.startswith("erm1_") or new_write.startswith("ermt1_")):
        fail("WRITE credential does not look like a Research Manager token")
    if len(new_outer) < 32:
        fail("Outer production bearer is unexpectedly short")

    original = ENV_PATH.read_text(encoding="utf-8")
    parsed = parse_env(original)
    tenants = json.loads(parsed.get("MCP_TENANTS_JSON", "[]"))
    principals = json.loads(parsed.get("MCP_PRINCIPALS_JSON", "[]"))
    if not isinstance(tenants, list) or not isinstance(principals, list):
        fail("MCP_TENANTS_JSON / MCP_PRINCIPALS_JSON must be arrays")

    tenant = next((item for item in tenants if isinstance(item, dict) and item.get("id") == "eduardo-research"), None)
    if not isinstance(tenant, dict):
        fail("eduardo-research tenant is missing")
    base_url = str(tenant.get("baseUrl") or "")
    if not base_url.startswith("https://"):
        fail("eduardo-research baseUrl is missing or not HTTPS")

    acceptance = next(
        (item for item in principals if isinstance(item, dict) and item.get("id") == "phase2c-acceptance"),
        None,
    )
    if not isinstance(acceptance, dict):
        fail("phase2c-acceptance principal is missing")
    old_outer_ref = str(acceptance.get("bearerTokenEnv") or "")
    old_outer = parsed.get(old_outer_ref, "")
    if not old_outer:
        fail("Current phase2c acceptance bearer secret is missing")

    capabilities = acceptance.get("capabilities")
    if not isinstance(capabilities, list) or not capabilities:
        fail("Current acceptance principal capabilities are missing")

    # Validate new Manager credentials before changing production.
    require_scopes("READ", manager_capabilities(base_url, new_read), EXPECTED_READ_SCOPES)
    require_scopes("WRITE", manager_capabilities(base_url, new_write), EXPECTED_WRITE_SCOPES)
    print("Manager READ credential: VALID")
    print("Manager WRITE credential: VALID")

    if BACKUP_PATH.exists():
        fail(f"Refusing to overwrite existing backup: {BACKUP_PATH}")
    BACKUP_PATH.write_text(original, encoding="utf-8")
    os.chmod(BACKUP_PATH, 0o600)

    new_principal = {
        "id": "research-ops",
        "bearerTokenEnv": "PRINCIPAL_RESEARCH_OPS_TOKEN",
        "tenants": ["eduardo-research"],
        "capabilities": capabilities,
    }
    remaining_principals = [
        item
        for item in principals
        if not (isinstance(item, dict) and item.get("id") == "phase2c-acceptance")
    ]
    remaining_principals.append(new_principal)

    replacements = {
        "TENANT_EDUARDO_READ_TOKEN": new_read,
        "TENANT_EDUARDO_WRITE_TOKEN": new_write,
        "PRINCIPAL_RESEARCH_OPS_TOKEN": new_outer,
        "MCP_PRINCIPALS_JSON": json.dumps(remaining_principals, separators=(",", ":")),
    }
    removals = {old_outer_ref}
    updated = replace_env_lines(original, replacements, removals)
    tmp = ENV_PATH.with_suffix(".production.tmp")
    tmp.write_text(updated, encoding="utf-8")
    os.chmod(tmp, 0o600)
    tmp.replace(ENV_PATH)

    try:
        recreate_runtime()
        wait_health()
        old_status = mcp_initialize_status(old_outer)
        new_status = mcp_initialize_status(new_outer)
        if old_status != 401:
            raise RuntimeError(f"old outer bearer was not rejected with 401 (got {old_status})")
        if new_status != 200:
            raise RuntimeError(f"new outer bearer failed MCP initialize (got {new_status})")
    except Exception:
        ENV_PATH.write_text(BACKUP_PATH.read_text(encoding="utf-8"), encoding="utf-8")
        os.chmod(ENV_PATH, 0o600)
        recreate_runtime()
        wait_health()
        raise

    print("Runtime recreate: OK")
    print("Public health: OK")
    print("Old outer bearer rejected: HTTP 401")
    print("New research-ops bearer initialize: HTTP 200")
    print(f"Pre-closeout backup retained: {BACKUP_PATH}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
