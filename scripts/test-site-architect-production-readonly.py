#!/usr/bin/env python3
"""Offline no-network tests for #475.21 production read-only comparator."""
import importlib.util
import json
from pathlib import Path
import sys
import unittest

PATH = Path(__file__).resolve().parent / "site-architect-475-production-readonly.py"
spec = importlib.util.spec_from_file_location("sa47521", PATH)
audit = importlib.util.module_from_spec(spec)
sys.modules["sa47521"] = audit
spec.loader.exec_module(audit)


class ProductionReadOnlyTest(unittest.TestCase):
    def test_routes_exact_24_and_localized_contract(self):
        pages = {
            "home": {"role": "front-page"},
            "about": {"wp_slug": "about", "slugs": {"es": "perfil"}},
        }
        for key in ("research", "publications", "projects", "software", "datasets",
                    "cv", "insights", "contact", "privacy-policy", "legal-notice"):
            pages[key] = {"wp_slug": key, "slugs": {"es": key}}
        routes = audit.manifest_routes(pages)
        self.assertEqual(len(routes), 24)
        by_id = {r["id"]: r for r in routes}
        self.assertEqual(by_id["home/en"]["url"], "https://eduardoyauriluna.com/")
        self.assertEqual(by_id["home/es"]["url"], "https://eduardoyauriluna.com/es/")
        self.assertEqual(by_id["about/es"]["url"], "https://eduardoyauriluna.com/es/perfil/")

    def test_reject_unsafe_urls_and_paths_before_network(self):
        for segment in ("../wp-admin", "about?x=y", "a/b", ""):
            with self.assertRaises(ValueError):
                audit.safe_segment(segment)
        with self.assertRaises(ValueError):
            audit.pinned_get("https://evil.example.com/")

    def test_html_metadata_and_cv_flag(self):
        url = "https://eduardoyauriluna.com/cv/"
        html = """<!doctype html><html lang="en"><head>
        <link rel="canonical" href="https://eduardoyauriluna.com/cv/">
        <link rel="alternate" hreflang="en" href="https://eduardoyauriluna.com/cv/">
        <link rel="alternate" hreflang="es" href="https://eduardoyauriluna.com/es/cv/">
        <link rel="alternate" hreflang="x-default" href="https://eduardoyauriluna.com/cv/">
        <link rel="stylesheet" href="/wp-content/themes/eduardo-research/style.css?v=1">
        <meta property="og:url" content="https://eduardoyauriluna.com/cv/">
        <script type="application/ld+json">{"@graph":[
        {"@type":"Person"},{"@type":"WebSite"},{"@type":"WebPage"}]}</script>
        </head><body><header class="research-site-header"></header>
        <nav id="research-primary-nav"></nav><nav class="research-footer-nav"></nav>
        <main><h2>Curriculum in development</h2></main></body></html>"""
        inspected = audit.inspect_html(html.encode(), url, "en", "cv")
        self.assertTrue(all(inspected["checks"].values()), inspected["checks"])
        self.assertEqual(inspected["editorial_flags"], ["cv_placeholder_public"])

    def test_clone_difference_is_a_delta_not_production_approval(self):
        route = {"id": "privacy-policy/en", "url": "https://eduardoyauriluna.com/privacy-policy/",
                 "language": "en", "key": "privacy-policy", "role": "legal"}
        clone = {"pages_verified": {}, "pages_not_public": {"privacy-policy/en": {"http_status": 404}}}
        got = audit.compare_route(route, 200, b"public without valid SEO", "", clone)
        self.assertTrue(got["http_delta"])
        self.assertFalse(got["seo_verified"])
        self.assertEqual(got["clone_http"], 404)


if __name__ == "__main__":
    unittest.main()
