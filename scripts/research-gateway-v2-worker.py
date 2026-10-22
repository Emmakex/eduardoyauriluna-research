#!/usr/bin/env python3
"""Durable serial worker for the Eduardo Research Gateway v2.

Requests are immutable JSON files. A request is unresolved when no matching result
exists. Each workflow run drains every unresolved request visible in its checkout in a
deterministic order. GitHub concurrency may cancel intermediate pending workflow runs,
but the newest surviving run still sees every committed request and therefore cannot
lose an accepted command merely because several pushes arrived quickly.

The worker deliberately reuses the already-accepted Phase 2E validators, replay guard,
production MCP isolation check and observable command runner. It changes transport
queue semantics, not the Research Manager authority or tool contracts.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
import subprocess
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
GUARD_PATH = ROOT / "scripts" / "phase2e-command-guard.py"
RUNNER_PATH = ROOT / "scripts" / "phase2e-command-runner-observable.py"
DEFAULT_REQUEST_DIR = ROOT / "commands" / "gateway-v2" / "requests"
DEFAULT_RESULT_DIR = ROOT / "commands" / "gateway-v2" / "results"
DEFAULT_MANIFEST = ROOT / ".gateway-v2-produced.json"
MAX_DEFAULT = 25

spec = importlib.util.spec_from_file_location("research_gateway_v2_guard", GUARD_PATH)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2E command guard")
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)


class WorkerError(RuntimeError):
    """Fail-closed worker error."""


def load_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise WorkerError(f"invalid JSON file: {path.name}") from exc
    if not isinstance(value, dict):
        raise WorkerError(f"{path.name} must contain a JSON object")
    return value


def safe_result_identity(command: dict[str, Any], result: dict[str, Any]) -> bool:
    return (
        result.get("command_id") == command.get("command_id")
        and result.get("tenant") == command.get("tenant")
        and result.get("action") == command.get("action")
    )


def resolved_result_is_safe(command: dict[str, Any], result_path: Path) -> bool:
    result = load_json(result_path)
    if not safe_result_identity(command, result):
        raise WorkerError(f"existing result identity mismatch for {command['command_id']}")
    recorded = result.get("command_sha256")
    expected = guard.guard.command_sha256(command)
    if recorded != expected:
        raise WorkerError(f"existing result fingerprint mismatch for {command['command_id']}")
    if result.get("status") not in {"success", "failed"}:
        raise WorkerError(f"unsupported existing result status for {command['command_id']}")
    return True


def unresolved_requests(request_dir: Path, result_dir: Path) -> list[tuple[Path, dict[str, Any]]]:
    pending: list[tuple[Path, dict[str, Any]]] = []
    if not request_dir.exists():
        return pending
    for path in sorted(request_dir.glob("*.json"), key=lambda item: item.name):
        command = guard.guard.load_validated_command(path)
        command_id = str(command["command_id"])
        result_path = result_dir / f"{command_id}.json"
        if result_path.exists():
            resolved_result_is_safe(command, result_path)
            continue
        pending.append((path, command))
    return pending


def execute_one(path: Path, command: dict[str, Any], result_dir: Path) -> tuple[Path, int]:
    command_id = str(command["command_id"])
    result_path = result_dir / f"{command_id}.json"
    completed = subprocess.run(
        [
            sys.executable,
            str(RUNNER_PATH),
            str(path),
            "--result-dir",
            str(result_dir),
        ],
        cwd=ROOT,
        env=os.environ.copy(),
        check=False,
        text=True,
    )
    if not result_path.is_file():
        raise WorkerError(f"execution produced no sanitized result for {command_id}")
    guard.guard.stamp_result(path, result_dir)
    resolved_result_is_safe(command, result_path)
    return result_path, int(completed.returncode)


def repo_relative(path: Path) -> str:
    absolute = path if path.is_absolute() else ROOT / path
    try:
        relative = absolute.resolve().relative_to(ROOT.resolve())
    except ValueError as exc:
        raise WorkerError(f"produced path escaped repository: {path}") from exc
    return relative.as_posix()


def write_manifest(path: Path, produced: list[Path], failures: list[str]) -> None:
    payload = {
        "version": "1",
        "produced": [repo_relative(item) for item in produced],
        "failures": failures,
    }
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def run(request_dir: Path, result_dir: Path, manifest: Path, max_commands: int) -> int:
    if max_commands < 1 or max_commands > 100:
        raise WorkerError("max_commands must be between 1 and 100")

    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise WorkerError("PHASE2D_MCP_TOKEN is required")

    request_dir.mkdir(parents=True, exist_ok=True)
    result_dir.mkdir(parents=True, exist_ok=True)

    queue = unresolved_requests(request_dir, result_dir)
    if not queue:
        write_manifest(manifest, [], [])
        print("GATEWAY_V2_QUEUE_EMPTY")
        return 0

    if len(queue) > max_commands:
        raise WorkerError(
            f"unresolved queue size {len(queue)} exceeds bounded max_commands={max_commands}"
        )

    tenants = {str(command["tenant"]) for _, command in queue}
    if tenants != {"eduardo-research"}:
        raise WorkerError("Gateway v2 queue contains an unregistered tenant")

    guard.guard.verify_tenant_isolation("eduardo-research")

    produced: list[Path] = []
    failures: list[str] = []
    for path, command in queue:
        command_id = str(command["command_id"])
        result_path, exit_code = execute_one(path, command, result_dir)
        produced.append(result_path)
        if exit_code != 0:
            failures.append(command_id)
            print(f"GATEWAY_V2_COMMAND_FAILED {command_id}", file=sys.stderr)
        else:
            print(f"GATEWAY_V2_COMMAND_OK {command_id}")

    write_manifest(manifest, produced, failures)
    print(
        f"GATEWAY_V2_DRAINED produced={len(produced)} failures={len(failures)}"
    )
    return 1 if failures else 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--request-dir", type=Path, default=DEFAULT_REQUEST_DIR)
    parser.add_argument("--result-dir", type=Path, default=DEFAULT_RESULT_DIR)
    parser.add_argument("--manifest", type=Path, default=DEFAULT_MANIFEST)
    parser.add_argument("--max-commands", type=int, default=MAX_DEFAULT)
    args = parser.parse_args()
    try:
        return run(args.request_dir, args.result_dir, args.manifest, args.max_commands)
    except (WorkerError, guard.guard.GuardError, guard.guard.runner.CommandError) as exc:
        print(f"GATEWAY_V2_ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
