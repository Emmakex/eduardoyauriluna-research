#!/usr/bin/env bash
set -euo pipefail

# Register a dedicated GitHub Actions runner for Emmakex/eduardoyauriluna-research
# on the shared Kairoseth OVH host without reusing the IA Empleado runner.
#
# Required environment:
#   GITHUB_RUNNER_TOKEN  Repository-scoped runner registration token (expires quickly)
#
# Optional environment:
#   RUNNER_USER          default: ghr-eduardo-research
#   RUNNER_ROOT          default: /srv/kairoseth/runners/eduardo-research
#   RUNNER_NAME          default: <hostname>-eduardo-research
#   RUNNER_LABELS        default: kairoseth-ci,eduardo-research,ovh
#   REPOSITORY_URL       default: https://github.com/Emmakex/eduardoyauriluna-research

if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
  echo "Run as root (or with sudo)." >&2
  exit 2
fi

: "${GITHUB_RUNNER_TOKEN:?GITHUB_RUNNER_TOKEN is required}"

RUNNER_USER="${RUNNER_USER:-ghr-eduardo-research}"
RUNNER_ROOT="${RUNNER_ROOT:-/srv/kairoseth/runners/eduardo-research}"
RUNNER_NAME="${RUNNER_NAME:-$(hostname)-eduardo-research}"
RUNNER_LABELS="${RUNNER_LABELS:-kairoseth-ci,eduardo-research,ovh}"
REPOSITORY_URL="${REPOSITORY_URL:-https://github.com/Emmakex/eduardoyauriluna-research}"

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ca-certificates curl git jq tar gzip php-cli >/dev/null

if ! id "$RUNNER_USER" >/dev/null 2>&1; then
  useradd --create-home --shell /bin/bash "$RUNNER_USER"
fi

install -d -m 0750 -o "$RUNNER_USER" -g "$RUNNER_USER" "$RUNNER_ROOT"

if getent group docker >/dev/null 2>&1; then
  usermod -aG docker "$RUNNER_USER"
fi

release_json="$(curl --fail --silent --show-error \
  -H 'Accept: application/vnd.github+json' \
  https://api.github.com/repos/actions/runner/releases/latest)"
runner_version="$(jq -r '.tag_name | ltrimstr("v")' <<<"$release_json")"
asset_url="$(jq -r '.assets[] | select(.name == "actions-runner-linux-x64-'"$runner_version"'.tar.gz") | .browser_download_url' <<<"$release_json")"

if [[ -z "$runner_version" || "$runner_version" == "null" || -z "$asset_url" || "$asset_url" == "null" ]]; then
  echo "Unable to resolve latest actions/runner linux-x64 release." >&2
  exit 3
fi

current_version=""
if [[ -f "$RUNNER_ROOT/.runner-version" ]]; then
  current_version="$(cat "$RUNNER_ROOT/.runner-version")"
fi

if [[ "$current_version" != "$runner_version" || ! -x "$RUNNER_ROOT/config.sh" ]]; then
  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' EXIT
  curl --fail --location --silent --show-error "$asset_url" -o "$tmp/runner.tar.gz"

  # Do not overwrite a configured runner in place. A version mismatch is
  # deliberately fail-closed; upgrade should be handled by the runner's own
  # self-update or a controlled maintenance operation.
  if [[ -f "$RUNNER_ROOT/.runner" ]]; then
    echo "Runner already configured at $RUNNER_ROOT; refusing in-place reinstall." >&2
    exit 4
  fi

  tar -xzf "$tmp/runner.tar.gz" -C "$RUNNER_ROOT"
  printf '%s\n' "$runner_version" > "$RUNNER_ROOT/.runner-version"
  chown -R "$RUNNER_USER:$RUNNER_USER" "$RUNNER_ROOT"
fi

if [[ -f "$RUNNER_ROOT/.runner" ]]; then
  echo "Runner is already configured at $RUNNER_ROOT; nothing to register."
else
  sudo -u "$RUNNER_USER" \
    env RUNNER_ALLOW_RUNASROOT=0 \
    "$RUNNER_ROOT/config.sh" \
      --unattended \
      --url "$REPOSITORY_URL" \
      --token "$GITHUB_RUNNER_TOKEN" \
      --name "$RUNNER_NAME" \
      --labels "$RUNNER_LABELS" \
      --work _work \
      --replace
fi

cd "$RUNNER_ROOT"

if [[ ! -f /etc/systemd/system/actions.runner.Emmakex-eduardoyauriluna-research.*.service ]]; then
  ./svc.sh install "$RUNNER_USER"
fi

./svc.sh start
./svc.sh status

# Registration token is intentionally never persisted by this script.
unset GITHUB_RUNNER_TOKEN

echo "OVH runner ready: name=$RUNNER_NAME labels=self-hosted,Linux,X64,$RUNNER_LABELS"
