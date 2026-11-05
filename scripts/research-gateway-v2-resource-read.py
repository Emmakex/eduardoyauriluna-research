#!/usr/bin/env python3
"""Generic bounded read engine for Research Gateway v2 resources.

This is intentionally not an MCP passthrough. Resource names and filters resolve to a
closed internal tool map. The caller cannot provide a tool name, URL, REST path or raw
backend payload.
"""
from __future__ import annotations

import argparse
import importlib.util
import os
import re
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE_RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
LIST_ACTION = "manager.resource.list"
INSPECT_ACTION = "manager.resource.inspect"
ACTIONS = {LIST_ACTION, INSPECT_ACTION}
LANGUAGE_RE = re.compile(r"^[a-z][a-z0-9-]{1,15}$")
RESEARCH_OBJECT_KINDS = {"output", "project", "software", "dataset"}
EVIDENCE_GROUPS = {
    "profile",
    "affiliations",
    "identifiers",
    "experience",
    "education",
    "awards",
    "contact",
}
PAGE_KEYS = {"home", "about", "research", "cv", "contact"}
LIST_RESOURCES = {"insight", "research_object", "research_line", "evidence", "page"}
INSPECT_RESOURCES = {
    "insight",
    "research_object",
    "research_line",
    "page",
    "navigation",
    "status",
    "versions",
    "design",
    "design_variants",
    "greenfield_lifecycle",
}
SENSITIVE_KEY_PARTS = (
    "token",
    "secret",
    "password",
    "credential",
    "authorization",
    "cookie",
    "nonce",
)


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"unable to load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


base = load_module("phase2d_command_runner_resource_read_base", BASE_RUNNER)
CommandError = base.CommandError
load_json = base.load_json
write_result = base.write_result
sanitized_message = base.sanitized_message


def sanitize(value: Any, depth: int = 0) -> Any:
    if depth > 10:
        return "[depth-limited]"
    if isinstance(value, dict):
        safe: dict[str, Any] = {}
        for key, child in value.items():
            name = str(key)
            if any(part in name.lower() for part in SENSITIVE_KEY_PARTS):
                continue
            safe[name] = sanitize(child, depth + 1)
        return safe
    if isinstance(value, list):
        return [sanitize(item, depth + 1) for item in value[:200]]
    if isinstance(value, str):
        return value[:40000]
    if value is None or isinstance(value, (bool, int, float)):
        return value
    return str(value)[:40000]


def valid_id(value: Any) -> bool:
    return (
        isinstance(value, int)
        and not isinstance(value, bool)
        and 0 < value <= base.MAX_SAFE_INTEGER
    )


def validate_language(value: Any, field: str) -> None:
    if value is not None and (
        not isinstance(value, str) or LANGUAGE_RE.fullmatch(value) is None
    ):
        raise CommandError(f"{field} is invalid")


def validate_list(payload: dict[str, Any]) -> None:
    if set(payload) != {"resource", "filters"}:
        raise CommandError("manager.resource.list payload must contain only resource and filters")
    resource = payload.get("resource")
    filters = payload.get("filters")
    if resource not in LIST_RESOURCES:
        raise CommandError("manager.resource.list resource is not supported")
    if not isinstance(filters, dict):
        raise CommandError("manager.resource.list filters must be an object")

    if resource == "page":
        if filters != {}:
            raise CommandError("Page list accepts no caller-selected filters")
        return

    if resource in {"insight", "research_line"}:
        if not set(filters).issubset({"language"}):
            raise CommandError(f"{resource} list filters may contain only language")
        validate_language(filters.get("language"), "payload.filters.language")
        return

    if resource == "research_object":
        if not set(filters).issubset({"kind", "language"}):
            raise CommandError("Research Object list filters may contain only kind and language")
        kind = filters.get("kind")
        if kind is not None and kind not in RESEARCH_OBJECT_KINDS:
            raise CommandError("payload.filters.kind is invalid")
        validate_language(filters.get("language"), "payload.filters.language")
        return

    if set(filters) != {"group"} or filters.get("group") not in EVIDENCE_GROUPS:
        raise CommandError("Evidence list requires exactly one registered group")


def summarize_greenfield_lifecycle(status: Any, pages: Any, navigation: Any) -> dict[str, Any]:
    """Read-only, data-minimized Site Architect health view; not a public launch decision.

    The live Manager's status, Theme-owned Page inventory and Navigation inspection
    are the only authorities. Do not treat an empty resource list or absent
    readiness keys as proof that the Greenfield instance is complete.
    """
    if not isinstance(status, dict) or not isinstance(pages, dict) or not isinstance(navigation, dict):
        raise CommandError("Greenfield lifecycle read returned an invalid shape")
    mode = status.get("mode")
    preset = status.get("preset")
    readiness = status.get("readiness")
    langs = status.get("languages")
    items = pages.get("items")
    nav_state = navigation.get("state")
    nav_contract = navigation.get("contract")
    if (
        not isinstance(mode, dict) or not isinstance(preset, dict)
        or not isinstance(readiness, dict) or not isinstance(langs, list)
        or not langs or any(lang not in {"en", "es"} for lang in langs)
        or len(set(langs)) != len(langs)
        or not isinstance(items, list) or not items or len(items) > 40
        or not isinstance(pages.get("count"), int) or pages["count"] != len(items)
        or not isinstance(nav_state, dict) or not isinstance(nav_contract, dict)
        or not isinstance(nav_state.get("primary"), list)
        or not isinstance(nav_state.get("footer"), list)
    ):
        raise CommandError("Greenfield lifecycle authority data is incomplete")
    keys: list[str] = []
    aligned = 0
    for page in items:
        if not isinstance(page, dict) or not isinstance(page.get("key"), str):
            raise CommandError("Greenfield Page identity is invalid")
        key = page["key"]
        if not key or len(key) > 80 or key in keys:
            raise CommandError("Greenfield Page key is missing or duplicated")
        keys.append(key)
        localizations = page.get("languages")
        if not isinstance(localizations, dict):
            raise CommandError("Greenfield Page localization data is missing")
        if page.get("exists") is True and all(
            isinstance(localizations.get(language), dict)
            and localizations[language].get("available") is True
            and localizations[language].get("contract_aligned") is True
            for language in langs
        ):
            aligned += 1
    nav_primary, nav_footer = nav_state["primary"], nav_state["footer"]
    if (
        any(not isinstance(key, str) or len(key) > 80 for key in nav_primary + nav_footer)
        or len(nav_primary) > 20 or len(nav_footer) > 20
    ):
        raise CommandError("Greenfield Navigation state is not bounded")

    checks = {
        "greenfield_mode": mode.get("greenfield") is True and mode.get("migration") is False,
        "theme_contract": preset.get("compatible") is True,
        "canonical_domain": status.get("canonical_domain_source") == "active-instance-blueprint"
            and status.get("runtime_matches_declared_canonical") is True,
        "manager_diagnostics": readiness.get("ready") is True,
        "theme_pages_localized": aligned == len(items),
        "bounded_navigation": nav_contract.get("arbitrary_urls") is False
            and len(nav_primary) > 0 and len(nav_footer) > 0,
    }
    return {
        "product": "Site Architect",
        "track": "Greenfield",
        "site": "eduardoyauriluna.com",
        "kind": "technical-governance-inspection",
        "read_only": True,
        "technical_operational_ready": all(checks.values()),
        "checks": checks,
        "page_inventory": {
            "count": len(items),
            "fully_aligned": aligned,
            "keys": keys,
            "languages": list(langs),
        },
        "navigation": {
            "primary_count": len(nav_primary),
            "footer_count": len(nav_footer),
            "arbitrary_urls": nav_contract["arbitrary_urls"],
        },
        "manager_diagnostics": {
            "ready": readiness["ready"] is True,
            "summary": sanitize(readiness.get("summary", {})),
        },
        "exclusions": {
            "public_visitor_and_legal_acceptance": "not_evaluated",
            "academic_evidence_and_publications": "not_evaluated",
            "greenfield_blueprint_apply": "not_executed",
            "release_or_launch_approval": "not_granted",
        },
    }


def validate_inspect(payload: dict[str, Any]) -> None:
    resource = payload.get("resource")
    if resource not in INSPECT_RESOURCES:
        raise CommandError("manager.resource.inspect resource is not supported")

    if resource in {"navigation", "status", "versions", "design", "design_variants", "greenfield_lifecycle"}:
        if set(payload) != {"resource"}:
            raise CommandError(f"{resource} inspect accepts no caller-selected parameters")
        return

    if resource == "insight":
        if set(payload) != {"resource", "id"} or not valid_id(payload.get("id")):
            raise CommandError("Insight inspect requires resource and positive safe integer id")
        return

    if resource == "research_line":
        if set(payload) != {"resource", "id"} or not valid_id(payload.get("id")):
            raise CommandError("Research Line inspect requires resource and positive safe integer id")
        return

    if resource == "research_object":
        if set(payload) != {"resource", "kind", "id"}:
            raise CommandError("Research Object inspect requires resource, kind and id")
        if payload.get("kind") not in RESEARCH_OBJECT_KINDS or not valid_id(payload.get("id")):
            raise CommandError("Research Object inspect payload is invalid")
        return

    if set(payload) != {"resource", "key", "language"}:
        raise CommandError("Page inspect requires resource, key and language")
    if payload.get("key") not in PAGE_KEYS:
        raise CommandError("Page inspect key is outside the reviewed Research Theme surface")
    validate_language(payload.get("language"), "payload.language")


def validate_command(command: dict[str, Any]) -> None:
    if set(command) != {"version", "command_id", "tenant", "action", "requested_by", "payload"}:
        raise CommandError("command keys do not match command-v1 contract")
    if command.get("version") != "1":
        raise CommandError("unsupported command version")
    command_id = command.get("command_id")
    if not isinstance(command_id, str) or base.COMMAND_ID_RE.fullmatch(command_id) is None:
        raise CommandError("invalid command_id")
    if command.get("tenant") != "eduardo-research":
        raise CommandError("resource read tenant is not registered")
    action = command.get("action")
    if action not in ACTIONS:
        raise CommandError("unsupported resource read action")
    if command.get("requested_by") != "chatgpt":
        raise CommandError("requested_by must be chatgpt")
    payload = command.get("payload")
    if not isinstance(payload, dict):
        raise CommandError("payload must be an object")
    if action == LIST_ACTION:
        validate_list(payload)
    else:
        validate_inspect(payload)


def load_validated_command(path: Path) -> dict[str, Any]:
    command = load_json(path)
    validate_command(command)
    return command


def execute(command_path: Path, result_dir: Path) -> int:
    command = load_validated_command(command_path)
    command_id = str(command["command_id"])
    existing = result_dir / f"{command_id}.json"
    if existing.exists():
        print(f"ALREADY_COMPLETED {existing}")
        return 0

    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise CommandError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", base.DEFAULT_BASE_URL).strip().rstrip("/")
    if base_url != base.DEFAULT_BASE_URL:
        raise CommandError("resource read execution only permits the production MCP base URL")

    tenant = str(command["tenant"])
    action = str(command["action"])
    payload = command["payload"]
    client = base.McpClient(f"{base_url}/{tenant}", token)
    try:
        client.initialize()
        client.call("research_capabilities")
        descriptor: dict[str, Any]
        if action == LIST_ACTION:
            resource = str(payload["resource"])
            filters = dict(payload["filters"])
            if resource == "insight":
                data = client.call("site_insights_list", filters)
            elif resource == "research_object":
                data = client.call("site_research_objects_list", filters)
            elif resource == "research_line":
                data = client.call("site_research_lines_list", filters)
            elif resource == "page":
                data = client.call("site_pages_list", {})
            else:
                data = client.call("site_evidence_list", {"group": filters["group"]})
            if not isinstance(data, (dict, list)):
                raise CommandError("resource list returned an unsupported result")
            descriptor = {"type": resource, "filters": sanitize(filters)}
        else:
            resource = str(payload["resource"])
            if resource == "navigation":
                data = client.call("site_navigation_inspect", {})
                descriptor = {"type": resource}
            elif resource == "greenfield_lifecycle":
                data = summarize_greenfield_lifecycle(
                    client.call("research_status", {}),
                    client.call("site_pages_list", {}),
                    client.call("site_navigation_inspect", {}),
                )
                descriptor = {"type": resource}
            elif resource == "status":
                data = client.call("research_status", {})
                descriptor = {"type": resource}
            elif resource == "versions":
                data = client.call("research_versions", {})
                descriptor = {"type": resource}
            elif resource == "design":
                data = client.call("site_design_inspect", {})
                descriptor = {"type": resource}
            elif resource == "design_variants":
                data = client.call("site_design_variants", {})
                descriptor = {"type": resource}
            elif resource == "insight":
                post_id = int(payload["id"])
                data = client.call("site_insight_inspect", {"postId": post_id})
                descriptor = {"type": resource, "id": post_id}
            elif resource == "research_line":
                post_id = int(payload["id"])
                data = client.call("site_research_line_inspect", {"postId": post_id})
                descriptor = {"type": resource, "id": post_id}
            elif resource == "research_object":
                post_id = int(payload["id"])
                kind = str(payload["kind"])
                data = client.call(
                    "site_research_object_inspect",
                    {"kind": kind, "postId": post_id},
                )
                descriptor = {"type": resource, "kind": kind, "id": post_id}
            else:
                key = str(payload["key"])
                language = str(payload["language"])
                data = client.call("site_page_inspect", {"key": key, "language": language})
                descriptor = {"type": resource, "key": key, "language": language}
            if not isinstance(data, dict):
                raise CommandError("resource inspect returned a non-object result")

        result = {
            "version": "1",
            "status": "success",
            "tenant": tenant,
            "action": action,
            "command_id": command_id,
            "read_only": True,
            "resource": descriptor,
            "data": sanitize(data),
        }
        path = write_result(result_dir, command_id, result)
        print(f"SUCCESS {path}")
        return 0
    except Exception as exc:  # noqa: BLE001
        result = {
            "version": "1",
            "status": "failed",
            "tenant": tenant,
            "action": action,
            "command_id": command_id,
            "read_only": True,
            "error": {
                "code": "execution_failed",
                "message": "The bounded resource read did not complete successfully.",
            },
        }
        path = write_result(result_dir, command_id, result)
        print(f"FAILED {path}: {sanitized_message(str(exc), token)}", file=sys.stderr)
        return 1
    finally:
        client.close()


def self_test() -> int:
    good_commands = [
        {
            "version": "1",
            "command_id": "cmd-selftest-resource-insights-0001",
            "tenant": "eduardo-research",
            "action": LIST_ACTION,
            "requested_by": "chatgpt",
            "payload": {"resource": "insight", "filters": {"language": "en"}},
        },
        {
            "version": "1",
            "command_id": "cmd-selftest-resource-lines-0002",
            "tenant": "eduardo-research",
            "action": LIST_ACTION,
            "requested_by": "chatgpt",
            "payload": {"resource": "research_line", "filters": {"language": "es"}},
        },
        {
            "version": "1",
            "command_id": "cmd-selftest-resource-evidence-0003",
            "tenant": "eduardo-research",
            "action": LIST_ACTION,
            "requested_by": "chatgpt",
            "payload": {"resource": "evidence", "filters": {"group": "identifiers"}},
        },
        {
            "version": "1",
            "command_id": "cmd-selftest-resource-page-0004",
            "tenant": "eduardo-research",
            "action": INSPECT_ACTION,
            "requested_by": "chatgpt",
            "payload": {"resource": "page", "key": "research", "language": "en"},
        },
        {
            "version": "1",
            "command_id": "cmd-selftest-resource-navigation-0005",
            "tenant": "eduardo-research",
            "action": INSPECT_ACTION,
            "requested_by": "chatgpt",
            "payload": {"resource": "navigation"},
        },
        {
            "version": "1",
            "command_id": "cmd-selftest-resource-status-0006",
            "tenant": "eduardo-research",
            "action": INSPECT_ACTION,
            "requested_by": "chatgpt",
            "payload": {"resource": "status"},
        },
        {
            "version": "1",
            "command_id": "cmd-selftest-resource-versions-0007",
            "tenant": "eduardo-research",
            "action": INSPECT_ACTION,
            "requested_by": "chatgpt",
            "payload": {"resource": "versions"},
        },
        {
            "version": "1",
            "command_id": "cmd-selftest-resource-line-inspect-0008",
            "tenant": "eduardo-research",
            "action": INSPECT_ACTION,
            "requested_by": "chatgpt",
            "payload": {"resource": "research_line", "id": 29},
        },
        {
            "version": "1",
            "command_id": "cmd-selftest-resource-design-0009",
            "tenant": "eduardo-research",
            "action": INSPECT_ACTION,
            "requested_by": "chatgpt",
            "payload": {"resource": "design"},
        },
        {
            "version": "1",
            "command_id": "cmd-selftest-resource-design-variants-0010",
            "tenant": "eduardo-research",
            "action": INSPECT_ACTION,
            "requested_by": "chatgpt",
            "payload": {"resource": "design_variants"},
        },
        {
            "version": "1",
            "command_id": "cmd-selftest-resource-pages-list-0011",
            "tenant": "eduardo-research",
            "action": LIST_ACTION,
            "requested_by": "chatgpt",
            "payload": {"resource": "page", "filters": {}},
        },
        {
            "version": "1",
            "command_id": "cmd-selftest-greenfield-lifecycle-0012",
            "tenant": "eduardo-research",
            "action": INSPECT_ACTION,
            "requested_by": "chatgpt",
            "payload": {"resource": "greenfield_lifecycle"},
        },
    ]
    for command in good_commands:
        validate_command(command)

    # The synthetic lifecycle fixture exercises shape, readiness and closed summary.
    fixture_status = {
        "mode": {"greenfield": True, "migration": False},
        "preset": {"compatible": True},
        "readiness": {"ready": True, "summary": {"pass": 22, "warning": 0, "fail": 0}},
        "languages": ["en", "es"],
        "canonical_domain_source": "active-instance-blueprint",
        "runtime_matches_declared_canonical": True,
    }
    fixture_pages = {"count": 1, "items": [{
        "key": "home", "exists": True,
        "languages": {
            "en": {"available": True, "contract_aligned": True, "stored_slots": {"secret": "DO_NOT_EXPOSE"}},
            "es": {"available": True, "contract_aligned": True},
        },
    }]}
    fixture_navigation = {
        "state": {"primary": ["about"], "footer": ["legal-notice"]},
        "contract": {"arbitrary_urls": False},
    }
    overview = summarize_greenfield_lifecycle(fixture_status, fixture_pages, fixture_navigation)
    assert overview["technical_operational_ready"] is True
    assert overview["exclusions"]["release_or_launch_approval"] == "not_granted"
    assert "DO_NOT_EXPOSE" not in str(overview)
    partial = dict(fixture_pages)
    partial["items"] = [{**fixture_pages["items"][0], "exists": False}]
    assert summarize_greenfield_lifecycle(fixture_status, partial, fixture_navigation)["technical_operational_ready"] is False
    try:
        summarize_greenfield_lifecycle(fixture_status, {"count": 0, "items": []}, fixture_navigation)
    except CommandError:
        pass
    else:
        raise CommandError("lifecycle inspection accepted an empty Page inventory")
    bad_lifecycle = dict(good_commands[-1])
    bad_lifecycle["payload"] = {"resource": "greenfield_lifecycle", "tool": "arbitrary"}
    try:
        validate_command(bad_lifecycle)
    except CommandError:
        pass
    else:
        raise CommandError("lifecycle inspection accepted arbitrary backend selection")

    bad = dict(good_commands[1])
    bad["payload"] = {"resource": "research_line", "filters": {"raw_tool": "x"}}
    try:
        validate_command(bad)
    except CommandError:
        pass
    else:
        raise CommandError("resource read self-test accepted an arbitrary filter")

    bad_navigation = dict(good_commands[4])
    bad_navigation["payload"] = {"resource": "navigation", "location": "primary"}
    try:
        validate_command(bad_navigation)
    except CommandError:
        pass
    else:
        raise CommandError("resource read self-test accepted caller-selected navigation parameters")

    bad_versions = dict(good_commands[6])
    bad_versions["payload"] = {"resource": "versions", "component": "theme"}
    try:
        validate_command(bad_versions)
    except CommandError:
        pass
    else:
        raise CommandError("resource read self-test accepted caller-selected system parameters")

    bad_line = dict(good_commands[7])
    bad_line["payload"] = {"resource": "research_line", "id": 0}
    try:
        validate_command(bad_line)
    except CommandError:
        pass
    else:
        raise CommandError("resource read self-test accepted an invalid Research Line id")

    bad_design = dict(good_commands[8])
    bad_design["payload"] = {"resource": "design", "preset": "researcher"}
    try:
        validate_command(bad_design)
    except CommandError:
        pass
    else:
        raise CommandError("resource read self-test accepted caller-selected Design parameters")

    bad_design_variants = dict(good_commands[9])
    bad_design_variants["payload"] = {"resource": "design_variants", "variant": "hero"}
    try:
        validate_command(bad_design_variants)
    except CommandError:
        pass
    else:
        raise CommandError("resource read self-test accepted caller-selected Design Variant parameters")

    bad_pages = dict(good_commands[10])
    bad_pages["payload"] = {"resource": "page", "filters": {"status": "publish"}}
    try:
        validate_command(bad_pages)
    except CommandError:
        pass
    else:
        raise CommandError("resource read self-test accepted caller-selected Page list filters")

    print("research-gateway-v2 resource read self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", nargs="?", type=Path)
    parser.add_argument("--result-dir", type=Path, default=ROOT / "commands" / "gateway-v2" / "results")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            return self_test()
        if args.command is None:
            parser.error("command is required unless --self-test is used")
        return execute(args.command, args.result_dir)
    except CommandError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
