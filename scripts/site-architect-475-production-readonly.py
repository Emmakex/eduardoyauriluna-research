#!/usr/bin/env python3
"""#475.21: read-only, strictly host-pinned production/clone HTTP contract inventory.

No credentials, cookies, POST, WordPress login, wp-admin, or mutation.
Produces machine-readable public URL status and metadata booleans, not HTML.
The production HTML baseline is evidence of the public surface ONLY, not
proof of installed Plugin version, plugin authority, private Media or DB parity.
"""
from __future__ import annotations

import argparse
import datetime as dt
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import ssl
import time
import urllib.error
import urllib.parse
import urllib.request

HOST = "eduardoyauriluna.com"
BASE = "https://" + HOST
MAX_HTML_BYTES = 1_500_000
VALID_SEGMENT = re.compile(r"^[a-z0-9][a-z0-9-]*$")


class SafeRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        parsed = urllib.parse.urlsplit(newurl)
        if parsed.scheme != "https" or parsed.hostname != HOST or parsed.port not in (None, 443):
            raise urllib.error.HTTPError(req.full_url, 403, "Cross-host redirect denied", headers, fp)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class HTMLProbe(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.lang = ""
        self.canonical = ""
        self.alternates = {}
        self.og_url = ""
        self.jsonld_scripts = []
        self._script_data = None
        self.header = False
        self.primary_nav = False
        self.footer_nav = False
        self.theme_stylesheet = False

    def handle_starttag(self, tag, attrs):
        props = dict(attrs)
        classes = str(props.get("class") or "").split()
        if tag == "html":
            self.lang = str(props.get("lang") or "").split("-")[0].lower()
        if tag == "link":
            rel = str(props.get("rel") or "").lower()
            href = str(props.get("href") or "")
            if "canonical" in rel.split():
                self.canonical = href
            if "alternate" in rel.split() and props.get("hreflang"):
                self.alternates[str(props["hreflang"]).lower()] = href
            if "stylesheet" in rel.split() and "/wp-content/themes/eduardo-research/style.css" in href:
                self.theme_stylesheet = True
        if tag == "meta" and props.get("property") == "og:url":
            self.og_url = str(props.get("content") or "")
        if tag == "script" and str(props.get("type") or "").lower() == "application/ld+json":
            self._script_data = []
        if tag == "header" and "research-site-header" in classes:
            self.header = True
        if tag == "nav" and props.get("id") == "research-primary-nav":
            self.primary_nav = True
        if tag == "nav" and "research-footer-nav" in classes:
            self.footer_nav = True

    def handle_data(self, data):
        if self._script_data is not None:
            self._script_data.append(data)

    def handle_endtag(self, tag):
        if tag == "script" and self._script_data is not None:
            self.jsonld_scripts.append("".join(self._script_data))
            self._script_data = None


def safe_segment(value: str) -> str:
    if not isinstance(value, str) or not VALID_SEGMENT.fullmatch(value):
        raise ValueError("Unsafe or invalid Theme route segment")
    return value


def manifest_routes(pages: dict) -> list[dict]:
    if not isinstance(pages, dict) or len(pages) != 12:
        raise ValueError("Research Theme must define exactly 12 owned page contracts")
    routes = []
    for key, page in pages.items():
        safe_segment(key)
        if not isinstance(page, dict):
            raise ValueError("Invalid Theme page specification")
        role = page.get("role", "")
        if key == "home":
            language_paths = {"en": "/", "es": "/es/"}
        else:
            en = safe_segment(page.get("wp_slug") or page.get("slug") or "")
            es = safe_segment((page.get("slugs") or {}).get("es") or "")
            language_paths = {"en": "/" + en + "/", "es": "/es/" + es + "/"}
        for lang, path in language_paths.items():
            url = BASE + path
            routes.append({
                "id": key + "/" + lang,
                "key": key,
                "language": lang,
                "role": str(role),
                "url": url,
            })
    if len({r["url"] for r in routes}) != 24:
        raise ValueError("Theme produced duplicate or missing EN/ES public routes")
    return routes


def normalized_url(url: str) -> str:
    return url.rstrip("/")


def inspect_html(body: bytes, url: str, language: str, key: str) -> dict:
    raw = body.decode("utf-8", errors="replace")
    parser = HTMLProbe()
    parser.feed(raw)
    parsed_json = []
    for script in parser.jsonld_scripts:
        try:
            parsed_json.append(json.loads(script))
        except (json.JSONDecodeError, TypeError):
            continue
    schema_types = set()
    for obj in parsed_json:
        nodes = obj.get("@graph", [obj]) if isinstance(obj, dict) else (obj if isinstance(obj, list) else [])
        for node in nodes:
            if isinstance(node, dict):
                kinds = node.get("@type", [])
                if isinstance(kinds, str):
                    kinds = [kinds]
                schema_types.update(str(kind) for kind in kinds if isinstance(kind, str))
    checks = {
        "canonical": normalized_url(parser.canonical) == normalized_url(url),
        "html_language": parser.lang == language,
        "hreflang_current": normalized_url(parser.alternates.get(language, "")) == normalized_url(url),
        "hreflang_en": bool(parser.alternates.get("en")),
        "hreflang_es": bool(parser.alternates.get("es")),
        "x_default_en": bool(parser.alternates.get("en")) and
            normalized_url(parser.alternates.get("x-default", "")) ==
            normalized_url(parser.alternates.get("en", "")),
        "og_url": normalized_url(parser.og_url) == normalized_url(url),
        "json_ld_valid": len(parsed_json) > 0,
        "json_ld_person": "Person" in schema_types,
        "json_ld_website": "WebSite" in schema_types,
        "json_ld_webpage": bool(schema_types.intersection(
            {"WebPage", "ProfilePage", "CollectionPage", "AboutPage", "ContactPage"}
        )),
        "html5": "<!doctype html>" in raw[:500].lower(),
        "theme_header": parser.header,
        "primary_navigation": parser.primary_nav,
        "footer_navigation": parser.footer_nav,
        "theme_stylesheet": parser.theme_stylesheet,
    }
    editorial_flags = []
    if key == "cv" and (
        "curriculum in development" in raw.lower()
        or "currículum en desarrollo" in raw.lower()
        or "curriculum en desarrollo" in raw.lower()
    ):
        editorial_flags.append("cv_placeholder_public")
    return {"checks": checks, "editorial_flags": editorial_flags}


def pinned_get(url: str, timeout: int = 8) -> tuple[int | None, bytes, str]:
    parts = urllib.parse.urlsplit(url)
    if parts.scheme != "https" or parts.hostname != HOST or parts.port not in (None, 443) or parts.query or parts.fragment:
        raise ValueError("Read-only audit requires a canonical HTTPS URL on the exact approved hostname")
    opener = urllib.request.build_opener(
        SafeRedirect(),
        urllib.request.HTTPSHandler(context=ssl.create_default_context()),
    )
    req = urllib.request.Request(url, method="GET", headers={
        "User-Agent": "SiteArchitect-ReadOnly-Contract-Audit/0.11.1",
        "Accept": "text/html",
    })
    try:
        with opener.open(req, timeout=timeout) as response:
            status = response.status
            final_url = response.geturl()
            if urllib.parse.urlsplit(final_url).hostname != HOST:
                raise ValueError("Response URL escaped the approved production host")
            return status, response.read(MAX_HTML_BYTES + 1), ""
    except urllib.error.HTTPError as ex:
        return ex.code, b"", "http_" + str(ex.code)
    except (urllib.error.URLError, TimeoutError, OSError) as ex:
        return None, b"", type(ex).__name__


def compare_route(route: dict, status: int | None, body: bytes, error: str, clone: dict) -> dict:
    rid = route["id"]
    expected = (
        200 if rid in clone.get("pages_verified", {})
        else 404 if rid in clone.get("pages_not_public", {})
        else None
    )
    verified = {"checks": {}, "editorial_flags": []}
    if status == 200 and len(body) <= MAX_HTML_BYTES:
        verified = inspect_html(body, route["url"], route["language"], route["key"])
    elif status == 200:
        error = "html_size_limit_exceeded"
    return {
        "id": rid, "url": route["url"], "role": route["role"],
        "production_http": status,
        "clone_http": expected,
        "http_delta": expected is not None and status is not None and expected != status,
        "transport_error": error,
        "checks": verified["checks"],
        "seo_verified": status == 200 and bool(verified["checks"]) and
            all(verified["checks"].values()),
        "editorial_flags": verified["editorial_flags"],
    }


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--contract", required=True, type=Path)
    ap.add_argument("--clone-report", required=True, type=Path)
    ap.add_argument("--output", required=True, type=Path)
    ap.add_argument("--source-sha", required=True)
    args = ap.parse_args()
    pages = json.loads(args.contract.read_text(encoding="utf-8"))
    clone = json.loads(args.clone_report.read_text(encoding="utf-8"))
    if not clone.get("pass") or not clone.get("hydrate_rollback_verified"):
        raise ValueError("A verified, completely rolled-back clone is required for comparison")
    routes = manifest_routes(pages)
    results = []
    for route in routes:
        status, body, error = pinned_get(route["url"])
        results.append(compare_route(route, status, body, error, clone))
        time.sleep(0.15)
    count = {
        "audited": len(results),
        "http_200": sum(r["production_http"] == 200 for r in results),
        "http_404": sum(r["production_http"] == 404 for r in results),
        "seo_verified": sum(r["seo_verified"] for r in results),
        "http_deltas": sum(r["http_delta"] for r in results),
        "unreachable": sum(r["production_http"] is None for r in results),
        "other_status": sum(r["production_http"] not in (200, 404, None) for r in results),
        "editorial_flags": sum(len(r["editorial_flags"]) for r in results),
    }
    report = {
        "audit_version": 1,
        "read_only": True,
        "data_scope": "24 anonymous public HTTP routes; no private WordPress, DB, login or plugin state",
        "production_origin": BASE,
        "clone_origin": "http://127.0.0.1:8080",
        "source_commit": args.source_sha,
        "observed_at_utc": dt.datetime.now(dt.timezone.utc).isoformat(),
        "complete_http_observation": count["audited"] == 24 and
            count["unreachable"] == 0 and count["other_status"] == 0,
        "parity_status": "PUBLIC_HTTP_COMPARABLE_ONLY",
        "counts": count,
        "routes": results,
        "release_gates_not_certified": [
            "production_plugin_version_and_credential_authority",
            "production_content_database_and_media_inventory",
            "visual_screenshot_parity",
            "legal_content_review",
            "reversible_production_cutover",
            "separate_private_product_repository",
        ],
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print("PASS: read-only production HTTP survey attempted exactly 24 Research Theme routes; "
          + json.dumps(count, sort_keys=True))
    deltas = [{"page": row["id"], "clone_http": row["clone_http"],
               "production_http": row["production_http"]}
              for row in results if row["http_delta"]]
    flags = [{"page": row["id"], "flags": row["editorial_flags"]}
             for row in results if row["editorial_flags"]]
    print("PUBLIC_HTTP_DELTAS=" + json.dumps(deltas, sort_keys=True))
    print("PUBLIC_EDITORIAL_FLAGS=" + json.dumps(flags, sort_keys=True))
    if not report["complete_http_observation"]:
        print("UNVERIFIED: public HTTP survey incomplete; do not claim production parity.")


if __name__ == "__main__":
    main()
