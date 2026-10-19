#!/usr/bin/env python3
"""Run the Phase 2D command runner while preserving sanitized MCP tool-error payloads.

Inspector v2 intentionally exits 5 when a tools/call result carries isError:true,
but still writes the actual tool payload to stdout. The original bounded runner
only included stderr on non-zero exit, which hid the Manager refusal that is needed
for safe production diagnosis. This wrapper keeps the lifecycle unchanged and only
adds that stdout payload to the runner's existing sanitized error path.
"""
from __future__ import annotations

import importlib.util
import subprocess
from pathlib import Path

RUNNER = Path(__file__).with_name("phase2d-command-runner.py")
spec = importlib.util.spec_from_file_location("phase2d_command_runner", RUNNER)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2D command runner")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

_real_run = subprocess.run


def observable_run(*args, **kwargs):
    completed = _real_run(*args, **kwargs)
    if completed.returncode != 0 and isinstance(completed.stdout, str) and completed.stdout.strip():
        stderr = completed.stderr if isinstance(completed.stderr, str) else ""
        payload = completed.stdout.strip()
        completed = subprocess.CompletedProcess(
            completed.args,
            completed.returncode,
            completed.stdout,
            f"{stderr.rstrip()}\nMCP tool payload: {payload}".strip(),
        )
    return completed


module.subprocess.run = observable_run
raise SystemExit(module.main())
