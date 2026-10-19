#!/usr/bin/env python3
"""Provision or rotate the reviewed GitHub automation MCP principal.

Run on the production OVH host as a user that can update
/opt/site-control-mcp/.env.production and recreate the MCP container.

Security properties:
- the reviewed principal capability set comes from one canonical manifest;
- tenant entitlements are converged additively so unrelated tenant grants survive;
- rotation fails closed if the live principal has capabilities absent from the manifest;
- the principal remains restricted to the single eduardo-research tenant;
- existing tenants/principals and unrelated secrets are preserved;
- the underlying bearer is never printed unless --emit-token is explicitly supplied;
- strict gateway-generation presentation is respected during public verification;
- a mode-0600 rollback snapshot exists only for the transaction;
- the accepted immutable MCP image already recorded on the host is recreated;
- public health, allowed tenant and denied tenant are verified before success.

The intended secret handoff is a direct pipe from SSH stdout to `gh secret set`,
so the underlying bearer never needs to be pasted into ChatGPT or a shell argument.
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
DEFAULT_MANIFEST_PATH = Path(__file__).resolve().parent.parent / "config" / "phase2e-gateway-capabilities.json"
MANIFEST_ENV = "PHASE2D_CAPABILITY_MANIFEST"
IMAGE_RE = re.compile(r"^ghcr\.io/emmakex/site-control-mcp@sha256:[0-9a-f]{64}$")
GENERATION_RE = re.compile(r"^[a-z0-9][a-z0-9._-]{0,63}$")
CAPABILITY_RE = re.compile(r"^[a-z][a-z0-9._-]{1,63}$")
BASELINE_CAPABILITIES = {"site.read", "insights.write", "operations.rollback"}
TRUE_VALUES = {"1", "true", "yes", "on"}


def log(message: str) -> None:
    print(message, file=sys.stderr, flush=True)


def fail(message: str) -> "NoReturn":
    raise SystemExit(message)


def manifest_path() -> Path:
    configured = os.environ.get(MANIFEST_ENV)
    return Path(configured) if configured else DEFAULT_MANIFEST_PATH


def load_capability_manifest(path: Path | None = None) -> list[str]:
    source = path or manifest_path()
    if not source.is_file():
        raise RuntimeError(f"reviewed capability manifest is missing: {source}")
    try:
        value = json.loads(source.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise RuntimeError("reviewed capability manifest is invalid JSON") from exc
    if not isinstance(value, dict):
        raise RuntimeError("reviewed capability manifest must be an object")
    expected_keys = {"version", "tenant", "principal", "bearer_token_env", "capabilities"}
    if set(value) != expected_keys:
        raise RuntimeError("reviewed capability manifest has an unexpected shape")
    if value.get("version") != "1":
        raise RuntimeError("unsupported reviewed capability manifest version")
    if value.get("tenant") != TENANT_ID or value.get("principal") != PRINCIPAL_ID:
        raise RuntimeError("reviewed capability manifest identity does not match the production contract")
    if value.get("bearer_token_env") != TOKEN_ENV:
        raise RuntimeError("reviewed capability manifest bearer reference does not match the production contract")
    capabilities = value.get("capabilities")
    if not isinstance(capabilities, list) or not capabilities:
        raise RuntimeError("reviewed capability manifest capabilities must be a non-empty array")
    if not all(isinstance(item, str) and CAPABILITY_RE.fullmatch(item) for item in capabilities):
        raise RuntimeError("reviewed capability manifest contains an invalid capability")
    if len(capabilities) != len(set(capabilities)):
        raise RuntimeError("reviewed capability manifest contains duplicate capabilities")
    missing_baseline = BASELINE_CAPABILITIES.difference(capabilities)
    if missing_baseline:
        raise RuntimeError("reviewed capability manifest is missing the Phase 2D baseline")
    return list(capabilities)


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


def string_list(value: Any, label: str) -> list[str]:
    if not isinstance(value, list) or not all(isinstance(item, str) and item for item in value):
        raise RuntimeError(f"{label} must be a non-empty-string array")
    if len(value) != len(set(value)):
        raise RuntimeError(f"{label} contains duplicates")
    return list(value)


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
            request = urllib.request.Request(
                PUBLIC_HEALTH,
                headers={"User-Agent": "phase2d-principal-provision/2.0"},
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
                "clientInfo": {"name": "phase2d-principal-provision", "version": "2.0"},
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
            "User-Agent": "phase2d-principal-provision/2.0",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            response.read()
            return int(response.status)
    except urllib.error.HTTPError as exc:
        exc.read()
        return int(exc.code)


def presented_gateway_bearer(env: dict[str, str], underlying: str) -> str:
    strict = env.get("MCP_PHASE2D_REQUIRE_GATEWAY_GENERATION", "").strip().lower() in TRUE_VALUES
    if not strict:
        return underlying
    generation = env.get("MCP_PHASE2D_GATEWAY_GENERATION", "").strip()
    if not GENERATION_RE.fullmatch(generation):
        raise RuntimeError("strict gateway generation is enabled but generation is missing or invalid")
    return f"{underlying}.{generation}"


def build_updated_env(
    original: str,
    new_token: str,
    rotate: bool,
    capabilities: list[str] | None = None,
) -> tuple[str, str | None]:
    reviewed = list(capabilities or load_capability_manifest())
    tenants, principals = read_runtime_state(original)
    tenant_matches = [item for item in tenants if item.get("id") == TENANT_ID]
    if len(tenant_matches) != 1:
        raise RuntimeError(f"required tenant {TENANT_ID} must exist exactly once")
    tenant = tenant_matches[0]
    tenant_entitlements = string_list(tenant.get("entitlements"), f"{TENANT_ID}.entitlements")

    existing_matches = [item for item in principals if item.get("id") == PRINCIPAL_ID]
    if len(existing_matches) > 1:
        raise RuntimeError(f"principal {PRINCIPAL_ID} exists more than once")
    existing = existing_matches[0] if existing_matches else None
    if existing is not None and not rotate:
        raise RuntimeError(f"principal {PRINCIPAL_ID} already exists; use --rotate to replace it")
    if existing is None and rotate:
        raise RuntimeError(f"principal {PRINCIPAL_ID} does not exist; omit --rotate for first provisioning")

    parsed = parse_env(original)
    old_token: str | None = None
    old_ref: str | None = None
    if existing is not None:
        tenants_allowed = string_list(existing.get("tenants"), f"{PRINCIPAL_ID}.tenants")
        if tenants_allowed != [TENANT_ID]:
            raise RuntimeError("existing Phase 2D principal violates the accepted single-tenant allowlist")
        existing_capabilities = string_list(existing.get("capabilities"), f"{PRINCIPAL_ID}.capabilities")
        extra_live = [item for item in existing_capabilities if item not in reviewed]
        if extra_live:
            raise RuntimeError(
                "live principal has capabilities absent from the canonical manifest; update review evidence before rotation"
            )
        old_ref_raw = existing.get("bearerTokenEnv")
        if not isinstance(old_ref_raw, str) or not old_ref_raw:
            raise RuntimeError("existing Phase 2D principal has no bearerTokenEnv")
        old_ref = old_ref_raw
        old_token = parsed.get(old_ref)
        if not old_token:
            raise RuntimeError("existing Phase 2D principal token reference is unresolved")

    next_entitlements = list(tenant_entitlements)
    for capability in reviewed:
        if capability not in next_entitlements:
            next_entitlements.append(capability)
    next_tenants = [
        {**item, "entitlements": next_entitlements} if item.get("id") == TENANT_ID else item
        for item in tenants
    ]

    new_principal = {
        "id": PRINCIPAL_ID,
        "bearerTokenEnv": TOKEN_ENV,
        "tenants": [TENANT_ID],
        "capabilities": reviewed,
    }
    next_principals = [item for item in principals if item.get("id") != PRINCIPAL_ID]
    next_principals.append(new_principal)

    replacements = {
        TOKEN_ENV: new_token,
        "MCP_TENANTS_JSON": json.dumps(next_tenants, separators=(",", ":")),
        "MCP_PRINCIPALS_JSON": json.dumps(next_principals, separators=(",", ":")),
    }
    removals: set[str] = set()
    if old_ref and old_ref != TOKEN_ENV:
        removals.add(old_ref)
    updated = replace_env_lines(original, replacements, removals)
    verify_updated_contract(updated, reviewed, new_token)
    return updated, old_token


def verify_updated_contract(text: str, reviewed: list[str], expected_token: str) -> None:
    parsed = parse_env(text)
    tenants, principals = read_runtime_state(text)
    tenant = next((item for item in tenants if item.get("id") == TENANT_ID), None)
    principal = next((item for item in principals if item.get("id") == PRINCIPAL_ID), None)
    if tenant is None or principal is None:
        raise RuntimeError("updated runtime is missing the reviewed tenant/principal")
    entitlements = string_list(tenant.get("entitlements"), f"{TENANT_ID}.entitlements")
    missing = [item for item in reviewed if item not in entitlements]
    if missing:
        raise RuntimeError("updated tenant is missing reviewed capabilities")
    if string_list(principal.get("tenants"), f"{PRINCIPAL_ID}.tenants") != [TENANT_ID]:
        raise RuntimeError("updated principal tenant allowlist is not the accepted single tenant")
    if string_list(principal.get("capabilities"), f"{PRINCIPAL_ID}.capabilities") != reviewed:
        raise RuntimeError("updated principal capabilities do not exactly match the canonical manifest")
    if principal.get("bearerTokenEnv") != TOKEN_ENV or parsed.get(TOKEN_ENV) != expected_token:
        raise RuntimeError("updated principal bearer reference does not resolve to the new bearer")


def self_test() -> int:
    reviewed = load_capability_manifest()
    tenant = {
        "id": TENANT_ID,
        "managerBaseUrl": "https://example.invalid",
        "readTokenEnv": "READ_TOKEN",
        "writeTokenEnv": "WRITE_TOKEN",
        "entitlements": ["tenant.other", "site.read"],
    }
    other_principal = {
        "id": "research-ops",
        "bearerTokenEnv": "OPS_TOKEN",
        "tenants": [TENANT_ID],
        "capabilities": ["research.read"],
    }
    sample = (
        "MCP_SHARED_ROUTING=true\n"
        "READ_TOKEN=read-secret\n"
        "WRITE_TOKEN=write-secret\n"
        "OPS_TOKEN=existing-secret\n"
        "MCP_PHASE2D_GATEWAY_GENERATION=phase2d-v2-20261019\n"
        "MCP_PHASE2D_REQUIRE_GATEWAY_GENERATION=true\n"
        f"MCP_TENANTS_JSON={json.dumps([tenant], separators=(',', ':'))}\n"
        f"MCP_PRINCIPALS_JSON={json.dumps([other_principal], separators=(',', ':'))}\n"
    )
    updated, old = build_updated_env(sample, "new-secret", rotate=False, capabilities=reviewed)
    if old is not None:
        raise RuntimeError("self-test unexpectedly found an old Phase 2D token")
    parsed = parse_env(updated)
    principals = json.loads(parsed["MCP_PRINCIPALS_JSON"])
    phase2d = next(item for item in principals if item.get("id") == PRINCIPAL_ID)
    if phase2d["capabilities"] != reviewed:
        raise RuntimeError("self-test did not provision the full canonical reviewed capability set")
    tenants = json.loads(parsed["MCP_TENANTS_JSON"])
    next_tenant = next(item for item in tenants if item.get("id") == TENANT_ID)
    if "tenant.other" not in next_tenant["entitlements"]:
        raise RuntimeError("self-test dropped an unrelated tenant entitlement")
    if any(item not in next_tenant["entitlements"] for item in reviewed):
        raise RuntimeError("self-test did not align tenant entitlements with the canonical manifest")
    if parsed.get("OPS_TOKEN") != "existing-secret":
        raise RuntimeError("self-test modified an unrelated principal secret")
    if parsed.get(TOKEN_ENV) != "new-secret":
        raise RuntimeError("self-test did not install the generated token")
    if presented_gateway_bearer(parsed, "new-secret") != "new-secret.phase2d-v2-20261019":
        raise RuntimeError("self-test did not apply strict gateway generation to bearer presentation")

    rotated, old = build_updated_env(updated, "rotated-secret", rotate=True, capabilities=reviewed)
    if old != "new-secret":
        raise RuntimeError("self-test rotation did not recover the prior token")
    rotated_env = parse_env(rotated)
    rotated_principals = json.loads(rotated_env["MCP_PRINCIPALS_JSON"])
    rotated_phase2d = next(item for item in rotated_principals if item.get("id") == PRINCIPAL_ID)
    if rotated_phase2d["capabilities"] != reviewed:
        raise RuntimeError("self-test rotation regressed reviewed gateway capabilities")
    if rotated_env.get(TOKEN_ENV) != "rotated-secret":
        raise RuntimeError("self-test rotation did not replace the underlying token")

    widened = json.loads(json.dumps(rotated_phase2d))
    widened["tenants"] = [TENANT_ID, "other-tenant"]
    widened_principals = [item for item in rotated_principals if item.get("id") != PRINCIPAL_ID] + [widened]
    widened_sample = replace_env_lines(
        rotated,
        {"MCP_PRINCIPALS_JSON": json.dumps(widened_principals, separators=(",", ":"))},
        set(),
    )
    try:
        build_updated_env(widened_sample, "next-secret", rotate=True, capabilities=reviewed)
    except RuntimeError as exc:
        if "single-tenant" not in str(exc):
            raise
    else:
        raise RuntimeError("self-test did not fail closed on widened tenant allowlist")

    stale = json.loads(json.dumps(rotated_phase2d))
    stale["capabilities"] = reviewed + ["future.write"]
    stale_principals = [item for item in rotated_principals if item.get("id") != PRINCIPAL_ID] + [stale]
    stale_sample = replace_env_lines(
        rotated,
        {"MCP_PRINCIPALS_JSON": json.dumps(stale_principals, separators=(",", ":"))},
        set(),
    )
    try:
        build_updated_env(stale_sample, "next-secret", rotate=True, capabilities=reviewed)
    except RuntimeError as exc:
        if "canonical manifest" not in str(exc):
            raise
    else:
        raise RuntimeError("self-test did not fail closed on live capability drift beyond the manifest")

    bad_generation = dict(rotated_env)
    bad_generation["MCP_PHASE2D_GATEWAY_GENERATION"] = "INVALID GENERATION"
    try:
        presented_gateway_bearer(bad_generation, "rotated-secret")
    except RuntimeError as exc:
        if "generation" not in str(exc):
            raise
    else:
        raise RuntimeError("self-test did not fail closed on invalid strict gateway generation")

    log(f"phase2d-principal-provision self-test: OK ({len(reviewed)} reviewed capabilities)")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--emit-token", action="store_true", help="emit the verified new underlying bearer on stdout exactly once")
    parser.add_argument("--rotate", action="store_true", help="rotate an already-provisioned phase2d-github principal")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()

    if args.self_test:
        return self_test()
    reviewed = load_capability_manifest()
    if not ENV_PATH.is_file() or not COMPOSE_PATH.is_file():
        fail("production MCP files are missing on this host")
    if BACKUP_PATH.exists():
        fail(f"refusing to continue while transaction backup exists: {BACKUP_PATH}")

    original = ENV_PATH.read_text(encoding="utf-8")
    original_env = parse_env(original)
    image_ref = current_image_ref()
    new_token = secrets.token_urlsafe(48)
    updated, old_token = build_updated_env(original, new_token, args.rotate, capabilities=reviewed)
    new_presented = presented_gateway_bearer(parse_env(updated), new_token)
    old_presented = presented_gateway_bearer(original_env, old_token) if old_token else None

    BACKUP_PATH.write_text(original, encoding="utf-8")
    os.chmod(BACKUP_PATH, 0o600)
    atomic_write_env(updated)

    restored = False
    try:
        recreate_runtime(image_ref)
        wait_health()
        allowed = mcp_initialize_status(f"{PUBLIC_MCP_BASE}/{TENANT_ID}", new_presented)
        denied = mcp_initialize_status(f"{PUBLIC_MCP_BASE}/phase2d-denied-probe", new_presented)
        if allowed != 200:
            raise RuntimeError(f"new automation principal failed allowed tenant initialize (HTTP {allowed})")
        if denied != 403:
            raise RuntimeError(f"new automation principal did not fail closed on denied tenant (HTTP {denied})")
        if old_presented:
            old_status = mcp_initialize_status(f"{PUBLIC_MCP_BASE}/{TENANT_ID}", old_presented)
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
    log(f"Reviewed capabilities: {', '.join(reviewed)}")
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
