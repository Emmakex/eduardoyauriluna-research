#!/usr/bin/env python3
"""Print the four rendered legal surfaces for human Phase 6 review."""
from __future__ import annotations

import re
import urllib.request
from html.parser import HTMLParser

BASE = "https://eduardoyauriluna.com"
PATHS = ("/privacy-policy/", "/es/privacidad/", "/legal-notice/", "/es/aviso-legal/")


class LegalParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.depth = 0
        self.parts: list[str] = []
        self.sections: list[str] = []

    def handle_starttag(self, tag, attrs):
        data = {str(k).lower(): str(v or "") for k, v in attrs}
        classes = set(data.get("class", "").split())
        if tag.lower() == "section" and "research-legal-section" in classes:
            self.depth = 1
            self.parts = []
        elif self.depth:
            self.depth += 1

    def handle_endtag(self, tag):
        if not self.depth:
            return
        self.depth -= 1
        if self.depth == 0:
            text = re.sub(r"\s+", " ", " ".join(self.parts)).strip()
            if text:
                self.sections.append(text)
            self.parts = []

    def handle_data(self, data):
        if self.depth and data.strip():
            self.parts.append(data.strip())


for path in PATHS:
    req = urllib.request.Request(BASE + path, headers={"User-Agent": "EduardoResearch-Phase6-Legal-Readback/1.0"})
    with urllib.request.urlopen(req, timeout=30) as response:
        body = response.read().decode(response.headers.get_content_charset() or "utf-8", errors="replace")
    parser = LegalParser(); parser.feed(body); parser.close()
    print(f"LEGAL COPY {path}")
    for index, section in enumerate(parser.sections, 1):
        print(f"  {index}. {section}")
    if not parser.sections:
        print("  [no rendered legal sections]")
