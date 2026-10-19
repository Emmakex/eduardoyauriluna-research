#!/usr/bin/env python3
"""Reject GitHub Actions reruns before command resolution or MCP access."""
from __future__ import annotations

import argparse
import os
import re
import sys

ATTEMPT_RE = re.compile(r"^[1-9][0-9]*$")


class AttemptError(RuntimeError):
    """Fail-closed run-attempt validation error."""


def validate_attempt(raw: str | None) -> int:
    value = (raw or "").strip()
    if not ATTEMPT_RE.fullmatch(value):
        raise AttemptError("GITHUB_RUN_ATTEMPT must be a positive integer")
    attempt = int(value)
    if attempt != 1:
        raise AttemptError("historical workflow reruns are not permitted")
    return attempt


def self_test() -> int:
    if validate_attempt("1") != 1:
        raise AttemptError("self-test did not accept first execution")
    for rejected in (None, "", "0", "-1", "2", "17", "abc", "1.0"):
        try:
            validate_attempt(rejected)
        except AttemptError:
            continue
        raise AttemptError(f"self-test accepted forbidden attempt: {rejected!r}")
    print("phase2d-run-attempt-guard self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    parser.add_argument("--attempt", help="explicit attempt value for local verification")
    args = parser.parse_args()
    if args.self_test:
        return self_test()
    raw = args.attempt if args.attempt is not None else os.environ.get("GITHUB_RUN_ATTEMPT")
    try:
        attempt = validate_attempt(raw)
    except AttemptError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2
    print(f"RUN_ATTEMPT_OK attempt={attempt}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
