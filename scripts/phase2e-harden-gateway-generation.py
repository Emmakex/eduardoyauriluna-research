#!/usr/bin/env python3
"""Activate generation-bound authentication for the production GitHub gateway.

The migration is deliberately two-stage on the current accepted MCP image:
1. compatibility mode proves both the historical and generation-bound bearer;
2. strict mode rejects the historical bearer while preserving normal tenant isolation.

No bearer, principal JSON, or environment content is printed.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

ENV_PATH = Path("/opt/site-control-mcp/.env.production")
BACKUP_PATH = Path("/opt/site-control-mcp/.env.production.phase2e-generation-backup")
COMPOSE_PATH = Path("/opt/site-control-mcp/compose.ingress.yml")
PROJECT_DIR = Path("/opt/site-control-mcp")
CURRENT_IMAGE_PATH = PROJECT_DIR / ".current-image-ref"
SERVICE = "research-manager-mcp"
PUBLIC_HEALTH = "https://mcp.kairoseth.com/healthz"
PUBLIC_MCP_BASE = "https://mcp.kairoseth.com/mcp"
TENANT_ID = "eduardo-research"
DENIED_TENANT = "phase2d-denied-probe"
PRINCIPAL_ID = "phase2d-github"
GENERATION = "phase2d-v2-20261019"
GENERATION_ENV = "MCP_PHASE2D_GATEWAY_GENERATION"
REQUIRE_ENV = "MCP_PHASE2D_REQUIRE_GATEWAY_GENERATION"
IMAGE_RE = re.compile(r"^ghcr\.io/emmakex/site-control-mcp@sha256:[0-9a-f]{64}$")


def log(message: str) -> None:
    print(message, file=sys.stderr, flush=True)


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


def replace_env_lines(original: str, replacements: dict[str, str]) -> str:
    seen: set[str] = set()
    output: list[str] = []
    for raw in original.splitlines():
        stripped = raw.strip()
        if not stripped or stripped.startswith("#") or "=" not in raw:
            output.append(raw)
            continue
        key = raw.split("=", 1)[0].strip()
        if key in replacements:
            output.append(f"{key}={replacements[key]}")
            seen.add(key)
        else:
            output.append(raw)
    for key, value in replacements.items():
        if key not in seen:
            output.append(f"{key}={value}")
    return "\n".join(output).rstrip() + "\n"


def gateway_bearer(text: str) -> str:
    parsed = parse_env(text)
    try:
        principals = json.loads(parsed.get("MCP_PRINCIPALS_JSON", "[]"))
    except json.JSONDecodeError as exc:
        raise RuntimeError("MCP_PRINCIPALS_JSON is invalid JSON") from exc
    if not isinstance(principals, list):
        raise RuntimeError("MCP_PRINCIPALS_JSON must be an array")
    matches = [item for item in principals if isinstance(item, dict) and item.get("id") == PRINCIPAL_ID]
    if len(matches) != 1:
        raise RuntimeError(f"expected exactly one {PRINCIPAL_ID} principal; found {len(matches)}")
    principal = matches[0]
    if principal.get("tenants") != [TENANT_ID]:
        raise RuntimeError("phase2d-github tenant allowlist is not the accepted single-tenant contract")
    token_ref = principal.get("bearerTokenEnv")
    if not isinstance(token_ref, str) or not token_ref:
        raise RuntimeError("phase2d-github has no bearerTokenEnv")
    token = parsed.get(token_ref, "")
    if not token:
        raise RuntimeError("phase2d-github bearerTokenEnv is unresolved")
    return token


def build_mode(original: str, required: bool) -> str:
    before = parse_env(original)
    existing_generation = before.get(GENERATION_ENV, "")
    if existing_generation and existing_generation != GENERATION:
        raise RuntimeError("production has a different gateway generation; refusing overwrite")
    updated = replace_env_lines(
        original,
        {
            GENERATION_ENV: GENERATION,
            REQUIRE_ENV: "true" if required else "false",
        },
    )
    after = parse_env(updated)
    for key, value in before.items():
        if key not in {GENERATION_ENV, REQUIRE_ENV} and after.get(key) != value:
            raise RuntimeError(f"gateway hardening changed unrelated env key {key}")
    if after.get("MCP_PRINCIPALS_JSON") != before.get("MCP_PRINCIPALS_JSON"):
        raise RuntimeError("gateway hardening changed principal configuration")
    if gateway_bearer(updated) != gateway_bearer(original):
        raise RuntimeError("gateway hardening changed the existing bearer")
    return updated


def current_image_ref() -> str:
    if not CURRENT_IMAGE_PATH.is_file():
        raise RuntimeError("accepted current image reference is missing")
    value = CURRENT_IMAGE_PATH.read_text(encoding="utf-8").strip()
    if not IMAGE_RE.fullmatch(value):
        raise RuntimeError("accepted current image reference is invalid")
    return value


def atomic_write_env(text: str) -> None:
    tmp = ENV_PATH.with_name(ENV_PATH.name + ".phase2e-generation-tmp")
    tmp.write_text(text, encoding="utf-8")
    os.chmod(tmp, 0o600)
    os.replace(tmp, ENV_PATH)
    os.chmod(ENV_PATH, 0o600)


def recreate_runtime(image_ref: str) -> None:
    env = {**os.environ, "MCP_IMAGE_REF": image_ref}
    subprocess.run(
        [
            "docker", "compose", "--project-directory", str(PROJECT_DIR),
            "-f", str(COMPOSE_PATH), "up", "-d", "--force-recreate", SERVICE,
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
            request = urllib.request.Request(PUBLIC_HEALTH, headers={"User-Agent": "phase2e-generation-hardening/1.0"})
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
                "clientInfo": {"name": "phase2e-generation-hardening", "version": "1.0"},
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
            "User-Agent": "phase2e-generation-hardening/1.0",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            response.read()
            return int(response.status)
    except urllib.error.HTTPError as exc:
        exc.read()
        return int(exc.code)


def verify_compatibility(token: str) -> None:
    contextual = f"{token}.{GENERATION}"
    bare_allowed = mcp_initialize_status(f"{PUBLIC_MCP_BASE}/{TENANT_ID}", token)
    contextual_allowed = mcp_initialize_status(f"{PUBLIC_MCP_BASE}/{TENANT_ID}", contextual)
    contextual_denied = mcp_initialize_status(f"{PUBLIC_MCP_BASE}/{DENIED_TENANT}", contextual)
    if bare_allowed != 200 or contextual_allowed != 200 or contextual_denied != 403:
        raise RuntimeError(
            "compatibility probe failed "
            f"(bare={bare_allowed}, contextual={contextual_allowed}, denied={contextual_denied})"
        )


def verify_strict(token: str) -> None:
    contextual = f"{token}.{GENERATION}"
    bare_allowed = mcp_initialize_status(f"{PUBLIC_MCP_BASE}/{TENANT_ID}", token)
    contextual_allowed = mcp_initialize_status(f"{PUBLIC_MCP_BASE}/{TENANT_ID}", contextual)
    contextual_denied = mcp_initialize_status(f"{PUBLIC_MCP_BASE}/{DENIED_TENANT}", contextual)
    if bare_allowed != 401 or contextual_allowed != 200 or contextual_denied != 403:
        raise RuntimeError(
            "strict generation probe failed "
            f"(historical={bare_allowed}, current={contextual_allowed}, denied={contextual_denied})"
        )


def self_test() -> int:
    principal = {
        "id": PRINCIPAL_ID,
        "bearerTokenEnv": "EXISTING_GATEWAY_TOKEN",
        "tenants": [TENANT_ID],
        "capabilities": ["site.read", "operations.rollback", "media.write"],
    }
    sample = (
        "UNCHANGED=value\n"
        "EXISTING_GATEWAY_TOKEN=gateway-secret\n"
        f"MCP_PRINCIPALS_JSON={json.dumps([principal], separators=(',', ':'))}\n"
    )
    compat = build_mode(sample, False)
    strict = build_mode(compat, True)
    if gateway_bearer(strict) != "gateway-secret":
        raise RuntimeError("self-test changed the gateway bearer")
    parsed = parse_env(strict)
    if parsed.get(GENERATION_ENV) != GENERATION or parsed.get(REQUIRE_ENV) != "true":
        raise RuntimeError("self-test did not build strict generation mode")
    if parsed.get("UNCHANGED") != "value":
        raise RuntimeError("self-test changed unrelated environment material")
    if build_mode(strict, True) != strict:
        raise RuntimeError("self-test strict convergence is not idempotent")

    conflict = strict.replace(f"{GENERATION_ENV}={GENERATION}", f"{GENERATION_ENV}=other-generation")
    try:
        build_mode(conflict, True)
    except RuntimeError as exc:
        if "different gateway generation" not in str(exc):
            raise
    else:
        raise RuntimeError("self-test did not fail closed on generation conflict")

    log("phase2e-generation-hardening self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    if args.self_test:
        return self_test()

    if not ENV_PATH.is_file() or not COMPOSE_PATH.is_file():
        raise SystemExit("production MCP files are missing on this host")
    if BACKUP_PATH.exists():
        raise SystemExit(f"refusing to continue while transaction backup exists: {BACKUP_PATH}")

    original = ENV_PATH.read_text(encoding="utf-8")
    token = gateway_bearer(original)
    image_ref = current_image_ref()
    parsed = parse_env(original)
    if parsed.get(GENERATION_ENV) == GENERATION and parsed.get(REQUIRE_ENV, "").lower() == "true":
        wait_health()
        verify_strict(token)
        log("Gateway generation hardening: already strict and verified")
        log("Historical bare bearer: HTTP 401")
        log("Current generation bearer: HTTP 200")
        log("Denied tenant with current generation: HTTP 403")
        return 0

    compatibility = build_mode(original, False)
    strict = build_mode(original, True)
    BACKUP_PATH.write_text(original, encoding="utf-8")
    os.chmod(BACKUP_PATH, 0o600)

    try:
        atomic_write_env(compatibility)
        recreate_runtime(image_ref)
        wait_health()
        verify_compatibility(token)
        log("Gateway generation compatibility mode: verified")

        atomic_write_env(strict)
        recreate_runtime(image_ref)
        wait_health()
        verify_strict(token)
        log("Gateway generation strict mode: verified")
        log("Historical bare bearer: HTTP 401")
        log("Current generation bearer: HTTP 200")
        log("Denied tenant with current generation: HTTP 403")
        BACKUP_PATH.unlink()
        return 0
    except Exception:
        log("Gateway generation hardening failed; restoring previous production environment")
        atomic_write_env(BACKUP_PATH.read_text(encoding="utf-8"))
        recreate_runtime(image_ref)
        wait_health()
        BACKUP_PATH.unlink(missing_ok=True)
        raise


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:  # noqa: BLE001
        log(f"ERROR: {type(exc).__name__}: {exc}")
        raise SystemExit(1)
