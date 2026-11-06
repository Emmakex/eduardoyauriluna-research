#!/usr/bin/env python3
"""One-shot four-slot Home/About editorial Plan/Preview: NEVER Apply.

The existing research-ops token is read only on the protected OVH host.
Production is queried via the canonical MCP with one read-only inventory call
and at most four one-slot site_page_slots_plan calls; no other tool allowed.
The immutable historical EN/ES baseline is required before any stored Plan.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import sys
from typing import Any

HERE = Path(__file__).resolve().parent
ATTEMPT = Path("/opt/site-control-mcp/.editorial-plan-issue-464.attempt")
SOURCES = HERE / "editorial-snapshots"
BLUEPRINT = HERE / "eduardo-research-blueprint-audit.json"
PLAN_TOOL = "site_page_slots_plan"
READ_TOOL = "site_pages_list"
PLAN_ID = re.compile(r"^erm-remote-plan-[a-f0-9]{20}-[a-f0-9]{8}$")
SHA256 = re.compile(r"^[a-f0-9]{64}$")
EXPIRES = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\+00:00|Z)$")
RISK = {"standard", "editorial-review", "evidence-required"}

# Closed editorial decision approved for Plan/Preview in #462, *not Apply*.
COPIES = (
    ("home", "en", "research-lines-intro",
     "The developing research agenda explores four complementary directions: governable autonomous AI agents for business processes; human–AI collaboration and responsible autonomy; AI adoption and digital transformation in SMEs; and information discovery mediated by generative AI."),
    ("home", "es", "research-lines-intro",
     "La agenda de investigación en desarrollo explora cuatro líneas complementarias: agentes autónomos de IA gobernables para procesos empresariales; colaboración humano–IA y autonomía responsable; adopción de IA y transformación digital en pymes; y descubrimiento de información mediado por IA generativa."),
    ("about", "en", "lead",
     "Eduardo Jose Yauri Luna develops an applied research agenda around AI-enabled business systems, drawing on more than ten years of professional experience in digital strategy, web technologies, automation and software-based business systems."),
    ("about", "es", "lead",
     "Eduardo Jose Yauri Luna desarrolla una agenda de investigación aplicada sobre sistemas empresariales habilitados por IA, apoyada en más de diez años de experiencia profesional en estrategia digital, tecnologías web, automatización y sistemas empresariales basados en software."),
)
SNAPSHOT_NAMES = {
    ("home", "en"): "cmd-v2-resource-page-home-en-20261028-01.json",
    ("home", "es"): "cmd-v2-resource-page-home-es-20261028-01.json",
    ("about", "en"): "cmd-v2-resource-page-about-en-20261028-01.json",
    ("about", "es"): "cmd-v2-resource-page-about-es-20261028-01.json",
}


def load(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, HERE / filename)
    if spec is None or spec.loader is None:
        raise ValueError("required known script unavailable")
    mod = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = mod
    spec.loader.exec_module(mod)
    return mod


protected = load("editorial_464_transport", "site-architect-greenfield-protected-preview.py")
editorial = load("editorial_464_baseline", "site-architect-editorial-baseline-462.py")
audit = load("editorial_464_audit", "site-architect-hydration-readonly-audit.py")


class PlanError(Exception):
    pass


def sha_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def authorized(values: dict[str, str]) -> bool:
    if protected.preflight.inspect(values)["status"] != "ready":
        return False
    try:
        principal = protected.preflight.exact_entry(values["MCP_PRINCIPALS_JSON"], "research-ops")
        tenant = protected.preflight.exact_entry(values["MCP_TENANTS_JSON"], "eduardo-research")
    except (KeyError, protected.preflight.ProtectedConfigError):
        return False
    return bool(
        principal and tenant
        and principal.get("tenants") == ["eduardo-research"]
        and isinstance(principal.get("capabilities"), list)
        and isinstance(tenant.get("entitlements"), list)
        and "pages.write" in principal["capabilities"]
        and "pages.write" in tenant["entitlements"]
    )


def mcp_data(result: dict[str, Any] | None) -> dict[str, Any]:
    if not isinstance(result, dict) or result.get("isError") is True:
        raise PlanError("MCP call did not return a successful result")
    wrapped = result.get("structuredContent")
    if isinstance(wrapped, dict) and isinstance(wrapped.get("data"), dict):
        return wrapped["data"]
    for item in result.get("content", []):
        if isinstance(item, dict) and item.get("type") == "text":
            try:
                value = json.loads(item.get("text", ""))
            except (TypeError, ValueError):
                continue
            if isinstance(value, dict):
                return value
    raise PlanError("missing canonical MCP result body")


def session(client: Any) -> None:
    response = client.post({
        "jsonrpc": "2.0", "id": 1, "method": "initialize",
        "params": {
            "protocolVersion": protected.PROTOCOL,
            "capabilities": {},
            "clientInfo": {"name": "eduardo-editorial-plan-464", "version": "1.0.0"},
        },
    }, 1)
    if not isinstance(response, dict) or not isinstance(response.get("protocolVersion"), str):
        raise PlanError("MCP session initialization invalid")
    client.post({"jsonrpc": "2.0", "method": "notifications/initialized"}, None)


def snapshot(page: str, lang: str) -> dict[str, Any]:
    return editorial.reference_snapshot(SOURCES / SNAPSHOT_NAMES[(page, lang)], page, lang)


def validate_live(inventory: dict[str, Any], desired: dict[str, Any],
                  archived: dict[tuple[str, str], dict[str, Any]]) -> dict[tuple[str, str], dict[str, Any]]:
    if (inventory.get("count") != 12 or inventory.get("languages") != ["en", "es"]
        or not isinstance(inventory.get("items"), list) or len(inventory["items"]) != 12):
        raise PlanError("live inventory violates accepted Theme contract")
    items = inventory["items"]
    mapped = {x.get("key"): x for x in items if isinstance(x, dict)}
    if len(mapped) != 12 or set(mapped) != set(desired):
        raise PlanError("live page keys differ from accepted Theme contract")
    before: dict[tuple[str, str], dict[str, Any]] = {}
    for page, lang, slot, new_text in COPIES:
        entry = mapped[page]
        if entry.get("exists") is not True:
            raise PlanError("selected Theme page missing")
        languages = entry.get("languages")
        state = languages.get(lang) if isinstance(languages, dict) else None
        if not isinstance(state, dict) or state.get("available") is not True or state.get("contract_aligned") is not True:
            raise PlanError("selected Theme Page is not contract aligned")
        stored, schema = state.get("stored_slots"), state.get("allowed_slots")
        if not isinstance(stored, dict) or not isinstance(schema, list) or slot not in schema:
            raise PlanError("selected Theme slot unavailable")
        expected = archived[(page, lang)]
        if stored != expected or slot not in stored:
            raise PlanError("selected Page slot has drifted since editorial acceptance")
        if page == "about" and desired[page][lang].get(slot) != new_text:
            raise PlanError("About must match the evidence-reviewed canonical Blueprint")
        if page == "home" and desired[page][lang].get(slot) == new_text:
            raise PlanError("Home must use the distinct evidence-reviewed new draft")
        if stored[slot] == new_text:
            raise PlanError("requested text already stored; no new plan needed")
        before[(page, lang)] = stored
    return before


def safe_plan(plan: dict[str, Any], page: str, lang: str, slot: str,
              before: str, after: str) -> dict[str, Any]:
    if (
        not isinstance(plan, dict)
        or plan.get("operation") != "page-slots-update"
        or plan.get("status") != "planned"
        or plan.get("confirmation_required") is not True
        or type(plan.get("apply_allowed")) is not bool
        or plan.get("target") != {"resource": "page", "key": page, "language": lang}
        or plan.get("requested") != {"slots": {slot: after}}
        or not isinstance(plan.get("plan_id"), str) or not PLAN_ID.fullmatch(plan["plan_id"])
        or not isinstance(plan.get("source_revision"), str) or not SHA256.fullmatch(plan["source_revision"])
        or not isinstance(plan.get("contract_fingerprint"), str) or not SHA256.fullmatch(plan["contract_fingerprint"])
        or not isinstance(plan.get("expires_at"), str) or not EXPIRES.fullmatch(plan["expires_at"])
        or plan.get("risk") not in RISK
        or plan.get("confirmation_class") not in ("explicit", "evidence-explicit")
    ):
        raise PlanError("unexpected canonical Page slot plan contract")
    return {
        "page": page, "language": lang, "slot": slot,
        "plan_id": plan["plan_id"], "status": "planned",
        "risk": plan["risk"], "confirmation_required": True,
        "apply_allowed": plan["apply_allowed"],
        "source_revision": plan["source_revision"],
        "contract_fingerprint": plan["contract_fingerprint"],
        "expires_at": plan["expires_at"],
        "before_sha256": sha_text(before),
        "after_sha256": sha_text(after),
        "public_site_apply": "not_executed",
    }


def execute() -> None:
    # No mutable credentials or protected registries are echoed or transmitted.
    values = protected.preflight.protected_vars(protected.ENV)
    if not authorized(values):
        print("EDITORIAL_464_BLOCKED tenant_or_principal_pages_write_not_granted")
        raise PlanError("commercial Page Plan authorization unavailable")
    bearer = protected.resolve_bearer(values)
    desired = audit.blueprint_records(BLUEPRINT.read_bytes())
    archive = {(p, l): snapshot(p, l) for p, l, _, _ in COPIES}

    client = protected.ProtectedMcp(bearer)
    session(client)
    inventory = mcp_data(client.post({
        "jsonrpc": "2.0", "id": 2, "method": "tools/call",
        "params": {"name": READ_TOOL, "arguments": {}},
    }, 2))
    baselines = validate_live(inventory, desired, archive)

    flags = os.O_CREAT | os.O_EXCL | os.O_WRONLY
    if hasattr(os, "O_NOFOLLOW"):
        flags |= os.O_NOFOLLOW
    with os.fdopen(os.open(ATTEMPT, flags, 0o600), "w", encoding="utf-8") as f:
        f.write("issue=464;operation=page-slots-plan-only;limit=4;public_apply=not_executed\n")
        f.flush()
        os.fsync(f.fileno())

    for i, (page, lang, slot, text) in enumerate(COPIES, 1):
        response = mcp_data(client.post({
            "jsonrpc": "2.0", "id": i + 2, "method": "tools/call",
            "params": {
                "name": PLAN_TOOL,
                "arguments": {
                    "key": page, "language": lang, "slots": {slot: text},
                    "reason": "Issue #464 bounded EN/ES academic editorial Preview only",
                },
            },
        }, i + 2))
        result = safe_plan(response, page, lang, slot, baselines[(page, lang)][slot], text)
        # Emit only whitelisted proof, no source text, full response, or bearer.
        print("EDITORIAL_464_PLAN " + json.dumps(result, sort_keys=True, separators=(",", ":")), flush=True)
    print("EDITORIAL_464_COMPLETE created_plans=4 public_site_apply=not_executed", flush=True)


def self_test() -> None:
    tenants = [{"id": "eduardo-research", "entitlements": ["site.read", "operations.apply", "pages.write"],
                "writeTokenEnv": "WR_TOKEN"}]
    principals = [{"id": "research-ops", "capabilities": ["site.read", "operations.apply", "pages.write"],
                   "tenants": ["eduardo-research"], "bearerTokenEnv": "OUTER"}]
    fixture = {
        "MCP_SHARED_ROUTING": "true",
        "MCP_TENANTS_JSON": json.dumps(tenants),
        "MCP_PRINCIPALS_JSON": json.dumps(principals),
        "WR_TOKEN": "SYNTHETIC_NEVER_LOG", "OUTER": "SYNTHETIC_BEARER_NEVER_LOG",
    }
    assert authorized(fixture) is True
    for p in (tenants, principals):
        updated = json.loads(json.dumps(p))
        field = "entitlements" if p is tenants else "capabilities"
        updated[0][field].remove("pages.write")
        blocked = dict(fixture)
        blocked["MCP_TENANTS_JSON" if p is tenants else "MCP_PRINCIPALS_JSON"] = json.dumps(updated)
        assert authorized(blocked) is False
    assert len(COPIES) == len(set((p, l, slot) for p, l, slot, _ in COPIES)) == 4
    plan = {
        "operation": "page-slots-update", "status": "planned", "confirmation_required": True,
        "apply_allowed": True, "risk": "editorial-review", "confirmation_class": "explicit",
        "plan_id": "erm-remote-plan-" + "a" * 20 + "-" + "b" * 8,
        "source_revision": "c" * 64, "contract_fingerprint": "d" * 64,
        "expires_at": "2026-11-06T12:00:00+00:00",
        "target": {"resource": "page", "key": "home", "language": "en"},
        "requested": {"slots": {"research-lines-intro": "NEW"}},
        "private_token": "NEVER_LOG_PRIVATE",
    }
    row = safe_plan(plan, "home", "en", "research-lines-intro", "OLD", "NEW")
    assert "NEVER_LOG_PRIVATE" not in json.dumps(row)
    assert "NEW" not in json.dumps(row)
    for tampered in ({**plan, "operation": "page-create"},
                     {**plan, "target": {"resource": "site"}},
                     {**plan, "requested": {"slots": {"lead": "NEW"}}},
                     {**plan, "status": "applied"}):
        try:
            safe_plan(tampered, "home", "en", "research-lines-intro", "OLD", "NEW")
        except PlanError:
            pass
        else:
            raise AssertionError("invalid Plan authority accepted")
    print("PASS: four bounded Page slot plans, commercial fail-closed, no Apply or content export")


def main() -> int:
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--self-test", action="store_true")
    mode.add_argument("--plan-once", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            self_test()
        else:
            execute()
        return 0
    except Exception:
        print("ERROR: #464 focused Page Plan/Preview failed closed; no Apply requested", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
