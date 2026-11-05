#!/usr/bin/env python3
"""Read-only, secret-free readiness inspection for Eduardo Site Architect Greenfield.

Runs locally on the *protected OVH host*. Never sources production env vars,
prints registry contents, exposes secret values, or updates permissions.
The recorded denial was tenant_not_entitled for operations.apply. This inspects
only the two commercial gates plus the existing Manager write-token reference.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import stat
import sys
import tempfile
from typing import Any

PRODUCTION_ENV = Path("/opt/site-control-mcp/.env.production")
TENANT = "eduardo-research"
PRINCIPAL = "research-ops"
CAPABILITY = "operations.apply"
KEY_RE = re.compile(r"^[A-Z][A-Z0-9_]{0,99}$")


class ProtectedConfigError(Exception):
    """Do not include the raw input or credential in any error."""


def protected_vars(path: Path) -> dict[str, str]:
    try:
        info = path.stat()
        if not stat.S_ISREG(info.st_mode) or (info.st_mode & 0o077):
            raise ProtectedConfigError()
        if info.st_size < 1 or info.st_size > 1_048_576:
            raise ProtectedConfigError()
        text = path.read_text(encoding="utf-8")
    except (OSError, UnicodeError, ValueError) as exc:
        raise ProtectedConfigError() from None

    values: dict[str, str] = {}
    for line in text.splitlines():
        raw = line.strip()
        if not raw or raw.startswith("#"):
            continue
        if "=" not in raw:
            raise ProtectedConfigError()
        key, value = raw.split("=", 1)
        if not KEY_RE.fullmatch(key) or key in values:
            raise ProtectedConfigError()
        value = value.strip()
        if len(value) >= 2 and value[:1] == value[-1:] and value[0] in {'"', "'"}:
            value = value[1:-1]
        values[key] = value
    return values


def exact_entry(raw: str | None, id_value: str) -> dict[str, Any] | None:
    if not isinstance(raw, str) or not raw:
        raise ProtectedConfigError()
    try:
        parsed = json.loads(raw)
    except (ValueError, TypeError) as exc:
        raise ProtectedConfigError() from None
    if not isinstance(parsed, list) or not 1 <= len(parsed) <= 100:
        raise ProtectedConfigError()
    matches = [item for item in parsed if isinstance(item, dict) and item.get("id") == id_value]
    if len(matches) > 1:
        raise ProtectedConfigError()
    return matches[0] if matches else None


def includes(value: Any, item: str) -> bool:
    return isinstance(value, list) and all(isinstance(v, str) for v in value) and item in value


def inspect(values: dict[str, str]) -> dict[str, Any]:
    if values.get("MCP_SHARED_ROUTING") != "true":
        raise ProtectedConfigError()
    tenant = exact_entry(values.get("MCP_TENANTS_JSON"), TENANT)
    principal = exact_entry(values.get("MCP_PRINCIPALS_JSON"), PRINCIPAL)
    write_reference = tenant.get("writeTokenEnv") if tenant else None
    write_ref_valid = isinstance(write_reference, str) and KEY_RE.fullmatch(write_reference) is not None
    checks = {
        "tenant_registered": tenant is not None,
        "research_theme_profile": bool(tenant and tenant.get("profile") == "research-theme"),
        "tenant_entitled_operations_apply": bool(tenant and includes(tenant.get("entitlements"), CAPABILITY)),
        "research_ops_principal_registered": principal is not None,
        "research_ops_tenant_allowed": bool(principal and includes(principal.get("tenants"), TENANT)),
        "research_ops_granted_operations_apply": bool(principal and includes(principal.get("capabilities"), CAPABILITY)),
        "manager_write_token_reference": bool(write_ref_valid),
        "manager_write_token_configured": bool(write_ref_valid and values.get(write_reference, "")),
    }
    return {
        "status": "ready" if all(checks.values()) else "blocked",
        "inspection": "site-architect-greenfield-plan-authz",
        "read_only": True,
        "checks": checks,
        "notes": {
            "manager_backend_scope": "separately_verified",
            "public_site_apply": "not_executed",
            "secrets_or_config_exported": False,
        },
    }


def self_test() -> int:
    def sample(*, tenant_grant=False, principal_grant=False, write_ref=True, profile="research-theme"):
        tenant = {
            "id": TENANT, "adapter": "research-manager", "profile": profile,
            "readTokenEnv": "TEST_READ_TOKEN",
            "entitlements": ["site.read"] + ([CAPABILITY] if tenant_grant else []),
        }
        if write_ref:
            tenant["writeTokenEnv"] = "TEST_WRITE_TOKEN"
        principal = {
            "id": PRINCIPAL, "bearerTokenEnv": "TEST_OUTER_TOKEN",
            "tenants": [TENANT], "capabilities": ["site.read"] + ([CAPABILITY] if principal_grant else []),
        }
        return {
            "MCP_SHARED_ROUTING": "true",
            "MCP_TENANTS_JSON": json.dumps([tenant], separators=(",", ":")),
            "MCP_PRINCIPALS_JSON": json.dumps([principal], separators=(",", ":")),
            "TEST_READ_TOKEN": "NEVER_EXPOSE_READ",
            "TEST_WRITE_TOKEN": "NEVER_EXPOSE_WRITE",
            "TEST_OUTER_TOKEN": "NEVER_EXPOSE_OUTER",
        }

    first = inspect(sample())
    assert first["status"] == "blocked"
    assert first["checks"]["tenant_entitled_operations_apply"] is False
    assert first["checks"]["research_ops_granted_operations_apply"] is False
    assert first["checks"]["manager_write_token_configured"] is True
    second = inspect(sample(tenant_grant=True))
    assert second["status"] == "blocked" and second["checks"]["research_ops_granted_operations_apply"] is False
    third = inspect(sample(tenant_grant=True, principal_grant=True))
    assert third["status"] == "ready" and all(third["checks"].values())
    assert inspect(sample(tenant_grant=True, principal_grant=True, write_ref=False))["status"] == "blocked"
    assert inspect(sample(tenant_grant=True, principal_grant=True, profile="generic-wordpress"))["status"] == "blocked"
    assert "NEVER_EXPOSE" not in json.dumps(third)
    for keys in ("MCP_TENANTS_JSON", "MCP_PRINCIPALS_JSON"):
        malformed = sample()
        malformed[keys] = "{NEVER_EXPOSE_INVALID"
        try:
            inspect(malformed)
        except ProtectedConfigError:
            pass
        else:
            raise AssertionError("invalid protected registry unexpectedly accepted")

    with tempfile.TemporaryDirectory() as td:
        path = Path(td) / ".env.production"
        contents = sample(tenant_grant=True, principal_grant=True)
        path.write_text("\n".join(f"{key}={value}" for key, value in contents.items()) + "\n", encoding="utf-8")
        path.chmod(0o600)
        assert inspect(protected_vars(path))["status"] == "ready"
        path.chmod(0o644)
        try:
            protected_vars(path)
        except ProtectedConfigError:
            pass
        else:
            raise AssertionError("unsafe .env.production permissions unexpectedly accepted")
    print("PASS: protected Greenfield entitlement inspection is read-only and secret-free")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Inspect only the existing Site Architect capability gates")
    parser.add_argument("--env-file", type=Path, default=PRODUCTION_ENV)
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    if args.self_test:
        return self_test()
    if args.env_file != PRODUCTION_ENV:
        print("ERROR: Site Architect preflight only accepts the canonical protected env path", file=sys.stderr)
        return 2
    try:
        result = inspect(protected_vars(args.env_file))
    except ProtectedConfigError:
        print("ERROR: Protected Site Architect configuration unavailable or invalid (no values printed)", file=sys.stderr)
        return 2
    print(json.dumps(result, sort_keys=True, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
