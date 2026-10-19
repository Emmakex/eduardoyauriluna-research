#!/usr/bin/env python3
"""Phase 2E bounded capability expansion for Research mutations.

This wrapper deliberately reuses the reviewed transactional gateway capability
expander. It only widens the reviewed capability set with ``research.write``;
all tenant/principal, bearer-preservation, single-tenant isolation, immutable
image recreation, health verification and rollback guarantees remain in the
base implementation.
"""
from __future__ import annotations

import importlib.util
from pathlib import Path

BASE = Path(__file__).with_name("phase2e-expand-gateway-capabilities.py")

spec = importlib.util.spec_from_file_location("phase2e_gateway_capability_expander", BASE)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load reviewed gateway capability expander")
expander = importlib.util.module_from_spec(spec)
spec.loader.exec_module(expander)

expander.APPROVED_CAPABILITIES = [
    "pages.write",
    "translations.write",
    "research.write",
]

if __name__ == "__main__":
    raise SystemExit(expander.main())
