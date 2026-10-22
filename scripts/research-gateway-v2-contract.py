#!/usr/bin/env python3
"""Declarative action contract and Manager-scope preflight for Research Gateway v2.

The registry is the single source of truth for Gateway v2 dispatch metadata and
required live Research Manager scopes. Command payloads still expose bounded business
actions only; this module never accepts caller-selected MCP tools or URLs.

Commercial MCP capabilities remain an independent inner authorization boundary and
are enforced by the production MCP itself. This preflight intentionally validates the
WordPress Research Manager credential scopes returned by /capabilities.
"""
from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
REGISTRY_PATH = ROOT / "config" / "research-gateway-v2-action-registry.json"
SCRIPTS_DIR = ROOT / "scripts"
ACTION_RE = re.compile(r"^[a-z][a-z0-9._-]{2,96}$")
CAPABILITY_RE = re.compile(r"^[a-z][a-z0-9._-]{2,96}$")
MODULE_RE = re.compile(r"^[a-z0-9][a-z0-9._-]*\.py$")
SUPPORTED_RESOLVERS = {"manager_resource_read"}
RESOURCE_READ_CAPABILITIES = {
    "insight": "insights.read",
    "research_object": "research.read",
    "research_line": "research.read",
    "evidence": "evidence.read",
    "page": "pages.read",
    # Research Manager deliberately governs Theme navigation reads with pages.read.
    # The outer MCP independently enforces its commercial navigation.read capability.
    "navigation": "pages.read",
    # Status and versions are bounded system reads exposed by the Manager under site.read.
    "status": "site.read",
    "versions": "site.read",
}


class ContractError(RuntimeError):
    """Fail-closed action-contract error."""


class ScopeDenied(ContractError):
    """The live Research Manager credential is missing one or more declared scopes."""

    def __init__(self, missing: list[str], required: list[str]) -> None:
        self.missing = missing
        self.required = required
        super().__init__("scope denied: live Manager credential is not granted: " + ", ".join(missing))


def load_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ContractError(f"invalid Gateway v2 registry: {path.name}") from exc
    if not isinstance(value, dict):
        raise ContractError("Gateway v2 registry must be a JSON object")
    return value


def _module_path(value: str) -> Path | None:
    if value == "base":
        return None
    if MODULE_RE.fullmatch(value) is None:
        raise ContractError("Gateway v2 registry contains an invalid module filename")
    path = (SCRIPTS_DIR / value).resolve()
    if path.parent != SCRIPTS_DIR.resolve() or not path.is_file():
        raise ContractError(f"Gateway v2 registry module is unavailable: {value}")
    return path


def validate_registry(registry: dict[str, Any]) -> None:
    if set(registry) != {"version", "tenant", "actions"}:
        raise ContractError("Gateway v2 registry top-level keys changed unexpectedly")
    if registry.get("version") != "1":
        raise ContractError("unsupported Gateway v2 registry version")
    if registry.get("tenant") != "eduardo-research":
        raise ContractError("Gateway v2 registry tenant changed unexpectedly")
    actions = registry.get("actions")
    if not isinstance(actions, dict) or not actions:
        raise ContractError("Gateway v2 registry exposes no actions")

    for action, cfg in actions.items():
        if not isinstance(action, str) or ACTION_RE.fullmatch(action) is None:
            raise ContractError("Gateway v2 registry contains an invalid action name")
        if not isinstance(cfg, dict):
            raise ContractError(f"Gateway v2 action {action} must be an object")
        allowed = {
            "runner",
            "validator",
            "read_only",
            "required_capabilities",
            "capability_resolver",
        }
        if set(cfg) - allowed:
            raise ContractError(f"Gateway v2 action {action} contains unsupported contract keys")
        if not isinstance(cfg.get("runner"), str) or not isinstance(cfg.get("validator"), str):
            raise ContractError(f"Gateway v2 action {action} must declare runner and validator")
        _module_path(str(cfg["runner"]))
        _module_path(str(cfg["validator"]))
        if not isinstance(cfg.get("read_only"), bool):
            raise ContractError(f"Gateway v2 action {action} must declare read_only")

        required = cfg.get("required_capabilities")
        resolver = cfg.get("capability_resolver")
        if (required is None) == (resolver is None):
            raise ContractError(
                f"Gateway v2 action {action} must declare exactly one capability contract"
            )
        if required is not None:
            if (
                not isinstance(required, list)
                or not required
                or any(not isinstance(item, str) or CAPABILITY_RE.fullmatch(item) is None for item in required)
                or len(set(required)) != len(required)
            ):
                raise ContractError(f"Gateway v2 action {action} has invalid required_capabilities")
        if resolver is not None and resolver not in SUPPORTED_RESOLVERS:
            raise ContractError(f"Gateway v2 action {action} has unsupported capability_resolver")


def load_registry() -> dict[str, Any]:
    registry = load_json(REGISTRY_PATH)
    validate_registry(registry)
    return registry


def action_contract(action: str, registry: dict[str, Any] | None = None) -> dict[str, Any]:
    source = registry or load_registry()
    actions = source["actions"]
    cfg = actions.get(action)
    if not isinstance(cfg, dict):
        raise ContractError(f"Gateway v2 action is not registered: {action}")
    return cfg


def runner_path(action: str, registry: dict[str, Any] | None = None) -> Path | None:
    return _module_path(str(action_contract(action, registry)["runner"]))


def validator_path(action: str, registry: dict[str, Any] | None = None) -> Path | None:
    return _module_path(str(action_contract(action, registry)["validator"]))


def required_capabilities(
    command: dict[str, Any], registry: dict[str, Any] | None = None
) -> list[str]:
    action = command.get("action")
    if not isinstance(action, str):
        raise ContractError("command action is invalid")
    cfg = action_contract(action, registry)
    static = cfg.get("required_capabilities")
    if isinstance(static, list):
        return list(static)

    resolver = cfg.get("capability_resolver")
    if resolver == "manager_resource_read":
        payload = command.get("payload")
        if not isinstance(payload, dict):
            raise ContractError("manager resource command payload is invalid")
        resource = payload.get("resource")
        capability = RESOURCE_READ_CAPABILITIES.get(resource)
        if capability is not None:
            return [capability]
        raise ContractError("manager resource command has no bounded capability mapping")

    raise ContractError("Gateway v2 action has no resolvable capability contract")


def granted_scopes(capabilities: Any) -> list[str]:
    """Extract the authenticated Manager credential's granted_scopes, fail closed if absent."""
    candidates: list[list[str]] = []

    def visit(value: Any, depth: int = 0) -> None:
        if depth > 10:
            return
        if isinstance(value, dict):
            granted = value.get("granted_scopes")
            if isinstance(granted, list) and all(isinstance(item, str) for item in granted):
                candidates.append(list(granted))
            for child in value.values():
                visit(child, depth + 1)
        elif isinstance(value, list):
            for child in value[:200]:
                visit(child, depth + 1)

    visit(capabilities)
    if len(candidates) != 1:
        raise ContractError("live Manager capabilities did not expose one unambiguous granted_scopes list")
    scopes = candidates[0]
    if any(CAPABILITY_RE.fullmatch(item) is None for item in scopes):
        raise ContractError("live Manager capabilities exposed an invalid granted scope")
    return sorted(set(scopes))


def preflight(command: dict[str, Any], capabilities: Any, registry: dict[str, Any] | None = None) -> dict[str, Any]:
    cfg = action_contract(str(command.get("action", "")), registry)
    required = required_capabilities(command, registry)
    granted = granted_scopes(capabilities)
    missing = sorted(set(required) - set(granted))
    if missing:
        raise ScopeDenied(missing, required)
    return {
        "checked": True,
        "read_only": bool(cfg["read_only"]),
        "required_capabilities": required,
        "granted": True,
    }


def classify_failure(diagnostic: str) -> tuple[str, str, str]:
    """Normalize sanitized diagnostics into stable Gateway failure semantics."""
    lowered = diagnostic.lower()
    if any(
        marker in lowered
        for marker in (
            "principal_not_granted",
            "commercial entitlement denied",
            "insufficient_scope",
            "scope denied",
            "scope_missing",
            "not granted",
        )
    ):
        return (
            "scope_denied",
            "authorization",
            "The production authorization contract does not grant the required capability or scope.",
        )
    if any(marker in lowered for marker in ("tool_is_error", "iserror:true", '"iserror":true')):
        return (
            "mcp_refused",
            "execution",
            "The production MCP or Research Manager refused the bounded operation.",
        )
    if any(
        marker in lowered
        for marker in (
            "connection refused",
            "connection reset",
            "timed out",
            "timeout",
            "could not resolve",
            "temporary failure in name resolution",
            "network is unreachable",
            "tls",
            "ssl",
        )
    ):
        return (
            "transport_failed",
            "transport",
            "The production MCP transport did not complete successfully.",
        )
    return (
        "execution_failed",
        "execution",
        "The bounded production operation did not complete successfully.",
    )


if __name__ == "__main__":
    registry = load_registry()
    print(f"research-gateway-v2 action contract: OK ({len(registry['actions'])} actions)")
