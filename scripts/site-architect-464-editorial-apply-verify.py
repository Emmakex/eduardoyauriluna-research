#!/usr/bin/env python3
"""Approved Site Architect #464: four exact editorial Page Apply + rendered Verify.

Authorized 2026-11-11 for Home/About EN/ES only. Replans from live state on
OVH (old Plans expire), verifies each operation and full 12-page final readback.
The existing research-ops bearer never leaves the protected OVH server.
No rollback, no unrelated capability, identity, navigation or content operation.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
from pathlib import Path
import re
import sys
from typing import Any

HERE = Path(__file__).resolve().parent
ATTEMPT = Path("/opt/site-control-mcp/.editorial-apply-464-20261111.attempt")
OPERATION_ID = re.compile(r"^erm-operation-[a-f0-9]{32}$")
SHA256 = re.compile(r"^[a-f0-9]{64}$")
ALLOWED_TOOLS = frozenset({
    "site_pages_list",
    "site_page_slots_plan",
    "site_page_slots_apply",
    "site_page_slots_verify",
})


def load(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, HERE / filename)
    if spec is None or spec.loader is None:
        raise RuntimeError("trusted Site Architect dependency missing")
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


plan_source = load("editorial_464_apply_source", "site-architect-editorial-plan-464.py")
protected = plan_source.protected
COPIES = plan_source.COPIES


class ApplyError(RuntimeError):
    pass


def invoke(client: Any, request_id: int, name: str, arguments: dict[str, Any]) -> dict[str, Any]:
    if name not in ALLOWED_TOOLS:
        raise ApplyError("MCP operation is outside owner-approved scope")
    if name == "site_pages_list" and arguments != {}:
        raise ApplyError("unexpected inventory request arguments")
    if name == "site_page_slots_apply" and (
        arguments.get("confirm") is not True or set(arguments) != {"planId", "confirm"}
    ):
        raise ApplyError("Apply must confirm an exact plan ID")
    if name == "site_page_slots_verify" and (
        arguments.get("rendered") is not True or set(arguments) != {"operationId", "rendered"}
    ):
        raise ApplyError("rendered Verify is mandatory")
    response = client.post({
        "jsonrpc": "2.0", "id": request_id, "method": "tools/call",
        "params": {"name": name, "arguments": arguments},
    }, request_id)
    return plan_source.mcp_data(response)


def copy_slots(inventory: Any) -> dict[tuple[str, str], dict[str, Any]]:
    if (not isinstance(inventory, dict) or inventory.get("count") != 12
            or inventory.get("languages") != ["en", "es"]
            or not isinstance(inventory.get("items"), list)
            or len(inventory["items"]) != 12):
        raise ApplyError("live Theme inventory shape changed")
    output: dict[tuple[str, str], dict[str, Any]] = {}
    for page in inventory["items"]:
        if not isinstance(page, dict) or page.get("exists") is not True:
            raise ApplyError("live Theme page record unavailable")
        key, languages = page.get("key"), page.get("languages")
        if not isinstance(key, str) or not isinstance(languages, dict):
            raise ApplyError("invalid Theme page keys or languages")
        for lang in ("en", "es"):
            view = languages.get(lang)
            if (not isinstance(view, dict) or view.get("available") is not True
                    or view.get("contract_aligned") is not True
                    or not isinstance(view.get("stored_slots"), dict)):
                raise ApplyError("live Theme page contract or stored slots invalid")
            pair = (key, lang)
            if pair in output:
                raise ApplyError("duplicate Theme page/language")
            output[pair] = view["stored_slots"]
    if len(output) != 24:
        raise ApplyError("complete Theme EN/ES inventory unavailable")
    return output


def validate_applied(data: Any, safe: dict[str, Any], desired: str) -> str:
    operation_id = data.get("operation_id") if isinstance(data, dict) else None
    if (
        not isinstance(data, dict)
        or data.get("operation") != "page-slots-update"
        or data.get("status") != "applied"
        or data.get("plan_id") != safe["plan_id"]
        or data.get("target") != {
            "resource": "page", "key": safe["page"], "language": safe["language"]
        }
        or data.get("requested") != {"slots": {safe["slot"]: desired}}
        or not isinstance(operation_id, str) or not OPERATION_ID.fullmatch(operation_id)
        or not isinstance(data.get("post_apply_revision"), str)
        or not SHA256.fullmatch(data["post_apply_revision"])
        or not isinstance(data.get("result"), dict)
        or data["result"].get("verified") is not True
    ):
        raise ApplyError("WordPress Apply response failed exact-operation validation")
    return operation_id


def validate_verified(data: Any, safe: dict[str, Any], desired: str, operation_id: str) -> None:
    if (
        not isinstance(data, dict)
        or data.get("operation_id") != operation_id
        or data.get("plan_id") != safe["plan_id"]
        or data.get("operation") != "page-slots-update"
        or data.get("status") != "verified"
        or data.get("target") != {
            "resource": "page", "key": safe["page"], "language": safe["language"]
        }
        or data.get("requested") != {"slots": {safe["slot"]: desired}}
        or not isinstance(data.get("stored_verification"), dict)
        or data["stored_verification"].get("verified") is not True
        or data["stored_verification"].get("contract_matches") is not True
        or data["stored_verification"].get("revision_matches") is not True
        or not isinstance(data.get("rendered_verification"), dict)
        or data["rendered_verification"].get("requested") is not True
        or data["rendered_verification"].get("verified") is not True
    ):
        raise ApplyError("stored or rendered WordPress Verify failed")


def compare_final(before: dict[tuple[str, str], dict[str, Any]],
                  after: dict[tuple[str, str], dict[str, Any]]) -> int:
    if set(before) != set(after):
        raise ApplyError("live Page inventory changed unexpectedly")
    expected = {(page, lang): (slot, value) for page, lang, slot, value in COPIES}
    if len(expected) != 4:
        raise ApplyError("editorial scope contains duplicates")
    checked = 0
    for pair, original in before.items():
        final = after[pair]
        target = expected.get(pair)
        if target is None:
            if final != original:
                raise ApplyError("unrelated page or language changed")
            continue
        slot, text = target
        wanted = {**original, slot: text}
        if final != wanted:
            raise ApplyError("post-Apply slot readback does not match exact approved copy")
        checked += 1
    if checked != 4:
        raise ApplyError("not all approved page slots verified")
    return checked


def attempt_once() -> None:
    flags = os.O_CREAT | os.O_EXCL | os.O_WRONLY
    if hasattr(os, "O_NOFOLLOW"):
        flags |= os.O_NOFOLLOW
    try:
        fd = os.open(ATTEMPT, flags, 0o600)
    except FileExistsError:
        raise ApplyError("approved #464 Apply already attempted; no replay") from None
    with os.fdopen(fd, "w", encoding="utf-8") as stream:
        stream.write("issue=464;authorized=2026-11-11;targets=4;mode=exact-plan-apply-verify\n")
        stream.flush()
        os.fsync(stream.fileno())


def execute() -> None:
    values = protected.preflight.protected_vars(protected.ENV)
    if not plan_source.authorized(values):
        raise ApplyError("research-ops pages.write authorization was revoked")
    # Validates the exact accepted Blueprint and the previous editorial sources.
    desired = plan_source.audit.blueprint_records(plan_source.BLUEPRINT.read_bytes())
    archived = {(p, l): plan_source.snapshot(p, l) for p, l, _, _ in COPIES}
    reviewed = (HERE / "reviewed-editorial-464.md").read_text(encoding="utf-8")
    if not all(text in reviewed for _, _, _, text in COPIES):
        raise ApplyError("reviewed evidence-safe copy changed")

    client = protected.ProtectedMcp(protected.resolve_bearer(values))
    plan_source.session(client)
    request_id = 2
    first = invoke(client, request_id, "site_pages_list", {})
    plan_source.validate_live(first, desired, archived)
    baseline = copy_slots(first)
    attempt_once()  # BEFORE first new stored Plan or public Apply

    completed = 0
    for page, lang, slot, desired_text in COPIES:
        before_value = archived[(page, lang)][slot]
        request_id += 1
        result = invoke(client, request_id, "site_page_slots_plan", {
            "key": page, "language": lang, "slots": {slot: desired_text},
            "reason": "Owner-authorized #464 Home/About bilingual editorial Apply and rendered Verify",
        })
        safe = plan_source.safe_plan(result, page, lang, slot, before_value, desired_text)
        if not safe["apply_allowed"] or safe["risk"] != "editorial-review":
            raise ApplyError("Page plan is not safely applicable")
        print("EDITORIAL_464_APPLY_PLAN " + json.dumps({
            "page": page, "language": lang, "slot": slot,
            "plan_id": safe["plan_id"], "expires_at": safe["expires_at"],
            "source_revision": safe["source_revision"], "risk": safe["risk"],
            "before_sha256": safe["before_sha256"], "after_sha256": safe["after_sha256"],
        }, sort_keys=True, separators=(",", ":")), flush=True)

        request_id += 1
        applied = invoke(client, request_id, "site_page_slots_apply", {
            "planId": safe["plan_id"], "confirm": True,
        })
        operation_id = validate_applied(applied, safe, desired_text)
        print("EDITORIAL_464_APPLIED " + json.dumps({
            "page": page, "language": lang, "slot": slot,
            "plan_id": safe["plan_id"], "operation_id": operation_id,
            "status": "applied",
        }, sort_keys=True, separators=(",", ":")), flush=True)

        request_id += 1
        verified = invoke(client, request_id, "site_page_slots_verify", {
            "operationId": operation_id, "rendered": True,
        })
        validate_verified(verified, safe, desired_text, operation_id)
        print("EDITORIAL_464_VERIFIED " + json.dumps({
            "page": page, "language": lang, "slot": slot,
            "operation_id": operation_id,
            "stored_verified": True, "rendered_verified": True,
        }, sort_keys=True, separators=(",", ":")), flush=True)
        completed += 1

    request_id += 1
    final = invoke(client, request_id, "site_pages_list", {})
    matches = compare_final(baseline, copy_slots(final))
    if completed != 4 or matches != 4:
        raise ApplyError("not all approved editorial operations were accepted")
    print("EDITORIAL_464_APPLY_COMPLETE " + json.dumps({
        "status": "verified", "applied": completed, "rendered_verified": completed,
        "final_readback_verified": matches, "unrelated_slots_unchanged": True,
        "credentials_exported": False, "approved_page_language_pairs": 4,
    }, sort_keys=True, separators=(",", ":")), flush=True)


def self_test() -> int:
    assert len(COPIES) == 4
    assert len({(p, l, s) for p, l, s, _ in COPIES}) == 4
    assert ALLOWED_TOOLS == {
        "site_pages_list", "site_page_slots_plan",
        "site_page_slots_apply", "site_page_slots_verify",
    }
    original = {("home", "en"): {"hero": "preserved", "research-lines-intro": "old"},
                ("home", "es"): {"hero": "conservado", "research-lines-intro": "viejo"},
                ("about", "en"): {"lead": "old", "extra": ["preserve"]},
                ("about", "es"): {"lead": "viejo", "extra": ["preserve"]}}
    for i in range(8):
        for lang in ("en", "es"):
            original[(f"not-target-{i}", lang)] = {"lead": f"keep-{i}-{lang}"}
    assert len(original) == 20
    final = {k: dict(v) for k, v in original.items()}
    for page, lang, slot, value in COPIES:
        final[(page, lang)][slot] = value
    assert compare_final(original, final) == 4
    wrong = {k: dict(v) for k, v in final.items()}
    wrong[("not-target-0", "es")]["lead"] = "drift"
    try:
        compare_final(original, wrong)
    except ApplyError:
        pass
    else:
        raise AssertionError("unrelated page drift not blocked")
    plan = {
        "plan_id": "erm-remote-plan-" + "a" * 20 + "-1234abcd",
        "page": "home", "language": "en", "slot": "research-lines-intro",
    }
    operation_id = "erm-operation-" + "c" * 32
    apply = {
        "operation_id": operation_id, "plan_id": plan["plan_id"],
        "operation": "page-slots-update", "status": "applied",
        "target": {"resource": "page", "key": "home", "language": "en"},
        "requested": {"slots": {"research-lines-intro": "new"}},
        "post_apply_revision": "b" * 64,
        "result": {"verified": True},
        "private_data": "SECRET_NEVER_LOG",
    }
    assert validate_applied(apply, plan, "new") == operation_id
    verify = {
        **apply, "status": "verified",
        "stored_verification": {"verified": True, "contract_matches": True, "revision_matches": True},
        "rendered_verification": {"requested": True, "verified": True},
    }
    validate_verified(verify, plan, "new", operation_id)
    try:
        validate_verified({**verify, "rendered_verification": {"verified": False, "requested": True}},
                          plan, "new", operation_id)
    except ApplyError:
        pass
    else:
        raise AssertionError("unverified rendering unexpectedly accepted")
    for name, args in (("site_page_slots_rollback", {"operationId": operation_id, "confirm": True}),
                       ("site_page_slots_apply", {"planId": plan["plan_id"], "confirm": False}),
                       ("site_page_slots_verify", {"operationId": operation_id, "rendered": False})):
        class ForbiddenClient:
            def post(self, *_a, **_kw):
                raise AssertionError("an invalid operation was invoked")
        try:
            invoke(ForbiddenClient(), 5, name, args)
        except ApplyError:
            pass
        else:
            raise AssertionError("forbidden operation was not blocked")
    assert "SECRET_NEVER_LOG" not in json.dumps({
        "operation_id": validate_applied(apply, plan, "new"),
        "rendered_verified": True,
    })
    print("PASS: #464 exact four editorial Apply/Verify, immutable unrelated slots, no rollback")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--self-test", action="store_true")
    modes.add_argument("--apply-verify-once", action="store_true")
    args = parser.parse_args()
    try:
        return self_test() if args.self_test else (execute() or 0)
    except Exception:
        print("ERROR: #464 bounded Apply/Verify failed closed; inspect approved operation evidence before retry", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
