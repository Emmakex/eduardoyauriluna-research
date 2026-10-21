#!/usr/bin/env python3
"""Dispatch the proven Phase 2D replay/isolation guard across Phase 2E actions.

Existing actions keep the original validator unchanged. Bounded Page, Navigation,
Media metadata, Design, Insight Research-Line relation, structured Research translation,
Research Object, Research Line and read-only Manager inspection actions use dedicated
strict validators, then reuse the same fingerprinting, replay semantics, result stamping
and tenant-isolation probe. Binary Media ingest stays outside Git persistence.
"""
from __future__ import annotations

import importlib.util
from pathlib import Path

HERE = Path(__file__).resolve().parent
BASE_GUARD = HERE / "phase2d-command-guard.py"
PAGE_TEXT_SLOT_RUNNER = HERE / "phase2e-page-text-slot-runner.py"
NAVIGATION_RUNNER = HERE / "phase2e-navigation-runner.py"
MEDIA_METADATA_RUNNER = HERE / "phase2e-media-metadata-runner.py"
DESIGN_RUNNER = HERE / "phase2e-design-runner.py"
INSIGHT_RESEARCH_LINES_RUNNER = HERE / "phase2e-insight-research-lines-runner.py"
RESEARCH_TRANSLATION_RUNNER = HERE / "phase2e-research-translation-runner.py"
RESEARCH_CREATE_RUNNER = HERE / "phase2e-research-object-runner.py"
RESEARCH_UPDATE_RUNNER = HERE / "phase2e-research-object-update-runner.py"
RESEARCH_LINE_CREATE_RUNNER = HERE / "phase2e-research-line-create-runner.py"
RESEARCH_LINE_UPDATE_RUNNER = HERE / "phase2e-research-line-update-runner.py"
MANAGER_READ_RUNNER = HERE / "phase2e-manager-read-runner.py"


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise SystemExit(f"unable to load {path.name}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


guard = load_module("phase2d_command_guard_base", BASE_GUARD)
page_text_slot = load_module("phase2e_page_text_slot_guard", PAGE_TEXT_SLOT_RUNNER)
navigation = load_module("phase2e_navigation_guard", NAVIGATION_RUNNER)
media_metadata = load_module("phase2e_media_metadata_guard", MEDIA_METADATA_RUNNER)
design = load_module("phase2e_design_guard", DESIGN_RUNNER)
insight_research_lines = load_module("phase2e_insight_research_lines_guard", INSIGHT_RESEARCH_LINES_RUNNER)
research_translation = load_module("phase2e_research_translation_guard", RESEARCH_TRANSLATION_RUNNER)
research_create = load_module("phase2e_research_object_create_guard", RESEARCH_CREATE_RUNNER)
research_update = load_module("phase2e_research_object_update_guard", RESEARCH_UPDATE_RUNNER)
research_line_create = load_module("phase2e_research_line_create_guard", RESEARCH_LINE_CREATE_RUNNER)
research_line_update = load_module("phase2e_research_line_update_guard", RESEARCH_LINE_UPDATE_RUNNER)
manager_read = load_module("phase2e_manager_read_guard", MANAGER_READ_RUNNER)
original_load_validated_command = guard.load_validated_command

PHASE2E_VALIDATORS = {
    page_text_slot.ACTION: page_text_slot,
    navigation.ACTION: navigation,
    media_metadata.ACTION: media_metadata,
    design.PRESET_ACTION: design,
    design.VARIANT_ACTION: design,
    insight_research_lines.ACTION: insight_research_lines,
    research_translation.PAIR_ACTION: research_translation,
    research_translation.UNPAIR_ACTION: research_translation,
    research_create.ACTION: research_create,
    research_update.ACTION: research_update,
    research_line_create.ACTION: research_line_create,
    research_line_update.ACTION: research_line_update,
    manager_read.LIST_ACTION: manager_read,
    manager_read.DESCRIBE_ACTION: manager_read,
    manager_read.PAGE_INSPECT_PAIR_ACTION: manager_read,
    manager_read.EVIDENCE_ABOUT_AUDIT_ACTION: manager_read,
    manager_read.RESEARCH_LINES_LIST_PAIR_ACTION: manager_read,
}


def dispatched_load_validated_command(path: Path):
    raw = guard.runner.load_json(path)
    action = raw.get("action")
    validator = PHASE2E_VALIDATORS.get(action)
    if validator is not None:
        try:
            return validator.load_validated_command(path)
        except validator.CommandError as exc:
            raise guard.GuardError(str(exc)) from exc
    return original_load_validated_command(path)


guard.load_validated_command = dispatched_load_validated_command

if __name__ == "__main__":
    raise SystemExit(guard.main())
