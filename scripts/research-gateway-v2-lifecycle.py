#!/usr/bin/env python3
"""Shared bounded Plan -> Apply -> Verify -> Rollback lifecycle for Gateway v2 writes.

This module does not accept command payloads, caller-selected MCP tools or backend URLs.
Action-specific runners remain responsible for validation, allowlists, trusted registry
resolution, ID patterns and result shaping. The engine only removes duplicated lifecycle
orchestration after those boundaries have already been established.
"""
from __future__ import annotations

import re
from typing import Any, Callable

TOOL_RE = re.compile(r"^site_[a-z0-9_]{3,96}$")
TOOL_KEYS = {"plan", "apply", "verify", "rollback"}


class LifecycleError(RuntimeError):
    """Fail-closed bounded lifecycle error."""


class LifecycleState:
    """Mutable correlation state retained across failures so rollback can be exact."""

    def __init__(self) -> None:
        self.plan_id: str | None = None
        self.operation_id: str | None = None
        self.plan: Any = None
        self.applied: Any = None
        self.verification: Any = None
        self.rollback_attempted = False
        self.rollback_verified: bool | None = None

    def correlation(self) -> dict[str, str | None]:
        return {"plan_id": self.plan_id, "operation_id": self.operation_id}

    def rollback_result(self) -> dict[str, bool | None]:
        return {
            "attempted": self.rollback_attempted,
            "verified": self.rollback_verified,
        }


def validate_tools(tools: Any) -> dict[str, str]:
    if not isinstance(tools, dict) or set(tools) != TOOL_KEYS:
        raise LifecycleError("bounded lifecycle requires exactly plan/apply/verify/rollback tools")
    normalized: dict[str, str] = {}
    for key in sorted(TOOL_KEYS):
        value = tools.get(key)
        if not isinstance(value, str) or TOOL_RE.fullmatch(value) is None:
            raise LifecycleError(f"bounded lifecycle has invalid trusted {key} tool")
        normalized[key] = value
    return normalized


def run(
    *,
    client: Any,
    tools: Any,
    plan_args: dict[str, Any],
    parse_plan_id: Callable[[Any], str],
    parse_operation_id: Callable[[Any], str],
    is_verified: Callable[[Any], bool],
    state: LifecycleState,
    verify_args: dict[str, Any] | None = None,
    verification_error: str = "verification did not report verified=true",
) -> LifecycleState:
    trusted = validate_tools(tools)
    if not isinstance(plan_args, dict):
        raise LifecycleError("bounded lifecycle plan_args must be an object")
    if verify_args is not None and not isinstance(verify_args, dict):
        raise LifecycleError("bounded lifecycle verify_args must be an object")
    if state.plan_id is not None or state.operation_id is not None:
        raise LifecycleError("bounded lifecycle state must be fresh")

    state.plan = client.call(trusted["plan"], plan_args)
    state.plan_id = parse_plan_id(state.plan)
    if not isinstance(state.plan_id, str) or not state.plan_id:
        raise LifecycleError("bounded lifecycle plan parser returned no identifier")

    state.applied = client.call(
        trusted["apply"],
        {"planId": state.plan_id, "confirm": True},
    )
    state.operation_id = parse_operation_id(state.applied)
    if not isinstance(state.operation_id, str) or not state.operation_id:
        raise LifecycleError("bounded lifecycle operation parser returned no identifier")

    verify_payload: dict[str, Any] = {"operationId": state.operation_id}
    if verify_args:
        overlap = set(verify_args) & {"operationId"}
        if overlap:
            raise LifecycleError("bounded lifecycle verify_args cannot override operationId")
        verify_payload.update(verify_args)
    state.verification = client.call(trusted["verify"], verify_payload)
    if not is_verified(state.verification):
        raise LifecycleError(verification_error)
    return state


def rollback(
    *,
    client: Any,
    tools: Any,
    state: LifecycleState,
    is_rollback_verified: Callable[[Any], bool],
) -> bool | None:
    trusted = validate_tools(tools)
    if state.operation_id is None:
        state.rollback_attempted = False
        state.rollback_verified = None
        return None

    state.rollback_attempted = True
    try:
        rolled = client.call(
            trusted["rollback"],
            {"operationId": state.operation_id, "confirm": True},
        )
        state.rollback_verified = bool(is_rollback_verified(rolled))
    except Exception:  # noqa: BLE001 - rollback status is surfaced, original error remains authoritative.
        state.rollback_verified = False
    return state.rollback_verified


def self_test() -> int:
    calls: list[tuple[str, dict[str, Any]]] = []

    class FakeClient:
        def __init__(self, verify: bool = True) -> None:
            self.verify = verify

        def call(self, tool: str, args: dict[str, Any]) -> Any:
            calls.append((tool, args))
            if tool == "site_test_plan":
                return {"plan_id": "plan-1"}
            if tool == "site_test_apply":
                return {"operation_id": "op-1"}
            if tool == "site_test_verify":
                return {"verified": self.verify}
            if tool == "site_test_rollback":
                return {"rolled_back": True}
            raise AssertionError(tool)

    tools = {
        "plan": "site_test_plan",
        "apply": "site_test_apply",
        "verify": "site_test_verify",
        "rollback": "site_test_rollback",
    }
    state = LifecycleState()
    run(
        client=FakeClient(),
        tools=tools,
        plan_args={"value": "bounded"},
        parse_plan_id=lambda value: value["plan_id"],
        parse_operation_id=lambda value: value["operation_id"],
        is_verified=lambda value: value.get("verified") is True,
        state=state,
        verify_args={"rendered": True},
    )
    assert state.correlation() == {"plan_id": "plan-1", "operation_id": "op-1"}
    assert calls == [
        ("site_test_plan", {"value": "bounded"}),
        ("site_test_apply", {"planId": "plan-1", "confirm": True}),
        ("site_test_verify", {"operationId": "op-1", "rendered": True}),
    ]

    calls.clear()
    failed = LifecycleState()
    try:
        run(
            client=FakeClient(verify=False),
            tools=tools,
            plan_args={"value": "bounded"},
            parse_plan_id=lambda value: value["plan_id"],
            parse_operation_id=lambda value: value["operation_id"],
            is_verified=lambda value: value.get("verified") is True,
            state=failed,
        )
    except LifecycleError:
        pass
    else:
        raise AssertionError("failed verification did not fail closed")
    assert failed.operation_id == "op-1"
    assert rollback(
        client=FakeClient(),
        tools=tools,
        state=failed,
        is_rollback_verified=lambda value: value.get("rolled_back") is True,
    ) is True
    assert failed.rollback_result() == {"attempted": True, "verified": True}

    try:
        validate_tools({**tools, "shell": "site_test_shell"})
    except LifecycleError:
        pass
    else:
        raise AssertionError("extra lifecycle tool did not fail closed")

    print("research-gateway-v2 lifecycle self-test: OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(self_test())
