#!/usr/bin/env python3
"""Dispatch the proven replay/isolation guard through the Gateway v2 action contract.

The declarative Gateway registry is the single dispatch source. Base Phase 2D actions
retain their accepted validator; bounded Phase 2E actions resolve their dedicated
validator module from the registry. No caller can select a module or MCP tool.
"""
from __future__ import annotations

import importlib.util
from pathlib import Path
from typing import Any

HERE = Path(__file__).resolve().parent
BASE_GUARD = HERE / "phase2d-command-guard.py"
CONTRACT_PATH = HERE / "research-gateway-v2-contract.py"


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"unable to load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


guard = load_module("phase2d_command_guard_base", BASE_GUARD)
contract = load_module("research_gateway_v2_contract_guard", CONTRACT_PATH)
registry = contract.load_registry()
original_load_validated_command = guard.load_validated_command
_validator_cache: dict[Path, Any] = {}


def validator_module(path: Path):
    cached = _validator_cache.get(path)
    if cached is not None:
        return cached
    module = load_module(f"gateway_validator_{path.stem.replace('-', '_')}", path)
    if not callable(getattr(module, "load_validated_command", None)):
        raise guard.GuardError(f"registered validator exposes no load_validated_command: {path.name}")
    _validator_cache[path] = module
    return module


def dispatched_load_validated_command(path: Path):
    raw = guard.runner.load_json(path)
    action = raw.get("action")
    if not isinstance(action, str):
        raise guard.GuardError("command action is invalid")
    try:
        validator_path = contract.validator_path(action, registry)
    except contract.ContractError as exc:
        raise guard.GuardError(str(exc)) from exc
    if validator_path is None:
        return original_load_validated_command(path)

    validator = validator_module(validator_path)
    try:
        return validator.load_validated_command(path)
    except validator.CommandError as exc:
        raise guard.GuardError(str(exc)) from exc


guard.load_validated_command = dispatched_load_validated_command

if __name__ == "__main__":
    raise SystemExit(guard.main())
