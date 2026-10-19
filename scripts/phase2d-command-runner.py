#!/usr/bin/env python3
"""Execute one bounded Kairoseth command through the production MCP.

The command envelope never carries an MCP tool name or backend URL. A trusted
registry maps a deliberately small business-action surface to already-accepted MCP
lifecycles. Only sanitized result data is written back to GitHub.
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
MAX_SAFE_INTEGER = 9007199254740991
SUPPORTED_ACTIONS = {"insight.create", "insight.update", "page.slot.update"}
UPDATE_FIELDS = {"title", "excerpt", "content", "language", "insight_type"}
PAGE_KEY = "contact"
PAGE_LANGUAGE = "en"
PAGE_SLOT = "eyebrow"
PAGE_VALUE_MAX = 200


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
            if isinstance(post_id, int) and not isinstance(post_id, bool) and post_id > 0:
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


def validate_insight_type(value: Any, field: str) -> None:
    if value is not None and (
        not isinstance(value, str) or not INSIGHT_TYPE_RE.fullmatch(value)
    ):
        raise CommandError(f"{field} is invalid")


def validate_create_payload(payload: dict[str, Any]) -> None:
    allowed = {"title", "language", "status", "content", "excerpt", "insight_type"}
    if not set(payload).issubset(allowed):
        raise CommandError("insight.create payload contains unsupported fields")
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
        raise CommandError("insight.create only allows draft state")
    excerpt = payload.get("excerpt")
    if excerpt is not None and (not isinstance(excerpt, str) or len(excerpt) > 1000):
        raise CommandError("payload.excerpt is invalid")
    validate_insight_type(payload.get("insight_type"), "payload.insight_type")


def validate_update_payload(payload: dict[str, Any]) -> None:
    if set(payload) != {"post_id", "changes"}:
        raise CommandError("insight.update payload must contain only post_id and changes")
    post_id = payload.get("post_id")
    if (
        not isinstance(post_id, int)
        or isinstance(post_id, bool)
        or post_id <= 0
        or post_id > MAX_SAFE_INTEGER
    ):
        raise CommandError("payload.post_id must be a positive safe integer")
    changes = payload.get("changes")
    if not isinstance(changes, dict) or not changes:
        raise CommandError("payload.changes must contain at least one bounded editorial field")
    if not set(changes).issubset(UPDATE_FIELDS):
        raise CommandError("payload.changes contains unsupported or higher-risk fields")
    if "title" in changes:
        title = changes["title"]
        if not isinstance(title, str) or not title.strip() or len(title) > 200:
            raise CommandError("payload.changes.title is invalid")
    if "excerpt" in changes:
        excerpt = changes["excerpt"]
        if not isinstance(excerpt, str) or len(excerpt) > 1000:
            raise CommandError("payload.changes.excerpt is invalid")
    if "content" in changes:
        content = changes["content"]
        if not isinstance(content, str) or not content.strip() or len(content) > 50000:
            raise CommandError("payload.changes.content is invalid")
    if "language" in changes and changes["language"] not in {"es", "en"}:
        raise CommandError("payload.changes.language must be es or en")
    validate_insight_type(changes.get("insight_type"), "payload.changes.insight_type")


def validate_page_slot_payload(payload: dict[str, Any]) -> None:
    if set(payload) != {"key", "language", "slot", "value"}:
        raise CommandError("page.slot.update payload must contain only key, language, slot and value")
    if payload.get("key") != PAGE_KEY:
        raise CommandError("page.slot.update key is outside the accepted target allowlist")
    if payload.get("language") != PAGE_LANGUAGE:
        raise CommandError("page.slot.update language is outside the accepted target allowlist")
    if payload.get("slot") != PAGE_SLOT:
        raise CommandError("page.slot.update slot is outside the accepted target allowlist")
    value = payload.get("value")
    if (
        not isinstance(value, str)
        or not value.strip()
        or len(value) > PAGE_VALUE_MAX
        or "<" in value
        or ">" in value
    ):
        raise CommandError("page.slot.update value must be bounded plain text without HTML/CSS markup")


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
    if (
        not isinstance(action, str)
        or action not in SUPPORTED_ACTIONS
        or not isinstance(actions, dict)
        or action not in actions
    ):
        raise CommandError("action is not registered")
    if command.get("requested_by") != "chatgpt":
        raise CommandError("requested_by must be chatgpt")
    payload = command.get("payload")
    if not isinstance(payload, dict):
        raise CommandError("payload must be an object")
    if action == "insight.create":
        validate_create_payload(payload)
    elif action == "insight.update":
        validate_update_payload(payload)
    elif action == "page.slot.update":
        validate_page_slot_payload(payload)
    else:
        raise CommandError("unsupported action")


def validate_registry(registry: dict[str, Any]) -> None:
    if registry.get("version") != "1":
        raise CommandError("unsupported registry version")
    actions = registry.get("actions")
    if not isinstance(actions, dict) or set(actions) != SUPPORTED_ACTIONS:
        raise CommandError("registry action set does not match the bounded command surface")

    insight_common = {
        "capabilities": "research_capabilities",
        "apply": "site_insight_apply",
        "verify": "site_insight_verify",
        "rollback": "site_insight_rollback",
    }
    for name, plan_tool in {
        "insight.create": "site_insight_create_plan",
        "insight.update": "site_insight_update_plan",
    }.items():
        action = actions.get(name)
        if not isinstance(action, dict):
            raise CommandError(f"invalid {name} registry entry")
        if action.get("tools") != {**insight_common, "plan": plan_tool}:
            raise CommandError(f"{name} tool mapping is not the accepted bounded lifecycle")
        if action.get("required_capabilities") != ["insights.write", "operations.rollback"]:
            raise CommandError(f"{name} capability contract changed unexpectedly")
        if action.get("verify_rendered") is not True:
            raise CommandError(f"{name} must require rendered verification")

    if actions["insight.create"].get("allowed_statuses") != ["draft"]:
        raise CommandError("insight.create registry must remain draft-only")
    allowed_change_fields = actions["insight.update"].get("allowed_change_fields")
    if not isinstance(allowed_change_fields, list) or set(allowed_change_fields) != UPDATE_FIELDS:
        raise CommandError("insight.update registry fields do not match the bounded update surface")

    page = actions.get("page.slot.update")
    if not isinstance(page, dict):
        raise CommandError("invalid page.slot.update registry entry")
    if page.get("required_capabilities") != ["pages.write", "operations.rollback"]:
        raise CommandError("page.slot.update capability contract changed unexpectedly")
    if page.get("tools") != {
        "capabilities": "research_capabilities",
        "plan": "site_page_slots_plan",
        "apply": "site_page_slots_apply",
        "verify": "site_page_slots_verify",
        "rollback": "site_page_slots_rollback",
    }:
        raise CommandError("page.slot.update tool mapping is not the accepted bounded lifecycle")
    if page.get("verify_rendered") is not True:
        raise CommandError("page.slot.update must require rendered verification")
    if page.get("allowed_targets") != [
        {"key": PAGE_KEY, "language": PAGE_LANGUAGE, "slots": [PAGE_SLOT]}
    ]:
        raise CommandError("page.slot.update target allowlist changed unexpectedly")


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
            "npx", "--yes", "@modelcontextprotocol/inspector@2.5.0", "--cli",
            "--config", str(config), "--server", "phase2d", "--stored-auth-only", "--format", "json",
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
        payload = self.run([
            "--method", "tools/call",
            "--tool-name", tool,
            "--tool-args-json", json.dumps(args or {}, separators=(",", ":"), ensure_ascii=False),
        ])
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
    action_name = str(command["action"])
    action = registry["actions"][action_name]
    tools = action["tools"]
    server_url = f"{base_url}/{tenant}"

    client = McpClient(server_url, token)
    plan_id: str | None = None
    operation_id: str | None = None
    rollback_ok: bool | None = None
    try:
        client.initialize()
        caps = client.call(tools["capabilities"])
        types: list[str] = []
        if action_name.startswith("insight."):
            types = editorial_types(caps)
            if not types:
                raise CommandError("live Manager capabilities expose no editorial_types")

        payload = command["payload"]
        reason = f"Kairoseth command {command_id}: {action_name}"
        resource: dict[str, Any]

        if action_name == "insight.create":
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
            plan_args: dict[str, Any] = {"data": data, "reason": reason}
            resource = {
                "title": payload["title"],
                "state": payload["status"],
                "language": payload["language"],
            }
        elif action_name == "insight.update":
            changes = dict(payload["changes"])
            requested_type = changes.get("insight_type")
            if requested_type is not None and requested_type not in types:
                raise CommandError("requested insight_type is not available on the target tenant")
            target_post_id = int(payload["post_id"])
            plan_args = {"postId": target_post_id, "changes": changes, "reason": reason}
            resource = {"post_id": target_post_id, "changed_fields": sorted(changes)}
            if "title" in changes:
                resource["title"] = changes["title"]
            if "language" in changes:
                resource["language"] = changes["language"]
        elif action_name == "page.slot.update":
            plan_args = {
                "key": payload["key"],
                "language": payload["language"],
                "slots": {payload["slot"]: payload["value"]},
                "reason": reason,
            }
            resource = {
                "key": payload["key"],
                "language": payload["language"],
                "slot": payload["slot"],
            }
        else:
            raise CommandError("unsupported action reached execution")

        plan = client.call(tools["plan"], plan_args)
        plan_id = regex_string(plan, PLAN_ID_RE)
        applied = client.call(tools["apply"], {"planId": plan_id, "confirm": True})
        operation_id = regex_string(applied, OP_ID_RE)
        verification = client.call(tools["verify"], {"operationId": operation_id, "rendered": True})
        if not verified(verification):
            raise CommandError("rendered verification did not report verified=true")

        observed_post_id = first_post_id(verification, applied, plan)
        if action_name == "insight.update":
            target_post_id = int(payload["post_id"])
            if observed_post_id is not None and observed_post_id != target_post_id:
                raise CommandError("verified Insight post_id does not match the requested target")
        elif action_name == "insight.create" and observed_post_id is not None:
            resource["post_id"] = observed_post_id

        result: dict[str, Any] = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": action_name,
            "command_id": command_id,
            "verified": True,
            "resource": resource,
            "correlation": {"plan_id": plan_id, "operation_id": operation_id},
        }
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
            "action": action_name,
            "command_id": command_id,
            "verified": False,
            "error": {
                "code": "execution_failed",
                "message": "The bounded MCP lifecycle did not complete successfully.",
            },
            "correlation": {"plan_id": plan_id, "operation_id": operation_id},
            "rollback": {"attempted": operation_id is not None, "verified": rollback_ok},
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
    schema_actions = schema.get("properties", {}).get("action", {}).get("enum")
    if not isinstance(schema_actions, list) or set(schema_actions) != SUPPORTED_ACTIONS:
        raise CommandError("schema action set and registry are out of sync")
    branches = schema.get("oneOf")
    if not isinstance(branches, list):
        raise CommandError("schema must contain action-specific oneOf branches")
    by_action: dict[str, dict[str, Any]] = {}
    for branch in branches:
        if not isinstance(branch, dict):
            continue
        props = branch.get("properties")
        if not isinstance(props, dict):
            continue
        action_prop = props.get("action")
        if isinstance(action_prop, dict) and isinstance(action_prop.get("const"), str):
            by_action[action_prop["const"]] = branch
    if set(by_action) != SUPPORTED_ACTIONS:
        raise CommandError("schema action branches do not match the bounded command surface")

    create_payload_schema = by_action["insight.create"]["properties"]["payload"]
    if create_payload_schema.get("properties", {}).get("status", {}).get("const") != "draft":
        raise CommandError("schema must keep insight.create draft-only")
    update_changes_schema = by_action["insight.update"]["properties"]["payload"].get("properties", {}).get("changes", {})
    if set(update_changes_schema.get("properties", {})) != UPDATE_FIELDS:
        raise CommandError("schema update fields do not match the runner")
    page_payload_schema = by_action["page.slot.update"]["properties"]["payload"]
    page_props = page_payload_schema.get("properties", {})
    if (
        page_props.get("key", {}).get("const") != PAGE_KEY
        or page_props.get("language", {}).get("const") != PAGE_LANGUAGE
        or page_props.get("slot", {}).get("const") != PAGE_SLOT
    ):
        raise CommandError("schema Page target does not match the runner allowlist")

    good_create = {
        "version": "1", "command_id": "cmd-selftest-create-0001",
        "tenant": "eduardo-research", "action": "insight.create", "requested_by": "chatgpt",
        "payload": {"title": "Hola mundo", "language": "es", "status": "draft", "content": "Hola mundo."},
    }
    validate_command(good_create, registry)

    good_update = {
        "version": "1", "command_id": "cmd-selftest-update-0001",
        "tenant": "eduardo-research", "action": "insight.update", "requested_by": "chatgpt",
        "payload": {"post_id": 94, "changes": {"title": "Updated title", "content": "Updated bounded content."}},
    }
    validate_command(good_update, registry)

    good_page = {
        "version": "1", "command_id": "cmd-selftest-page-slot-0001",
        "tenant": "eduardo-research", "action": "page.slot.update", "requested_by": "chatgpt",
        "payload": {"key": PAGE_KEY, "language": PAGE_LANGUAGE, "slot": PAGE_SLOT, "value": "Research contact"},
    }
    validate_command(good_page, registry)

    bad_action = json.loads(json.dumps(good_create))
    bad_action["action"] = "shell.execute"
    try:
        validate_command(bad_action, registry)
    except CommandError:
        pass
    else:
        raise CommandError("self-test: unknown action did not fail closed")

    bad_tenant = json.loads(json.dumps(good_create))
    bad_tenant["tenant"] = "unknown-tenant"
    try:
        validate_command(bad_tenant, registry)
    except CommandError:
        pass
    else:
        raise CommandError("self-test: unknown tenant did not fail closed")

    bad_create_status = json.loads(json.dumps(good_create))
    bad_create_status["payload"]["status"] = "publish"
    try:
        validate_command(bad_create_status, registry)
    except CommandError:
        pass
    else:
        raise CommandError("self-test: publish status did not fail closed")

    for field, value in [("status", "publish"), ("scheduled_at", "2030-01-01T12:00:00Z")]:
        bad = json.loads(json.dumps(good_update))
        bad["payload"]["changes"][field] = value
        try:
            validate_command(bad, registry)
        except CommandError:
            pass
        else:
            raise CommandError(f"self-test: update {field} did not fail closed")

    bad_update_empty = json.loads(json.dumps(good_update))
    bad_update_empty["payload"]["changes"] = {}
    try:
        validate_command(bad_update_empty, registry)
    except CommandError:
        pass
    else:
        raise CommandError("self-test: empty update did not fail closed")

    bad_update_post = json.loads(json.dumps(good_update))
    bad_update_post["payload"]["post_id"] = 0
    try:
        validate_command(bad_update_post, registry)
    except CommandError:
        pass
    else:
        raise CommandError("self-test: invalid update post_id did not fail closed")

    for field, value in [
        ("key", "home"),
        ("language", "es"),
        ("slot", "hero"),
        ("value", "<style>body{display:none}</style>"),
    ]:
        bad = json.loads(json.dumps(good_page))
        bad["payload"][field] = value
        try:
            validate_command(bad, registry)
        except CommandError:
            pass
        else:
            raise CommandError(f"self-test: unsafe Page field {field} did not fail closed")

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
