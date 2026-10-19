#!/usr/bin/env python3
"""Bind the reviewed Research Object acceptance restore to successful command 0003."""
from __future__ import annotations

import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(__file__).with_name("phase2e-research-object-acceptance-restore.py")
COMMAND_ID = "cmd-phase2e-research-software-create-0003"

spec = importlib.util.spec_from_file_location("phase2e_research_object_acceptance_restore", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Research Object acceptance restore helper")
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)

helper.EXPECTED_COMMAND_ID = COMMAND_ID
helper.EXPECTED_RESULT = ROOT / "commands" / "phase2d" / "results" / f"{COMMAND_ID}.json"

if __name__ == "__main__":
    raise SystemExit(helper.main())
