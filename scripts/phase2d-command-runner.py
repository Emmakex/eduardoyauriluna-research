#!/usr/bin/env python3
"""Execute one bounded Phase 2D command through the production MCP.

The command envelope never carries an MCP tool name or backend URL. A trusted
registry maps the small business action surface to the already-accepted MCP
lifecycle. Only sanitized result data is written back to GitHub.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import Any, Iterable

ROOT = Path(__file__).resolve().parents[1]
REGISTRY_PATH = ROOT / "config" / "phase2d-command-registry.json"
SCHEMA_PATH = ROOT / "schemas" / "phase2d-command-v1.schema.json"
DEFAULT_BASE_URL = "https://mcp.kairoseth.com/mcp"
COMMAND_ID_RE = re.compile(r"^cmd-[a-z0-9][a-z0-9-]{7,63}$")
TENANT_RE = re.compile(r"^[a-z0-9][a-z0-9-]{1,63}$")
INSIGHT_TYPE_RE = re.compile(r"^[a-z0-9][a-z0-9_-]{0,63}$")
PLAN_ID_RE = re.compile(r"^erm-remote-plan-[a-f0-9]{20}-[a-f0-9]{8}$")
OP_ID_RE = re.compile(r"^erm-operation-[a-f0-9]{32}$")


class CommandError(RuntimeError):
    """Fail-closed command validation/execution error."""


def load_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise CommandError(f"missing JSON file: {path}") from exc
    except json.JSONDecodeError as exc:
        raise CommandError(f"invalid JSON in {path.name}") from exc
    if not isinstance(value, dict):
        raise CommandError(f"{path.name} must contain a JSON object")
    return value


def walk(value: Any) -> Iterable[Any]:
    yield value
    if isinstance(value, dict):
        for child in value.values():
            yield from walk(child)
    elif isinstance(value, list):
        for child in value:
            yield from walk(child)


def objects(value: Any) -> Iterable[dict[str, Any]]:
    for item in walk(value):
        if isinstance(item, dict):
            yield item


def regex_string(value: Any, pattern: re.Pattern[str]) -> str:
    for item in walk(value):
        if isinstance(item, str) and pattern.fullmatch(item):
            return item
    raise CommandError(f"expected identifier not found: {pattern.pattern}")


def first_post_id(*values: Any) -> int | None:
    for value in values:
        for obj in objects(value):
            post_id = obj.get("post_id")
            if isinstance(post_id, int) and post_id > 0:
                return post_id
            if isinstance(post_id, str) and post_id.isdigit() and int(post_id) > 0:
                return int(post_id)
    return None


def verified(value: Any) -> bool:
    for obj in objects(value):
        if obj.get("verified") is True or obj.get("status") == "verified":
            return True
    return False


def rollback_verified(value: Any) -> bool:
    for obj in objects(value):
        if obj.get("status") in {"rolled-back", "rolled_back"}:
            return True
        if obj.get("rollback_restored_baseline") is True:
            return True
        nested = obj.get("rollback_result")
        if isinstance(nested, dict) and nested.get("verified") is True:
            return True
    return False


def editorial_types(value: Any) -> list[str]:
    found: list[str] = []
    for obj in objects(value):
        candidate = obj.get("editorial_types")
        if isinstance(candidate, list):
            for item in candidate:
                if isinstance(item, str) and item and item not in found:
                    found.append(item)
    return found


def validate_command(command: dict[str, Any], registry: dict[str, Any]) -> None:
    allowed_top = {"version", "command_id", "tenant", "action", "requested_by", "payload"}
    if set(command) != allowed_top:
        raise CommandError("command keys do not match command-v1 contract")
    if command.get("version") != "1":
        raise CommandError("unsupported command version")
    command_id = command.get("command_id")
    if not isinstance(command_id, str) or not COMMAND_ID_RE.fullmatch(command_id):
        raise CommandError("invalid command_id")
    tenant = command.get("tenant")
    if not isinstance(tenant, str) or not TENANT_RE.fullmatch(tenant):
        raise CommandError("invalid tenant id")
    tenants = registry.get("tenants")
    if not isinstance(tenants, dict) or tenant not in tenants:
        raise CommandError("tenant is not registered")
    tenant_config = tenants.get(tenant)
    if not isinstance(tenant_config, dict) or tenant_config.get("enabled") is not True:
        raise CommandError("tenant is disabled")
    action = command.get("action")
    actions = registry.get("actions")
    if not isinstance(action, str) or not isinstance(actions, dict) or action not in actions:
        raise CommandError("action is not registered")
    if action != "insight.create":
        raise CommandError("command-v1 currently permits only insight.create")
    if command.get("requested_by") != "chatgpt":
        raise CommandError("requested_by must be chatgpt")

    payload = command.get("payload")
    if not isinstance(payload, dict):
        raise CommandError("payload must be an object")
    allowed_payload = {"title", "language", "status", "content", "excerpt", "insight_type"}
    if not set(payload).issubset(allowed_payload):
        raise CommandError("payload contains unsupported fields")
    for required in ("title", "language", "status", "content"):
        if required not in payload:
            raise CommandError(f"payload.{required} is required")
    title = payload.get("title")
    content = payload.get("content")
    if not isinstance(title, str) or not title.strip() or len(title) > 200:
        raise CommandError("payload.title is invalid")
    if not isinstance(content, str) or not content.strip() or len(content) > 50000:
        raise CommandError("payload.content is invalid")
    if payload.get("language") not in {"es", "en"}:
        raise CommandError("payload.language must be es or en")
    if payload.get("status") != "draft":
        raise CommandError("command-v1 only allows draft insight creation")
    excerpt = payload.get("excerpt")
    if excerpt is not None and (not isinstance(excerpt, str) or len(excerpt) > 1000):
        raise CommandError("payload.excerpt is invalid")
    insight_type = payload.get("insight_type")
    if insight_type is not None and (
        not isinstance(insight_type, str) or not INSIGHT_TYPE_RE.fullmatch(insight_type)
    ):
        raise CommandError("payload.insight_type is invalid")


def validate_registry(registry: dict[str, Any]) -> None:
    if registry.get("version") != "1":
        raise CommandError("unsupported registry version")
    actions = registry.get("actions")
    if not isinstance(actions, dict) or set(actions) != {"insight.create"}:
        raise CommandError("initial registry must expose insight.create only")
    action = actions["insight.create"]
    if not isinstance(action, dict):
        raise CommandError("invalid insight.create registry entry")
    expected_tools = {
        "capabilities": "research_capabilities",
        "plan": "site_insight_create_plan",
        "apply": "site_insight_apply",
        "verify": "site_insight_verify",
        "rollback": "site_insight_rollback",
    }
    if action.get("tools") != expected_tools:
        raise CommandError("insight.create tool mapping is not the accepted bounded lifecycle")
    if action.get("verify_rendered") is not True:
        raise CommandError("insight.create must require rendered verification")
    if action.get("allowed_statuses") != ["draft"]:
        raise CommandError("initial insight.create registry must be draft-only")


def sanitized_message(message: str, token: str) -> str:
    safe = message.replace(token, "[REDACTED]") if token else message
    safe = re.sub(r"Bearer\s+[^\s\"']+", "Bearer [REDACTED]", safe, flags=re.I)
    safe = re.sub(r"\b(?:ermt?1|mcp)_[A-Za-z0-9._-]{12,}\b", "[REDACTED]", safe)
    return safe[-800:]


def write_result(result_dir: Path, command_id: str, result: dict[str, Any]) -> Path:
    result_dir.mkdir(parents=True, exist_ok=True)
    path = result_dir / f"{command_id}.json"
    path.write_text(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return path


class McpClient:
    def __init__(self, server_url: str, token: str) -> None:
        self.token = token
        self.temp = tempfile.TemporaryDirectory(prefix="phase2d-command-")
        temp_path = Path(self.temp.name)
        config = temp_path / "mcp.json"
        config.write_text(
            json.dumps(
                {
                    "mcpServers": {
                        "phase2d": {
                            "type": "http",
                            "url": server_url,
                            "headers": {"Authorization": f"Bearer {token}"},
                            "connectionTimeout": 10000,
                            "requestTimeout": 60000,
                        }
                    }
                }
            ),
            encoding="utf-8",
        )
        config.chmod(0o600)
        self.storage = temp_path / "storage"
        self.oauth = temp_path / "oauth.json"
        self.base = [
            "npx",
            "--yes",
            "@modelcontextprotocol/inspector@2.5.0",
            "--cli",
            "--config",
            str(config),
            "--server",
            "phase2d",
            "--stored-auth-only",
            "--format",
            "json",
        ]

    def close(self) -> None:
        self.temp.cleanup()

    def run(self, extra: list[str]) -> dict[str, Any]:
        proc = subprocess.run(
            self.base + extra,
            text=True,
            capture_output=True,
            check=False,
            env={
                **os.environ,
                "MCP_STORAGE_DIR": str(self.storage),
                "MCP_INSPECTOR_OAUTH_STATE_PATH": str(self.oauth),
            },
        )
        if proc.returncode != 0:
            raise CommandError(
                f"MCP inspector failed ({proc.returncode}): {sanitized_message(proc.stderr, self.token)}"
            )
        try:
            return json.loads(proc.stdout)
        except json.JSONDecodeError as exc:
            raise CommandError("MCP inspector returned invalid JSON") from exc

    def initialize(self) -> None:
        self.run(["--method", "initialize"])

    def call(self, tool: str, args: dict[str, Any] | None = None) -> Any:
        payload = self.run(
            [
                "--method",
                "tools/call",
                "--tool-name",
                tool,
                "--tool-args-json",
                json.dumps(args or {}, separators=(",", ":"), ensure_ascii=False),
            ]
        )
        result = payload.get("result") or {}
        if result.get("isError"):
            raise CommandError(f"MCP tool {tool} returned an error")
        structured = result.get("structuredContent")
        if isinstance(structured, dict) and "data" in structured:
            return structured["data"]
        for row in result.get("content", []):
            if isinstance(row, dict) and row.get("type") == "text":
                try:
                    return json.loads(row.get("text", ""))
                except json.JSONDecodeError:
                    continue
        raise CommandError(f"MCP tool {tool} returned no structured result")


def execute(command_path: Path, result_dir: Path) -> int:
    registry = load_json(REGISTRY_PATH)
    validate_registry(registry)
    command = load_json(command_path)
    validate_command(command, registry)

    command_id = str(command["command_id"])
    existing = result_dir / f"{command_id}.json"
    if existing.exists():
        print(f"ALREADY_COMPLETED {existing}")
        return 0

    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise CommandError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", DEFAULT_BASE_URL).strip().rstrip("/")
    if not base_url.startswith("https://"):
        raise CommandError("PHASE2D_MCP_BASE_URL must use HTTPS")
    tenant = str(command["tenant"])
    server_url = f"{base_url}/{tenant}"
    action = registry["actions"]["insight.create"]
    tools = action["tools"]

    client = McpClient(server_url, token)
    plan_id: str | None = None
    operation_id: str | None = None
    rollback_ok: bool | None = None
    try:
        client.initialize()
        caps = client.call(tools["capabilities"])
        types = editorial_types(caps)
        if not types:
            raise CommandError("live Manager capabilities expose no editorial_types")

        payload = command["payload"]
        requested_type = payload.get("insight_type")
        if requested_type is not None and requested_type not in types:
            raise CommandError("requested insight_type is not available on the target tenant")
        insight_type = requested_type or ("research_note" if "research_note" in types else sorted(types)[0])

        data: dict[str, Any] = {
            "title": payload["title"],
            "content": payload["content"],
            "language": payload["language"],
            "insight_type": insight_type,
            "status": payload["status"],
        }
        if "excerpt" in payload:
            data["excerpt"] = payload["excerpt"]

        plan = client.call(
            tools["plan"],
            {
                "data": data,
                "reason": f"Phase 2D command {command_id}: insight.create",
            },
        )
        plan_id = regex_string(plan, PLAN_ID_RE)
        applied = client.call(tools["apply"], {"planId": plan_id, "confirm": True})
        operation_id = regex_string(applied, OP_ID_RE)
        verification = client.call(
            tools["verify"],
            {"operationId": operation_id, "rendered": True},
        )
        if not verified(verification):
            raise CommandError("rendered verification did not report verified=true")

        post_id = first_post_id(verification, applied, plan)
        result: dict[str, Any] = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": "insight.create",
            "command_id": command_id,
            "verified": True,
            "resource": {
                "title": payload["title"],
                "state": payload["status"],
                "language": payload["language"],
            },
            "correlation": {
                "plan_id": plan_id,
                "operation_id": operation_id,
            },
        }
        if post_id is not None:
            result["resource"]["post_id"] = post_id
        path = write_result(result_dir, command_id, result)
        print(f"SUCCESS {path}")
        return 0
    except Exception as exc:  # noqa: BLE001
        if operation_id is not None:
            try:
                rolled = client.call(tools["rollback"], {"operationId": operation_id, "confirm": True})
                rollback_ok = rollback_verified(rolled)
            except Exception:  # noqa: BLE001
                rollback_ok = False
        result = {
            "version": "1",
            "status": "failed",
            "tenant": tenant,
            "action": "insight.create",
            "command_id": command_id,
            "verified": False,
            "error": {
                "code": "execution_failed",
                "message": "The bounded MCP lifecycle did not complete successfully.",
            },
            "correlation": {
                "plan_id": plan_id,
                "operation_id": operation_id,
            },
            "rollback": {
                "attempted": operation_id is not None,
                "verified": rollback_ok,
            },
        }
        path = write_result(result_dir, command_id, result)
        print(f"FAILED {path}: {sanitized_message(str(exc), token)}", file=sys.stderr)
        return 1
    finally:
        client.close()


def self_test() -> int:
    registry = load_json(REGISTRY_PATH)
    validate_registry(registry)
    schema = load_json(SCHEMA_PATH)
    if schema.get("properties", {}).get("action", {}).get("const") != "insight.create":
        raise CommandError("schema action and registry are out of sync")
    if schema.get("properties", {}).get("payload", {}).get("properties", {}).get("status", {}).get("const") != "draft":
        raise CommandError("schema must keep initial write surface draft-only")

    good = {
        "version": "1",
        "command_id": "cmd-selftest-0001",
        "tenant": "eduardo-research",
        "action": "insight.create",
        "requested_by": "chatgpt",
        "payload": {
            "title": "Hola mundo",
            "language": "es",
            "status": "draft",
            "content": "Hola mundo.",
        },
    }
    validate_command(good, registry)

    bad_action = json.loads(json.dumps(good))
    bad_action["action"] = "shell.execute"
    try:
        validate_command(bad_action, registry)
    except CommandError:
        pass
    else:
        raise CommandError("self-test: unknown action did not fail closed")

    bad_tenant = json.loads(json.dumps(good))
    bad_tenant["tenant"] = "unknown-tenant"
    try:
        validate_command(bad_tenant, registry)
    except CommandError:
        pass
    else:
        raise CommandError("self-test: unknown tenant did not fail closed")

    bad_status = json.loads(json.dumps(good))
    bad_status["payload"]["status"] = "publish"
    try:
        validate_command(bad_status, registry)
    except CommandError:
        pass
    else:
        raise CommandError("self-test: publish status did not fail closed")

    print("phase2d-command-runner self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", nargs="?", type=Path)
    parser.add_argument("--result-dir", type=Path, default=ROOT / "commands" / "phase2d" / "results")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            return self_test()
        if args.command is None:
            parser.error("command path is required unless --self-test is used")
        return execute(args.command, args.result_dir)
    except CommandError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
