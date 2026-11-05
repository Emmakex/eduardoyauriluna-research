#!/usr/bin/env python3
"""One-time, fail-closed commercial entitlement grant on the protected OVH host.

Changes only operations.apply in eduardo-research tenant entitlements and
research-ops principal capabilities. Never logs config, secrets or registry JSON.
Never invokes WordPress or a public Apply operation.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
import time
import urllib.request

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location(
    "greenfield_preflight", HERE / "site-architect-greenfield-authz-preflight.py"
)
if spec is None or spec.loader is None:
    raise SystemExit("ERROR: protected preflight module not found")
preflight = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = preflight
spec.loader.exec_module(preflight)

ENV = Path("/opt/site-control-mcp/.env.production")
COMPOSE = Path("/opt/site-control-mcp/compose.ingress.yml")
IMAGE_REF = Path("/opt/site-control-mcp/.current-image-ref")
CAPABILITY = "operations.apply"
PROTECTED_KEYS = ("MCP_TENANTS_JSON", "MCP_PRINCIPALS_JSON")
DIGEST = re.compile(r"^ghcr\.io/emmakex/site-control-mcp@sha256:[a-f0-9]{64}$")


class GrantError(RuntimeError):
    pass


def parse_lines(contents: str) -> dict[str, str]:
    values: dict[str, str] = {}
    for line in contents.splitlines():
        raw = line.strip()
        if not raw or raw.startswith("#"):
            continue
        if "=" not in raw:
            raise GrantError("invalid configuration line")
        key, value = raw.split("=", 1)
        if not preflight.KEY_RE.fullmatch(key) or key in values:
            raise GrantError("invalid or duplicate configuration key")
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in ("'", '"'):
            value = value[1:-1]
        values[key] = value
    return values


def rewrite(contents: str) -> tuple[str, bool]:
    before = parse_lines(contents)
    checks = preflight.inspect(before)["checks"]
    for key, value in checks.items():
        if key not in ("tenant_entitled_operations_apply", "research_ops_granted_operations_apply") and not value:
            raise GrantError("existing protected authorization prerequisites not met")

    lines = contents.splitlines(keepends=True)
    modified: set[str] = set()
    output: list[str] = []
    for line in lines:
        if not line.startswith(tuple(f"{key}=" for key in PROTECTED_KEYS)):
            output.append(line)
            continue
        key, value = line.rstrip("\r\n").split("=", 1)
        if key in modified:
            raise GrantError("duplicate protected registry")
        modified.add(key)
        stripped = value.strip()
        quote = stripped[0] if len(stripped) >= 2 and stripped[0] == stripped[-1] and stripped[0] in ("'", '"') else ""
        try:
            entries = json.loads(stripped[1:-1] if quote else stripped)
        except (ValueError, TypeError):
            raise GrantError("invalid protected registry") from None
        if not isinstance(entries, list) or not 1 <= len(entries) <= 100:
            raise GrantError("invalid protected registry shape")
        identity = "eduardo-research" if key == "MCP_TENANTS_JSON" else "research-ops"
        field = "entitlements" if key == "MCP_TENANTS_JSON" else "capabilities"
        matched = [item for item in entries if isinstance(item, dict) and item.get("id") == identity]
        if len(matched) != 1:
            raise GrantError("protected entry must exist exactly once")
        item = matched[0]
        capabilities = item.get(field)
        if not isinstance(capabilities, list) or not capabilities or not all(isinstance(v, str) for v in capabilities):
            raise GrantError("protected capabilities must be a non-empty string list")
        if CAPABILITY not in capabilities:
            capabilities.append(CAPABILITY)
        newline = "\r\n" if line.endswith("\r\n") else ("\n" if line.endswith("\n") else "")
        output.append(f"{key}={quote}{json.dumps(entries, ensure_ascii=False, separators=(',', ':'))}{quote}{newline}")

    if modified != set(PROTECTED_KEYS):
        raise GrantError("missing protected registry")
    after_text = "".join(output)
    after = parse_lines(after_text)
    if set(before) != set(after):
        raise GrantError("protected key set changed unexpectedly")
    if any(before[k] != after[k] for k in before if k not in PROTECTED_KEYS):
        raise GrantError("unrelated configuration changed")
    if preflight.inspect(after)["status"] != "ready":
        raise GrantError("protected entitlement readiness was not achieved")
    # Only one targeted entry/field may change in each registry.
    for key in PROTECTED_KEYS:
        old = json.loads(before[key])
        new = json.loads(after[key])
        identity = "eduardo-research" if key == PROTECTED_KEYS[0] else "research-ops"
        field = "entitlements" if key == PROTECTED_KEYS[0] else "capabilities"
        for a, b in zip(old, new):
            if a.get("id") != identity:
                if a != b:
                    raise GrantError("unrelated registry entry changed")
            else:
                ac = dict(a)
                bc = dict(b)
                old_caps = ac.pop(field)
                new_caps = bc.pop(field)
                if ac != bc or new_caps != old_caps + ([] if CAPABILITY in old_caps else [CAPABILITY]):
                    raise GrantError("protected registry mutation outside approved grant")
        if len(old) != len(new):
            raise GrantError("registry length changed")
    return after_text, after_text != contents


def atomic_write(contents: str, original_stat: os.stat_result) -> None:
    fd, path = tempfile.mkstemp(prefix=".env.production.greenfield-", dir=ENV.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="") as stream:
            os.fchmod(stream.fileno(), stat.S_IMODE(original_stat.st_mode))
            stream.write(contents)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(path, ENV)
    finally:
        if os.path.exists(path):
            os.unlink(path)


def compose_command(image: str, *args: str) -> list[str]:
    return [
        "docker", "compose", "--project-directory", str(ENV.parent),
        "-f", str(COMPOSE), *args,
    ]


def compose_run(image: str, *args: str) -> subprocess.CompletedProcess[str]:
    env = {**os.environ, "MCP_IMAGE_REF": image, "MCP_PUBLIC_HOST": "mcp.kairoseth.com"}
    return subprocess.run(compose_command(image, *args), env=env, text=True, check=True,
                          stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=150)


def restart(image: str) -> None:
    compose_run(image, "config", "--quiet")
    compose_run(image, "up", "-d", "--no-deps", "--force-recreate", "research-manager-mcp")
    for _ in range(60):
        cid = compose_run(image, "ps", "-q", "research-manager-mcp").stdout.strip()
        if cid:
            status = subprocess.run(
                ["docker", "inspect", "--format",
                 "{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}", cid],
                capture_output=True, text=True, check=False, timeout=10,
            )
            if status.returncode == 0 and status.stdout.strip() == "healthy":
                return
        time.sleep(2)
    raise GrantError("MCP container did not become healthy")


def verify_public_health() -> None:
    """Require the existing canonical ingress to serve healthy shared MCP."""
    for attempt in range(5):
        try:
            with urllib.request.urlopen("https://mcp.kairoseth.com/healthz", timeout=10) as response:
                if response.status == 200:
                    data = json.loads(response.read(4096))
                    if (isinstance(data, dict) and data.get("ok") is True
                            and data.get("routing") == "shared"
                            and data.get("persistent_telemetry") is True):
                        return
        except (OSError, ValueError, TimeoutError):
            pass
        if attempt < 4:
            time.sleep(3)
    raise GrantError("canonical public MCP health was not verified")


def grant() -> int:
    if not ENV.is_file() or not COMPOSE.is_file() or not IMAGE_REF.is_file():
        raise GrantError("required protected deployment files missing")
    info = ENV.stat()
    if (not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) != 0o600
            or info.st_uid != os.geteuid() or info.st_size < 1 or info.st_size > 1048576):
        raise GrantError("protected environment ownership or permissions invalid")
    original = ENV.read_text(encoding="utf-8")
    updated, changed = rewrite(original)
    image = IMAGE_REF.read_text(encoding="utf-8").strip()
    if not DIGEST.fullmatch(image):
        raise GrantError("accepted immutable image reference missing")
    # The preflight is intentionally run before touching any secret-bearing file.
    if changed:
        atomic_write(updated, info)
    try:
        restart(image)
        if preflight.inspect(preflight.protected_vars(ENV))["status"] != "ready":
            raise GrantError("post-restart protected preflight failed")
        verify_public_health()
    except Exception:
        if changed:
            atomic_write(original, info)
            try:
                restart(image)
            except Exception:
                raise GrantError("grant failed; rollback restart also failed") from None
        raise GrantError("grant failed; original protected configuration restored") from None
    print("GREENFIELD_AUTHZ_READY tenant=eduardo-research principal=research-ops capability=operations.apply")
    print("MCP_SAME_DIGEST_RESTART_HEALTHY public_health=verified public_apply=not_executed")
    return 0


def self_test() -> int:
    tenants = [
        {"id": "another", "entitlements": ["site.read"]},
        {"id": "eduardo-research", "profile": "", "writeTokenEnv": "WR_TOKEN",
         "entitlements": ["site.read"]},
    ]
    principals = [
        {"id": "another", "tenants": ["another"], "capabilities": ["site.read"]},
        {"id": "research-ops", "tenants": ["eduardo-research"], "capabilities": ["site.read"]},
    ]
    sample = {
        "MCP_SHARED_ROUTING": "true",
        "MCP_TENANTS_JSON": json.dumps(tenants, separators=(",", ":")),
        "MCP_PRINCIPALS_JSON": json.dumps(principals, separators=(",", ":")),
        "WR_TOKEN": "SECRET_NEVER_PRINTED",
        "INDEPENDENT_KEY": "SECRET_NEVER_PRINTED",
    }
    raw = "\n".join(f"{k}={v}" for k, v in sample.items()) + "\n"
    updated, changed = rewrite(raw)
    assert changed and preflight.inspect(parse_lines(updated))["status"] == "ready"
    again, changed_again = rewrite(updated)
    assert not changed_again and again == updated
    assert "SECRET_NEVER_PRINTED" in updated
    assert parse_lines(updated)["INDEPENDENT_KEY"] == sample["INDEPENDENT_KEY"]
    assert json.loads(parse_lines(updated)["MCP_TENANTS_JSON"])[0] == tenants[0]
    assert json.loads(parse_lines(updated)["MCP_PRINCIPALS_JSON"])[0] == principals[0]
    try:
        rewrite(raw.replace("MCP_SHARED_ROUTING=true", "MCP_SHARED_ROUTING=false"))
    except (GrantError, preflight.ProtectedConfigError):
        pass
    else:
        raise AssertionError("unsafe configuration was accepted")
    print("PASS: exact two protected commercial grants; no secret printing or public Apply")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--self-test", action="store_true")
    modes.add_argument("--grant", action="store_true")
    args = parser.parse_args()
    try:
        return self_test() if args.self_test else grant()
    except (GrantError, OSError, UnicodeError, ValueError, subprocess.SubprocessError):
        print("ERROR: bounded protected Greenfield grant failed (no secret values logged)", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
