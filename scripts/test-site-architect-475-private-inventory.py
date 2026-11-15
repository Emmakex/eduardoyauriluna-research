#!/usr/bin/env python3
"""Offline negative validation cases for #475.22 private aggregate inventory."""
import copy
import hashlib
import importlib.util
import pathlib
import sys
import tempfile
import unittest

p = pathlib.Path(__file__).resolve().parent / "site-architect-475-validate-private-inventory.py"
spec = importlib.util.spec_from_file_location("sa47522privatevalidator", p)
audit = importlib.util.module_from_spec(spec)
sys.modules["sa47522privatevalidator"] = audit
spec.loader.exec_module(audit)

def sample():
    return {
        "schema_version":1,"origin":"clone","read_only_transaction":True,
        "site_host_verified":True,"observed_at_utc":"2026-11-14T18:00:00Z",
        "plugin":{"active":True,"product":"Site Architect","version":"0.11.1",
                  "entrypoint_sha256":"a"*64},
        "theme":{"stylesheet":"eduardo-research","version":"1.1.3",
                 "preset_id":"research","preset_version":6,
                 "compatible":True,"languages":["en","es"],"page_contract_count":12},
        "owned_pages":[{"key":key,"language":lang,"exists":True,"status":"publish",
                        "contract_aligned":True,"stored_slot_count":0,
                        "allowed_slot_count":3}
                       for key in ("home","about","research","publications","projects",
                                   "software","datasets","cv","insights","contact",
                                   "privacy-policy","legal-notice")
                       for lang in ("en","es")],
        "page_language_count":24,"aligned_page_language_count":24,
        "post_status_counts":{"page":{"publish":12}},
        "media_type_counts":{"image":0,"application":0,"other":0},
        "menu_item_counts":{"primary":5,"footer":2},
        "remote":{"primary_configured":False,"primary_enabled":False,
                  "credential_present":False,"scope_count":0,"write_scope_count":0,
                  "temporary_active_count":0},
        "excluded_sensitive_fields":["tokens","titles","identifiers"],
        "certification":"PRIVATE_READBACK_ONLY_NOT_CUTOVER",
    }

class ReadOnlyInventoryTest(unittest.TestCase):
    def test_accept_safe_full_24_record_clone(self):
        self.assertEqual(audit.validate(sample(), expected_origin="clone")["record_count"],24)

    def test_refuse_credential_leak(self):
        bad = sample()
        bad["remote"]["token_hash"] = "secret"
        with self.assertRaises(ValueError):
            audit.validate(bad, expected_origin="clone")

    def test_refuse_fabricated_no_go_certification(self):
        bad = sample()
        bad["certification"] = "LIVE_READY"
        with self.assertRaises(ValueError):
            audit.validate(bad, expected_origin="clone")

    def test_refuse_unsafe_report_origin(self):
        with self.assertRaises(ValueError):
            audit.validate(sample(), expected_origin="production")

    def test_production_integrity_requires_comparison_against_source(self):
        report = sample()
        report["origin"] = "production"
        self.assertEqual(
            audit.validate(report, expected_origin="production")["entrypoint_integrity"],
            "UNVERIFIED",
        )
        with tempfile.TemporaryDirectory() as dirname:
            canonical = pathlib.Path(dirname) / "canonical-plugin.php"
            canonical.write_bytes(b"<?php // certified product entrypoint\\n")
            report["plugin"]["entrypoint_sha256"] = hashlib.sha256(
                canonical.read_bytes()
            ).hexdigest()
            aligned = audit.validate(
                report, expected_origin="production", source_entrypoint=canonical
            )
            self.assertEqual(aligned["entrypoint_integrity"], "MATCH")
            self.assertEqual(report["certification"], "PRIVATE_READBACK_ONLY_NOT_CUTOVER")
            report["plugin"]["entrypoint_sha256"] = "d" * 64
            mismatch = audit.validate(
                report, expected_origin="production", source_entrypoint=canonical
            )
            self.assertEqual(mismatch["entrypoint_integrity"], "DRIFT_REVIEW")
            report["plugin"]["entrypoint_sha256"] = None
            missing = audit.validate(
                report, expected_origin="production", source_entrypoint=canonical
            )
            self.assertEqual(missing["entrypoint_integrity"], "DRIFT_REVIEW")

    def test_production_refuses_missing_reference_entrypoint(self):
        report = sample()
        report["origin"] = "production"
        with tempfile.TemporaryDirectory() as dirname:
            with self.assertRaises(ValueError):
                audit.validate(
                    report, expected_origin="production",
                    source_entrypoint=pathlib.Path(dirname) / "missing.php",
                )

    def test_refuse_draft_shown_http_200(self):
        bad = sample()
        bad["owned_pages"][0]["status"] = "draft"
        clone = {"pass":True,"hydrate_rollback_verified":True,
                 "pages_verified":{p["key"]+"/"+p["language"]:200
                                   for p in bad["owned_pages"]},
                 "pages_not_public":{}}
        with self.assertRaises(ValueError):
            audit.validate(bad, expected_origin="clone", clone_http=clone)

if __name__ == "__main__":
    unittest.main()
