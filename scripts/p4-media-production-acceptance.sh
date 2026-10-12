#!/usr/bin/env bash
set -euo pipefail

# Phase 4 Remote Media production acceptance orchestrator.
#
# This wrapper deliberately delegates every authenticated request to the already
# bounded Phase 4 PHP clients. It never reads or prints the bearer token itself,
# never opens a network transport or invokes WordPress CLI, and never performs
# credential revocation.
#
# Modes:
#   --preflight-only      read-only production preflight; no resource IDs needed
#   (default)             preflight + metadata Preview + assignment Preview
#   --apply               full reversible lifecycles + read-only closeout
#   --revocation-proof    run only after the temporary credential was revoked locally
#
# Apply requires --confirm-production so a copied Preview command cannot mutate
# production accidentally.

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
ROOT_DIR="$(cd -- "${SCRIPT_DIR}/.." >/dev/null 2>&1 && pwd)"

ATTACHMENT_ID=""
INSIGHT_ID=""
FIELD="alt_text"
VALUE=""
EVIDENCE_DIR=""
MODE="preview"
CONFIRM_PRODUCTION=0

usage() {
  cat <<'EOF'
Usage:
  ERM_BASE_URL='https://eduardoyauriluna.com' ERM_TOKEN='...' \
    bash scripts/p4-media-production-acceptance.sh --preflight-only [--evidence-dir=DIR]

  ERM_BASE_URL='https://eduardoyauriluna.com' ERM_TOKEN='...' \
    bash scripts/p4-media-production-acceptance.sh \
      --attachment-id=123 --insight-id=456 \
      [--field=alt_text] [--value='Temporary Phase 4 acceptance text'] \
      [--evidence-dir=DIR]

  ERM_BASE_URL='https://eduardoyauriluna.com' ERM_TOKEN='...' \
    bash scripts/p4-media-production-acceptance.sh \
      --attachment-id=123 --insight-id=456 \
      --apply --confirm-production [--field=alt_text] [--value='Temporary text'] \
      [--evidence-dir=DIR]

After the Apply run reports PASS, revoke the temporary Media credential locally.
Then prove the old token is dead:

  ERM_BASE_URL='https://eduardoyauriluna.com' ERM_TOKEN='<just-revoked-token>' \
    bash scripts/p4-media-production-acceptance.sh --revocation-proof [--evidence-dir=DIR]

The wrapper never stores or prints ERM_TOKEN.
EOF
}

for arg in "$@"; do
  case "$arg" in
    --attachment-id=*) ATTACHMENT_ID="${arg#*=}" ;;
    --insight-id=*) INSIGHT_ID="${arg#*=}" ;;
    --field=*) FIELD="${arg#*=}" ;;
    --value=*) VALUE="${arg#*=}" ;;
    --evidence-dir=*) EVIDENCE_DIR="${arg#*=}" ;;
    --preflight-only)
      [[ "$MODE" == "preview" ]] || { echo "Only one execution mode may be selected." >&2; exit 2; }
      MODE="preflight"
      ;;
    --apply)
      [[ "$MODE" == "preview" ]] || { echo "Only one execution mode may be selected." >&2; exit 2; }
      MODE="apply"
      ;;
    --revocation-proof)
      [[ "$MODE" == "preview" ]] || { echo "Only one execution mode may be selected." >&2; exit 2; }
      MODE="revocation"
      ;;
    --confirm-production) CONFIRM_PRODUCTION=1 ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Unknown argument: $arg" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ -z "${ERM_BASE_URL:-}" || -z "${ERM_TOKEN:-}" ]]; then
  echo "ERM_BASE_URL and ERM_TOKEN must be set in the local environment." >&2
  exit 2
fi
if [[ ! "${ERM_BASE_URL}" =~ ^https:// ]]; then
  echo "ERM_BASE_URL must use HTTPS." >&2
  exit 2
fi

for required in \
  p4-media-production-preflight.php \
  p4-media-metadata-production-acceptance.php \
  p4-media-assignment-production-acceptance.php \
  p4-media-production-closeout.php \
  p4-media-revocation-probe.php; do
  [[ -f "${SCRIPT_DIR}/${required}" ]] || { echo "Missing required Phase 4 client: ${required}" >&2; exit 2; }
done
command -v php >/dev/null 2>&1 || { echo "php is required." >&2; exit 2; }

umask 077
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
if [[ -z "$EVIDENCE_DIR" ]]; then
  EVIDENCE_DIR="${ROOT_DIR}/tmp/p4-media-production-acceptance-${RUN_ID}"
fi
mkdir -p "$EVIDENCE_DIR"
chmod 700 "$EVIDENCE_DIR" 2>/dev/null || true

run_php() {
  local label="$1"
  shift
  printf '\n== %s ==\n' "$label"
  php "$@"
}

if [[ "$MODE" == "revocation" ]]; then
  run_php "P4.5 post-revocation negative-authentication proof" \
    "${SCRIPT_DIR}/p4-media-revocation-probe.php" \
    "--evidence=${EVIDENCE_DIR}/p4-media-revocation-probe-${RUN_ID}.json"
  printf '\nPhase 4 Media revocation proof: COMPLETE\nEvidence directory: %s\n' "$EVIDENCE_DIR"
  exit 0
fi

PREFLIGHT_EVIDENCE="${EVIDENCE_DIR}/p4-media-production-preflight-${RUN_ID}.json"
run_php "P4.2 read-only production preflight" \
  "${SCRIPT_DIR}/p4-media-production-preflight.php" \
  "--evidence=${PREFLIGHT_EVIDENCE}"

if [[ "$MODE" == "preflight" ]]; then
  printf '\nPhase 4 Media preflight: COMPLETE\nReview the candidate attachment/Insight in: %s\n' "$PREFLIGHT_EVIDENCE"
  exit 0
fi

if [[ ! "$ATTACHMENT_ID" =~ ^[1-9][0-9]*$ || ! "$INSIGHT_ID" =~ ^[1-9][0-9]*$ ]]; then
  echo "--attachment-id and --insight-id must be positive production IDs returned/reviewed from preflight." >&2
  exit 2
fi

case "$FIELD" in
  title|alt_text|caption|description) ;;
  *) echo "--field must be one of: title, alt_text, caption, description" >&2; exit 2 ;;
esac

if [[ -z "$VALUE" ]]; then
  VALUE="Temporary Phase 4 acceptance ${RUN_ID}"
fi
if [[ "$FIELD" == "title" && -z "${VALUE//[[:space:]]/}" ]]; then
  echo "A temporary title cannot be empty." >&2
  exit 2
fi

if [[ "$MODE" == "apply" && "$CONFIRM_PRODUCTION" -ne 1 ]]; then
  echo "--apply requires --confirm-production after reviewing the Preview evidence." >&2
  exit 2
fi

METADATA_EVIDENCE="${EVIDENCE_DIR}/p4-media-metadata-production-acceptance-${RUN_ID}.json"
ASSIGNMENT_EVIDENCE="${EVIDENCE_DIR}/p4-media-assignment-production-acceptance-${RUN_ID}.json"
CLOSEOUT_EVIDENCE="${EVIDENCE_DIR}/p4-media-production-closeout-${RUN_ID}.json"

metadata_args=(
  "${SCRIPT_DIR}/p4-media-metadata-production-acceptance.php"
  "--attachment-id=${ATTACHMENT_ID}"
  "--field=${FIELD}"
  "--value=${VALUE}"
  "--evidence=${METADATA_EVIDENCE}"
)
assignment_args=(
  "${SCRIPT_DIR}/p4-media-assignment-production-acceptance.php"
  "--insight-id=${INSIGHT_ID}"
  "--attachment-id=${ATTACHMENT_ID}"
  "--evidence=${ASSIGNMENT_EVIDENCE}"
)

if [[ "$MODE" == "apply" ]]; then
  metadata_args+=(--apply)
  assignment_args+=(--apply)
fi

run_php "P4.3 metadata lifecycle ${MODE}" "${metadata_args[@]}"
run_php "P4.4 assignment lifecycle ${MODE}" "${assignment_args[@]}"

if [[ "$MODE" == "preview" ]]; then
  printf '\nPhase 4 Media production Preview: PASS\n'
  printf 'No production mutation was executed. Review both plans before Apply.\n'
  printf 'Evidence directory: %s\n' "$EVIDENCE_DIR"
  printf 'Re-run with the same IDs/value plus --apply --confirm-production to execute reversible acceptance.\n'
  exit 0
fi

run_php "P4.5 read-only production closeout" \
  "${SCRIPT_DIR}/p4-media-production-closeout.php" \
  "--metadata-evidence=${METADATA_EVIDENCE}" \
  "--assignment-evidence=${ASSIGNMENT_EVIDENCE}" \
  "--evidence=${CLOSEOUT_EVIDENCE}"

cat >"${EVIDENCE_DIR}/REVOCATION_REQUIRED.txt" <<EOF
Phase 4 reversible production acceptance and closeout passed at $(date -u +%Y-%m-%dT%H:%M:%SZ).
The temporary Media credential must now be revoked locally.
After revocation, run this wrapper with --revocation-proof while ERM_TOKEN still contains the just-revoked token.
Do not commit credential material to GitHub.
EOF
chmod 600 "${EVIDENCE_DIR}/REVOCATION_REQUIRED.txt" 2>/dev/null || true

printf '\nPhase 4 Media reversible production acceptance: PASS\n'
printf 'Metadata and assignment were applied, verified and rolled back; closeout passed.\n'
printf 'NEXT REQUIRED ACTION: revoke the temporary Media credential locally, then run --revocation-proof.\n'
printf 'Evidence directory: %s\n' "$EVIDENCE_DIR"
