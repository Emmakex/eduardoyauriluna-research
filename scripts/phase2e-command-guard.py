#!/usr/bin/env python3
"""Dispatch the proven Phase 2D replay/isolation guard across Phase 2E actions.

Existing actions keep the original validator unchanged. The first Research action
uses its dedicated strict validator, then reuses the same command fingerprinting,
replay semantics, result stamping and production tenant-isolation probe.
"""
from __future__ import annotations

import importlib.util
from pathlib import Path

HERE = Path(__file__).resolve().parent
BASE_GUARD = HERE / "phase2d-command-guard.py"
RESEARCH_RUNNER = HERE / "phase2e-research-object-runner.py"


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"unable to load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


guard = load_module("phase2d_command_guard_base", BASE_GUARD)
research = load_module("phase2e_research_object_runner_guard", RESEARCH_RUNNER)
original_load_validated_command = guard.load_validated_command


def dispatched_load_validated_command(path: Path):
    raw = guard.runner.load_json(path)
    if raw.get("action") == research.ACTION:
        try:
            return research.load_validated_command(path)
        except research.CommandError as exc:
            raise guard.GuardError(str(exc)) from exc
    return original_load_validated_command(path)


guard.load_validated_command = dispatched_load_validated_command

if __name__ == "__main__":
    raise SystemExit(guard.main())
