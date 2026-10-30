#!/usr/bin/env bash
set -euo pipefail

readonly BASE_URL="https://eduardoyauriluna.com"
readonly USER_AGENT="EduardoResearch-Phase3-Acceptance/1.0"

readonly -a HEALTH_ROUTES=(
  "/en/"
  "/es/"
)

readonly -a EMPTY_COLLECTION_ROUTES=(
  "/en/publications/"
  "/en/projects/"
  "/en/software/"
  "/en/datasets/"
  "/es/publicaciones/"
  "/es/proyectos/"
  "/es/software/"
  "/es/datos/"
)

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

if [[ "${1:-}" == "--self-test" ]]; then
  [[ ${#HEALTH_ROUTES[@]} -eq 2 ]] || fail "expected 2 health routes"
  [[ ${#EMPTY_COLLECTION_ROUTES[@]} -eq 8 ]] || fail "expected 8 empty collection routes"
  [[ "${EMPTY_COLLECTION_ROUTES[*]}" == *"/en/publications/"* ]] || fail "missing EN publications route"
  [[ "${EMPTY_COLLECTION_ROUTES[*]}" == *"/es/datos/"* ]] || fail "missing ES datasets route"
  printf 'PASS: contract contains %d health routes and %d empty collection routes.\n' \
    "${#HEALTH_ROUTES[@]}" "${#EMPTY_COLLECTION_ROUTES[@]}"
  exit 0
fi

probe() {
  local path="$1"
  local url="${BASE_URL}${path}"
  local result status effective_url

  if ! result="$(curl \
    --silent \
    --show-error \
    --location \
    --max-redirs 5 \
    --connect-timeout 10 \
    --max-time 30 \
    --proto '=https' \
    --proto-redir '=https' \
    --tlsv1.2 \
    --user-agent "$USER_AGENT" \
    --output /dev/null \
    --write-out '%{http_code}\t%{url_effective}' \
    "$url")"; then
    fail "request failed for ${url}"
  fi

  IFS=$'\t' read -r status effective_url <<<"$result"
  [[ -n "$status" && -n "$effective_url" ]] || fail "incomplete curl result for ${url}"

  case "$effective_url" in
    "${BASE_URL}"/*) ;;
    *) fail "redirect escaped production host for ${url}: ${effective_url}" ;;
  esac

  printf '%s\t%s\t%s\n' "$status" "$url" "$effective_url"
}

printf 'Phase 3 production route acceptance\n'
printf 'Target: %s\n' "$BASE_URL"

printf '\nBaseline routes (final status must be 2xx):\n'
for path in "${HEALTH_ROUTES[@]}"; do
  line="$(probe "$path")"
  IFS=$'\t' read -r status requested effective <<<"$line"
  printf 'GET %s -> %s (final: %s)\n' "$requested" "$status" "$effective"
  [[ "$status" =~ ^2[0-9][0-9]$ ]] || fail "baseline route did not return 2xx: ${requested} -> ${status}"
done

printf '\nEmpty collection routes (final status must be 404):\n'
for path in "${EMPTY_COLLECTION_ROUTES[@]}"; do
  line="$(probe "$path")"
  IFS=$'\t' read -r status requested effective <<<"$line"
  printf 'GET %s -> %s (final: %s)\n' "$requested" "$status" "$effective"
  [[ "$status" == "404" ]] || fail "empty collection route did not return 404: ${requested} -> ${status}"
done

printf '\nPASS: production is reachable and all 8 empty collection routes return 404.\n'
