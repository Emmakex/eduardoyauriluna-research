#!/usr/bin/env python3
"""Bind the reviewed Phase 2E gateway capability expander to seo.write."""
from __future__ import annotations

import importlib.util
from pathlib import Path

BASE = Path(__file__).with_name("phase2e-expand-gateway-capabilities.py")
spec = importlib.util.spec_from_file_location("phase2e_expand_gateway_capabilities", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Phase 2E gateway capability expander")
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)

# Preserve the already-accepted Phase 2E base capabilities and add only seo.write.
helper.APPROVED_CAPABILITIES = [
    "pages.write",
    "translations.write",
    "seo.write",
]

if __name__ == "__main__":
    raise SystemExit(helper.main())
