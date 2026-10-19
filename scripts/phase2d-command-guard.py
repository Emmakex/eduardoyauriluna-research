#!/usr/bin/env python3
"""Phase 2D command replay/idempotency guard and live tenant-isolation probe.

This helper keeps the GitHub command bridge fail-closed around two hardening edges:
1. a command_id can be executed at most once for one exact canonical payload;
2. the dedicated GitHub bearer must be accepted only for its registered tenant.

The helper never prints the MCP bearer and never accepts an arbitrary MCP URL.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
import re
import sys
import tempfile
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
DEFAULT_RESULT_DIR = ROOT / "commands" / "phase2d" / "results"
PRODUCTION_BASE_URL = "https://mcp.kairoseth.com/mcp"
DENIED_TENANT = "phase2d-denied-probe"
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")

spec = importlib.util.spec_from_file_location("phase2d_command_runner", RUNNER)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class GuardError(RuntimeError):
    """Fail-closed guard error."""


def load_validated_command(path: Path) -> dict[str, Any]:
    registry = runner.load_json(runner.REGISTRY_PATH)
    runner.validate_registry(registry)
    command = runner.load_json(path)
    runner.validate_command(command, registry)
    return command


def command_sha256(command: dict[str, Any]) -> str:
    canonical = json.dumps(
        command,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    ).encode("utf-8")
    return hashlib.sha256(canonical).hexdigest()


def emit_output(key: str, value: str) -> None:
    output = os.environ.get("GITHUB_OUTPUT", "").strip()
    if not output:
        return
    with open(output, "a", encoding="utf-8") as handle:
        handle.write(f"{key}={value}\n")


def result_path(command: dict[str, Any], result_dir: Path) -> Path:
    return result_dir / f"{command['command_id']}.json"


def check_replay(command_path: Path, result_dir: Path) -> int:
    command = load_validated_command(command_path)
    digest = command_sha256(command)
    path = result_path(command, result_dir)
    emit_output("command_sha256", digest)

    if not path.exists():
        emit_output("decision", "new")
        print(f"NEW_COMMAND {command['command_id']}")
        return 0

    result = runner.load_json(path)
    if result.get("command_id") != command["command_id"]:
        raise GuardError("existing result command_id does not match the command")
    if result.get("tenant") != command["tenant"] or result.get("action") != command["action"]:
        raise GuardError("existing result identity does not match tenant/action")

    recorded = result.get("command_sha256")
    if not isinstance(recorded, str) or not SHA256_RE.fullmatch(recorded):
        raise GuardError(
            "existing result predates command fingerprinting; refusing ambiguous replay"
        )
    if recorded != digest:
        raise GuardError(
            "command_id collision: existing result fingerprint differs from current payload"
        )

    status = result.get("status")
    if status == "success":
        emit_output("decision", "replay_success")
        print(f"ALREADY_COMPLETED {path}")
        return 0
    if status == "failed":
        emit_output("decision", "replay_failed")
        raise GuardError("command already completed with failed status; use a new command_id")
    raise GuardError("existing result has an unsupported status")


def stamp_result(command_path: Path, result_dir: Path) -> int:
    command = load_validated_command(command_path)
    digest = command_sha256(command)
    path = result_path(command, result_dir)
    if not path.is_file():
        raise GuardError("execution produced no sanitized result to fingerprint")

    result = runner.load_json(path)
    if result.get("command_id") != command["command_id"]:
        raise GuardError("result command_id does not match the command")
    if result.get("tenant") != command["tenant"] or result.get("action") != command["action"]:
        raise GuardError("result identity does not match tenant/action")

    recorded = result.get("command_sha256")
    if recorded is not None and recorded != digest:
        raise GuardError("refusing to overwrite a different command fingerprint")

    result["command_sha256"] = digest
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(
        json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    os.replace(tmp, path)
    print(f"FINGERPRINTED_RESULT {path}")
    return 0


def mcp_initialize_status(url: str, token: str) -> int:
    body = json.dumps(
        {
            "jsonrpc": "2.0",
            "id": 1,
            "method": "initialize",
            "params": {
                "protocolVersion": "2025-06-18",
                "capabilities": {},
                "clientInfo": {"name": "phase2d-command-guard", "version": "1.0"},
            },
        }
    ).encode("utf-8")
    request = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={
            "Authorization": "Bearer " + token,
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "User-Agent": "phase2d-command-guard/1.0",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            response.read()
            return int(response.status)
    except urllib.error.HTTPError as exc:
        exc.read()
        return int(exc.code)


def verify_tenant_isolation(tenant: str) -> int:
    if not runner.TENANT_RE.fullmatch(tenant):
        raise GuardError("invalid allowed tenant id")
    token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
    if not token:
        raise GuardError("PHASE2D_MCP_TOKEN is required")
    base_url = os.environ.get("PHASE2D_MCP_BASE_URL", PRODUCTION_BASE_URL).strip().rstrip("/")
    if base_url != PRODUCTION_BASE_URL:
        raise GuardError("Phase 2D live isolation probe only permits the production MCP base URL")

    allowed = mcp_initialize_status(f"{base_url}/{tenant}", token)
    denied = mcp_initialize_status(f"{base_url}/{DENIED_TENANT}", token)
    if allowed != 200:
        raise GuardError(f"automation principal failed allowed tenant initialize (HTTP {allowed})")
    if denied != 403:
        raise GuardError(f"automation principal did not fail closed on denied tenant (HTTP {denied})")
    print(f"TENANT_ISOLATION_OK allowed={allowed} denied={denied}")
    return 0


def self_test() -> int:
    command = {
        "version": "1",
        "command_id": "cmd-guard-selftest-0001",
        "tenant": "eduardo-research",
        "action": "insight.create",
        "requested_by": "chatgpt",
        "payload": {
            "title": "Guard self-test",
            "language": "es",
            "status": "draft",
            "content": "No network execution.",
        },
    }

    with tempfile.TemporaryDirectory(prefix="phase2d-guard-selftest-") as temp:
        root = Path(temp)
        command_path = root / "command.json"
        result_dir = root / "results"
        command_path.write_text(json.dumps(command), encoding="utf-8")

        if check_replay(command_path, result_dir) != 0:
            raise GuardError("self-test: new command was not accepted")

        result_dir.mkdir(parents=True)
        result = {
            "version": "1",
            "status": "success",
            "tenant": command["tenant"],
            "action": command["action"],
            "command_id": command["command_id"],
            "verified": True,
        }
        result_path(command, result_dir).write_text(json.dumps(result), encoding="utf-8")
        stamp_result(command_path, result_dir)
        if check_replay(command_path, result_dir) != 0:
            raise GuardError("self-test: exact successful replay was not idempotent")

        changed = json.loads(json.dumps(command))
        changed["payload"]["content"] = "Different payload under the same command id."
        command_path.write_text(json.dumps(changed), encoding="utf-8")
        try:
            check_replay(command_path, result_dir)
        except GuardError as exc:
            if "collision" not in str(exc):
                raise
        else:
            raise GuardError("self-test: command_id collision did not fail closed")

        failed_command = json.loads(json.dumps(command))
        failed_command["command_id"] = "cmd-guard-selftest-0002"
        command_path.write_text(json.dumps(failed_command), encoding="utf-8")
        failed_result = {
            "version": "1",
            "status": "failed",
            "tenant": failed_command["tenant"],
            "action": failed_command["action"],
            "command_id": failed_command["command_id"],
            "verified": False,
        }
        result_path(failed_command, result_dir).write_text(json.dumps(failed_result), encoding="utf-8")
        stamp_result(command_path, result_dir)
        try:
            check_replay(command_path, result_dir)
        except GuardError as exc:
            if "failed status" not in str(exc):
                raise
        else:
            raise GuardError("self-test: failed command replay did not fail closed")

    print("phase2d-command-guard self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="mode", required=True)

    guard = sub.add_parser("guard")
    guard.add_argument("command", type=Path)
    guard.add_argument("--result-dir", type=Path, default=DEFAULT_RESULT_DIR)

    stamp = sub.add_parser("stamp")
    stamp.add_argument("command", type=Path)
    stamp.add_argument("--result-dir", type=Path, default=DEFAULT_RESULT_DIR)

    isolation = sub.add_parser("isolation")
    isolation.add_argument("--tenant", required=True)

    sub.add_parser("self-test")
    args = parser.parse_args()

    try:
        if args.mode == "guard":
            return check_replay(args.command, args.result_dir)
        if args.mode == "stamp":
            return stamp_result(args.command, args.result_dir)
        if args.mode == "isolation":
            return verify_tenant_isolation(args.tenant)
        if args.mode == "self-test":
            return self_test()
        raise GuardError("unsupported mode")
    except (GuardError, runner.CommandError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
