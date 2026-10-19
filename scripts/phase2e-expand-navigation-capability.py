#!/usr/bin/env python3
"""Bind the reviewed Phase 2E gateway capability expander to navigation.write."""
from __future__ import annotations

import importlib.util
from pathlib import Path

BASE = Path(__file__).with_name("phase2e-expand-gateway-capabilities.py")
spec = importlib.util.spec_from_file_location("phase2e_expand_gateway_capabilities", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2E gateway capability expander")
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)

# The base expander is additive: all already-active reviewed capabilities are
# preserved exactly; this binding permits only the new Navigation capability.
helper.APPROVED_CAPABILITIES = ["navigation.write"]

if __name__ == "__main__":
    raise SystemExit(helper.main())
