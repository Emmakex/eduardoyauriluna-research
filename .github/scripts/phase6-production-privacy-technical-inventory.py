#!/usr/bin/env python3
"""Observe public privacy-relevant technical signals; never infer legal processing facts.

Anonymous GET only. Records hostnames, form methods/action hosts and cookie NAMES;
does not submit forms, store response bodies, inspect account data or collect cookie values.
"""
from __future__ import annotations

import argparse
import json
import re
from collections import defaultdict
from html.parser import HTMLParser
from http.client import HTTPMessage
from urllib.parse import urljoin, urlsplit
from urllib.request import Request, urlopen

BASE = "https://eduardoyauriluna.com"
SITE_HOST = "eduardoyauriluna.com"
USER_AGENT = "Site-Architect-Phase6-Privacy-Observation/1.0"
PATHS = (
    "/", "/es/", "/about/", "/es/perfil/", "/research/", "/es/investigacion/",
    "/cv/", "/es/cv/", "/insights/", "/es/notas/", "/contact/",
    "/es/contacto/", "/privacy-policy/", "/es/privacidad/", "/legal-notice/",
    "/es/aviso-legal/",
)
ASSET_ATTRS = {"script": "src", "iframe": "src", "img": "src", "link": "href"}
LINK_RELS = {"stylesheet", "preload", "preconnect", "dns-prefetch", "modulepreload"}
MAX_BODY_BYTES = 2_000_000


def host_of(base: str, value: str) -> str | None:
    if not value or value.startswith(("#", "data:", "blob:", "javascript:", "mailto:", "tel:")):
        return None
    parts = urlsplit(urljoin(base, value))
    if parts.scheme not in {"http", "https"}:
        return None
    return (parts.hostname or "").lower() or None


def cookie_names(headers: HTTPMessage) -> list[str]:
    names = []
    for value in headers.get_all("Set-Cookie", []):
        name = value.partition("=")[0].strip()
        if re.fullmatch(r"[A-Za-z0-9_.-]{1,80}", name):
            names.append(name)
    return sorted(set(names))


class TechnicalParser(HTMLParser):
    def __init__(self, page_url: str) -> None:
        super().__init__(convert_charrefs=True)
        self.page_url = page_url
        self.third_party: dict[str, set[str]] = defaultdict(set)
        self.forms: list[dict[str, str]] = []
        self.collection_risks: set[str] = set()
        self._in_form = False

    def handle_starttag(self, tag: str, attributes: list[tuple[str, str | None]]) -> None:
        tag = tag.lower()
        attrs = {str(k).lower(): str(v or "") for k, v in attributes}
        if tag == "form":
            action_host = host_of(self.page_url, attrs.get("action", "")) or SITE_HOST
            method = (attrs.get("method") or "get").strip().lower()[:12]
            self.forms.append({
                "method": method,
                "action_host": "same-site" if action_host == SITE_HOST else action_host,
            })
            self._in_form = True
            if method != "get":
                self.collection_risks.add("non-get-form")
            if action_host != SITE_HOST:
                self.collection_risks.add("external-form-endpoint")
        if tag == "textarea":
            self.collection_risks.add("free-text-submission-field")
        if tag == "input":
            input_type = attrs.get("type", "text").strip().lower()
            field_name = attrs.get("name", "").strip().lower()
            if input_type in {"email", "tel", "password", "file"}:
                self.collection_risks.add("personal-data-input-type")
            if self._in_form and input_type in {"text", "search"}:
                # The only intended direct entry on the academic site is search.
                if field_name not in {"s", "q", "search", "search_query", "query"}:
                    # The Theme's research Insights EN/ES use this exact scoped
                    # GET parameter. Keep it limited to those two canonical routes.
                    insights_url = urlsplit(self.page_url).path.rstrip("/")
                    is_insight_search = field_name == "insight_search" and insights_url in {
                        "/insights", "/es/notas"
                    }
                    if not is_insight_search:
                        self.collection_risks.add("non-search-entry-field")
            if self._in_form and input_type in {"number", "date", "datetime-local"}:
                self.collection_risks.add("structured-data-entry-field")
        if tag not in ASSET_ATTRS:
            return
        if tag == "link" and not (set(attrs.get("rel", "").lower().split()) & LINK_RELS):
            return
        host = host_of(self.page_url, attrs.get(ASSET_ATTRS[tag], ""))
        if host and host != SITE_HOST:
            self.third_party[tag].add(host)

    def handle_endtag(self, tag: str) -> None:
        if tag.lower() == "form":
            self._in_form = False

    def report(self) -> dict:
        return {
            "forms": self.forms,
            "collection_risks": sorted(self.collection_risks),
            "external_hosts": {
                kind: sorted(self.third_party.get(kind, set()))
                for kind in ("script", "iframe", "img", "link")
            },
        }


def inspect(path: str) -> dict:
    url = BASE + path
    req = Request(url, headers={"User-Agent": USER_AGENT, "Accept": "text/html"})
    with urlopen(req, timeout=20) as response:
        final_url = response.geturl()
        if host_of(url, final_url) != SITE_HOST:
            raise RuntimeError("unexpected off-site redirect")
        if response.status != 200:
            raise RuntimeError("public page did not return HTTP 200")
        if "text/html" not in response.headers.get("Content-Type", "").lower():
            raise RuntimeError("public page did not return HTML")
        data = response.read(MAX_BODY_BYTES + 1)
        if len(data) > MAX_BODY_BYTES:
            raise RuntimeError("public HTML exceeds bounded observation size")
        cookies = cookie_names(response.headers)
        html = data.decode(response.headers.get_content_charset() or "utf-8", "replace")
    parser = TechnicalParser(final_url)
    parser.feed(html)
    parser.close()
    return {"path": path, "status": 200, "set_cookie_names": cookies, **parser.report()}


def self_test() -> None:
    parser = TechnicalParser(BASE + "/contact/")
    parser.feed(
        '<form action="/wp-json/example" method="post"><input name="private" value="NOT-LOGGED"></form>'
        '<script src="https://cdn.example.org/a.js"></script>'
        '<img src="https://cdn.example.org/pixel.png">'
        '<iframe src="https://video.example.net/embed"></iframe>'
        '<link rel="preconnect" href="https://cdn.example.org">'
    )
    result = parser.report()
    assert result["forms"] == [{"method": "post", "action_host": "same-site"}]
    assert result["collection_risks"] == ["non-get-form", "non-search-entry-field"]
    search = TechnicalParser(BASE + "/insights/")
    search.feed('<form method="GET" action="/"><input name="s" type="search"></form>')
    assert search.report()["collection_risks"] == []
    assert search.report()["forms"] == [{"method": "get", "action_host": "same-site"}]
    # Exact Theme field name (inc/research-insights.php), both locales.
    for route in ("/insights/", "/es/notas/"):
        exact = TechnicalParser(BASE + route)
        exact.feed('<form method="get" action="' + route + '"><input type="search" name="insight_search"><select name="insight_type"><option value="">All</option></select></form>')
        assert exact.report()["collection_risks"] == [], route
    # Do not silently allow this non-generic parameter on unrelated surfaces.
    unrelated = TechnicalParser(BASE + "/contact/")
    unrelated.feed('<form method="get"><input type="search" name="insight_search"></form>')
    assert unrelated.report()["collection_risks"] == ["non-search-entry-field"]
    lead = TechnicalParser(BASE + "/contact/")
    lead.feed('<form method="post" action="/submit"><input type="email" name="email" value="SECRET"><textarea name="message">PRIVATE</textarea></form>')
    assert lead.report()["collection_risks"] == [
        "free-text-submission-field", "non-get-form", "personal-data-input-type"
    ]
    assert "SECRET" not in json.dumps(lead.report())
    assert "PRIVATE" not in json.dumps(lead.report())
    assert result["external_hosts"]["script"] == ["cdn.example.org"]
    assert result["external_hosts"]["iframe"] == ["video.example.net"]
    assert "NOT-LOGGED" not in json.dumps(result)
    assert host_of(BASE, "mailto:someone@example.org") is None
    headers = HTTPMessage()
    headers.add_header("Set-Cookie", "session=DO-NOT-LOG; HttpOnly; Secure")
    assert cookie_names(headers) == ["session"]
    print("PASS: bounded technical privacy observation self-test")


def main() -> None:
    args = argparse.ArgumentParser()
    args.add_argument("--self-test", action="store_true")
    options = args.parse_args()
    if options.self_test:
        self_test()
        return
    pages = [inspect(path) for path in PATHS]
    grouped: dict[str, set[str]] = {name: set() for name in ("script", "iframe", "img", "link")}
    cookies: set[str] = set()
    for page in pages:
        cookies.update(page["set_cookie_names"])
        for name, hosts in page["external_hosts"].items():
            grouped[name].update(hosts)
    risks = {name for page in pages for name in page["collection_risks"]}
    result = {
        "status": "OBSERVED_ONLY",
        "site": BASE,
        "pages_checked": len(pages),
        "anonymous_forms_seen": sum(len(page["forms"]) for page in pages),
        "personal_data_collection_form_risks": sorted(risks),
        "no_direct_collection_forms_observed": not risks,
        "cookie_names_seen": sorted(cookies),
        "external_hosts_seen": {name: sorted(hosts) for name, hosts in grouped.items()},
        "pages": pages,
        "limitations": (
            "Anonymous GET snapshot only; no form submissions or logged-in behavior.",
            "Dynamically loaded scripts, browser storage and server-side processors are not fully observable here.",
            "Absence of an observed signal does not establish absence of personal-data processing.",
        ),
    }
    print(json.dumps(result, indent=2, ensure_ascii=False))
    # Enforce the user-confirmed "no lead/contact forms" public contract.
    # A technical visit can still create server logs or voluntary email messages.
    if risks:
        raise RuntimeError("Unexpected public personal-data collection form on academic website")


if __name__ == "__main__":
    main()
