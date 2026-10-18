#!/usr/bin/env python3
"""Inspect the actual Manager READ credential used by the live MCP tenant.

Outputs only non-secret connection metadata and granted scopes. The bearer token
is never printed or persisted.
"""
from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path
from typing import Any

SERVER = os.environ.get("MCP_SERVER_URL", "").strip()
TOKEN = os.environ.get("MCP_TOKEN", "").strip()
EVIDENCE = Path(os.environ.get("MCP_EVIDENCE_DIR", "").strip() or ".phase2c-seo-acceptance")

if not SERVER or not TOKEN:
    raise SystemExit("MCP_SERVER_URL and MCP_TOKEN are required")

EVIDENCE.mkdir(parents=True, exist_ok=True)
TMP = Path(tempfile.mkdtemp(prefix="phase2c-seo-scope-"))
CONFIG = TMP / "mcp.json"
CONFIG.write_text(
    json.dumps(
        {
            "mcpServers": {
                "phase2c": {
                    "type": "http",
                    "url": SERVER,
                    "headers": {"Authorization": f"Bearer {TOKEN}"},
                    "connectionTimeout": 10000,
                    "requestTimeout": 60000,
                }
            }
        }
    ),
    encoding="utf-8",
)
CONFIG.chmod(0o600)

base = [
    "npx", "--yes", "@modelcontextprotocol/inspector@2.5.0", "--cli",
    "--config", str(CONFIG), "--server", "phase2c", "--stored-auth-only",
    "--format", "json",
]

env = {
    **os.environ,
    "MCP_STORAGE_DIR": str(TMP / "storage"),
    "MCP_INSPECTOR_OAUTH_STATE_PATH": str(TMP / "oauth.json"),
}


def run(extra: list[str]) -> dict[str, Any]:
    proc = subprocess.run(base + extra, text=True, capture_output=True, check=False, env=env)
    if proc.returncode != 0:
        raise RuntimeError(f"Inspector failed with exit {proc.returncode}")
    payload = json.loads(proc.stdout)
    if not isinstance(payload, dict):
        raise RuntimeError("Inspector did not return an object")
    result = payload.get("result") or {}
    if isinstance(result, dict) and result.get("isError"):
        raise RuntimeError("MCP tool returned an error")
    return payload


def tool_data(name: str) -> Any:
    payload = run([
        "--method", "tools/call", "--tool-name", name,
        "--tool-args-json", "{}",
    ])
    result = payload.get("result") or {}
    structured = result.get("structuredContent") if isinstance(result, dict) else None
    if isinstance(structured, dict) and "data" in structured:
        return structured["data"]
    for row in result.get("content", []) if isinstance(result, dict) else []:
        if isinstance(row, dict) and row.get("type") == "text":
            try:
                return json.loads(str(row.get("text", "")))
            except json.JSONDecodeError:
                pass
    raise RuntimeError("research_capabilities returned no structured data")


run(["--method", "initialize"])
capabilities = tool_data("research_capabilities")
if not isinstance(capabilities, dict):
    raise RuntimeError("research_capabilities payload is not an object")

granted = capabilities.get("granted_scopes")
if not isinstance(granted, list):
    raise RuntimeError("research_capabilities did not expose granted_scopes")

granted_scopes = sorted(str(scope) for scope in granted)
required = ["site.diagnostics", "seo.read"]
missing = [scope for scope in required if scope not in granted_scopes]
connection_id = str(capabilities.get("connection_id") or "")

safe = {
    "connection_id": connection_id,
    "granted_scopes": granted_scopes,
    "required_scopes": required,
    "missing_scopes": missing,
}
(EVIDENCE / "read-scope-diagnostic.json").write_text(
    json.dumps(safe, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)

print("Manager READ connection:", connection_id or "<unknown>")
print("Manager READ granted scopes:", ", ".join(granted_scopes))
if missing:
    print("Manager READ missing SEO scopes:", ", ".join(missing))
    raise SystemExit(2)
print("Manager READ SEO scopes: OK")
