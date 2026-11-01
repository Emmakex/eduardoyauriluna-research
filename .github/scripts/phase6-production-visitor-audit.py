#!/usr/bin/env python3
"""Read-only Phase 6 production audit for global visitor surfaces.

Observes rendered public HTML only. It never calls WordPress write APIs.
"""
from __future__ import annotations

import argparse
import html
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, field
from html.parser import HTMLParser
from typing import Dict, Iterable, List, Optional, Tuple

BASE_URL = "https://eduardoyauriluna.com"
USER_AGENT = "EduardoResearch-Phase6-Visitor-Audit/1.0"
TIMEOUT = 30

CORE: Tuple[Tuple[str, str], ...] = (
    ("en", "/"), ("es", "/es/"),
    ("en", "/about/"), ("es", "/es/perfil/"),
    ("en", "/research/"), ("es", "/es/investigacion/"),
    ("en", "/cv/"), ("es", "/es/cv/"),
    ("en", "/insights/"), ("es", "/es/notas/"),
    ("en", "/contact/"), ("es", "/es/contacto/"),
    ("en", "/privacy-policy/"), ("es", "/es/privacidad/"),
    ("en", "/legal-notice/"), ("es", "/es/aviso-legal/"),
)
LEGAL_PATHS = {
    "/privacy-policy/": ("Privacy", ("data", "contact")),
    "/es/privacidad/": ("Privacidad", ("datos", "contacto")),
    "/legal-notice/": ("Legal", ("ownership", "terms")),
    "/es/aviso-legal/": ("Aviso legal", ("titularidad", "condiciones")),
}
FOOTER_LINKS = {
    "en": {"/privacy-policy/", "/legal-notice/"},
    "es": {"/es/privacidad/", "/es/aviso-legal/"},
}
FORBIDDEN_VISITOR_PATTERNS = (
    r"\bmanager\b", r"\bmcp\b", r"\bgateway\b", r"\btenant\b", r"ci/cd",
    r"acceptance fixture", r"acceptance test", r"implementation test",
    r"verification lifecycle", r"\brollback\b", r"\bpreview\b",
    r"\bview source\b", r"\bver fuente\b",
)
PLACEHOLDER_PATTERNS = (r"lorem ipsum", r"\bplaceholder\b", r"\btodo\b", r"\btbd\b")


def absolute(value: str, base: str = BASE_URL) -> str:
    joined = urllib.parse.urljoin(base.rstrip("/") + "/", html.unescape(value.strip()))
    parsed = urllib.parse.urlsplit(joined)
    return urllib.parse.urlunsplit((parsed.scheme.lower(), parsed.netloc.lower(), parsed.path or "/", parsed.query, ""))


def path_of(value: str) -> str:
    return urllib.parse.urlsplit(value).path or "/"


def same_host(value: str) -> bool:
    return urllib.parse.urlsplit(value).netloc.lower() == urllib.parse.urlsplit(BASE_URL).netloc.lower()


@dataclass
class Link:
    href: str
    text: str = ""
    aria_label: str = ""
    classes: Tuple[str, ...] = ()
    in_footer: bool = False

    @property
    def accessible_name(self) -> str:
        return re.sub(r"\s+", " ", self.aria_label or self.text).strip()


@dataclass
class Page:
    requested_url: str
    final_url: str
    status: int
    body: str
    lang: str = ""
    viewport: str = ""
    text: str = ""
    h1: List[str] = field(default_factory=list)
    headings: List[Tuple[str, str]] = field(default_factory=list)
    links: List[Link] = field(default_factory=list)
    buttons: List[str] = field(default_factory=list)
    image_alt_presence: List[bool] = field(default_factory=list)
    main_ids: List[str] = field(default_factory=list)
    footer_count: int = 0
    json_ld: List[object] = field(default_factory=list)
    legal_sections: List[str] = field(default_factory=list)


class VisitorParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.lang = ""
        self.viewport = ""
        self.text_parts: List[str] = []
        self.h1: List[str] = []
        self.headings: List[Tuple[str, str]] = []
        self.links: List[Link] = []
        self.buttons: List[str] = []
        self.image_alt_presence: List[bool] = []
        self.main_ids: List[str] = []
        self.footer_count = 0
        self.json_ld: List[object] = []
        self.legal_sections: List[str] = []
        self._footer_depth = 0
        self._ignore_depth = 0
        self._heading_tag: Optional[str] = None
        self._heading_parts: List[str] = []
        self._link: Optional[Dict[str, object]] = None
        self._button_parts: Optional[List[str]] = None
        self._json_parts: Optional[List[str]] = None
        self._legal_depth = 0
        self._legal_parts: List[str] = []

    @staticmethod
    def attrs(attrs: Iterable[Tuple[str, Optional[str]]]) -> Dict[str, str]:
        return {str(k).lower(): str(v or "") for k, v in attrs}

    def handle_starttag(self, tag: str, attrs: List[Tuple[str, Optional[str]]]) -> None:
        tag = tag.lower()
        data = self.attrs(attrs)
        classes = tuple(x for x in data.get("class", "").split() if x)
        if tag == "html":
            self.lang = data.get("lang", "").strip().lower()
        if tag == "meta" and data.get("name", "").lower() == "viewport":
            self.viewport = data.get("content", "").strip().lower()
        if tag in {"script", "style", "noscript"}:
            self._ignore_depth += 1
        if tag == "script" and data.get("type", "").lower() == "application/ld+json":
            self._json_parts = []
        if tag == "footer":
            self._footer_depth += 1
            self.footer_count += 1
        if tag == "main":
            self.main_ids.append(data.get("id", ""))
        if tag in {"h1", "h2", "h3", "h4", "h5", "h6"}:
            self._heading_tag = tag
            self._heading_parts = []
        if tag == "a":
            self._link = {
                "href": data.get("href", ""), "aria": data.get("aria-label", ""),
                "classes": classes, "footer": self._footer_depth > 0, "parts": [],
            }
        if tag == "button":
            self._button_parts = []
        if tag == "img":
            self.image_alt_presence.append("alt" in data)
        if tag == "section" and "research-legal-section" in classes:
            self._legal_depth = 1
            self._legal_parts = []
        elif self._legal_depth:
            self._legal_depth += 1

    def handle_endtag(self, tag: str) -> None:
        tag = tag.lower()
        if self._json_parts is not None and tag == "script":
            raw = "".join(self._json_parts).strip()
            if raw:
                try:
                    self.json_ld.append(json.loads(raw))
                except json.JSONDecodeError:
                    self.json_ld.append({"__invalid_json_ld__": raw[:160]})
            self._json_parts = None
        if tag in {"script", "style", "noscript"} and self._ignore_depth:
            self._ignore_depth -= 1
        if self._heading_tag == tag:
            text = re.sub(r"\s+", " ", "".join(self._heading_parts)).strip()
            self.headings.append((tag, text))
            if tag == "h1":
                self.h1.append(text)
            self._heading_tag = None
            self._heading_parts = []
        if tag == "a" and self._link is not None:
            href = str(self._link["href"])
            self.links.append(Link(
                href=href,
                text="".join(self._link["parts"]),
                aria_label=str(self._link["aria"]),
                classes=tuple(self._link["classes"]),
                in_footer=bool(self._link["footer"]),
            ))
            self._link = None
        if tag == "button" and self._button_parts is not None:
            self.buttons.append(re.sub(r"\s+", " ", "".join(self._button_parts)).strip())
            self._button_parts = None
        if self._legal_depth:
            self._legal_depth -= 1
            if self._legal_depth == 0:
                self.legal_sections.append(re.sub(r"\s+", " ", "".join(self._legal_parts)).strip())
                self._legal_parts = []
        if tag == "footer" and self._footer_depth:
            self._footer_depth -= 1

    def handle_data(self, data: str) -> None:
        if self._json_parts is not None:
            self._json_parts.append(data)
        if self._ignore_depth:
            return
        if data.strip():
            self.text_parts.append(data)
            if self._heading_tag:
                self._heading_parts.append(data)
            if self._link is not None:
                self._link["parts"].append(data)
            if self._button_parts is not None:
                self._button_parts.append(data)
            if self._legal_depth:
                self._legal_parts.append(data)


def request(url: str) -> Tuple[int, str, str, str]:
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Accept": "text/html,*/*;q=0.8"})
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as response:
            charset = response.headers.get_content_charset() or "utf-8"
            return int(response.getcode() or 0), str(response.geturl()), str(response.headers.get("Content-Type", "")), response.read().decode(charset, errors="replace")
    except urllib.error.HTTPError as exc:
        charset = exc.headers.get_content_charset() if exc.headers else None
        return int(exc.code), str(exc.geturl()), str(exc.headers.get("Content-Type", "") if exc.headers else ""), exc.read().decode(charset or "utf-8", errors="replace")


def fetch(path: str) -> Page:
    requested = absolute(path)
    status, final_url, content_type, body = request(requested)
    final_url = absolute(final_url)
    if not same_host(final_url):
        raise AssertionError(f"redirect escaped production host: {requested} -> {final_url}")
    page = Page(requested, final_url, status, body)
    if "html" not in content_type.lower():
        return page
    parser = VisitorParser()
    parser.feed(body)
    parser.close()
    page.lang = parser.lang
    page.viewport = parser.viewport
    page.text = re.sub(r"\s+", " ", " ".join(parser.text_parts)).strip()
    page.h1 = parser.h1
    page.headings = parser.headings
    page.links = parser.links
    page.buttons = parser.buttons
    page.image_alt_presence = parser.image_alt_presence
    page.main_ids = parser.main_ids
    page.footer_count = parser.footer_count
    page.json_ld = parser.json_ld
    page.legal_sections = parser.legal_sections
    return page


def schema_nodes(value: object) -> List[dict]:
    if isinstance(value, list):
        out: List[dict] = []
        for item in value:
            out.extend(schema_nodes(item))
        return out
    if isinstance(value, dict):
        graph = value.get("@graph")
        if isinstance(graph, list):
            return schema_nodes(graph)
        return [value]
    return []


def assert_clean(text: str, label: str, patterns: Tuple[str, ...] = FORBIDDEN_VISITOR_PATTERNS) -> None:
    low = text.lower()
    for pattern in patterns:
        if re.search(pattern, low, re.IGNORECASE):
            raise AssertionError(f"{label}: visitor-facing forbidden term matched {pattern!r}")


def audit_page(language: str, path: str) -> Page:
    page = fetch(path)
    print(f"PAGE {path} -> {page.status} final={page.final_url}")
    if page.status != 200:
        raise AssertionError(f"{path}: expected 200, got {page.status}")
    if page.lang != language:
        raise AssertionError(f"{path}: html lang={page.lang!r}, expected {language!r}")
    if "width=device-width" not in page.viewport:
        raise AssertionError(f"{path}: missing responsive viewport meta")
    if page.main_ids.count("main") != 1:
        raise AssertionError(f"{path}: expected one <main id=main>, got {page.main_ids}")
    if len(page.h1) != 1 or not page.h1[0]:
        raise AssertionError(f"{path}: expected exactly one non-empty H1, got {page.h1}")
    if page.footer_count != 1:
        raise AssertionError(f"{path}: expected one footer, got {page.footer_count}")
    if not any("screen-reader-text" in link.classes and link.href == "#main" for link in page.links):
        raise AssertionError(f"{path}: missing skip link to #main")
    if page.image_alt_presence and not all(page.image_alt_presence):
        raise AssertionError(f"{path}: one or more images omit the alt attribute")
    for link in page.links:
        if link.href and not link.href.startswith(("#", "mailto:", "tel:", "javascript:")) and not link.accessible_name:
            raise AssertionError(f"{path}: link without accessible name: {link.href}")
    for index, label in enumerate(page.buttons, start=1):
        if not label:
            raise AssertionError(f"{path}: button #{index} has no visible label")
    assert_clean(page.text, path)
    cta_text = " ".join([link.accessible_name for link in page.links] + page.buttons)
    assert_clean(cta_text, f"{path} CTAs")

    footer_paths = {path_of(absolute(link.href, page.final_url)) for link in page.links if link.in_footer and link.href}
    missing_footer = FOOTER_LINKS[language] - footer_paths
    if missing_footer:
        raise AssertionError(f"{path}: footer missing {sorted(missing_footer)}")

    if path not in {"/", "/es/"}:
        breadcrumbs = [node for payload in page.json_ld for node in schema_nodes(payload) if node.get("@type") == "BreadcrumbList"]
        if not breadcrumbs:
            raise AssertionError(f"{path}: missing BreadcrumbList schema")
        items = breadcrumbs[0].get("itemListElement", [])
        if not isinstance(items, list) or len(items) < 2:
            raise AssertionError(f"{path}: BreadcrumbList has fewer than two items")
        last = items[-1] if isinstance(items[-1], dict) else {}
        if path_of(str(last.get("item", ""))) != path:
            raise AssertionError(f"{path}: breadcrumb final item does not point to current surface")
    return page


def audit_legal(page: Page, path: str) -> None:
    label, concepts = LEGAL_PATHS[path]
    if len(page.legal_sections) < 2:
        raise AssertionError(f"{path}: expected at least two substantive legal sections, got {len(page.legal_sections)}")
    for index, section in enumerate(page.legal_sections, start=1):
        if len(section) < 45:
            raise AssertionError(f"{path}: legal section {index} is too thin ({len(section)} chars)")
        assert_clean(section, f"{path} legal section {index}", PLACEHOLDER_PATTERNS + FORBIDDEN_VISITOR_PATTERNS)
    low = page.text.lower()
    for concept in concepts:
        if concept not in low:
            raise AssertionError(f"{path}: expected legal concept {concept!r} not found")
    print(f"  LEGAL {label}: {len(page.legal_sections)} substantive sections")


def audit_404(language: str, path: str, expected_title: str) -> None:
    page = fetch(path)
    print(f"404 {path} -> {page.status} lang={page.lang} h1={page.h1}")
    if page.status != 404:
        raise AssertionError(f"{path}: expected 404, got {page.status}")
    if page.lang != language:
        raise AssertionError(f"{path}: 404 language {page.lang!r}, expected {language!r}")
    if page.h1 != [expected_title]:
        raise AssertionError(f"{path}: unexpected 404 H1 {page.h1}")
    assert_clean(page.text, f"{path} 404")
    destinations = {path_of(absolute(link.href, page.final_url)) for link in page.links if link.href}
    expected = {"/", "/research/"} if language == "en" else {"/es/", "/es/investigacion/"}
    if not expected.issubset(destinations):
        raise AssertionError(f"{path}: 404 missing recovery links {sorted(expected - destinations)}")


def self_test() -> int:
    fixture = """<!doctype html><html lang='en'><head><meta name='viewport' content='width=device-width, initial-scale=1'><script type='application/ld+json'>{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":1,"name":"Home","item":"https://eduardoyauriluna.com/"},{"@type":"ListItem","position":2,"name":"About","item":"https://eduardoyauriluna.com/about/"}]}</script></head><body><a class='screen-reader-text' href='#main'>Skip</a><main id='main'><h1>About</h1><img src='x' alt=''></main><footer><a href='/privacy-policy/'>Privacy</a><a href='/legal-notice/'>Legal notice</a></footer></body></html>"""
    parser = VisitorParser(); parser.feed(fixture); parser.close()
    assert parser.lang == "en"
    assert "width=device-width" in parser.viewport
    assert parser.h1 == ["About"]
    assert parser.main_ids == ["main"]
    assert parser.footer_count == 1
    assert parser.image_alt_presence == [True]
    assert any(link.href == "#main" for link in parser.links)
    assert any(node.get("@type") == "BreadcrumbList" for payload in parser.json_ld for node in schema_nodes(payload))
    print("PASS: Phase 6 visitor-audit parser self-test")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    if args.self_test:
        return self_test()

    print("Phase 6 production visitor-surface audit")
    pages: Dict[str, Page] = {}
    for language, path in CORE:
        pages[path] = audit_page(language, path)
    for path in LEGAL_PATHS:
        audit_legal(pages[path], path)
    audit_404("en", "/phase6-not-found-acceptance/", "Page not found")
    audit_404("es", "/es/phase6-not-found-acceptance/", "Página no encontrada")
    print("PASS: legal/utility surfaces, breadcrumbs, footer, CTAs and accessibility basics are production-ready")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (AssertionError, RuntimeError) as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        raise SystemExit(1)
