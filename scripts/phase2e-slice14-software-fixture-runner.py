#!/usr/bin/env python3
"""Create the exact two temporary published Research Software fixtures for Slice 14.

Acceptance-only. Git supplies no titles, languages, statuses, slugs, tool names, routes
or arbitrary data. Both fixtures are fixed and explicitly non-research. Any partially
applied setup is rolled back automatically if the full pair cannot be verified.
"""
from __future__ import annotations

import argparse
import importlib.util
import os
import re
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(__file__).with_name("phase2d-command-runner.py")
ACTION = "slice14.acceptance.software-fixtures.create"
COMMAND_ID = "cmd-phase2e-slice14-software-fixtures-0001"
PLAN_RE = re.compile(r"^erm-object-plan-[a-f0-9]{18}-[a-f0-9]{8}$")
OP_RE = re.compile(r"^erm-object-op-[a-f0-9]{32}$")
FIXTURES = (
    {
        "language": "en",
        "title": "Temporary Research Software EN — Slice 14 translation acceptance fixture",
        "slug": "phase2e-slice14-translation-software-en-20261019",
        "evidence": "acceptance:phase2e:slice14:software-en",
    },
    {
        "language": "es",
        "title": "Software de investigación temporal ES — fixture de aceptación Slice 14",
        "slug": "phase2e-slice14-translation-software-es-20261019",
        "evidence": "acceptance:phase2e:slice14:software-es",
    },
)

spec = importlib.util.spec_from_file_location("phase2d_slice14_fixture_base", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
CommandError = base.CommandError
write_result = base.write_result
sanitized_message = base.sanitized_message
subprocess = base.subprocess


def expected_command() -> dict[str, Any]:
    return {
        "version": "1",
        "command_id": COMMAND_ID,
        "tenant": "eduardo-research",
        "action": ACTION,
        "requested_by": "chatgpt",
        "payload": {"acceptance_only": True},
    }


def validate_command(command: dict[str, Any], registry: dict[str, Any] | None = None) -> None:
    if command != expected_command():
        raise CommandError("Slice 14 Software fixture command must match the exact acceptance-only contract")


def load_validated_command(path: Path) -> dict[str, Any]:
    command = base.load_json(path)
    validate_command(command)
    return command


def fixture_data(item: dict[str, str]) -> dict[str, Any]:
    return {
        "title": item["title"],
        "slug": item["slug"],
        "excerpt": "Temporary structural translation acceptance fixture. Not research content and not an academic claim.",
        "content": "<p>Temporary structural fixture used only to verify bounded EN/ES Research Software translation control. Not research content and not an academic claim.</p>",
        "language": item["language"],
        "status": "publish",
        "software_status": "acceptance-only",
        "version": "slice14-fixture",
        "programming_languages": [],
        "line_ids": [],
    }


def execute(command_path: Path, result_dir: Path) -> int:
    command = base.load_json(command_path)
    validate_command(command)
    existing = result_dir / f"{COMMAND_ID}.json"
    if existing.exists():
        print(f"ALREADY_COMPLETED {existing}")
        return 0
    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise CommandError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", base.DEFAULT_BASE_URL).strip().rstrip("/")
    if not base_url.startswith("https://"):
        raise CommandError("PHASE2D_MCP_BASE_URL must use HTTPS")

    client = base.McpClient(f"{base_url}/eduardo-research", token)
    applied_ops: list[str] = []
    resources: list[dict[str, Any]] = []
    plans: list[str] = []
    try:
        client.initialize()
        for item in FIXTURES:
            plan = client.call(
                "site_research_object_create_plan",
                {
                    "kind": "software",
                    "data": fixture_data(item),
                    "evidenceConfirmed": True,
                    "evidenceReference": item["evidence"],
                },
            )
            plan_id = base.regex_string(plan, PLAN_RE)
            plans.append(plan_id)
            applied = client.call("site_research_object_apply", {"planId": plan_id, "confirm": True})
            operation_id = base.regex_string(applied, OP_RE)
            applied_ops.append(operation_id)
            verified = client.call("site_research_object_verify", {"operationId": operation_id, "rendered": True})
            if not base.verified(verified):
                raise CommandError(f"Slice 14 {item['language']} Software fixture was not verified")
            post_id = base.first_post_id(verified, applied, plan)
            if post_id is None:
                raise CommandError(f"Slice 14 {item['language']} Software fixture returned no post_id")
            resources.append({"post_id": post_id, "language": item["language"], "state": "publish"})
        if len(resources) != 2 or {row["language"] for row in resources} != {"en", "es"}:
            raise CommandError("Slice 14 fixture setup did not produce exactly one EN and one ES Software object")
        result = {
            "version": "1",
            "status": "success",
            "tenant": "eduardo-research",
            "action": ACTION,
            "command_id": COMMAND_ID,
            "verified": True,
            "resource": {"kind": "software", "fixtures": resources},
            "correlation": {"plan_ids": plans, "operation_ids": applied_ops},
        }
        path = write_result(result_dir, COMMAND_ID, result)
        print(f"SUCCESS {path}")
        return 0
    except Exception:
        rollback_failures = 0
        for operation_id in reversed(applied_ops):
            try:
                rolled = client.call("site_research_object_rollback", {"operationId": operation_id, "confirm": True})
                if not base.rollback_verified(rolled):
                    rollback_failures += 1
            except Exception:
                rollback_failures += 1
        if rollback_failures:
            raise CommandError("Slice 14 fixture setup failed and automatic rollback was incomplete")
        raise
    finally:
        client.close()


def self_test() -> int:
    validate_command(expected_command())
    bad = expected_command(); bad["payload"] = {"acceptance_only": True, "status": "publish"}
    try:
        validate_command(bad)
    except CommandError:
        pass
    else:
        raise CommandError("self-test: arbitrary fixture payload escaped exact validation")
    assert len(FIXTURES) == 2 and {x["language"] for x in FIXTURES} == {"en", "es"}
    assert all(fixture_data(x)["status"] == "publish" for x in FIXTURES)
    print("phase2e-slice14-software-fixture-runner self-test: OK")
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
    except CommandError as exc:
        print(f"ERROR: {exc}", file=__import__("sys").stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
