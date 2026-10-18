#!/usr/bin/env python3
"""Enable bounded Phase 2C write capabilities in the private OVH env registry.

This script never reads or prints bearer/Manager secret values beyond preserving the
existing .env.production file verbatim for unrelated keys.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path

WRITE_CAPABILITIES = (
    "insights.write",
    "research.write",
    "translations.write",
    "evidence.write",
    "media.write",
    "seo.write",
    "design.write",
    "operations.rollback",
)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--env-file", default="/opt/site-control-mcp/.env.production")
    parser.add_argument("--tenant", default="eduardo-research")
    parser.add_argument("--principal", default="phase2c-acceptance")
    args = parser.parse_args()

    path = Path(args.env_file)
    lines = path.read_text(encoding="utf-8").splitlines()
    env: dict[str, str] = {}
    layout: list[tuple[str | None, str | None]] = []
    for line in lines:
        if line and not line.startswith("#") and "=" in line:
            key, value = line.split("=", 1)
            env[key] = value
            layout.append((key, None))
        else:
            layout.append((None, line))

    tenants = json.loads(env["MCP_TENANTS_JSON"])
    principals = json.loads(env["MCP_PRINCIPALS_JSON"])

    tenant = next((item for item in tenants if item.get("id") == args.tenant), None)
    if tenant is None:
        raise SystemExit(f"tenant missing: {args.tenant}")
    if not tenant.get("writeTokenEnv"):
        raise SystemExit(f"tenant has no writeTokenEnv: {args.tenant}")

    principal = next((item for item in principals if item.get("id") == args.principal), None)
    if principal is None:
        raise SystemExit(f"principal missing: {args.principal}")
    if args.tenant not in principal.get("tenants", []):
        raise SystemExit(f"principal is not granted tenant: {args.tenant}")

    for target, key in ((tenant, "entitlements"), (principal, "capabilities")):
        values = list(target.get(key, []))
        for capability in WRITE_CAPABILITIES:
            if capability not in values:
                values.append(capability)
        target[key] = values

    env["MCP_TENANTS_JSON"] = json.dumps(tenants, separators=(",", ":"))
    env["MCP_PRINCIPALS_JSON"] = json.dumps(principals, separators=(",", ":"))

    seen: set[str] = set()
    output: list[str] = []
    for key, raw in layout:
        if key is None:
            output.append(raw or "")
        elif key in env and key not in seen:
            output.append(f"{key}={env[key]}")
            seen.add(key)
    for key, value in env.items():
        if key not in seen:
            output.append(f"{key}={value}")

    tmp = path.with_suffix(path.suffix + ".phase2c-write.tmp")
    tmp.write_text("\n".join(output) + "\n", encoding="utf-8")
    tmp.chmod(0o600)
    os.replace(tmp, path)
    path.chmod(0o600)

    print("WRITE ACCEPTANCE CAPABILITIES: OK")
    print("tenant=" + args.tenant)
    print("principal=" + args.principal)
    print("capabilities=" + ",".join(WRITE_CAPABILITIES))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
