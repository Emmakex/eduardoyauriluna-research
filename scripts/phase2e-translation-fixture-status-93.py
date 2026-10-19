#!/usr/bin/env python3
"""Acceptance-only binding of the translation fixture status helper to post 93."""
from __future__ import annotations

import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(__file__).with_name("phase2e-translation-fixture-status.py")

spec = importlib.util.spec_from_file_location("phase2e_translation_fixture_status", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load translation fixture status helper")
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)

helper.EXPECTED_POST_ID = 93
helper.EXPECTED_REQUEST_PATH = ROOT / "ops" / "phase2e" / "translation-fixture-status-93.json"

if __name__ == "__main__":
    raise SystemExit(helper.main())
