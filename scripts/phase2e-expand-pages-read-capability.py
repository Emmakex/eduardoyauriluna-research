#!/usr/bin/env python3
"""Bind the reviewed Phase 2E gateway capability expander to pages.read."""
from __future__ import annotations

import importlib.util
import json
import sys
from pathlib import Path

BASE = Path(__file__).with_name("phase2e-expand-gateway-capabilities.py")
spec = importlib.util.spec_from_file_location("phase2e_expand_gateway_capabilities", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2E gateway capability expander")
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)

helper.APPROVED_CAPABILITIES = ["pages.read"]


def self_test() -> int:
    tenant = {
        "id": helper.TENANT_ID,
        "managerBaseUrl": "https://example.invalid",
        "readTokenEnv": "READ_TOKEN",
        "writeTokenEnv": "WRITE_TOKEN",
        "entitlements": ["site.read", "insights.write", "operations.rollback"],
    }
    principal = {
        "id": helper.PRINCIPAL_ID,
        "bearerTokenEnv": "EXISTING_GATEWAY_TOKEN",
        "tenants": [helper.TENANT_ID],
        "capabilities": ["site.read", "insights.write", "operations.rollback"],
    }
    sample = (
        "READ_TOKEN=read-secret\n"
        "WRITE_TOKEN=write-secret\n"
        "EXISTING_GATEWAY_TOKEN=gateway-secret\n"
        f"MCP_TENANTS_JSON={json.dumps([tenant], separators=(',', ':'))}\n"
        f"MCP_PRINCIPALS_JSON={json.dumps([principal], separators=(',', ':'))}\n"
    )
    updated, token, tenant_added, principal_added = helper.build_updated_env(sample)
    if token != "gateway-secret":
        raise RuntimeError("pages.read self-test changed the existing bearer")
    if tenant_added != ["pages.read"] or principal_added != ["pages.read"]:
        raise RuntimeError("pages.read self-test did not add the reviewed capability to both layers")
    if helper.verify_contract(updated) != "gateway-secret":
        raise RuntimeError("pages.read self-test expanded contract is invalid")

    # Reproduce the production mismatch we are fixing: tenant already entitled,
    # principal still missing pages.read. Convergence must add only the principal grant.
    tenant_with_read = {**tenant, "entitlements": tenant["entitlements"] + ["pages.read"]}
    mismatch = (
        "READ_TOKEN=read-secret\n"
        "WRITE_TOKEN=write-secret\n"
        "EXISTING_GATEWAY_TOKEN=gateway-secret\n"
        f"MCP_TENANTS_JSON={json.dumps([tenant_with_read], separators=(',', ':'))}\n"
        f"MCP_PRINCIPALS_JSON={json.dumps([principal], separators=(',', ':'))}\n"
    )
    repaired, repaired_token, tenant_added, principal_added = helper.build_updated_env(mismatch)
    if repaired_token != "gateway-secret":
        raise RuntimeError("pages.read mismatch repair changed the existing bearer")
    if tenant_added != [] or principal_added != ["pages.read"]:
        raise RuntimeError("pages.read mismatch repair did not converge only the missing principal grant")
    if helper.verify_contract(repaired) != "gateway-secret":
        raise RuntimeError("pages.read mismatch repair verification failed")

    again, token_again, tenant_again, principal_again = helper.build_updated_env(repaired)
    if tenant_again or principal_again or token_again != "gateway-secret" or again != repaired:
        raise RuntimeError("pages.read convergence is not idempotent")
    print("PAGES_READ_CAPABILITY_SELF_TEST_OK")
    return 0


if __name__ == "__main__":
    if sys.argv[1:] == ["--self-test"]:
        raise SystemExit(self_test())
    raise SystemExit(helper.main())
