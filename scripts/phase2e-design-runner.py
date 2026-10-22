#!/usr/bin/env python3
"""Execute bounded Theme Design updates through the production MCP.

The public Git command channel exposes only Theme-registered design presets and
component variants. Arbitrary CSS, font URLs, HTML, classes, token names, content
ordering and section hiding are intentionally outside this surface.
Lifecycle: Plan -> Apply -> Verify -> exact rollback on post-Apply failure.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
import re
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE_RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
LIFECYCLE_PATH = Path(__file__).with_name("research-gateway-v2-lifecycle.py")
REGISTRY_PATH = ROOT / "config" / "phase2e-design-command-registry.json"
PRESET_SCHEMA_PATH = ROOT / "schemas" / "phase2e-design-preset-v1.schema.json"
VARIANT_SCHEMA_PATH = ROOT / "schemas" / "phase2e-design-variant-v1.schema.json"
PRESET_ACTION = "design.preset.update"
VARIANT_ACTION = "design.variant.update"
ACTIONS = (PRESET_ACTION, VARIANT_ACTION)
PRESET_VALUES = {
    "palette": ("researcher", "ocean-lab", "graphite-academic"),
    "typography": ("modern-sans", "academic-mixed", "humanist-sans"),
    "density": ("compact", "balanced", "airy"),
}
VARIANT_VALUES = {
    "hero": ("orbit", "editorial", "compact"),
    "sections": ("layered", "plain", "bands"),
    "cards": ("bordered", "lifted", "quiet"),
    "collections": ("editorial-grid", "compact-grid", "list"),
}
PRESET_PLAN_ID_RE = re.compile(r"^erm-design-plan-[a-f0-9]{18}-[a-f0-9]{8}$")
PRESET_OP_ID_RE = re.compile(r"^erm-design-op-[a-f0-9]{32}$")
VARIANT_PLAN_ID_RE = re.compile(r"^erm-design-variant-plan-[a-f0-9]{18}-[a-f0-9]{8}$")
VARIANT_OP_ID_RE = re.compile(r"^erm-design-variant-op-[a-f0-9]{32}$")


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"unable to load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


base = load_module("phase2d_command_runner_design_base", BASE_RUNNER)
lifecycle = load_module("research_gateway_v2_design_lifecycle", LIFECYCLE_PATH)

CommandError = base.CommandError
load_json = base.load_json
write_result = base.write_result
sanitized_message = base.sanitized_message


def values_for(action: str) -> dict[str, tuple[str, ...]]:
    if action == PRESET_ACTION:
        return PRESET_VALUES
    if action == VARIANT_ACTION:
        return VARIANT_VALUES
    raise CommandError("unsupported Design action")


def validate_payload(action: str, payload: dict[str, Any]) -> None:
    if set(payload) != {"changes"}:
        raise CommandError(f"{action} payload must contain only changes")
    changes = payload.get("changes")
    allowed = values_for(action)
    if not isinstance(changes, dict) or not changes or not set(changes).issubset(allowed):
        raise CommandError(f"{action} changes must contain at least one registered Design field")
    for field, value in changes.items():
        if not isinstance(value, str) or value not in allowed[field]:
            raise CommandError(f"payload.changes.{field} is outside the registered Theme contract")


def validate_registry(registry: dict[str, Any]) -> None:
    if registry.get("version") != "1":
        raise CommandError("unsupported Design registry version")
    if registry.get("tenants") != {"eduardo-research": {"enabled": True}}:
        raise CommandError("Design tenant registry changed unexpectedly")
    actions = registry.get("actions")
    if not isinstance(actions, dict) or set(actions) != set(ACTIONS):
        raise CommandError("Design registry must expose exactly the two bounded actions")
    expected_tools = {
        PRESET_ACTION: {
            "plan": "site_design_preset_update_plan",
            "apply": "site_design_preset_apply",
            "verify": "site_design_preset_verify",
            "rollback": "site_design_preset_rollback",
        },
        VARIANT_ACTION: {
            "plan": "site_design_variant_update_plan",
            "apply": "site_design_variant_apply",
            "verify": "site_design_variant_verify",
            "rollback": "site_design_variant_rollback",
        },
    }
    for action in ACTIONS:
        config = actions[action]
        if config.get("required_capabilities") != ["design.write", "operations.rollback"]:
            raise CommandError(f"{action} capability contract changed unexpectedly")
        if config.get("tools") != expected_tools[action]:
            raise CommandError(f"{action} tool mapping changed unexpectedly")
        lifecycle.validate_tools(config["tools"])
        expected_values = {field: list(values) for field, values in values_for(action).items()}
        if config.get("allowed_values") != expected_values:
            raise CommandError(f"{action} registered value contract changed unexpectedly")


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
        raise CommandError("Design command tenant is not registered")
    action = command.get("action")
    if action not in ACTIONS or command.get("requested_by") != "chatgpt":
        raise CommandError("unsupported Design command envelope")
    payload = command.get("payload")
    if not isinstance(payload, dict):
        raise CommandError("payload must be an object")
    validate_payload(str(action), payload)


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

    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise CommandError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", base.DEFAULT_BASE_URL).strip().rstrip("/")
    if not base_url.startswith("https://"):
        raise CommandError("PHASE2D_MCP_BASE_URL must use HTTPS")

    tenant = str(command["tenant"])
    action = str(command["action"])
    changes = command["payload"]["changes"]
    tools = registry["actions"][action]["tools"]
    plan_re = PRESET_PLAN_ID_RE if action == PRESET_ACTION else VARIANT_PLAN_ID_RE
    op_re = PRESET_OP_ID_RE if action == PRESET_ACTION else VARIANT_OP_ID_RE
    client = base.McpClient(f"{base_url}/{tenant}", token)
    state = lifecycle.LifecycleState()
    try:
        client.initialize()
        lifecycle.run(
            client=client,
            tools=tools,
            plan_args={"changes": changes},
            parse_plan_id=lambda value: base.regex_string(value, plan_re),
            parse_operation_id=lambda value: base.regex_string(value, op_re),
            is_verified=base.verified,
            state=state,
            verification_error="Design verification did not report verified=true",
        )

        result = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": action,
            "command_id": command_id,
            "verified": True,
            "resource": {"changed_fields": sorted(changes.keys())},
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
            "action": action,
            "command_id": command_id,
            "verified": False,
            "error": {"code": "execution_failed", "message": "The bounded Design lifecycle did not complete successfully."},
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
    preset_schema = load_json(PRESET_SCHEMA_PATH)
    variant_schema = load_json(VARIANT_SCHEMA_PATH)
    if preset_schema.get("properties", {}).get("action", {}).get("const") != PRESET_ACTION:
        raise CommandError("Design preset schema action changed unexpectedly")
    if variant_schema.get("properties", {}).get("action", {}).get("const") != VARIANT_ACTION:
        raise CommandError("Design variant schema action changed unexpectedly")

    good_preset = {
        "version": "1",
        "command_id": "cmd-selftest-design-preset-0001",
        "tenant": "eduardo-research",
        "action": PRESET_ACTION,
        "requested_by": "chatgpt",
        "payload": {"changes": {"typography": "academic-mixed", "density": "balanced"}},
    }
    good_variant = {
        "version": "1",
        "command_id": "cmd-selftest-design-variant-0001",
        "tenant": "eduardo-research",
        "action": VARIANT_ACTION,
        "requested_by": "chatgpt",
        "payload": {"changes": {"hero": "orbit", "cards": "quiet"}},
    }
    validate_command(good_preset, registry)
    validate_command(good_variant, registry)

    bad_cases: list[dict[str, Any]] = []
    for source in (good_preset, good_variant):
        extra = json.loads(json.dumps(source)); extra["payload"]["css"] = ":root{}"; bad_cases.append(extra)
        arbitrary = json.loads(json.dumps(source)); arbitrary["payload"]["changes"] = {"custom_css": "body{}"}; bad_cases.append(arbitrary)
        empty = json.loads(json.dumps(source)); empty["payload"]["changes"] = {}; bad_cases.append(empty)
        tenant_case = json.loads(json.dumps(source)); tenant_case["tenant"] = "other-tenant"; bad_cases.append(tenant_case)
    invalid_preset = json.loads(json.dumps(good_preset)); invalid_preset["payload"]["changes"] = {"typography": "remote-font"}; bad_cases.append(invalid_preset)
    invalid_variant = json.loads(json.dumps(good_variant)); invalid_variant["payload"]["changes"] = {"hero": "raw-html"}; bad_cases.append(invalid_variant)
    for index, bad in enumerate(bad_cases):
        try:
            validate_command(bad, registry)
        except CommandError:
            pass
        else:
            raise CommandError(f"self-test: unsafe Design case {index} did not fail closed")
    print("phase2e-design-runner self-test: OK")
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
