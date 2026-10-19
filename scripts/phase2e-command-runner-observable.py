#!/usr/bin/env python3
"""Dispatch the observable production command runner across accepted Phase 2E actions.

Existing commands still execute in the original Phase 2D runner. Bounded Page,
Navigation, Media metadata, Design, Insight Research-Line relation, Research Object
and Research Line commands execute in dedicated runners. Acceptance-only fixture and
rollback actions are exact-contract helpers removed after production proof. Binary
Media ingest remains outside this Git-persisted command channel. All paths retain
sanitized MCP tool-error capture.
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
PAGE_TEXT_SLOT_RUNNER = HERE / "phase2e-page-text-slot-runner.py"
NAVIGATION_RUNNER = HERE / "phase2e-navigation-runner.py"
MEDIA_METADATA_RUNNER = HERE / "phase2e-media-metadata-runner.py"
DESIGN_RUNNER = HERE / "phase2e-design-runner.py"
INSIGHT_RESEARCH_LINES_RUNNER = HERE / "phase2e-insight-research-lines-runner.py"
SLICE13_LINE_FIXTURE_RUNNER = HERE / "phase2e-slice13-line-fixture-runner.py"
SLICE13_ROLLBACK_RUNNER = HERE / "phase2e-slice13-acceptance-rollback-runner.py"
RESEARCH_CREATE_RUNNER = HERE / "phase2e-research-object-runner.py"
RESEARCH_UPDATE_RUNNER = HERE / "phase2e-research-object-update-runner.py"
RESEARCH_LINE_CREATE_RUNNER = HERE / "phase2e-research-line-create-runner.py"
RESEARCH_LINE_UPDATE_RUNNER = HERE / "phase2e-research-line-update-runner.py"
RUNNERS = {
    "page.slot.update": PAGE_TEXT_SLOT_RUNNER,
    "navigation.update": NAVIGATION_RUNNER,
    "media.metadata.update": MEDIA_METADATA_RUNNER,
    "design.preset.update": DESIGN_RUNNER,
    "design.variant.update": DESIGN_RUNNER,
    "insight.research-lines.update": INSIGHT_RESEARCH_LINES_RUNNER,
    "research.line.acceptance.fixture.create": SLICE13_LINE_FIXTURE_RUNNER,
    "slice13.acceptance.rollback": SLICE13_ROLLBACK_RUNNER,
    "research.object.create": RESEARCH_CREATE_RUNNER,
    "research.object.update": RESEARCH_UPDATE_RUNNER,
    "research.line.create": RESEARCH_LINE_CREATE_RUNNER,
    "research.line.update": RESEARCH_LINE_UPDATE_RUNNER,
}


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"unable to load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def selected_runner_path() -> Path:
    if len(sys.argv) < 2 or sys.argv[1].startswith("-"):
        return BASE_RUNNER
    path = Path(sys.argv[1])
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return BASE_RUNNER
    if isinstance(value, dict):
        return RUNNERS.get(value.get("action"), BASE_RUNNER)
    return BASE_RUNNER


path = selected_runner_path()
module = load_module("phase2e_selected_command_runner" if path != BASE_RUNNER else "phase2d_command_runner", path)
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
        completed = subprocess.CompletedProcess(completed.args, completed.returncode, completed.stdout, combined)
    return completed


def observable_write_result(result_dir, command_id, result):
    if result.get("status") == "failed" and _last_diagnostic:
        result = dict(result)
        error = result.get("error")
        if isinstance(error, dict):
            result["error"] = {**error, "diagnostic": _last_diagnostic}
    return _real_write_result(result_dir, command_id, result)


if hasattr(module, "subprocess"):
    module.subprocess.run = observable_run
module.write_result = observable_write_result
if hasattr(module, "base"):
    module.base.subprocess.run = observable_run
raise SystemExit(module.main())
