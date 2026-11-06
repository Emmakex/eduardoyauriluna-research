#!/usr/bin/env python3
"""Read-only, redacted production gates for #464 Page Plan authorization.

Reads the protected environment file on OVH, but prints ONLY fixed boolean flags.
Never emits registry entries, tokens, paths to secrets or arbitrary config values.
Does not create a Plan, grant access, contact WordPress, or execute Apply.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
from pathlib import Path
import sys
from typing import Any

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("editorial464_authz_preflight", HERE / "site-architect-greenfield-authz-preflight.py")
if spec is None or spec.loader is None:
    raise SystemExit("ERROR: trusted protected preflight module not found")
preflight = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = preflight
spec.loader.exec_module(preflight)

CAPABILITY = "pages.write"


def inspect(values: dict[str, str]) -> dict[str, Any]:
    base = preflight.inspect(values)
    tenant = preflight.exact_entry(values.get("MCP_TENANTS_JSON"), "eduardo-research")
    principal = preflight.exact_entry(values.get("MCP_PRINCIPALS_JSON"), "research-ops")
    checks = {
        "greenfield_operations_apply_preflight_ready": base["status"] == "ready",
        "tenant_pages_write_entitled": bool(tenant and preflight.includes(tenant.get("entitlements"), CAPABILITY)),
        "research_ops_pages_write_granted": bool(principal and preflight.includes(principal.get("capabilities"), CAPABILITY)),
        "research_ops_tenant_bounded": bool(principal and principal.get("tenants") == ["eduardo-research"]),
        "manager_write_token_reference_configured": bool(base["checks"]["manager_write_token_configured"]),
    }
    return {
        "inspection": "site-architect-464-pages-write-authorization",
        "status": "ready" if all(checks.values()) else "blocked",
        "read_only": True,
        "checks": checks,
        "backend_scope_for_plan": "operations.apply (contract; not re-queried)",
        "no_grants_changed": True,
        "public_site_apply": "not_executed",
        "secrets_or_registry_exported": False,
    }


def self_test() -> int:
    def fixture(tenant_grant: bool, principal_grant: bool) -> dict[str, str]:
        tenant = {"id": "eduardo-research", "profile": "research-theme",
                  "entitlements": ["site.read", "operations.apply"] +
                  (["pages.write"] if tenant_grant else []),
                  "writeTokenEnv": "PRIVATE_MANAGER_TOKEN"}
        principal = {"id": "research-ops", "tenants": ["eduardo-research"],
                     "capabilities": ["site.read", "operations.apply"] +
                     (["pages.write"] if principal_grant else []),
                     "bearerTokenEnv": "PRIVATE_CALLER_TOKEN"}
        return {
            "MCP_SHARED_ROUTING": "true",
            "MCP_TENANTS_JSON": json.dumps([tenant]),
            "MCP_PRINCIPALS_JSON": json.dumps([principal]),
            "PRIVATE_MANAGER_TOKEN": "SECRET_NEVER_PRINT",
            "PRIVATE_CALLER_TOKEN": "SECRET_NEVER_PRINT",
        }
    for tenant_grant in (False, True):
        for principal_grant in (False, True):
            answer = inspect(fixture(tenant_grant, principal_grant))
            assert answer["checks"]["tenant_pages_write_entitled"] == tenant_grant
            assert answer["checks"]["research_ops_pages_write_granted"] == principal_grant
            assert (answer["status"] == "ready") == (tenant_grant and principal_grant)
            assert "SECRET_NEVER_PRINT" not in json.dumps(answer)
            assert "PRIVATE_MANAGER_TOKEN" not in json.dumps(answer)
            assert "PRIVATE_CALLER_TOKEN" not in json.dumps(answer)
    missing = fixture(True, True)
    principal = json.loads(missing["MCP_PRINCIPALS_JSON"])
    principal[0]["tenants"] = ["other-tenant"]
    missing["MCP_PRINCIPALS_JSON"] = json.dumps(principal)
    assert inspect(missing)["status"] == "blocked"
    try:
        inspect({**fixture(True, True), "MCP_TENANTS_JSON": "[BAD_SECRET"})
    except preflight.ProtectedConfigError:
        pass
    else:
        raise AssertionError("malformed tenant registry unexpectedly accepted")
    print("PASS: isolated tenant/principal pages.write booleans; no grants, Plans or secrets")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--self-test", action="store_true")
    mode.add_argument("--inspect-production", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            return self_test()
        print(json.dumps(inspect(preflight.protected_vars(preflight.PRODUCTION_ENV)),
                         sort_keys=True, separators=(",", ":")))
        return 0  # A blocked authorization is a successful READ-ONLY diagnostic.
    except Exception:
        print("ERROR: protected #464 authorization inspection unavailable; no registry or values exported", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
