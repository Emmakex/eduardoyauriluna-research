#!/usr/bin/env python3
"""Offline invariants for the Research Gateway v2 durable queue, preflight and bounded runners."""
from __future__ import annotations

import importlib.util
import json
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKER_PATH = ROOT / "scripts" / "research-gateway-v2-worker.py"
LIFECYCLE_PATH = ROOT / "scripts" / "research-gateway-v2-lifecycle.py"
RUNNER_SELFTESTS = (
    ROOT / "scripts" / "research-gateway-v2-resource-read.py",
    ROOT / "scripts" / "research-gateway-v2-capability-check.py",
    ROOT / "scripts" / "research-gateway-v2-operation-rollback.py",
    ROOT / "scripts" / "phase2e-navigation-runner.py",
    ROOT / "scripts" / "phase2e-design-runner.py",
    ROOT / "scripts" / "phase2e-media-metadata-runner.py",
    ROOT / "scripts" / "phase2e-research-object-update-runner.py",
)


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"unable to load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


worker = load_module("research_gateway_v2_worker_selftest", WORKER_PATH)
lifecycle = load_module("research_gateway_v2_lifecycle_selftest", LIFECYCLE_PATH)


def command(command_id: str, tool: str) -> dict:
    return {
        "version": "1",
        "command_id": command_id,
        "tenant": "eduardo-research",
        "action": "manager.tool.describe",
        "requested_by": "chatgpt",
        "payload": {"tool": tool},
    }


def assert_contract_invariants() -> None:
    registry = worker.contract.load_registry()
    actions = registry["actions"]
    assert "manager.resource.list" in actions
    assert "manager.capability.check" in actions
    assert "navigation.update" in actions
    assert "operation.rollback" in actions
    assert worker.contract.runner_path("manager.resource.list", registry).name == "research-gateway-v2-resource-read.py"
    assert worker.contract.validator_path("manager.resource.list", registry).name == "research-gateway-v2-resource-read.py"
    assert worker.contract.runner_path("manager.capability.check", registry).name == "research-gateway-v2-capability-check.py"
    assert worker.contract.runner_path("operation.rollback", registry).name == "research-gateway-v2-operation-rollback.py"
    assert worker.contract.validator_path("operation.rollback", registry).name == "research-gateway-v2-operation-rollback.py"
    assert worker.contract.runner_path("insight.create", registry) is None
    assert worker.contract.validator_path("insight.create", registry) is None

    insight_read = {
        "action": "manager.resource.list",
        "payload": {"resource": "insight", "filters": {}},
    }
    research_read = {
        "action": "manager.resource.inspect",
        "payload": {"resource": "research_object", "kind": "software", "id": 100},
    }
    research_line_read = {
        "action": "manager.resource.list",
        "payload": {"resource": "research_line", "filters": {"language": "en"}},
    }
    evidence_read = {
        "action": "manager.resource.list",
        "payload": {"resource": "evidence", "filters": {"group": "identifiers"}},
    }
    page_read = {
        "action": "manager.resource.inspect",
        "payload": {"resource": "page", "key": "research", "language": "es"},
    }
    navigation_read = {
        "action": "manager.resource.inspect",
        "payload": {"resource": "navigation"},
    }
    status_read = {
        "action": "manager.resource.inspect",
        "payload": {"resource": "status"},
    }
    versions_read = {
        "action": "manager.resource.inspect",
        "payload": {"resource": "versions"},
    }
    nav_write = {"action": "navigation.update", "payload": {}}
    rollback = {
        "action": "operation.rollback",
        "payload": {"source_command_id": "cmd-source-navigation-0001"},
    }
    assert worker.contract.required_capabilities(insight_read, registry) == ["insights.read"]
    assert worker.contract.required_capabilities(research_read, registry) == ["research.read"]
    assert worker.contract.required_capabilities(research_line_read, registry) == ["research.read"]
    assert worker.contract.required_capabilities(evidence_read, registry) == ["evidence.read"]
    assert worker.contract.required_capabilities(page_read, registry) == ["pages.read"]
    assert worker.contract.required_capabilities(navigation_read, registry) == ["pages.read"]
    assert worker.contract.required_capabilities(status_read, registry) == ["site.read"]
    assert worker.contract.required_capabilities(versions_read, registry) == ["site.read"]
    assert worker.contract.required_capabilities(nav_write, registry) == [
        "pages.read",
        "pages.write",
        "site.diagnostics",
        "operations.apply",
        "operations.rollback",
    ]
    assert worker.contract.required_capabilities(rollback, registry) == [
        "pages.read",
        "pages.write",
        "operations.rollback",
    ]

    ok = worker.contract.preflight(
        insight_read,
        {"granted_scopes": ["site.read", "insights.read"]},
        registry,
    )
    assert ok == {
        "checked": True,
        "read_only": True,
        "required_capabilities": ["insights.read"],
        "granted": True,
    }

    navigation_ok = worker.contract.preflight(
        navigation_read,
        {"granted_scopes": ["site.read", "pages.read"]},
        registry,
    )
    assert navigation_ok["required_capabilities"] == ["pages.read"]
    assert navigation_ok["granted"] is True

    versions_ok = worker.contract.preflight(
        versions_read,
        {"granted_scopes": ["site.read"]},
        registry,
    )
    assert versions_ok["required_capabilities"] == ["site.read"]
    assert versions_ok["granted"] is True

    try:
        worker.contract.preflight(
            research_read,
            {"granted_scopes": ["site.read", "insights.read"]},
            registry,
        )
    except worker.contract.ScopeDenied as exc:
        assert exc.missing == ["research.read"]
    else:
        raise AssertionError("missing research.read did not fail closed")

    try:
        worker.contract.granted_scopes({"manager": {"status": "ok"}})
    except worker.contract.ContractError as exc:
        assert "granted_scopes" in str(exc)
    else:
        raise AssertionError("ambiguous capability payload did not fail closed")


def assert_runner_invariants() -> None:
    for runner in RUNNER_SELFTESTS:
        completed = subprocess.run(
            [sys.executable, str(runner), "--self-test"],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        if completed.returncode != 0:
            diagnostic = (completed.stderr or completed.stdout).strip()[-2000:]
            raise AssertionError(f"{runner.name} self-test failed: {diagnostic}")


def main() -> int:
    assert_contract_invariants()
    assert lifecycle.self_test() == 0
    assert_runner_invariants()

    with tempfile.TemporaryDirectory(prefix="research-gateway-v2-selftest-") as temp:
        root = Path(temp)
        requests = root / "requests"
        results = root / "results"
        requests.mkdir()
        results.mkdir()

        first = command("cmd-v2-selftest-one-0001", "site_insights_list")
        second = command("cmd-v2-selftest-two-0002", "site_research_objects_list")
        first_path = requests / f"{first['command_id']}.json"
        second_path = requests / f"{second['command_id']}.json"
        first_path.write_text(json.dumps(first), encoding="utf-8")
        second_path.write_text(json.dumps(second), encoding="utf-8")

        queue = worker.unresolved_requests(requests, results)
        assert [item[1]["command_id"] for item in queue] == [
            "cmd-v2-selftest-one-0001",
            "cmd-v2-selftest-two-0002",
        ]

        first_result = {
            "version": "1",
            "status": "success",
            "tenant": first["tenant"],
            "action": first["action"],
            "command_id": first["command_id"],
            "command_sha256": worker.guard.guard.command_sha256(first),
        }
        (results / f"{first['command_id']}.json").write_text(
            json.dumps(first_result), encoding="utf-8"
        )

        queue = worker.unresolved_requests(requests, results)
        assert [item[1]["command_id"] for item in queue] == ["cmd-v2-selftest-two-0002"]

        # A sealed historical request may no longer satisfy the current action-specific
        # schema. Identity + fingerprint + terminal result keep it valid as audit history.
        legacy = {
            "version": "1",
            "command_id": "cmd-v2-selftest-legacy-0003",
            "tenant": "eduardo-research",
            "action": "manager.capability.check",
            "requested_by": "chatgpt",
            "payload": {"capabilities": ["navigation.write"]},
        }
        legacy_path = requests / f"{legacy['command_id']}.json"
        legacy_path.write_text(json.dumps(legacy), encoding="utf-8")
        legacy_result = {
            "version": "1",
            "status": "success",
            "tenant": legacy["tenant"],
            "action": legacy["action"],
            "command_id": legacy["command_id"],
            "command_sha256": worker.guard.guard.command_sha256(legacy),
        }
        (results / f"{legacy['command_id']}.json").write_text(
            json.dumps(legacy_result), encoding="utf-8"
        )
        queue = worker.unresolved_requests(requests, results)
        assert [item[1]["command_id"] for item in queue] == ["cmd-v2-selftest-two-0002"]

        tampered = json.loads(json.dumps(first))
        tampered["payload"]["tool"] = "site_media_list"
        first_path.write_text(json.dumps(tampered), encoding="utf-8")
        try:
            worker.unresolved_requests(requests, results)
        except worker.WorkerError as exc:
            assert "fingerprint mismatch" in str(exc)
        else:
            raise AssertionError("tampered resolved request did not fail closed")

        relative = Path("commands/gateway-v2/results/example.json")
        absolute = ROOT / relative
        assert worker.repo_relative(relative) == relative.as_posix()
        assert worker.repo_relative(absolute) == relative.as_posix()

    print("research-gateway-v2 self-test: OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
