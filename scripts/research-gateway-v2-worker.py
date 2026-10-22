#!/usr/bin/env python3
"""Durable serial worker for the Eduardo Research Gateway v2.

Requests are immutable JSON files. A request is unresolved when no matching terminal
result with the same identity and fingerprint exists. Sealed historical requests are
not revalidated against newer action schemas; this lets bounded contracts evolve
without invalidating the immutable audit ledger. Only unresolved requests must satisfy
the current validators.

Each workflow run drains every unresolved request visible in its checkout in a
deterministic order. Before any business runner executes, the worker resolves the
bounded action contract and checks the live Research Manager credential scopes exposed
by /capabilities. Missing Manager scopes fail closed before plan/apply. Commercial MCP
capabilities remain an independent inner authorization boundary enforced by the MCP.
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
CONTRACT_PATH = ROOT / "scripts" / "research-gateway-v2-contract.py"
DEFAULT_REQUEST_DIR = ROOT / "commands" / "gateway-v2" / "requests"
DEFAULT_RESULT_DIR = ROOT / "commands" / "gateway-v2" / "results"
DEFAULT_MANIFEST = ROOT / ".gateway-v2-produced.json"
MAX_DEFAULT = 25


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"unable to load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


guard = load_module("research_gateway_v2_guard", GUARD_PATH)
contract = load_module("research_gateway_v2_contract_worker", CONTRACT_PATH)
registry = contract.load_registry()
_capabilities_cache: dict[str, Any] = {}


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
        raise WorkerError(f"existing result identity mismatch for {command.get('command_id', 'unknown')}")
    recorded = result.get("command_sha256")
    expected = guard.guard.command_sha256(command)
    if recorded != expected:
        raise WorkerError(f"existing result fingerprint mismatch for {command.get('command_id', 'unknown')}")
    if result.get("status") not in {"success", "failed"}:
        raise WorkerError(f"unsupported existing result status for {command.get('command_id', 'unknown')}")
    return True


def unresolved_requests(request_dir: Path, result_dir: Path) -> list[tuple[Path, dict[str, Any]]]:
    pending: list[tuple[Path, dict[str, Any]]] = []
    if not request_dir.exists():
        return pending
    for path in sorted(request_dir.glob("*.json"), key=lambda item: item.name):
        # First inspect immutable identity/fingerprint only. A resolved request is a sealed
        # audit record and must not be invalidated by future validator/schema evolution.
        raw = load_json(path)
        command_id = raw.get("command_id")
        if (
            not isinstance(command_id, str)
            or guard.guard.runner.COMMAND_ID_RE.fullmatch(command_id) is None
        ):
            raise WorkerError(f"historical request has invalid command_id: {path.name}")
        result_path = result_dir / f"{command_id}.json"
        if result_path.exists():
            resolved_result_is_safe(raw, result_path)
            continue

        # Only unresolved work is admitted through the current bounded command contract.
        command = guard.guard.load_validated_command(path)
        pending.append((path, command))
    return pending


def live_capabilities(tenant: str) -> Any:
    cached = _capabilities_cache.get(tenant)
    if cached is not None:
        return cached

    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise WorkerError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get(
        "PHASE2D_MCP_BASE_URL", guard.guard.runner.DEFAULT_BASE_URL
    ).strip().rstrip("/")
    if base_url != guard.guard.runner.DEFAULT_BASE_URL:
        raise WorkerError("Gateway v2 preflight only permits the production MCP base URL")

    client = guard.guard.runner.McpClient(f"{base_url}/{tenant}", token)
    try:
        client.initialize()
        capabilities = client.call("research_capabilities")
    finally:
        client.close()
    _capabilities_cache[tenant] = capabilities
    return capabilities


def live_preflight(command: dict[str, Any]) -> dict[str, Any]:
    tenant = str(command["tenant"])
    return contract.preflight(command, live_capabilities(tenant), registry)


def persist_preflight_failure(
    command: dict[str, Any], result_dir: Path, exc: Exception
) -> tuple[Path, int]:
    command_id = str(command["command_id"])
    action = str(command["action"])
    required: list[str] = []
    try:
        required = contract.required_capabilities(command, registry)
    except contract.ContractError:
        pass

    diagnostic = str(exc)
    code, stage, message = contract.classify_failure(diagnostic)
    if isinstance(exc, contract.ScopeDenied):
        code, stage = "scope_denied", "authorization"
        message = "The live Research Manager credential is not granted the required scope."
    elif isinstance(exc, contract.ContractError):
        code, stage = "capability_unavailable", "authorization"
        message = "The live Manager-scope preflight could not establish the required authorization contract."

    result = {
        "version": "1",
        "status": "failed",
        "tenant": str(command["tenant"]),
        "action": action,
        "command_id": command_id,
        "verified": False,
        "preflight": {
            "checked": True,
            "read_only": bool(contract.action_contract(action, registry)["read_only"]),
            "required_capabilities": required,
            "granted": False,
        },
        "error": {
            "code": code,
            "stage": stage,
            "message": message,
        },
    }
    path = guard.guard.runner.write_result(result_dir, command_id, result)
    return path, 1


def attach_preflight(result_path: Path, preflight: dict[str, Any]) -> None:
    result = load_json(result_path)
    result["preflight"] = preflight
    result_path.write_text(
        json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )


def execute_one(path: Path, command: dict[str, Any], result_dir: Path) -> tuple[Path, int]:
    command_id = str(command["command_id"])
    result_path = result_dir / f"{command_id}.json"
    try:
        preflight = live_preflight(command)
    except Exception as exc:  # noqa: BLE001
        result_path, exit_code = persist_preflight_failure(command, result_dir, exc)
        guard.guard.stamp_result(path, result_dir)
        resolved_result_is_safe(command, result_path)
        return result_path, exit_code

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
    attach_preflight(result_path, preflight)
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
    print(f"GATEWAY_V2_DRAINED produced={len(produced)} failures={len(failures)}")
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
    except (
        WorkerError,
        contract.ContractError,
        guard.guard.GuardError,
        guard.guard.runner.CommandError,
    ) as exc:
        print(f"GATEWAY_V2_ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
