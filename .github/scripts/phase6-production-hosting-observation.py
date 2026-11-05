#!/usr/bin/env python3
"""Observe DNS routing and safe HTTPS response metadata for Eduardo Research WordPress.

This is NOT a contractual-hosting-provider detector. DNS/CDN and actual origin can
be different entities; legal processor attribution requires owner/account evidence.
No secrets, response body, Set-Cookie values, request identifiers or visitor data
are stored or printed.
"""
from __future__ import annotations

import argparse
import json
import re
import shutil
import socket
import subprocess
import urllib.parse
import urllib.request

SITE = "eduardoyauriluna.com"
URL = f"https://{SITE}/"
USER_AGENT = "EduardoResearch-Phase6-Hosting-Observation/1.0"
ALLOWED_HEADERS = (
    "server", "via", "x-powered-by", "x-litespeed-cache", "cf-cache-status",
    "x-cache", "content-type",
)
MAX_HEADER_LEN = 120


def public_address_records() -> dict[str, list[str]]:
    # OS resolver response: current public edge, not necessarily the origin host.
    results = {"ipv4": set(), "ipv6": set()}
    for family, kind in ((socket.AF_INET, "ipv4"), (socket.AF_INET6, "ipv6")):
        try:
            addresses = socket.getaddrinfo(SITE, 443, family=family, type=socket.SOCK_STREAM)
        except socket.gaierror:
            continue
        for entry in addresses:
            results[kind].add(entry[4][0])
    return {key: sorted(values) for key, values in results.items()}


def public_nameservers() -> dict:
    if shutil.which("dig") is None:
        return {"status": "unavailable", "records": [], "reason": "dig not installed"}
    try:
        query = subprocess.run(
            ["dig", "+time=3", "+tries=1", "+short", "NS", SITE],
            capture_output=True, text=True, timeout=8, check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        return {"status": "unavailable", "records": [], "reason": "DNS query failed"}
    if query.returncode != 0:
        return {"status": "unavailable", "records": [], "reason": "DNS query failed"}
    records = []
    for line in query.stdout.splitlines():
        record = line.strip().lower().rstrip(".")
        if re.fullmatch(r"[a-z0-9.-]{1,250}", record):
            records.append(record)
    return {"status": "observed", "records": sorted(set(records))}


MAIL_DOMAIN = "emmake.com"


def parse_public_mx(text: str) -> list[dict[str, str | int]]:
    """Return a bounded list of public MX hosts, never mailbox existence."""
    records: set[tuple[int, str]] = set()
    for line in text.splitlines()[:30]:
        pieces = line.strip().lower().split()
        if len(pieces) != 2 or not pieces[0].isdigit():
            continue
        priority = int(pieces[0])
        host = pieces[1].rstrip(".")
        if 0 <= priority <= 65535 and re.fullmatch(r"[a-z0-9.-]{1,250}", host):
            records.add((priority, host))
    return [{"priority": priority, "host": host} for priority, host in sorted(records)[:20]]


def public_mail_mx() -> dict:
    """Observe only the route for the confirmed public legal-contact domain."""
    if shutil.which("dig") is None:
        return {"status": "unavailable", "reason": "dig not installed", "records": []}
    try:
        query = subprocess.run(
            ["dig", "+time=3", "+tries=1", "+short", "MX", MAIL_DOMAIN],
            capture_output=True, text=True, timeout=8, check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        return {"status": "unavailable", "reason": "DNS query failed", "records": []}
    if query.returncode != 0:
        return {"status": "unavailable", "reason": "DNS query failed", "records": []}
    return {"status": "observed", "domain": MAIL_DOMAIN, "records": parse_public_mx(query.stdout)}


def safe_headers(headers) -> dict[str, str]:
    return {
        name: str(headers.get(name, "")).strip()[:MAX_HEADER_LEN]
        for name in ALLOWED_HEADERS if headers.get(name)
    }


def https_observation() -> dict:
    req = urllib.request.Request(
        URL, method="GET",
        headers={"Accept": "text/html", "User-Agent": USER_AGENT},
    )
    with urllib.request.urlopen(req, timeout=18) as response:
        host = urllib.parse.urlsplit(response.geturl()).hostname
        if host != SITE:
            raise RuntimeError("HTTPS response redirected outside canonical domain")
        return {
            "url_host": host,
            "http_status": int(response.status),
            "selected_headers": safe_headers(response.headers),
            "tls_validated_by_standard_client": True,
        }


def selftest() -> None:
    class Headers:
        def get(self, key, default=""):
            return {
                "server": "LiteSpeed",
                "Set-Cookie": "secret=DO-NOT-LOG",
                "Authorization": "Bearer DO-NOT-LOG",
                "cf-ray": "DO-NOT-LOG",
                "x-powered-by": "PHP/8.3",
            }.get(key, default)
    result = safe_headers(Headers())
    assert result == {"server": "LiteSpeed", "x-powered-by": "PHP/8.3"}
    assert "DO-NOT-LOG" not in json.dumps(result)
    assert parse_public_mx("10 ASPMX.L.GOOGLE.COM.\n5 ALT1.ASPMX.L.GOOGLE.COM.\nsecret") == [
        {"priority": 5, "host": "alt1.aspmx.l.google.com"},
        {"priority": 10, "host": "aspmx.l.google.com"},
    ]
    assert parse_public_mx("10 mx.example.com. victim@example.org") == []
    print("PASS: bounded hosting provenance observation self-test")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    if args.self_test:
        selftest()
        return
    dns = public_address_records()
    if not dns["ipv4"] and not dns["ipv6"]:
        raise RuntimeError("no DNS address record could be observed")
    https = https_observation()
    if https["http_status"] != 200:
        raise RuntimeError("canonical public HTTPS did not return 200")
    print(json.dumps({
        "status": "NETWORK_ROUTING_OBSERVATION_ONLY",
        "target": SITE,
        "dns": dns,
        "nameservers": public_nameservers(),
        "legal_contact_mail_mx": public_mail_mx(),
        "https": https,
        "not_established": [
            "WordPress contractual hosting vendor and account owner",
            "origin server behind any reverse proxy or CDN",
            "server log categories, retention period and location",
            "identity/contract of any personal-data processor",
            "mailbox delivery/existence or legal-contract details despite observable MX route",
            "email message retention and sub-processors",
        ],
        "privacy": "Public records and allowlisted response headers only; no body, cookies or private data",
    }, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
