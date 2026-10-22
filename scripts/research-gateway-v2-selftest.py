#!/usr/bin/env python3
"""Offline invariants for the Research Gateway v2 durable queue."""
from __future__ import annotations

import importlib.util
import json
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKER_PATH = ROOT / "scripts" / "research-gateway-v2-worker.py"

spec = importlib.util.spec_from_file_location("research_gateway_v2_worker_selftest", WORKER_PATH)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Gateway v2 worker")
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


def command(command_id: str, tool: str) -> dict:
    return {
        "version": "1",
        "command_id": command_id,
        "tenant": "eduardo-research",
        "action": "manager.tool.describe",
        "requested_by": "chatgpt",
        "payload": {"tool": tool},
    }


def main() -> int:
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
