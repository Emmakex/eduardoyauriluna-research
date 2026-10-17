#!/usr/bin/env bash
set -euo pipefail

: "${MCP_INGRESS_IMAGE:?set MCP_INGRESS_IMAGE to an immutable ingress digest reference}"
: "${MCP_PUBLIC_HOST:?set MCP_PUBLIC_HOST to the public MCP hostname}"

ACTION="${MCP_DEPLOY_ACTION:-deploy}"
DEPLOY_DIR="${MCP_DEPLOY_DIR:-/opt/site-control-mcp}"
SOURCE_DIR="${MCP_RELEASE_SOURCE_DIR:-}"
ENV_FILE="$DEPLOY_DIR/.env.production"
COMPOSE_FILE="$DEPLOY_DIR/compose.ingress.yml"
CURRENT_REF_FILE="$DEPLOY_DIR/.current-image-ref"
PREVIOUS_REF_FILE="$DEPLOY_DIR/.previous-image-ref"

case "$ACTION" in
  deploy|rollback) ;;
  *) echo 'MCP_DEPLOY_ACTION must be deploy or rollback' >&2; exit 2 ;;
esac

if ! [[ "$MCP_PUBLIC_HOST" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]]; then
  echo 'MCP_PUBLIC_HOST is not a valid bounded hostname' >&2
  exit 2
fi
if ! [[ "$MCP_INGRESS_IMAGE" =~ ^[^[:space:]]+@sha256:[0-9a-f]{64}$ ]]; then
  echo 'MCP_INGRESS_IMAGE must be an immutable digest reference' >&2
  exit 2
fi
if [ "$ACTION" = 'deploy' ]; then
  : "${MCP_IMAGE_REF:?set MCP_IMAGE_REF to the immutable MCP digest reference}"
  if ! [[ "$MCP_IMAGE_REF" =~ ^ghcr\.io/emmakex/site-control-mcp@sha256:[0-9a-f]{64}$ ]]; then
    echo 'MCP_IMAGE_REF must be the GHCR digest reference produced by the publish workflow' >&2
    exit 2
  fi
fi

mkdir -p "$DEPLOY_DIR/ingress"

if [ "$ACTION" = 'deploy' ]; then
  : "${SOURCE_DIR:?set MCP_RELEASE_SOURCE_DIR for deploy}"
  test -f "$SOURCE_DIR/compose.ingress.example.yml"
  test -f "$SOURCE_DIR/ingress/Caddyfile"
  install -m 0644 "$SOURCE_DIR/compose.ingress.example.yml" "$COMPOSE_FILE"
  install -m 0644 "$SOURCE_DIR/ingress/Caddyfile" "$DEPLOY_DIR/ingress/Caddyfile"
fi

test -f "$COMPOSE_FILE"
test -f "$DEPLOY_DIR/ingress/Caddyfile"
test -f "$ENV_FILE" || { echo "$ENV_FILE must be pre-provisioned server-side" >&2; exit 1; }

# Production secret/config file must not be readable by group or world.
if [ -n "$(find "$ENV_FILE" -maxdepth 0 -perm /077 -print -quit)" ]; then
  echo '.env.production must have no group/world permissions' >&2
  exit 1
fi

# Validate required production posture without printing secret-bearing lines.
grep -Fqx 'MCP_SHARED_ROUTING=true' "$ENV_FILE" || { echo 'MCP_SHARED_ROUTING=true is required' >&2; exit 1; }
grep -Fqx 'MCP_REQUIRE_PERSISTENT_TELEMETRY=true' "$ENV_FILE" || { echo 'persistent telemetry must be required' >&2; exit 1; }
grep -Fqx 'MCP_TELEMETRY_PATH=/var/lib/site-control-mcp/events.jsonl' "$ENV_FILE" || { echo 'canonical persistent telemetry path is required' >&2; exit 1; }
grep -Fqx "MCP_ALLOWED_HOSTS=$MCP_PUBLIC_HOST" "$ENV_FILE" || { echo 'MCP_ALLOWED_HOSTS must equal the public MCP hostname' >&2; exit 1; }
grep -Eq '^MCP_TENANTS_JSON=.+$' "$ENV_FILE" || { echo 'MCP_TENANTS_JSON is required' >&2; exit 1; }
grep -Eq '^MCP_PRINCIPALS_JSON=.+$' "$ENV_FILE" || { echo 'MCP_PRINCIPALS_JSON is required' >&2; exit 1; }

command -v docker >/dev/null
docker compose version >/dev/null

compose() {
  MCP_IMAGE_REF="$1" \
  MCP_INGRESS_IMAGE="$MCP_INGRESS_IMAGE" \
  MCP_PUBLIC_HOST="$MCP_PUBLIC_HOST" \
  docker compose --project-directory "$DEPLOY_DIR" -f "$COMPOSE_FILE" "${@:2}"
}

wait_healthy() {
  local image_ref="$1"
  local cid ingress_cid status ingress_status
  for _ in $(seq 1 60); do
    cid="$(compose "$image_ref" ps -q research-manager-mcp 2>/dev/null || true)"
    ingress_cid="$(compose "$image_ref" ps -q ingress 2>/dev/null || true)"
    if [ -n "$cid" ] && [ -n "$ingress_cid" ]; then
      status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$cid" 2>/dev/null || true)"
      ingress_status="$(docker inspect --format '{{.State.Status}}' "$ingress_cid" 2>/dev/null || true)"
      if [ "$status" = 'healthy' ] && [ "$ingress_status" = 'running' ]; then
        return 0
      fi
    fi
    sleep 2
  done
  return 1
}

activate() {
  local image_ref="$1"
  compose "$image_ref" config --quiet
  compose "$image_ref" pull
  compose "$image_ref" up -d --remove-orphans
  wait_healthy "$image_ref"
}

current_ref=''
if [ -f "$CURRENT_REF_FILE" ]; then
  current_ref="$(tr -d '\r\n' < "$CURRENT_REF_FILE")"
fi

if [ "$ACTION" = 'rollback' ]; then
  test -f "$PREVIOUS_REF_FILE" || { echo 'no previous image reference is recorded' >&2; exit 1; }
  target_ref="$(tr -d '\r\n' < "$PREVIOUS_REF_FILE")"
  if ! [[ "$target_ref" =~ ^ghcr\.io/emmakex/site-control-mcp@sha256:[0-9a-f]{64}$ ]]; then
    echo 'recorded previous image reference is invalid' >&2
    exit 1
  fi
  activate "$target_ref"
  if [ -n "$current_ref" ] && [ "$current_ref" != "$target_ref" ]; then
    printf '%s\n' "$current_ref" > "$PREVIOUS_REF_FILE"
    chmod 0600 "$PREVIOUS_REF_FILE"
  fi
  printf '%s\n' "$target_ref" > "$CURRENT_REF_FILE"
  chmod 0600 "$CURRENT_REF_FILE"
  echo "rollback healthy: $target_ref"
  exit 0
fi

# Deploy exact requested digest. Roll back automatically to the prior accepted digest on local health failure.
if ! activate "$MCP_IMAGE_REF"; then
  echo 'new deployment failed local container health checks' >&2
  if [ -n "$current_ref" ] && [[ "$current_ref" =~ ^ghcr\.io/emmakex/site-control-mcp@sha256:[0-9a-f]{64}$ ]]; then
    echo "attempting automatic rollback to $current_ref" >&2
    activate "$current_ref" || { echo 'automatic rollback also failed' >&2; exit 1; }
  fi
  exit 1
fi

if [ -n "$current_ref" ] && [ "$current_ref" != "$MCP_IMAGE_REF" ]; then
  printf '%s\n' "$current_ref" > "$PREVIOUS_REF_FILE"
  chmod 0600 "$PREVIOUS_REF_FILE"
fi
printf '%s\n' "$MCP_IMAGE_REF" > "$CURRENT_REF_FILE"
chmod 0600 "$CURRENT_REF_FILE"
echo "deployment healthy: $MCP_IMAGE_REF"
