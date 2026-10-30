#!/usr/bin/env python3
"""Read-only production SEO/GEO audit for eduardoyauriluna.com.

The audit deliberately observes rendered public HTML. It does not call WordPress
write APIs and does not mutate Theme/Manager state.
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
import xml.etree.ElementTree as ET
from dataclasses import dataclass, field
from html.parser import HTMLParser
from typing import Dict, Iterable, List, Optional, Set, Tuple

BASE_URL = "https://eduardoyauriluna.com"
CANONICAL_NAME = "Eduardo Jose Yauri Luna"
USER_AGENT = "EduardoResearch-Phase4-SEO-Audit/1.0"
TIMEOUT = 30

CORE_PAIRS: Tuple[Tuple[str, str, str], ...] = (
    ("home", "/", "/es/"),
    ("about", "/about/", "/es/perfil/"),
    ("research", "/research/", "/es/investigacion/"),
    ("cv", "/cv/", "/es/cv/"),
    ("insights", "/insights/", "/es/notas/"),
    ("contact", "/contact/", "/es/contacto/"),
    ("privacy-policy", "/privacy-policy/", "/es/privacidad/"),
    ("legal-notice", "/legal-notice/", "/es/aviso-legal/"),
)

PRIMARY_BY_LANGUAGE = {
    "en": {"/about/", "/research/", "/cv/", "/insights/", "/contact/"},
    "es": {"/es/perfil/", "/es/investigacion/", "/es/cv/", "/es/notas/", "/es/contacto/"},
}

EMPTY_COLLECTION_PATHS = {
    "/publications/", "/projects/", "/software/", "/datasets/",
    "/en/publications/", "/en/projects/", "/en/software/", "/en/datasets/",
    "/es/publicaciones/", "/es/proyectos/", "/es/software/", "/es/datos/",
}

FORBIDDEN_METADATA_TERMS = (
    "manager", "mcp", "gateway", "ci/cd", "tenant", "acceptance fixture",
    "acceptance test", "implementation test", "verification lifecycle",
)


@dataclass
class PageData:
    requested_url: str
    final_url: str
    status: int
    language: str = ""
    title: str = ""
    metas: Dict[str, List[str]] = field(default_factory=dict)
    canonical: List[str] = field(default_factory=list)
    alternates: Dict[str, List[str]] = field(default_factory=dict)
    hrefs: Set[str] = field(default_factory=set)
    json_ld: List[object] = field(default_factory=list)
    raw_json_ld: List[str] = field(default_factory=list)


class DocumentParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.language = ""
        self.title_parts: List[str] = []
        self.in_title = False
        self.in_json_ld = False
        self.json_ld_parts: List[str] = []
        self.json_ld_blocks: List[str] = []
        self.metas: Dict[str, List[str]] = {}
        self.canonical: List[str] = []
        self.alternates: Dict[str, List[str]] = {}
        self.hrefs: Set[str] = set()

    @staticmethod
    def attrs_dict(attrs: Iterable[Tuple[str, Optional[str]]]) -> Dict[str, str]:
        return {str(k).lower(): str(v or "") for k, v in attrs}

    def handle_starttag(self, tag: str, attrs: List[Tuple[str, Optional[str]]]) -> None:
        tag = tag.lower()
        data = self.attrs_dict(attrs)
        if tag == "html":
            self.language = data.get("lang", "").strip()
        elif tag == "title":
            self.in_title = True
        elif tag == "meta":
            key = (data.get("name") or data.get("property") or "").strip().lower()
            value = data.get("content", "").strip()
            if key and value:
                self.metas.setdefault(key, []).append(value)
        elif tag == "link":
            rel = {part.lower() for part in data.get("rel", "").split() if part}
            href = data.get("href", "").strip()
            if "canonical" in rel and href:
                self.canonical.append(href)
            if "alternate" in rel and href and data.get("hreflang"):
                code = data["hreflang"].strip().lower()
                self.alternates.setdefault(code, []).append(href)
        elif tag == "a":
            href = data.get("href", "").strip()
            if href:
                self.hrefs.add(href)
        elif tag == "script" and data.get("type", "").lower() == "application/ld+json":
            self.in_json_ld = True
            self.json_ld_parts = []

    def handle_endtag(self, tag: str) -> None:
        tag = tag.lower()
        if tag == "title":
            self.in_title = False
        elif tag == "script" and self.in_json_ld:
            self.in_json_ld = False
            raw = "".join(self.json_ld_parts).strip()
            if raw:
                self.json_ld_blocks.append(raw)
            self.json_ld_parts = []

    def handle_data(self, data: str) -> None:
        if self.in_title:
            self.title_parts.append(data)
        if self.in_json_ld:
            self.json_ld_parts.append(data)


def normalize_url(url: str, base: str = BASE_URL) -> str:
    absolute = urllib.parse.urljoin(base + "/", html.unescape(url.strip()))
    parsed = urllib.parse.urlsplit(absolute)
    path = parsed.path or "/"
    return urllib.parse.urlunsplit((parsed.scheme.lower(), parsed.netloc.lower(), path, parsed.query, ""))


def same_host(url: str) -> bool:
    return urllib.parse.urlsplit(url).netloc.lower() == urllib.parse.urlsplit(BASE_URL).netloc.lower()


def request(url: str) -> Tuple[int, str, str, str]:
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Accept": "text/html,application/xml;q=0.9,*/*;q=0.8"})
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as response:
            status = int(response.getcode() or 0)
            final_url = str(response.geturl())
            content_type = str(response.headers.get("Content-Type", ""))
            charset = response.headers.get_content_charset() or "utf-8"
            body = response.read().decode(charset, errors="replace")
            return status, final_url, content_type, body
    except urllib.error.HTTPError as exc:
        charset = exc.headers.get_content_charset() if exc.headers else None
        body = exc.read().decode(charset or "utf-8", errors="replace")
        return int(exc.code), str(exc.geturl()), str(exc.headers.get("Content-Type", "") if exc.headers else ""), body


def fetch_page(path_or_url: str) -> PageData:
    requested = normalize_url(path_or_url)
    status, final_url, content_type, body = request(requested)
    final_url = normalize_url(final_url)
    if not same_host(final_url):
        raise RuntimeError(f"redirect escaped production host: {requested} -> {final_url}")
    page = PageData(requested_url=requested, final_url=final_url, status=status)
    if status != 200 or "html" not in content_type.lower():
        return page
    parser = DocumentParser()
    parser.feed(body)
    parser.close()
    page.language = parser.language
    page.title = re.sub(r"\s+", " ", "".join(parser.title_parts)).strip()
    page.metas = parser.metas
    page.canonical = [normalize_url(v, final_url) for v in parser.canonical]
    page.alternates = {k: [normalize_url(v, final_url) for v in values] for k, values in parser.alternates.items()}
    page.hrefs = {normalize_url(v, final_url) for v in parser.hrefs if same_host(normalize_url(v, final_url))}
    page.raw_json_ld = parser.json_ld_blocks
    for raw in parser.json_ld_blocks:
        try:
            page.json_ld.append(json.loads(raw))
        except json.JSONDecodeError:
            page.json_ld.append({"__invalid_json_ld__": raw[:200]})
    return page


def flatten_schema(value: object) -> List[dict]:
    nodes: List[dict] = []
    if isinstance(value, list):
        for item in value:
            nodes.extend(flatten_schema(item))
    elif isinstance(value, dict):
        graph = value.get("@graph")
        if isinstance(graph, list):
            nodes.extend(flatten_schema(graph))
        else:
            nodes.append(value)
    return nodes


def meta_one(page: PageData, key: str) -> str:
    values = page.metas.get(key.lower(), [])
    return values[0].strip() if values else ""


def path_of(url: str) -> str:
    return urllib.parse.urlsplit(url).path or "/"


def sitemap_inventory() -> Tuple[Set[str], Dict[str, Set[str]]]:
    pending = [BASE_URL + "/wp-sitemap.xml"]
    visited: Set[str] = set()
    urls: Set[str] = set()
    by_source: Dict[str, Set[str]] = {}
    while pending:
        sitemap = normalize_url(pending.pop(0))
        if sitemap in visited:
            continue
        visited.add(sitemap)
        status, final_url, _content_type, body = request(sitemap)
        if status != 200:
            raise RuntimeError(f"sitemap request failed: {sitemap} -> {status}")
        if not same_host(final_url):
            raise RuntimeError(f"sitemap redirect escaped host: {sitemap} -> {final_url}")
        try:
            root = ET.fromstring(body)
        except ET.ParseError as exc:
            raise RuntimeError(f"invalid sitemap XML at {sitemap}: {exc}") from exc
        local_name = root.tag.rsplit("}", 1)[-1].lower()
        locs = [normalize_url((node.text or "").strip()) for node in root.iter() if node.tag.rsplit("}", 1)[-1].lower() == "loc" and (node.text or "").strip()]
        if local_name == "sitemapindex":
            pending.extend([u for u in locs if same_host(u)])
        elif local_name == "urlset":
            same_host_urls = {u for u in locs if same_host(u)}
            by_source[sitemap] = same_host_urls
            urls.update(same_host_urls)
        else:
            raise RuntimeError(f"unexpected sitemap root {root.tag} at {sitemap}")
    return urls, by_source


def self_test() -> int:
    fixture = """<!doctype html><html lang='en'><head><title>Research | Eduardo Jose Yauri Luna</title>
    <link rel='canonical' href='https://eduardoyauriluna.com/research/'>
    <link rel='alternate' hreflang='en' href='https://eduardoyauriluna.com/research/'>
    <link rel='alternate' hreflang='es' href='https://eduardoyauriluna.com/es/investigacion/'>
    <link rel='alternate' hreflang='x-default' href='https://eduardoyauriluna.com/research/'>
    <meta name='description' content='Research profile'>
    <meta property='og:title' content='Research | Eduardo Jose Yauri Luna'>
    <script type='application/ld+json'>{"@context":"https://schema.org","@graph":[{"@type":"Person","name":"Eduardo Jose Yauri Luna"},{"@type":"WebPage"}]}</script>
    </head><body><a href='/about/'>About</a></body></html>"""
    parser = DocumentParser()
    parser.feed(fixture)
    parser.close()
    assert parser.language == "en"
    assert "Eduardo Jose Yauri Luna" in "".join(parser.title_parts)
    assert parser.canonical == ["https://eduardoyauriluna.com/research/"]
    assert set(parser.alternates) == {"en", "es", "x-default"}
    payload = json.loads(parser.json_ld_blocks[0])
    assert any(node.get("@type") == "Person" for node in flatten_schema(payload))
    print("PASS: Phase 4 production SEO audit parser contract")
    return 0


def add_finding(findings: List[str], message: str) -> None:
    findings.append(message)
    print(f"FINDING: {message}")


def audit_page(page: PageData, expected_language: str, expected_url: str, findings: List[str]) -> None:
    expected_url = normalize_url(expected_url)
    print(f"PAGE {page.requested_url} -> {page.status} final={page.final_url}")
    if page.status != 200:
        add_finding(findings, f"{page.requested_url} expected 200, got {page.status}")
        return
    if page.final_url != expected_url:
        add_finding(findings, f"{page.requested_url} final URL {page.final_url} != expected {expected_url}")
    if not page.language.lower().startswith(expected_language):
        add_finding(findings, f"{page.final_url} html lang={page.language!r}, expected {expected_language}")
    if not page.title:
        add_finding(findings, f"{page.final_url} missing title")
    elif CANONICAL_NAME.lower() not in page.title.lower():
        add_finding(findings, f"{page.final_url} title does not contain canonical researcher name: {page.title!r}")
    description = meta_one(page, "description")
    if not description:
        add_finding(findings, f"{page.final_url} missing meta description")
    if len(page.canonical) != 1:
        add_finding(findings, f"{page.final_url} expected exactly one canonical, found {page.canonical}")
    elif page.canonical[0] != expected_url:
        add_finding(findings, f"{page.final_url} canonical {page.canonical[0]} != {expected_url}")
    robots = " ".join(page.metas.get("robots", [])).lower()
    if "noindex" in robots:
        add_finding(findings, f"{page.final_url} is noindex")
    for key in ("og:title", "og:url", "og:description", "twitter:title", "twitter:description"):
        if not meta_one(page, key):
            add_finding(findings, f"{page.final_url} missing {key}")
    og_url = meta_one(page, "og:url")
    if og_url and normalize_url(og_url, page.final_url) != expected_url:
        add_finding(findings, f"{page.final_url} og:url {og_url} != {expected_url}")
    metadata_text = "\n".join([page.title, description] + [v for values in page.metas.values() for v in values] + page.raw_json_ld).lower()
    for term in FORBIDDEN_METADATA_TERMS:
        if term in metadata_text:
            add_finding(findings, f"{page.final_url} machine-readable metadata contains forbidden visitor-facing term {term!r}")
    nodes = [node for payload in page.json_ld for node in flatten_schema(payload)]
    if any("__invalid_json_ld__" in node for node in nodes):
        add_finding(findings, f"{page.final_url} contains invalid JSON-LD")
    people = [node for node in nodes if node.get("@type") == "Person"]
    if not people:
        add_finding(findings, f"{page.final_url} missing Person schema")
    elif not any(str(node.get("name", "")).strip() == CANONICAL_NAME for node in people):
        add_finding(findings, f"{page.final_url} Person schema does not use canonical name {CANONICAL_NAME!r}")
    if not any(node.get("@type") == "WebSite" for node in nodes):
        add_finding(findings, f"{page.final_url} missing WebSite schema")
    if not any(node.get("@type") in {"WebPage", "ProfilePage", "CollectionPage"} for node in nodes):
        add_finding(findings, f"{page.final_url} missing page-level schema")
    same_as: List[str] = []
    for person in people:
        value = person.get("sameAs", [])
        if isinstance(value, str):
            same_as.append(value)
        elif isinstance(value, list):
            same_as.extend(str(v) for v in value)
    print(f"  title={page.title!r}")
    print(f"  description={description!r}")
    print(f"  canonical={page.canonical}")
    print(f"  alternates={page.alternates}")
    print(f"  Person.sameAs={sorted(set(same_as))}")


def audit_pair(key: str, en_path: str, es_path: str, pages: Dict[str, PageData], findings: List[str]) -> None:
    en_url = normalize_url(en_path)
    es_url = normalize_url(es_path)
    for language, url, other_language, other_url in (("en", en_url, "es", es_url), ("es", es_url, "en", en_url)):
        page = pages[url]
        own = page.alternates.get(language, [])
        other = page.alternates.get(other_language, [])
        default = page.alternates.get("x-default", [])
        if own != [url]:
            add_finding(findings, f"{key}/{language} hreflang self is {own}, expected {[url]}")
        if other != [other_url]:
            add_finding(findings, f"{key}/{language} hreflang {other_language} is {other}, expected {[other_url]}")
        if default != [en_url]:
            add_finding(findings, f"{key}/{language} x-default is {default}, expected {[en_url]}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    if args.self_test:
        return self_test()

    findings: List[str] = []
    pages: Dict[str, PageData] = {}

    print("Phase 4 production SEO/GEO audit")
    print(f"Target: {BASE_URL}")
    print("\nCore localized surfaces:")
    for key, en_path, es_path in CORE_PAIRS:
        for language, path in (("en", en_path), ("es", es_path)):
            url = normalize_url(path)
            page = fetch_page(url)
            pages[url] = page
            audit_page(page, language, url, findings)
    print("\nReciprocal hreflang:")
    for key, en_path, es_path in CORE_PAIRS:
        audit_pair(key, en_path, es_path, pages, findings)

    print("\nPrimary internal linking:")
    for language in ("en", "es"):
        expected_paths = PRIMARY_BY_LANGUAGE[language]
        for _key, en_path, es_path in CORE_PAIRS[:6]:
            path = en_path if language == "en" else es_path
            page = pages[normalize_url(path)]
            linked_paths = {path_of(url) for url in page.hrefs}
            missing = sorted(expected_paths - linked_paths)
            print(f"  {path_of(page.final_url)} -> primary links {sorted(expected_paths & linked_paths)}")
            if missing:
                add_finding(findings, f"{page.final_url} missing primary internal links: {missing}")

    print("\nSitemap inventory:")
    try:
        sitemap_urls, by_source = sitemap_inventory()
    except RuntimeError as exc:
        add_finding(findings, str(exc))
        sitemap_urls, by_source = set(), {}
    print(f"  discovered {len(sitemap_urls)} same-host public URLs across {len(by_source)} URL sitemaps")
    sitemap_paths = {path_of(url) for url in sitemap_urls}
    for key, en_path, es_path in CORE_PAIRS:
        for path in (en_path, es_path):
            if path not in sitemap_paths:
                add_finding(findings, f"core public route {path} missing from sitemap inventory")
    leaked_empty = sorted(EMPTY_COLLECTION_PATHS & sitemap_paths)
    if leaked_empty:
        add_finding(findings, f"empty collection routes leaked into sitemap: {leaked_empty}")
    utility = sorted(path for path in sitemap_paths if path.startswith("/author/") or path.startswith("/search/"))
    if utility:
        add_finding(findings, f"low-value utility routes exposed in sitemap: {utility}")

    print("\nResearch Notes / post sitemap schema:")
    post_urls: Set[str] = set()
    for source, urls in by_source.items():
        if "wp-sitemap-posts-post-" in source:
            post_urls.update(urls)
    for url in sorted(post_urls):
        page = fetch_page(url)
        nodes = [node for payload in page.json_ld for node in flatten_schema(payload)]
        types = sorted({str(node.get("@type", "")) for node in nodes if node.get("@type")})
        print(f"  {url} -> {page.status} schema={types}")
        if page.status != 200:
            add_finding(findings, f"Research Note sitemap URL {url} returned {page.status}")
            continue
        if not any(node.get("@type") in {"Article", "BlogPosting"} for node in nodes):
            add_finding(findings, f"Research Note {url} missing Article/BlogPosting schema")

    print("\nSummary:")
    if findings:
        print(f"FAIL: {len(findings)} Phase 4 production finding(s)")
        for index, finding in enumerate(findings, 1):
            print(f"  {index}. {finding}")
        return 1
    print("PASS: core EN/ES SEO metadata, reciprocal hreflang, schema, sitemap and primary internal linking are production-ready")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:  # fail closed with a compact diagnostic
        print(f"ERROR: {exc}", file=sys.stderr)
        raise
