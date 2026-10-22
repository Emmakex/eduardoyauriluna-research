#!/usr/bin/env python3
"""Safely converge the production command gateway on reviewed capabilities.

Each wrapper binds ``APPROVED_CAPABILITIES`` to one reviewed Phase 2E slice.
Capabilities are added to BOTH tenant entitlements and the existing
``phase2d-github`` principal while preserving the underlying bearer exactly.

The production mutation is transactional: backup mode 0600, accepted immutable
image recreation, public health + allowed/denied routing checks, and automatic
rollback on any verification failure. Public verification respects the strict
Phase 2D gateway-generation presentation contract without mutating stored
credential material. No bearer or .env content is printed.
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
BACKUP_PATH = Path("/opt/site-control-mcp/.env.production.phase2e-capability-backup")
COMPOSE_PATH = Path("/opt/site-control-mcp/compose.ingress.yml")
PROJECT_DIR = Path("/opt/site-control-mcp")
CURRENT_IMAGE_PATH = PROJECT_DIR / ".current-image-ref"
SERVICE = "research-manager-mcp"
PUBLIC_HEALTH = "https://mcp.kairoseth.com/healthz"
PUBLIC_MCP_BASE = "https://mcp.kairoseth.com/mcp"
TENANT_ID = "eduardo-research"
PRINCIPAL_ID = "phase2d-github"
APPROVED_CAPABILITIES = ["design.read", "design.write"]
IMAGE_RE = re.compile(r"^ghcr\.io/emmakex/site-control-mcp@sha256:[0-9a-f]{64}$")
GENERATION_RE = re.compile(r"^[a-z0-9][a-z0-9._-]{0,63}$")
TRUE_VALUES = {"1", "true", "yes", "on"}


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


def runtime_json(original: str) -> tuple[dict[str, str], list[dict[str, Any]], list[dict[str, Any]]]:
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
    return parsed, tenants, principals


def one_by_id(items: list[dict[str, Any]], item_id: str, kind: str) -> dict[str, Any]:
    matches = [item for item in items if item.get("id") == item_id]
    if len(matches) != 1:
        raise RuntimeError(f"expected exactly one {kind} {item_id}; found {len(matches)}")
    return matches[0]


def string_list(value: Any, label: str) -> list[str]:
    if not isinstance(value, list) or not all(isinstance(item, str) and item for item in value):
        raise RuntimeError(f"{label} must be a non-empty-string array")
    if len(value) != len(set(value)):
        raise RuntimeError(f"{label} contains duplicates")
    return list(value)


def add_missing(values: list[str], approved: list[str]) -> tuple[list[str], list[str]]:
    output = list(values)
    added: list[str] = []
    for capability in approved:
        if capability not in output:
            output.append(capability)
            added.append(capability)
    return output, added


def build_updated_env(original: str) -> tuple[str, str, list[str], list[str]]:
    parsed, tenants, principals = runtime_json(original)
    tenant = one_by_id(tenants, TENANT_ID, "tenant")
    principal = one_by_id(principals, PRINCIPAL_ID, "principal")

    principal_tenants = string_list(principal.get("tenants"), f"{PRINCIPAL_ID}.tenants")
    if principal_tenants != [TENANT_ID]:
        raise RuntimeError("phase2d-github tenant allowlist is not the accepted single-tenant contract")

    token_ref = principal.get("bearerTokenEnv")
    if not isinstance(token_ref, str) or not token_ref:
        raise RuntimeError("phase2d-github has no bearerTokenEnv")
    token = parsed.get(token_ref)
    if not token:
        raise RuntimeError("phase2d-github bearerTokenEnv is unresolved")

    entitlements = string_list(tenant.get("entitlements"), f"{TENANT_ID}.entitlements")
    capabilities = string_list(principal.get("capabilities"), f"{PRINCIPAL_ID}.capabilities")
    next_entitlements, tenant_added = add_missing(entitlements, APPROVED_CAPABILITIES)
    next_capabilities, principal_added = add_missing(capabilities, APPROVED_CAPABILITIES)

    next_tenants = [
        {**item, "entitlements": next_entitlements} if item.get("id") == TENANT_ID else item
        for item in tenants
    ]
    next_principals = [
        {**item, "capabilities": next_capabilities} if item.get("id") == PRINCIPAL_ID else item
        for item in principals
    ]

    updated = replace_env_lines(
        original,
        {
            "MCP_TENANTS_JSON": json.dumps(next_tenants, separators=(",", ":")),
            "MCP_PRINCIPALS_JSON": json.dumps(next_principals, separators=(",", ":")),
        },
    )
    after = parse_env(updated)
    if after.get(token_ref) != token:
        raise RuntimeError("capability expansion changed the existing gateway bearer")
    for key, value in parsed.items():
        if key not in {"MCP_TENANTS_JSON", "MCP_PRINCIPALS_JSON"} and after.get(key) != value:
            raise RuntimeError(f"capability expansion changed unrelated env key {key}")
    return updated, token, tenant_added, principal_added


def verify_contract(text: str) -> str:
    parsed, tenants, principals = runtime_json(text)
    tenant = one_by_id(tenants, TENANT_ID, "tenant")
    principal = one_by_id(principals, PRINCIPAL_ID, "principal")
    entitlements = string_list(tenant.get("entitlements"), f"{TENANT_ID}.entitlements")
    capabilities = string_list(principal.get("capabilities"), f"{PRINCIPAL_ID}.capabilities")
    for capability in APPROVED_CAPABILITIES:
        if capability not in entitlements:
            raise RuntimeError(f"tenant is missing {capability}")
        if capability not in capabilities:
            raise RuntimeError(f"principal is missing {capability}")
    if string_list(principal.get("tenants"), f"{PRINCIPAL_ID}.tenants") != [TENANT_ID]:
        raise RuntimeError("principal tenant allowlist changed")
    token_ref = principal.get("bearerTokenEnv")
    if not isinstance(token_ref, str) or not token_ref or not parsed.get(token_ref):
        raise RuntimeError("principal bearer reference no longer resolves")
    return parsed[token_ref]


def presented_gateway_bearer(env: dict[str, str], underlying: str) -> str:
    """Return the public bearer presentation without changing stored credentials."""
    strict = env.get("MCP_PHASE2D_REQUIRE_GATEWAY_GENERATION", "").strip().lower() in TRUE_VALUES
    if not strict:
        return underlying
    generation = env.get("MCP_PHASE2D_GATEWAY_GENERATION", "").strip()
    if not GENERATION_RE.fullmatch(generation):
        raise RuntimeError("strict gateway generation is enabled but generation is missing or invalid")
    return f"{underlying}.{generation}"


def current_image_ref() -> str:
    if not CURRENT_IMAGE_PATH.is_file():
        raise RuntimeError("accepted current image reference is missing")
    value = CURRENT_IMAGE_PATH.read_text(encoding="utf-8").strip()
    if not IMAGE_RE.fullmatch(value):
        raise RuntimeError("accepted current image reference is invalid")
    return value


def atomic_write_env(text: str) -> None:
    tmp = ENV_PATH.with_name(ENV_PATH.name + ".phase2e-tmp")
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
            request = urllib.request.Request(PUBLIC_HEALTH, headers={"User-Agent": "phase2e-capability-expand/2.1"})
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
                "clientInfo": {"name": "phase2e-capability-expand", "version": "2.1"},
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
            "User-Agent": "phase2e-capability-expand/2.1",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            response.read()
            return int(response.status)
    except urllib.error.HTTPError as exc:
        exc.read()
        return int(exc.code)


def verify_public_routing(env: dict[str, str], underlying: str) -> None:
    token = presented_gateway_bearer(env, underlying)
    allowed = mcp_initialize_status(f"{PUBLIC_MCP_BASE}/{TENANT_ID}", token)
    denied = mcp_initialize_status(f"{PUBLIC_MCP_BASE}/phase2d-denied-probe", token)
    if allowed != 200:
        raise RuntimeError(f"existing automation bearer failed allowed tenant initialize (HTTP {allowed})")
    if denied != 403:
        raise RuntimeError(f"existing automation bearer did not fail closed on denied tenant (HTTP {denied})")


def self_test() -> int:
    tenant = {
        "id": TENANT_ID,
        "managerBaseUrl": "https://example.invalid",
        "readTokenEnv": "READ_TOKEN",
        "writeTokenEnv": "WRITE_TOKEN",
        "entitlements": ["site.read", "pages.read", "insights.write", "operations.rollback"],
    }
    principal = {
        "id": PRINCIPAL_ID,
        "bearerTokenEnv": "EXISTING_GATEWAY_TOKEN",
        "tenants": [TENANT_ID],
        "capabilities": ["site.read", "insights.write", "operations.rollback"],
    }
    sample = (
        "UNCHANGED=value\n"
        "READ_TOKEN=read-secret\n"
        "WRITE_TOKEN=write-secret\n"
        "EXISTING_GATEWAY_TOKEN=gateway-secret\n"
        f"MCP_TENANTS_JSON={json.dumps([tenant], separators=(',', ':'))}\n"
        f"MCP_PRINCIPALS_JSON={json.dumps([principal], separators=(',', ':'))}\n"
    )
    updated, token, tenant_added, principal_added = build_updated_env(sample)
    if token != "gateway-secret":
        raise RuntimeError("self-test changed the existing bearer")
    if tenant_added != APPROVED_CAPABILITIES or principal_added != APPROVED_CAPABILITIES:
        raise RuntimeError("self-test did not add the reviewed capability set")
    if parse_env(updated).get("UNCHANGED") != "value":
        raise RuntimeError("self-test changed unrelated env material")
    if verify_contract(updated) != "gateway-secret":
        raise RuntimeError("self-test expanded contract is invalid")
    if presented_gateway_bearer(parse_env(updated), token) != "gateway-secret":
        raise RuntimeError("self-test changed bearer presentation in compatibility mode")
    again, token_again, tenant_again, principal_again = build_updated_env(updated)
    if tenant_again or principal_again or token_again != "gateway-secret" or again != updated:
        raise RuntimeError("self-test convergence is not idempotent")

    strict_sample = sample + (
        "MCP_PHASE2D_REQUIRE_GATEWAY_GENERATION=true\n"
        "MCP_PHASE2D_GATEWAY_GENERATION=phase2d-v2-20261019\n"
    )
    strict_updated, strict_token, _, _ = build_updated_env(strict_sample)
    strict_env = parse_env(strict_updated)
    if strict_env.get("EXISTING_GATEWAY_TOKEN") != "gateway-secret":
        raise RuntimeError("self-test strict generation mutated stored bearer")
    if presented_gateway_bearer(strict_env, strict_token) != "gateway-secret.phase2d-v2-20261019":
        raise RuntimeError("self-test strict generation did not contextualize bearer presentation")

    for invalid_generation in ("", "INVALID GENERATION", ".bad", "a" * 65):
        invalid_env = dict(strict_env)
        invalid_env["MCP_PHASE2D_GATEWAY_GENERATION"] = invalid_generation
        try:
            presented_gateway_bearer(invalid_env, strict_token)
        except RuntimeError as exc:
            if "generation is missing or invalid" not in str(exc):
                raise
        else:
            raise RuntimeError("self-test did not fail closed on invalid strict generation")

    bad = json.loads(json.dumps(principal))
    bad["tenants"] = [TENANT_ID, "other-tenant"]
    bad_sample = sample.replace(
        f"MCP_PRINCIPALS_JSON={json.dumps([principal], separators=(',', ':'))}",
        f"MCP_PRINCIPALS_JSON={json.dumps([bad], separators=(',', ':'))}",
    )
    try:
        build_updated_env(bad_sample)
    except RuntimeError as exc:
        if "tenant allowlist" not in str(exc):
            raise
    else:
        raise RuntimeError("self-test did not fail closed on widened tenant allowlist")

    log("phase2e-capability-expand self-test: OK")
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
    updated, token, tenant_added, principal_added = build_updated_env(original)
    image_ref = current_image_ref()
    changed = bool(tenant_added or principal_added)

    if not changed:
        verify_contract(original)
        wait_health()
        verify_public_routing(parse_env(original), token)
        log("Reviewed gateway capabilities: already active for tenant + principal")
        log("Existing automation bearer: preserved and verified")
        log("Allowed tenant initialize: HTTP 200")
        log("Denied tenant initialize: HTTP 403")
        return 0

    BACKUP_PATH.write_text(original, encoding="utf-8")
    os.chmod(BACKUP_PATH, 0o600)
    atomic_write_env(updated)
    try:
        live = ENV_PATH.read_text(encoding="utf-8")
        verify_contract(live)
        recreate_runtime(image_ref)
        wait_health()
        verify_public_routing(parse_env(live), token)
    except Exception:
        log("Capability expansion verification failed; restoring previous production environment")
        atomic_write_env(BACKUP_PATH.read_text(encoding="utf-8"))
        recreate_runtime(image_ref)
        wait_health()
        BACKUP_PATH.unlink(missing_ok=True)
        raise

    BACKUP_PATH.unlink(missing_ok=True)
    for capability in tenant_added:
        log(f"Tenant entitlement added: {capability}")
    for capability in principal_added:
        log(f"Principal capability added: {capability}")
    log("Existing automation bearer: preserved and verified")
    log("Allowed tenant initialize: HTTP 200")
    log("Denied tenant initialize: HTTP 403")
    log("Secret-bearing transaction backup: REMOVED")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())