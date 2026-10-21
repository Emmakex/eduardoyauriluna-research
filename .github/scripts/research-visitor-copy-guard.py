#!/usr/bin/env python3
"""Fail when internal Research control language leaks back into visitor-facing Theme copy."""
from __future__ import annotations

from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
THEME = ROOT / "wordpress" / "themes" / "eduardo-research"

VISITOR_FILES = [
    THEME / "front-page.php",
    THEME / "page.php",
    THEME / "single.php",
    THEME / "single-post.php",
    THEME / "archive.php",
    THEME / "index.php",
    THEME / "404.php",
    THEME / "header.php",
    THEME / "footer.php",
    THEME / "research-templates" / "page-research-surface.php",
    THEME / "inc" / "research-language.php",
    THEME / "inc" / "research-model.php",
    THEME / "inc" / "research-surface-layouts.php",
    THEME / "inc" / "research-objects.php",
    THEME / "inc" / "research-relations.php",
    THEME / "inc" / "research-cv.php",
    THEME / "inc" / "research-insights.php",
    THEME / "inc" / "research-collections.php",
]

# Exact phrases that previously reached rendered visitor surfaces. Internal function names,
# evidence gates and code comments remain allowed; this guard protects public copy only.
FORBIDDEN_PHRASES = (
    "Source-verified evidence",
    "Evidencia verificada en fuente",
    "Verified research line",
    "Línea de investigación verificada",
    "Verified line",
    "Línea verificada",
    "verified research lines",
    "líneas de investigación verificadas",
    "when verified evidence exists",
    "cuando exista evidencia verificada",
    "No verified records",
    "No hay registros verificados",
    "evidence-backed research objects",
    "objetos de investigación respaldados por evidencia",
    "Privacy information in preparation",
    "Información de privacidad en preparación",
    "Legal information in preparation",
    "Información legal en preparación",
    "Research Manager",
)

missing = [str(path.relative_to(ROOT)) for path in VISITOR_FILES if not path.is_file()]
if missing:
    print("Visitor-copy guard is missing expected Theme files:", file=sys.stderr)
    for path in missing:
        print(f" - {path}", file=sys.stderr)
    sys.exit(1)

violations: list[tuple[str, str]] = []
for path in VISITOR_FILES:
    text = path.read_text(encoding="utf-8")
    for phrase in FORBIDDEN_PHRASES:
        if phrase.casefold() in text.casefold():
            violations.append((str(path.relative_to(ROOT)), phrase))

language = (THEME / "inc" / "research-language.php").read_text(encoding="utf-8")
model = (THEME / "inc" / "research-model.php").read_text(encoding="utf-8")
objects = (THEME / "inc" / "research-objects.php").read_text(encoding="utf-8")

required_contracts = {
    "neutral evidence heading EN": "'verified_evidence'=>array('en'=>'Profile information'",
    "neutral evidence heading ES": "'es'=>'Información del perfil'",
    "canonical researcher fallback": "return 'Eduardo Jose Yauri Luna';",
    "visitor research-line label EN": "? 'Línea de investigación' : 'Research line'",
}
for label, needle in required_contracts.items():
    haystack = language if "evidence heading" in label else model if "fallback" in label else objects
    if needle not in haystack:
        violations.append(("required-contract", label))

if violations:
    print("Visitor-copy regression guard failed:", file=sys.stderr)
    for path, phrase in violations:
        print(f" - {path}: {phrase}", file=sys.stderr)
    sys.exit(1)

print(f"Visitor-copy guard PASS ({len(VISITOR_FILES)} visitor-facing files checked)")
