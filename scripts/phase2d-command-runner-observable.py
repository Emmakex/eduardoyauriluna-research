#!/usr/bin/env python3
"""Run the Phase 2D command runner while preserving sanitized MCP tool-error payloads.

Inspector v2 intentionally exits 5 when a tools/call result carries isError:true,
but still writes the actual tool payload to stdout. The original bounded runner
only included stderr on non-zero exit, which hid the Manager refusal that is needed
for safe production diagnosis. This wrapper keeps the lifecycle unchanged, adds the
stdout payload to the runner's existing sanitized error path, and persists only the
sanitized diagnostic in failed command evidence.
"""
from __future__ import annotations

import importlib.util
import os
import subprocess
from pathlib import Path

RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
spec = importlib.util.spec_from_file_location("phase2d_command_runner", RUNNER)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

_real_run = subprocess.run
_real_write_result = module.write_result
_last_diagnostic: str | None = None


def observable_run(*args, **kwargs):
    global _last_diagnostic
    completed = _real_run(*args, **kwargs)
    if completed.returncode != 0 and isinstance(completed.stdout, str) and completed.stdout.strip():
        stderr = completed.stderr if isinstance(completed.stderr, str) else ""
        payload = completed.stdout.strip()
        combined = f"{stderr.rstrip()}\nMCP tool payload: {payload}".strip()
        token = os.environ.get("PHASE2D_MCP_TOKEN", "").strip()
        _last_diagnostic = module.sanitized_message(combined, token)
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
            result["error"] = {**error, "diagnostic": _last_diagnostic}
    return _real_write_result(result_dir, command_id, result)


module.subprocess.run = observable_run
module.write_result = observable_write_result
raise SystemExit(module.main())
