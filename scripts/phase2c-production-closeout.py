#!/usr/bin/env python3
"""Finalize Phase 2C production credential hygiene on OVH.

Prerequisite: the old Research Manager acceptance credentials have already been
revoked in WordPress. This helper then:
- proves both old Manager credentials are rejected;
- proves the old outer acceptance bearer is rejected and research-ops works;
- removes the temporary phase2c-isolation tenant and its secret reference;
- recreates the startup-resolved runtime with the recorded immutable image;
- rechecks public health/auth/isolation;
- removes the pre-closeout secret-bearing backup only after success.

No bearer/token values are printed.
"""
from __future__ import annotations

import json
import os
import secrets
import subprocess
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

ENV_PATH = Path("/opt/site-control-mcp/.env.production")
PRE_CLOSEOUT_BACKUP = Path("/opt/site-control-mcp/.env.production.pre-closeout")
FINALIZE_BACKUP = Path("/opt/site-control-mcp/.env.production.pre-finalize")
IMAGE_REF_PATH = Path("/opt/site-control-mcp/.current-image-ref")
COMPOSE_PATH = "/opt/site-control-mcp/compose.ingress.yml"
PROJECT_DIR = "/opt/site-control-mcp"
SERVICE = "research-manager-mcp"
PUBLIC_HEALTH = "https://mcp.kairoseth.com/healthz"
PUBLIC_MCP_ROOT = "https://mcp.kairoseth.com/mcp"


def parse_env(text: str) -> dict[str, str]:
    out: dict[str, str] = {}
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in raw:
            continue
        key, value = raw.split("=", 1)
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {'"', "'"}:
            value = value[1:-1]
        out[key.strip()] = value
    return out


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


def manager_status(base_url: str, token: str) -> int:
    request = urllib.request.Request(
        base_url.rstrip("/") + "/wp-json/research-manager/v1/capabilities",
        method="GET",
        headers={
            "Authorization": "Bearer " + token,
            "X-Research-Manager-Request-Id": "phase2c-closeout-" + secrets.token_hex(8),
            "X-Research-Manager-Nonce": secrets.token_hex(16),
            "X-Research-Manager-Timestamp": str(int(time.time())),
            "Accept": "application/json",
            "User-Agent": "phase2c-production-closeout/1.0",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            response.read()
            return int(response.status)
    except urllib.error.HTTPError as exc:
        exc.read()
        return int(exc.code)


def mcp_initialize_status(token: str, tenant_id: str) -> int:
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
        f"{PUBLIC_MCP_ROOT}/{tenant_id}",
        data=body,
        method="POST",
        headers={
            "Authorization": "Bearer " + token,
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "User-Agent": "phase2c-production-closeout/1.0",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            response.read()
            return int(response.status)
    except urllib.error.HTTPError as exc:
        exc.read()
        return int(exc.code)


def recreate_runtime() -> None:
    if not IMAGE_REF_PATH.is_file():
        raise RuntimeError("current MCP image reference is missing")
    image_ref = IMAGE_REF_PATH.read_text(encoding="utf-8").strip()
    if not image_ref.startswith("ghcr.io/emmakex/site-control-mcp@sha256:"):
        raise RuntimeError("recorded MCP image reference is not an immutable accepted digest")
    env = os.environ.copy()
    env["MCP_IMAGE_REF"] = image_ref
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
        env=env,
    )


def wait_health() -> None:
    last = ""
    for _ in range(30):
        try:
            request = urllib.request.Request(PUBLIC_HEALTH, headers={"User-Agent": "phase2c-production-closeout/1.0"})
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
            last = "unexpected health payload"
        except Exception as exc:  # noqa: BLE001
            last = type(exc).__name__
        time.sleep(2)
    raise RuntimeError(f"public health did not recover: {last}")


def tenant_by_id(items: list[Any], tenant_id: str) -> dict[str, Any] | None:
    return next((x for x in items if isinstance(x, dict) and x.get("id") == tenant_id), None)


def principal_by_id(items: list[Any], principal_id: str) -> dict[str, Any] | None:
    return next((x for x in items if isinstance(x, dict) and x.get("id") == principal_id), None)


def main() -> int:
    if not ENV_PATH.is_file() or not PRE_CLOSEOUT_BACKUP.is_file():
        raise SystemExit("current env and pre-closeout backup are required")
    if FINALIZE_BACKUP.exists():
        raise SystemExit(f"refusing to overwrite existing finalize backup: {FINALIZE_BACKUP}")

    current_text = ENV_PATH.read_text(encoding="utf-8")
    old_text = PRE_CLOSEOUT_BACKUP.read_text(encoding="utf-8")
    current = parse_env(current_text)
    old = parse_env(old_text)

    current_tenants = json.loads(current.get("MCP_TENANTS_JSON", "[]"))
    current_principals = json.loads(current.get("MCP_PRINCIPALS_JSON", "[]"))
    old_tenants = json.loads(old.get("MCP_TENANTS_JSON", "[]"))
    old_principals = json.loads(old.get("MCP_PRINCIPALS_JSON", "[]"))
    if not all(isinstance(x, list) for x in (current_tenants, current_principals, old_tenants, old_principals)):
        raise SystemExit("tenant/principal JSON must be arrays")

    current_eduardo = tenant_by_id(current_tenants, "eduardo-research")
    old_eduardo = tenant_by_id(old_tenants, "eduardo-research")
    research_ops = principal_by_id(current_principals, "research-ops")
    old_acceptance = principal_by_id(old_principals, "phase2c-acceptance")
    if not all(isinstance(x, dict) for x in (current_eduardo, old_eduardo, research_ops, old_acceptance)):
        raise SystemExit("expected Eduardo/research-ops/acceptance records are missing")
    if principal_by_id(current_principals, "phase2c-acceptance") is not None:
        raise SystemExit("phase2c-acceptance principal is still present in current config")

    base_url = str(current_eduardo.get("baseUrl") or "")
    if not base_url.startswith("https://"):
        raise SystemExit("Eduardo baseUrl is invalid")

    old_read_ref = str(old_eduardo.get("readTokenEnv") or "")
    old_write_ref = str(old_eduardo.get("writeTokenEnv") or "")
    old_read = old.get(old_read_ref, "")
    old_write = old.get(old_write_ref, "")
    old_outer_ref = str(old_acceptance.get("bearerTokenEnv") or "")
    old_outer = old.get(old_outer_ref, "")
    new_outer_ref = str(research_ops.get("bearerTokenEnv") or "")
    new_outer = current.get(new_outer_ref, "")
    if not all((old_read, old_write, old_outer, new_outer)):
        raise SystemExit("required credential material is missing from current/backup env")

    read_status = manager_status(base_url, old_read)
    write_status = manager_status(base_url, old_write)
    if read_status != 401:
        raise SystemExit(f"old Manager READ credential is not revoked (HTTP {read_status})")
    if write_status != 401:
        raise SystemExit(f"old Manager WRITE credential is not revoked (HTTP {write_status})")
    print("Old Manager READ rejected: HTTP 401")
    print("Old Manager WRITE rejected: HTTP 401")

    if mcp_initialize_status(old_outer, "eduardo-research") != 401:
        raise SystemExit("old outer acceptance bearer is not rejected")
    if mcp_initialize_status(new_outer, "eduardo-research") != 200:
        raise SystemExit("research-ops bearer cannot initialize Eduardo tenant")
    print("Old outer bearer rejected: HTTP 401")
    print("research-ops Eduardo initialize: HTTP 200")

    isolation = tenant_by_id(current_tenants, "phase2c-isolation")
    isolation_secret_ref = ""
    if isinstance(isolation, dict):
        isolation_secret_ref = str(isolation.get("readTokenEnv") or "")

    FINALIZE_BACKUP.write_text(current_text, encoding="utf-8")
    os.chmod(FINALIZE_BACKUP, 0o600)

    cleaned_tenants = [
        item for item in current_tenants
        if not (isinstance(item, dict) and item.get("id") == "phase2c-isolation")
    ]
    replacements = {
        "MCP_TENANTS_JSON": json.dumps(cleaned_tenants, separators=(",", ":")),
    }
    removals = {"TENANT_ISOLATION_READ_TOKEN"}
    if isolation_secret_ref:
        removals.add(isolation_secret_ref)

    updated = replace_env_lines(current_text, replacements, removals)
    tmp = ENV_PATH.with_suffix(".production.tmp")
    tmp.write_text(updated, encoding="utf-8")
    os.chmod(tmp, 0o600)
    tmp.replace(ENV_PATH)

    try:
        recreate_runtime()
        wait_health()
        if mcp_initialize_status(new_outer, "eduardo-research") != 200:
            raise RuntimeError("research-ops bearer failed after final runtime recreate")
        isolation_status = mcp_initialize_status(new_outer, "phase2c-isolation")
        if isolation_status != 403:
            raise RuntimeError(f"removed isolation tenant did not fail closed with 403 (HTTP {isolation_status})")

        final = parse_env(ENV_PATH.read_text(encoding="utf-8"))
        final_tenants = json.loads(final.get("MCP_TENANTS_JSON", "[]"))
        final_principals = json.loads(final.get("MCP_PRINCIPALS_JSON", "[]"))
        if tenant_by_id(final_tenants, "phase2c-isolation") is not None:
            raise RuntimeError("phase2c-isolation tenant still present")
        if principal_by_id(final_principals, "phase2c-acceptance") is not None:
            raise RuntimeError("phase2c-acceptance principal still present")
        if final.get("TENANT_ISOLATION_READ_TOKEN"):
            raise RuntimeError("TENANT_ISOLATION_READ_TOKEN still present")
        if final.get("PRINCIPAL_PHASE2C_TOKEN"):
            raise RuntimeError("PRINCIPAL_PHASE2C_TOKEN still present")
    except Exception:
        ENV_PATH.write_text(FINALIZE_BACKUP.read_text(encoding="utf-8"), encoding="utf-8")
        os.chmod(ENV_PATH, 0o600)
        recreate_runtime()
        wait_health()
        raise

    FINALIZE_BACKUP.unlink(missing_ok=True)
    PRE_CLOSEOUT_BACKUP.unlink(missing_ok=True)

    print("Runtime recreate after isolation cleanup: OK")
    print("Public health: OK")
    print("research-ops Eduardo initialize after cleanup: HTTP 200")
    print("Removed isolation tenant fails closed: HTTP 403")
    print("phase2c-acceptance principal: ABSENT")
    print("phase2c-isolation tenant: ABSENT")
    print("Acceptance/isolation secret refs: ABSENT")
    print("Secret-bearing closeout backups: REMOVED")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
