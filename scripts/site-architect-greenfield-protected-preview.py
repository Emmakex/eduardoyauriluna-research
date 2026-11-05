#!/usr/bin/env python3
"""One-shot Site Architect canonical Greenfield Preview as existing research-ops.

Runs only on the protected OVH host, with production identity material resolved
locally. Performs one MCP tools/call for the canonical Plan. Never invokes Apply,
exports a bearer, changes a credential, or writes public WordPress content.
"""
from __future__ import annotations

import argparse
import hashlib
import http.client
import importlib.util
import json
import os
from pathlib import Path
import re
import ssl
import stat
import sys
from typing import Any

HERE = Path(__file__).resolve().parent
ENV = Path("/opt/site-control-mcp/.env.production")
ATTEMPT = Path("/opt/site-control-mcp/.greenfield-preview-issue-458.attempt")
HOST = "mcp.kairoseth.com"
PATH = "/mcp/eduardo-research"
PRINCIPAL = "research-ops"
TOOL = "site_greenfield_canonical_plan"
MAX_RESPONSE = 1_048_576
PROTOCOL = "2025-06-18"


def load(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, HERE / filename)
    if spec is None or spec.loader is None:
        raise RuntimeError("required trusted module unavailable")
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


preflight = load("preview_protected_preflight", "site-architect-greenfield-authz-preflight.py")
canonical = load("preview_canonical_contract", "research-gateway-v2-greenfield-plan.py")


class PreviewError(RuntimeError):
    """Internal errors are deliberately not forwarded to CI logs."""


def resolve_bearer(values: dict[str, str]) -> str:
    if preflight.inspect(values)["status"] != "ready":
        raise PreviewError("protected preflight not ready")
    principal = preflight.exact_entry(values.get("MCP_PRINCIPALS_JSON"), PRINCIPAL)
    if not principal or principal.get("tenants") != ["eduardo-research"]:
        raise PreviewError("unexpected protected caller scope")
    reference = principal.get("bearerTokenEnv")
    if not isinstance(reference, str) or not preflight.KEY_RE.fullmatch(reference):
        raise PreviewError("invalid protected principal reference")
    bearer = values.get(reference, "")
    if not isinstance(bearer, str) or not 16 <= len(bearer) <= 4096:
        raise PreviewError("protected principal bearer unavailable")
    if any(ord(ch) < 33 or ord(ch) > 126 for ch in bearer):
        raise PreviewError("invalid protected bearer syntax")
    return bearer


def response_object(data: bytes, content_type: str, response_id: int) -> dict[str, Any]:
    if len(data) > MAX_RESPONSE:
        raise PreviewError("MCP response exceeds bound")
    if content_type.split(";", 1)[0].strip().lower() == "application/json":
        try:
            parsed = json.loads(data)
        except (TypeError, ValueError):
            raise PreviewError("invalid MCP JSON response") from None
        candidates = [parsed]
    elif content_type.split(";", 1)[0].strip().lower() == "text/event-stream":
        try:
            lines = data.decode("utf-8", "strict").splitlines()
            candidates: list[Any] = []
            payload: list[str] = []
            for line in lines + [""]:
                if line.startswith("data:"):
                    payload.append(line[5:].lstrip(" "))
                elif line == "":
                    if payload:
                        candidates.append(json.loads("\n".join(payload)))
                        payload = []
                elif line.startswith(":") or line.startswith("event:") or line.startswith("id:") or line.startswith("retry:"):
                    continue
                else:
                    raise PreviewError("unsupported MCP event-stream line")
        except (UnicodeError, ValueError):
            raise PreviewError("invalid MCP event-stream response") from None
    else:
        raise PreviewError("unsupported MCP content type")

    matches = [item for item in candidates if isinstance(item, dict)
               and item.get("jsonrpc") == "2.0" and item.get("id") == response_id]
    if len(matches) != 1 or not isinstance(matches[0].get("result"), dict) or "error" in matches[0]:
        raise PreviewError("MCP response failed or is ambiguous")
    return matches[0]["result"]


class ProtectedMcp:
    def __init__(self, bearer: str):
        self.__bearer = bearer
        self.session: str | None = None
        self.tool_calls = 0

    def post(self, body: dict[str, Any], response_id: int | None) -> dict[str, Any] | None:
        data = json.dumps(body, separators=(",", ":"), ensure_ascii=True).encode("utf-8")
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "Authorization": f"Bearer {self.__bearer}",
            "MCP-Protocol-Version": PROTOCOL,
        }
        if self.session:
            headers["Mcp-Session-Id"] = self.session
        # An HTTPSConnection does not follow redirects. The bearer stays on this
        # single pinned hostname and is never sent in a subprocess/CLI argument.
        conn = http.client.HTTPSConnection(HOST, 443, context=ssl.create_default_context(), timeout=35)
        try:
            conn.request("POST", PATH, body=data, headers=headers)
            response = conn.getresponse()
            status = response.status
            raw = response.read(MAX_RESPONSE + 1)
            content_type = response.getheader("Content-Type", "")
            new_session = response.getheader("Mcp-Session-Id")
            if new_session is not None:
                if not isinstance(new_session, str) or not re.fullmatch(r"[A-Za-z0-9._-]{1,128}", new_session):
                    raise PreviewError("unexpected MCP session header")
                self.session = new_session
        finally:
            conn.close()
        if len(raw) > MAX_RESPONSE:
            raise PreviewError("oversized MCP response")
        if response_id is None:
            if status not in (200, 202):
                raise PreviewError("MCP initialization notification rejected")
            return None
        if status != 200:
            raise PreviewError("MCP request was not accepted")
        return response_object(raw, content_type, response_id)

    def preview(self) -> dict[str, Any]:
        initialized = self.post({
            "jsonrpc": "2.0", "id": 1, "method": "initialize",
            "params": {
                "protocolVersion": PROTOCOL, "capabilities": {},
                "clientInfo": {"name": "site-architect-protected-preview", "version": "1.0.0"},
            },
        }, 1)
        if not isinstance(initialized, dict) or not isinstance(initialized.get("protocolVersion"), str):
            raise PreviewError("MCP initialization handshake incomplete")
        self.post({"jsonrpc": "2.0", "method": "notifications/initialized"}, None)
        self.tool_calls += 1
        if self.tool_calls != 1:
            raise PreviewError("duplicate MCP tool call blocked")
        response = self.post({
            "jsonrpc": "2.0", "id": 2, "method": "tools/call",
            "params": {"name": TOOL, "arguments": {}},
        }, 2)
        if not isinstance(response, dict) or response.get("isError") is True:
            raise PreviewError("MCP Plan returned a tool error")
        structured = response.get("structuredContent")
        plan = structured.get("data") if isinstance(structured, dict) else None
        if not isinstance(plan, dict):
            plan = None
            for item in response.get("content", []):
                if isinstance(item, dict) and item.get("type") == "text":
                    try:
                        candidate = json.loads(item.get("text", ""))
                    except (TypeError, ValueError):
                        continue
                    if isinstance(candidate, dict):
                        plan = candidate
                        break
        try:
            return canonical.safe_plan(plan)
        except (canonical.CommandError, TypeError, ValueError):
            raise PreviewError("unexpected canonical Greenfield Plan result") from None


def execute() -> dict[str, Any]:
    if ENV != preflight.PRODUCTION_ENV:
        raise PreviewError("noncanonical configuration path")
    values = preflight.protected_vars(ENV)
    bearer = resolve_bearer(values)

    # Mark the attempt BEFORE any MCP request. Re-running an Actions job or
    # a second merge can never generate a second canonical Plan.
    flags = os.O_CREAT | os.O_EXCL | os.O_WRONLY
    if hasattr(os, "O_NOFOLLOW"):
        flags |= os.O_NOFOLLOW
    try:
        fd = os.open(ATTEMPT, flags, 0o600)
    except FileExistsError:
        raise PreviewError("canonical preview already attempted") from None
    with os.fdopen(fd, "w", encoding="utf-8") as file:
        file.write("issue=458\naction=site.blueprint.preview\ntenant=eduardo-research\n")
        file.flush()
        os.fsync(file.fileno())

    summary = ProtectedMcp(bearer).preview()
    # Only an allowlisted Plan summary is exported; the stored Plan is NOT applied.
    return {
        "status": "success",
        "issue": 458,
        "principal": PRINCIPAL,
        "tenant": "eduardo-research",
        "action": "site.blueprint.preview",
        "tool": TOOL,
        "plan": summary,
        "public_site_apply": "not_executed",
        "secrets_exported": False,
    }


def self_test() -> int:
    tenants = [{"id": "eduardo-research", "profile": "research-theme",
                "writeTokenEnv": "DUMMY_WRITE", "entitlements": ["site.read", "operations.apply"]}]
    principals = [{"id": "research-ops", "bearerTokenEnv": "DUMMY_OUTER",
                   "tenants": ["eduardo-research"], "capabilities": ["site.read", "operations.apply"]}]
    fixture = {
        "MCP_SHARED_ROUTING": "true",
        "MCP_TENANTS_JSON": json.dumps(tenants),
        "MCP_PRINCIPALS_JSON": json.dumps(principals),
        "DUMMY_WRITE": "SECRET_NEVER_PRINTED_WRITE",
        "DUMMY_OUTER": "SECRET_NEVER_PRINTED_OUTER",
    }
    assert resolve_bearer(fixture) == fixture["DUMMY_OUTER"]
    assert "SECRET_NEVER_PRINTED" not in str(preflight.inspect(fixture))
    for invalid in (
        {**fixture, "DUMMY_OUTER": ""},
        {**fixture, "DUMMY_OUTER": "bad\nBearer value"},
        {**fixture, "MCP_SHARED_ROUTING": "false"},
    ):
        try:
            resolve_bearer(invalid)
        except (PreviewError, preflight.ProtectedConfigError):
            pass
        else:
            raise AssertionError("invalid protected caller unexpectedly accepted")

    valid_response = {
        "jsonrpc": "2.0", "id": 2,
        "result": {"structuredContent": {"data": {"status": "planned"}}},
    }
    json_bytes = json.dumps(valid_response).encode()
    assert response_object(json_bytes, "application/json", 2) == valid_response["result"]
    sse_bytes = b"event: message\ndata: " + json_bytes + b"\n\n"
    assert response_object(sse_bytes, "text/event-stream", 2) == valid_response["result"]
    invalid_responses = (
        (b"not-json", "application/json"),
        (json.dumps({**valid_response, "id": 3}).encode(), "application/json"),
        (sse_bytes * 2, "text/event-stream"),
    )
    for bad, media_type in invalid_responses:
        try:
            response_object(bad, media_type, 2)
        except PreviewError:
            pass
        else:
            raise AssertionError("ambiguous MCP response unexpectedly accepted")

    original_post = ProtectedMcp.post
    calls: list[str] = []
    fake_plan = {
        "product": "Site Architect", "track": "Greenfield",
        "operation": "greenfield-canonical", "status": "planned",
        "confirmation_required": True, "apply_allowed": True,
        "stored_plan_created": True, "public_site_apply": "not_executed",
        "plan_id": "erm-remote-plan-" + "a" * 20 + "-1234abcd",
        "source_revision": "b" * 64, "blueprint_sha256": "c" * 64,
        "contract_fingerprint": "d" * 64, "expires_at": "2026-11-05T22:00:00+00:00",
        "phases": {name: {"status": "steady", "apply_allowed": True, "operation_count": 0, "change_count": 0}
                   for name in canonical.PHASES},
    }

    def fake_post(self, body, response_id):
        calls.append(body["method"])
        if body["method"] == "initialize":
            return {"protocolVersion": PROTOCOL}
        if body["method"] == "notifications/initialized":
            return None
        if body["method"] == "tools/call":
            assert body["params"] == {"name": TOOL, "arguments": {}}
            return {"structuredContent": {"data": fake_plan}}
        raise AssertionError("unknown or second tool call")

    try:
        ProtectedMcp.post = fake_post
        result = ProtectedMcp("SYNTHETIC_NOT_REAL").preview()
    finally:
        ProtectedMcp.post = original_post
    assert calls == ["initialize", "notifications/initialized", "tools/call"]
    assert result["status"] == "planned"
    assert result["public_site_apply"] == "not_executed"
    assert "SECRET_NEVER_PRINTED" not in json.dumps(result)
    print("PASS: protected research-ops one-shot canonical Preview contract, no Apply or secret export")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--self-test", action="store_true")
    modes.add_argument("--preview-once", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            return self_test()
        result = execute()
        print("GREENFIELD_PROTECTED_PREVIEW_RESULT " + json.dumps(result, sort_keys=True, separators=(",", ":")))
        return 0
    except Exception:
        # Never echo request headers, bearer, server responses or protected config.
        print("ERROR: canonical protected Preview could not be certified; no Apply was requested", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
