#!/usr/bin/env python3
"""Dispatch observable production commands through the Gateway v2 action contract.

The command file carries bounded business intent only. The trusted declarative registry
selects the already-accepted runner. Sanitized MCP diagnostics are normalized through
shared Gateway failure semantics.
"""
from __future__ import annotations

import importlib.util
import json
import os
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
BASE_RUNNER = HERE / "phase2d-command-runner.py"
CONTRACT_PATH = HERE / "research-gateway-v2-contract.py"


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"unable to load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


contract = load_module("research_gateway_v2_contract_observable", CONTRACT_PATH)
registry = contract.load_registry()


def selected_runner_path() -> Path:
    if len(sys.argv) < 2 or sys.argv[1].startswith("-"):
        return BASE_RUNNER
    path = Path(sys.argv[1])
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return BASE_RUNNER
    if not isinstance(value, dict) or not isinstance(value.get("action"), str):
        return BASE_RUNNER
    try:
        selected = contract.runner_path(str(value["action"]), registry)
    except contract.ContractError:
        return BASE_RUNNER
    return selected or BASE_RUNNER


path = selected_runner_path()
module = load_module(
    "phase2e_selected_command_runner" if path != BASE_RUNNER else "phase2d_command_runner",
    path,
)
_real_run = subprocess.run
_real_write_result = module.write_result
_last_diagnostic: str | None = None


def diagnostic_sanitizer():
    sanitizer = getattr(module, "sanitized_message", None)
    if callable(sanitizer):
        return sanitizer
    delegated_base = getattr(module, "base", None)
    sanitizer = getattr(delegated_base, "sanitized_message", None)
    if callable(sanitizer):
        return sanitizer
    raise RuntimeError("selected command runner does not expose sanitized diagnostic handling")


def observable_run(*args, **kwargs):
    global _last_diagnostic
    completed = _real_run(*args, **kwargs)
    if completed.returncode != 0 and isinstance(completed.stdout, str) and completed.stdout.strip():
        stderr = completed.stderr if isinstance(completed.stderr, str) else ""
        payload = completed.stdout.strip()
        combined = f"{stderr.rstrip()}\nMCP tool payload: {payload}".strip()
        token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
        _last_diagnostic = diagnostic_sanitizer()(combined, token)
        completed = subprocess.CompletedProcess(
            completed.args,
            completed.returncode,
            completed.stdout,
            combined,
        )
    return completed


def observable_write_result(result_dir, command_id, result):
    if result.get("status") == "failed" and _last_diagnostic:
        result = dict(result)
        error = result.get("error")
        if isinstance(error, dict):
            code, stage, message = contract.classify_failure(_last_diagnostic)
            existing_code = error.get("code")
            if existing_code in {None, "execution_failed"}:
                existing_code = code
            result["error"] = {
                **error,
                "code": existing_code,
                "stage": stage,
                "message": (
                    message
                    if error.get("code") in {None, "execution_failed"}
                    else error.get("message", message)
                ),
                "diagnostic": _last_diagnostic,
            }
    return _real_write_result(result_dir, command_id, result)


if hasattr(module, "subprocess"):
    module.subprocess.run = observable_run
module.write_result = observable_write_result
if hasattr(module, "base"):
    module.base.subprocess.run = observable_run
raise SystemExit(module.main())
