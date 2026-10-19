#!/usr/bin/env python3
"""Provision the least-privilege Phase 2D GitHub automation MCP principal.

Run on the production OVH host as a user that can update
/opt/site-control-mcp/.env.production and recreate the MCP container.

Security properties:
- never prints .env.production or any existing secret;
- generates a dedicated outer bearer locally on the OVH host;
- grants only eduardo-research + insights.write + operations.rollback;
- preserves all existing tenants/principals;
- keeps a mode-0600 rollback snapshot only during the transaction;
- recreates the accepted immutable MCP image already recorded on the host;
- verifies public health, allowed-tenant initialize and denied-tenant fail-closed;
- emits the new bearer on stdout only when --emit-token is supplied, and only
  after the production verification succeeds. All status output goes to stderr.

The intended secret handoff is a direct pipe from SSH stdout to `gh secret set`,
so the bearer never needs to be pasted into ChatGPT or a shell argument.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import secrets
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

ENV_PATH = Path("/opt/site-control-mcp/.env.production")
BACKUP_PATH = Path("/opt/site-control-mcp/.env.production.phase2d-provision-backup")
COMPOSE_PATH = Path("/opt/site-control-mcp/compose.ingress.yml")
PROJECT_DIR = Path("/opt/site-control-mcp")
CURRENT_IMAGE_PATH = PROJECT_DIR / ".current-image-ref"
SERVICE = "research-manager-mcp"
PUBLIC_HEALTH = "https://mcp.kairoseth.com/healthz"
PUBLIC_MCP_BASE = "https://mcp.kairoseth.com/mcp"
TENANT_ID = "eduardo-research"
PRINCIPAL_ID = "phase2d-github"
TOKEN_ENV = "PRINCIPAL_PHASE2D_GITHUB_TOKEN"
CAPABILITIES = ["insights.write", "operations.rollback"]
IMAGE_RE = re.compile(r"^ghcr\.io/emmakex/site-control-mcp@sha256:[0-9a-f]{64}$")


def log(message: str) -> None:
    print(message, file=sys.stderr, flush=True)


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


def read_runtime_state(original: str) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    parsed = parse_env(original)
    try:
        tenants = json.loads(parsed.get("MCP_TENANTS_JSON", "[]"))
        principals = json.loads(parsed.get("MCP_PRINCIPALS_JSON", "[]"))
    except json.JSONDecodeError as exc:
        raise RuntimeError("MCP_TENANTS_JSON / MCP_PRINCIPALS_JSON are invalid JSON") from exc
    if not isinstance(tenants, list) or not all(isinstance(item, dict) for item in tenants):
        raise RuntimeError("MCP_TENANTS_JSON must be an array of objects")
    if not isinstance(principals, list) or not all(isinstance(item, dict) for item in principals):
        raise RuntimeError("MCP_PRINCIPALS_JSON must be an array of objects")
    return tenants, principals


def current_image_ref() -> str:
    if not CURRENT_IMAGE_PATH.is_file():
        raise RuntimeError("accepted current image reference is missing")
    value = CURRENT_IMAGE_PATH.read_text(encoding="utf-8").strip()
    if not IMAGE_RE.fullmatch(value):
        raise RuntimeError("accepted current image reference is invalid")
    return value


def atomic_write_env(text: str) -> None:
    tmp = ENV_PATH.with_name(ENV_PATH.name + ".phase2d-tmp")
    tmp.write_text(text, encoding="utf-8")
    os.chmod(tmp, 0o600)
    os.replace(tmp, ENV_PATH)
    os.chmod(ENV_PATH, 0o600)


def recreate_runtime(image_ref: str) -> None:
    env = {**os.environ, "MCP_IMAGE_REF": image_ref}
    subprocess.run(
        [
            "docker",
            "compose",
            "--project-directory",
            str(PROJECT_DIR),
            "-f",
            str(COMPOSE_PATH),
            "up",
            "-d",
            "--force-recreate",
            SERVICE,
        ],
        cwd=PROJECT_DIR,
        env=env,
        check=True,
        stdout=sys.stderr,
        stderr=sys.stderr,
        text=True,
    )


def wait_health() -> None:
    last_error = ""
    for _ in range(30):
        try:
            request = urllib.request.Request(
                PUBLIC_HEALTH,
                headers={"User-Agent": "phase2d-principal-provision/1.0"},
            )
            with urllib.request.urlopen(request, timeout=10) as response:
                payload = json.loads(response.read().decode("utf-8"))
            if (
                isinstance(payload, dict)
                and payload.get("ok") is True
                and payload.get("routing") == "shared"
                and payload.get("persistent_telemetry") is True
            ):
                return
            last_error = "unexpected health payload"
        except Exception as exc:  # noqa: BLE001
            last_error = type(exc).__name__
        time.sleep(2)
    raise RuntimeError(f"public health did not recover: {last_error}")


def mcp_initialize_status(url: str, token: str) -> int:
    body = json.dumps(
        {
            "jsonrpc": "2.0",
            "id": 1,
            "method": "initialize",
            "params": {
                "protocolVersion": "2025-06-18",
                "capabilities": {},
                "clientInfo": {"name": "phase2d-principal-provision", "version": "1.0"},
            },
        }
    ).encode("utf-8")
    request = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={
            "Authorization": "Bearer " + token,
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "User-Agent": "phase2d-principal-provision/1.0",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            response.read()
            return int(response.status)
    except urllib.error.HTTPError as exc:
        exc.read()
        return int(exc.code)


def build_updated_env(original: str, new_token: str, rotate: bool) -> tuple[str, str | None]:
    tenants, principals = read_runtime_state(original)
    tenant = next((item for item in tenants if item.get("id") == TENANT_ID), None)
    if tenant is None:
        raise RuntimeError(f"required tenant {TENANT_ID} is missing")

    existing = next((item for item in principals if item.get("id") == PRINCIPAL_ID), None)
    if existing is not None and not rotate:
        raise RuntimeError(f"principal {PRINCIPAL_ID} already exists; use --rotate to replace it")
    if existing is None and rotate:
        raise RuntimeError(f"principal {PRINCIPAL_ID} does not exist; omit --rotate for first provisioning")

    parsed = parse_env(original)
    old_token: str | None = None
    old_ref: str | None = None
    if existing is not None:
        old_ref_raw = existing.get("bearerTokenEnv")
        if not isinstance(old_ref_raw, str) or not old_ref_raw:
            raise RuntimeError("existing Phase 2D principal has no bearerTokenEnv")
        old_ref = old_ref_raw
        old_token = parsed.get(old_ref)
        if not old_token:
            raise RuntimeError("existing Phase 2D principal token reference is unresolved")

    new_principal = {
        "id": PRINCIPAL_ID,
        "bearerTokenEnv": TOKEN_ENV,
        "tenants": [TENANT_ID],
        "capabilities": CAPABILITIES,
    }
    next_principals = [item for item in principals if item.get("id") != PRINCIPAL_ID]
    next_principals.append(new_principal)

    replacements = {
        TOKEN_ENV: new_token,
        "MCP_PRINCIPALS_JSON": json.dumps(next_principals, separators=(",", ":")),
    }
    removals: set[str] = set()
    if old_ref and old_ref != TOKEN_ENV:
        removals.add(old_ref)
    return replace_env_lines(original, replacements, removals), old_token


def self_test() -> int:
    sample = (
        "MCP_SHARED_ROUTING=true\n"
        "MCP_TENANTS_JSON=[{\"id\":\"eduardo-research\"}]\n"
        "MCP_PRINCIPALS_JSON=[{\"id\":\"research-ops\",\"bearerTokenEnv\":\"OPS_TOKEN\",\"tenants\":[\"eduardo-research\"],\"capabilities\":[\"research.read\"]}]\n"
        "OPS_TOKEN=existing-secret\n"
    )
    updated, old = build_updated_env(sample, "new-secret", rotate=False)
    if old is not None:
        raise RuntimeError("self-test unexpectedly found an old Phase 2D token")
    parsed = parse_env(updated)
    principals = json.loads(parsed["MCP_PRINCIPALS_JSON"])
    phase2d = next(item for item in principals if item.get("id") == PRINCIPAL_ID)
    if phase2d != {
        "id": PRINCIPAL_ID,
        "bearerTokenEnv": TOKEN_ENV,
        "tenants": [TENANT_ID],
        "capabilities": CAPABILITIES,
    }:
        raise RuntimeError("self-test principal contract mismatch")
    if parsed.get("OPS_TOKEN") != "existing-secret":
        raise RuntimeError("self-test modified an unrelated principal secret")
    if parsed.get(TOKEN_ENV) != "new-secret":
        raise RuntimeError("self-test did not install the generated token")

    rotated, old = build_updated_env(updated, "rotated-secret", rotate=True)
    if old != "new-secret":
        raise RuntimeError("self-test rotation did not recover the prior token")
    if parse_env(rotated).get(TOKEN_ENV) != "rotated-secret":
        raise RuntimeError("self-test rotation did not replace the token")
    log("phase2d-principal-provision self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--emit-token", action="store_true", help="emit the verified new bearer on stdout exactly once")
    parser.add_argument("--rotate", action="store_true", help="rotate an already-provisioned phase2d-github principal")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()

    if args.self_test:
        return self_test()
    if not ENV_PATH.is_file() or not COMPOSE_PATH.is_file():
        fail("production MCP files are missing on this host")
    if BACKUP_PATH.exists():
        fail(f"refusing to continue while transaction backup exists: {BACKUP_PATH}")

    original = ENV_PATH.read_text(encoding="utf-8")
    image_ref = current_image_ref()
    new_token = secrets.token_urlsafe(48)
    updated, old_token = build_updated_env(original, new_token, args.rotate)

    BACKUP_PATH.write_text(original, encoding="utf-8")
    os.chmod(BACKUP_PATH, 0o600)
    atomic_write_env(updated)

    restored = False
    try:
        recreate_runtime(image_ref)
        wait_health()
        allowed = mcp_initialize_status(f"{PUBLIC_MCP_BASE}/{TENANT_ID}", new_token)
        denied = mcp_initialize_status(f"{PUBLIC_MCP_BASE}/phase2d-denied-probe", new_token)
        if allowed != 200:
            raise RuntimeError(f"new automation principal failed allowed tenant initialize (HTTP {allowed})")
        if denied != 403:
            raise RuntimeError(f"new automation principal did not fail closed on denied tenant (HTTP {denied})")
        if old_token:
            old_status = mcp_initialize_status(f"{PUBLIC_MCP_BASE}/{TENANT_ID}", old_token)
            if old_status != 401:
                raise RuntimeError(f"rotated-out automation bearer was not rejected (HTTP {old_status})")
    except Exception:
        log("Provisioning verification failed; restoring previous production environment")
        atomic_write_env(BACKUP_PATH.read_text(encoding="utf-8"))
        recreate_runtime(image_ref)
        wait_health()
        restored = True
        BACKUP_PATH.unlink(missing_ok=True)
        raise

    BACKUP_PATH.unlink(missing_ok=True)
    log(f"Principal {PRINCIPAL_ID}: ACTIVE")
    log(f"Tenant allowlist: {TENANT_ID}")
    log("Commercial capabilities: insights.write, operations.rollback")
    log("Public health: OK")
    log("Allowed tenant initialize: HTTP 200")
    log("Denied tenant initialize: HTTP 403")
    if old_token:
        log("Previous automation bearer rejected: HTTP 401")
    log("Secret-bearing transaction backup: REMOVED")
    if restored:
        raise RuntimeError("unexpected restored state")
    if args.emit_token:
        print(new_token, flush=True)
    else:
        log("Bearer not emitted; rerunning without --rotate will be refused. Use --rotate --emit-token if a new handoff is required.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
