#!/usr/bin/env python3
"""Execute one bounded Research Theme navigation update through the production MCP.

The command surface is intentionally narrower than generic WordPress navigation:
only the registered Research Theme `primary`/`footer` locations and registered
Theme page targets are accepted. Arbitrary URLs, custom menu items and raw MCP
tool names are impossible. The lifecycle is Plan -> Apply -> rendered Verify,
with exact rollback on any post-Apply failure.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import re
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE_RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
LIFECYCLE_PATH = Path(__file__).with_name("research-gateway-v2-lifecycle.py")
REGISTRY_PATH = ROOT / "config" / "phase2e-navigation-command-registry.json"
SCHEMA_PATH = ROOT / "schemas" / "phase2e-navigation-v1.schema.json"
ACTION = "navigation.update"
ALLOWED_LOCATIONS = ("primary", "footer")
ALLOWED_TARGETS = (
    "home",
    "about",
    "research",
    "publications",
    "projects",
    "software",
    "datasets",
    "cv",
    "insights",
    "contact",
    "privacy-policy",
    "legal-notice",
)
NAV_PLAN_ID_RE = re.compile(r"^erm-nav-plan-[a-f0-9]{18}-[a-f0-9]{8}$")
NAV_OP_ID_RE = re.compile(r"^erm-nav-op-[a-f0-9]{32}$")


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"unable to load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


base = load_module("phase2d_command_runner_navigation_base", BASE_RUNNER)
lifecycle = load_module("research_gateway_v2_navigation_lifecycle", LIFECYCLE_PATH)

CommandError = base.CommandError
load_json = base.load_json
sanitized_message = base.sanitized_message
write_result = base.write_result


def validate_payload(payload: dict[str, Any]) -> None:
    if set(payload) != {"location", "items"}:
        raise CommandError("navigation.update payload must contain only location and items")
    location = payload.get("location")
    if location not in ALLOWED_LOCATIONS:
        raise CommandError("navigation.update location is outside the registered Theme contract")
    items = payload.get("items")
    if not isinstance(items, list) or not 1 <= len(items) <= len(ALLOWED_TARGETS):
        raise CommandError("navigation.update items must contain between 1 and 12 Theme targets")
    if any(not isinstance(item, str) or item not in ALLOWED_TARGETS for item in items):
        raise CommandError("navigation.update accepts registered Research Theme page targets only")
    if len(items) != len(set(items)):
        raise CommandError("navigation.update targets must be unique")


def validate_registry(registry: dict[str, Any]) -> None:
    if registry.get("version") != "1":
        raise CommandError("unsupported Navigation registry version")
    if registry.get("tenants") != {"eduardo-research": {"enabled": True}}:
        raise CommandError("Navigation tenant registry changed unexpectedly")
    actions = registry.get("actions")
    if not isinstance(actions, dict) or set(actions) != {ACTION}:
        raise CommandError("Navigation registry must expose exactly one bounded action")
    action = actions[ACTION]
    if action.get("required_capabilities") != ["navigation.write", "operations.rollback"]:
        raise CommandError("Navigation capability contract changed unexpectedly")
    if action.get("tools") != {
        "plan": "site_navigation_update_plan",
        "apply": "site_navigation_apply",
        "verify": "site_navigation_verify",
        "rollback": "site_navigation_rollback",
    }:
        raise CommandError("Navigation tool mapping changed unexpectedly")
    lifecycle.validate_tools(action["tools"])
    if action.get("verify_rendered") is not True:
        raise CommandError("Navigation must require rendered verification")
    if action.get("allowed_locations") != list(ALLOWED_LOCATIONS):
        raise CommandError("Navigation location allowlist changed unexpectedly")
    if action.get("allowed_targets") != list(ALLOWED_TARGETS):
        raise CommandError("Navigation target allowlist changed unexpectedly")
    if action.get("max_items") != len(ALLOWED_TARGETS):
        raise CommandError("Navigation max_items changed unexpectedly")
    if action.get("arbitrary_urls") is not False:
        raise CommandError("Navigation arbitrary URL boundary changed unexpectedly")


def validate_command(command: dict[str, Any], registry: dict[str, Any] | None = None) -> None:
    if registry is None:
        registry = load_json(REGISTRY_PATH)
    validate_registry(registry)
    if set(command) != {"version", "command_id", "tenant", "action", "requested_by", "payload"}:
        raise CommandError("command keys do not match command-v1 contract")
    if command.get("version") != "1":
        raise CommandError("unsupported command version")
    command_id = command.get("command_id")
    if not isinstance(command_id, str) or not base.COMMAND_ID_RE.fullmatch(command_id):
        raise CommandError("invalid command_id")
    if command.get("tenant") != "eduardo-research":
        raise CommandError("Navigation command tenant is not registered")
    if command.get("action") != ACTION:
        raise CommandError("unsupported Navigation command action")
    if command.get("requested_by") != "chatgpt":
        raise CommandError("requested_by must be chatgpt")
    payload = command.get("payload")
    if not isinstance(payload, dict):
        raise CommandError("payload must be an object")
    validate_payload(payload)


def load_validated_command(path: Path) -> dict[str, Any]:
    registry = load_json(REGISTRY_PATH)
    command = load_json(path)
    validate_command(command, registry)
    return command


def execute(command_path: Path, result_dir: Path) -> int:
    registry = load_json(REGISTRY_PATH)
    command = load_json(command_path)
    validate_command(command, registry)
    command_id = str(command["command_id"])
    existing = result_dir / f"{command_id}.json"
    if existing.exists():
        print(f"ALREADY_COMPLETED {existing}")
        return 0

    import os

    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise CommandError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", base.DEFAULT_BASE_URL).strip().rstrip("/")
    if not base_url.startswith("https://"):
        raise CommandError("PHASE2D_MCP_BASE_URL must use HTTPS")

    tenant = str(command["tenant"])
    payload = command["payload"]
    tools = registry["actions"][ACTION]["tools"]
    client = base.McpClient(f"{base_url}/{tenant}", token)
    state = lifecycle.LifecycleState()
    try:
        client.initialize()
        lifecycle.run(
            client=client,
            tools=tools,
            plan_args={
                "location": payload["location"],
                "items": list(payload["items"]),
                "intent": f"Phase 2E bounded navigation command {command_id}",
            },
            parse_plan_id=lambda value: base.regex_string(value, NAV_PLAN_ID_RE),
            parse_operation_id=lambda value: base.regex_string(value, NAV_OP_ID_RE),
            is_verified=base.verified,
            state=state,
            verify_args={"rendered": True},
            verification_error="Navigation verification did not report verified=true",
        )

        result = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "verified": True,
            "resource": {
                "location": payload["location"],
                "item_count": len(payload["items"]),
                "registered_targets_only": True,
            },
            "correlation": state.correlation(),
        }
        path = write_result(result_dir, command_id, result)
        print(f"SUCCESS {path}")
        return 0
    except Exception as exc:  # noqa: BLE001
        lifecycle.rollback(
            client=client,
            tools=tools,
            state=state,
            is_rollback_verified=base.rollback_verified,
        )
        result = {
            "version": "1",
            "status": "failed",
            "tenant": tenant,
            "action": ACTION,
            "command_id": command_id,
            "verified": False,
            "error": {
                "code": "execution_failed",
                "message": "The bounded Navigation lifecycle did not complete successfully.",
            },
            "correlation": state.correlation(),
            "rollback": state.rollback_result(),
        }
        path = write_result(result_dir, command_id, result)
        print(f"FAILED {path}: {sanitized_message(str(exc), token)}", file=__import__("sys").stderr)
        return 1
    finally:
        client.close()


def self_test() -> int:
    registry = load_json(REGISTRY_PATH)
    validate_registry(registry)
    schema = load_json(SCHEMA_PATH)
    props = schema.get("properties", {})
    if props.get("action", {}).get("const") != ACTION:
        raise CommandError("Navigation schema action changed unexpectedly")
    payload_props = props.get("payload", {}).get("properties", {})
    if set(payload_props) != {"location", "items"}:
        raise CommandError("Navigation schema payload changed unexpectedly")
    if payload_props.get("location", {}).get("enum") != list(ALLOWED_LOCATIONS):
        raise CommandError("Navigation schema locations do not match runner allowlist")
    item_enum = payload_props.get("items", {}).get("items", {}).get("enum")
    if item_enum != list(ALLOWED_TARGETS):
        raise CommandError("Navigation schema targets do not match runner allowlist")

    good = {
        "version": "1",
        "command_id": "cmd-selftest-navigation-0001",
        "tenant": "eduardo-research",
        "action": ACTION,
        "requested_by": "chatgpt",
        "payload": {
            "location": "primary",
            "items": ["home", "research", "publications"],
        },
    }
    validate_command(good, registry)

    for case in ("external-url", "duplicate", "unknown-location", "extra-field", "unknown-tenant"):
        bad = json.loads(json.dumps(good))
        if case == "external-url":
            bad["payload"]["items"] = ["home", "https://example.com"]
        elif case == "duplicate":
            bad["payload"]["items"] = ["home", "home"]
        elif case == "unknown-location":
            bad["payload"]["location"] = "sidebar"
        elif case == "extra-field":
            bad["payload"]["url"] = "https://example.com"
        else:
            bad["tenant"] = "other-tenant"
        try:
            validate_command(bad, registry)
        except CommandError:
            pass
        else:
            raise CommandError(f"self-test: unsafe Navigation case {case} did not fail closed")

    print("phase2e-navigation-runner self-test: OK")
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
            parser.error("command is required unless --self-test is used")
        return execute(args.command, args.result_dir)
    except (CommandError, lifecycle.LifecycleError) as exc:
        print(f"ERROR: {exc}", file=__import__("sys").stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
