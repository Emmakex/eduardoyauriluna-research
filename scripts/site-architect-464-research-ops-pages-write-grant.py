#!/usr/bin/env python3
"""Grant exactly pages.write to existing research-ops, not the tenant or any other principal.

Protected OVH only. No token rotation, registry export, WordPress Apply or
unrelated entitlement changes. Same-digest restart with rollback and health checks.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import sys

HERE = Path(__file__).resolve().parent


def load(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, HERE / filename)
    if spec is None or spec.loader is None:
        raise RuntimeError("trusted authorization helper unavailable")
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


base = load("site_architect_464_grant_support", "site-architect-greenfield-authz-grant.py")
audit = load("site_architect_464_pages_write_audit", "site-architect-464-pages-write-authz-preflight.py")
preflight = base.preflight

ENV = Path("/opt/site-control-mcp/.env.production")
TARGET = "research-ops"
TENANT = "eduardo-research"
CAPABILITY = "pages.write"
REGISTRY = "MCP_PRINCIPALS_JSON"


class GrantError(RuntimeError):
    pass


def rewrite(text: str) -> tuple[str, bool]:
    before = base.parse_lines(text)
    report = audit.inspect(before)
    checks = report["checks"]
    for key in (
        "greenfield_operations_apply_preflight_ready",
        "tenant_pages_write_entitled",
        "research_ops_tenant_bounded",
        "manager_write_token_reference_configured",
    ):
        if checks.get(key) is not True:
            raise GrantError("existing protected authorization prerequisites not satisfied")
    if REGISTRY not in before:
        raise GrantError("existing protected principal registry missing")
    entries = json.loads(before[REGISTRY])
    if not isinstance(entries, list) or not 1 <= len(entries) <= 100:
        raise GrantError("invalid protected principal registry")
    matching = [p for p in entries if isinstance(p, dict) and p.get("id") == TARGET]
    if len(matching) != 1:
        raise GrantError("protected research-ops must exist exactly once")
    principal = matching[0]
    if principal.get("tenants") != [TENANT]:
        raise GrantError("research-ops tenant boundary changed")
    permissions = principal.get("capabilities")
    if (not isinstance(permissions, list) or not permissions
            or any(not isinstance(item, str) for item in permissions)
            or len(set(permissions)) != len(permissions)):
        raise GrantError("invalid existing principal capabilities")
    original_entries = json.loads(json.dumps(entries))
    if CAPABILITY not in permissions:
        permissions.append(CAPABILITY)
    lines = text.splitlines(keepends=True)
    matches = [i for i, line in enumerate(lines) if line.startswith(REGISTRY + "=")]
    if len(matches) != 1:
        raise GrantError("principal registry must have one canonical configuration line")
    i = matches[0]
    line = lines[i]
    k, value = line.rstrip("\r\n").split("=", 1)
    raw = value.strip()
    quote = raw[0] if len(raw) >= 2 and raw[0] == raw[-1] and raw[0] in ("'", '"') else ""
    newline = "\r\n" if line.endswith("\r\n") else ("\n" if line.endswith("\n") else "")
    if k != REGISTRY:
        raise GrantError("unexpected registry line")
    lines[i] = k + "=" + quote + json.dumps(entries, ensure_ascii=False, separators=(",", ":")) + quote + newline
    updated = "".join(lines)
    after = base.parse_lines(updated)
    if set(before) != set(after) or any(before[key] != after[key] for key in before if key != REGISTRY):
        raise GrantError("unrelated protected configuration would change")
    after_entries = json.loads(after[REGISTRY])
    if len(after_entries) != len(original_entries):
        raise GrantError("principal registry cardinality changed")
    for old, new in zip(original_entries, after_entries):
        if old.get("id") != TARGET:
            if old != new:
                raise GrantError("another principal would change")
            continue
        old_caps, new_caps = old.get("capabilities"), new.get("capabilities")
        old_without = {k: v for k, v in old.items() if k != "capabilities"}
        new_without = {k: v for k, v in new.items() if k != "capabilities"}
        expected = old_caps if CAPABILITY in old_caps else old_caps + [CAPABILITY]
        if old_without != new_without or new_caps != expected:
            raise GrantError("principal changed outside approved pages.write")
    final = audit.inspect(after)
    if final["status"] != "ready" or any(x is not True for x in final["checks"].values()):
        raise GrantError("pages.write readiness not attained")
    return updated, updated != text


def apply_grant() -> int:
    if ENV != base.ENV or not ENV.is_file() or not base.COMPOSE.is_file() or not base.IMAGE_REF.is_file():
        raise GrantError("canonical OVH protected deployment unavailable")
    info = ENV.stat()
    if (not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) != 0o600
            or info.st_uid != os.geteuid() or not 1 <= info.st_size <= 1_048_576):
        raise GrantError("protected .env ownership or mode invalid")
    original = ENV.read_text(encoding="utf-8")
    updated, changed = rewrite(original)
    image = base.IMAGE_REF.read_text(encoding="utf-8").strip()
    if not base.DIGEST.fullmatch(image):
        raise GrantError("immutable running image digest not verified")

    if changed:
        base.atomic_write(updated, info)
    try:
        if changed:
            base.restart(image)  # pinned exact digest; no image upgrade
        fresh = audit.inspect(preflight.protected_vars(ENV))
        if fresh["status"] != "ready":
            raise GrantError("protected post-grant readiness not established")
        base.verify_public_health()
    except Exception:
        if changed:
            base.atomic_write(original, info)
            try:
                base.restart(image)
                base.verify_public_health()
            except Exception:
                raise GrantError("protected grant rolled back; original container recovery unverified") from None
        raise GrantError("protected grant failed; original authorization restored") from None
    print("SITE_ARCHITECT_464_RESEARCH_OPS_PAGES_WRITE_READY tenant_unchanged=true other_principals_unchanged=true")
    print("SITE_ARCHITECT_464_PUBLIC_HEALTH_OK same_digest=true credentials_unchanged=true public_apply=not_executed")
    return 0


def self_test() -> int:
    tenant = {"id": TENANT, "profile": "research-theme", "writeTokenEnv": "T_TOKEN",
              "entitlements": ["site.read", "operations.apply", CAPABILITY]}
    principals = [
        {"id": "other-principal", "tenants": [TENANT], "capabilities": ["site.read"]},
        {"id": TARGET, "tenants": [TENANT], "bearerTokenEnv": "P_TOKEN",
         "capabilities": ["site.read", "operations.apply"]},
    ]
    values = {
        "MCP_SHARED_ROUTING": "true",
        "MCP_TENANTS_JSON": json.dumps([tenant]),
        REGISTRY: json.dumps(principals),
        "T_TOKEN": "CONFIDENTIAL_TOKEN_NOT_FOR_LOGS",
        "P_TOKEN": "CONFIDENTIAL_BEARER_NOT_FOR_LOGS",
    }
    source = "\n".join(k + "=" + v for k, v in values.items()) + "\n"
    changed, has_delta = rewrite(source)
    assert has_delta is True
    old, new = base.parse_lines(source), base.parse_lines(changed)
    assert old["MCP_TENANTS_JSON"] == new["MCP_TENANTS_JSON"]
    assert old["P_TOKEN"] == new["P_TOKEN"]
    assert old["T_TOKEN"] == new["T_TOKEN"]
    assert json.loads(new[REGISTRY])[0] == principals[0]
    assert json.loads(new[REGISTRY])[1]["capabilities"] == ["site.read", "operations.apply", CAPABILITY]
    assert rewrite(changed) == (changed, False)
    assert audit.inspect(new)["status"] == "ready"
    for missing in ("MCP_SHARED_ROUTING", "MCP_TENANTS_JSON"):
        altered = dict(values)
        altered.pop(missing)
        try:
            rewrite("\n".join(k + "=" + v for k, v in altered.items()) + "\n")
        except (GrantError, preflight.ProtectedConfigError, KeyError, ValueError):
            pass
        else:
            raise AssertionError("incomplete protected authorization was accepted")
    another = json.loads(json.dumps(values))
    t = json.loads(another["MCP_TENANTS_JSON"])
    t[0]["entitlements"].remove(CAPABILITY)
    another["MCP_TENANTS_JSON"] = json.dumps(t)
    try:
        rewrite("\n".join(k + "=" + v for k, v in another.items()) + "\n")
    except (GrantError, preflight.ProtectedConfigError):
        pass
    else:
        raise AssertionError("missing tenant authorization was accepted")
    assert "CONFIDENTIAL" not in json.dumps(audit.inspect(new))
    print("PASS: exact principal-only pages.write grant, idempotency, tenant frozen, secret-safe")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--grant", action="store_true")
    modes.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        return self_test() if args.self_test else apply_grant()
    except Exception:
        print("ERROR: bounded principal-only pages.write grant failed; no config, token or secret exported", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
