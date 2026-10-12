#!/usr/bin/env bash
set -euo pipefail

APPLY=0
for arg in "$@"; do
  case "$arg" in
    --apply) APPLY=1 ;;
    -h|--help)
      cat <<'EOF'
Usage: bash scripts/m8-page-production-acceptance.sh [--apply]

Runs the bounded real-site Page acceptance against the English contact Page.
Preview is the default and executes no production mutation.
With --apply, the existing M8 safe client performs Plan → Apply → stored/rendered Verify → Rollback.
The orchestrator then proves the final stored/effective Page state exactly matches the baseline.

Requires ERM_BASE_URL and ERM_TOKEN in the local environment. The token is never written to config/evidence.
EOF
      exit 0
      ;;
    *) echo "Unknown argument: $arg" >&2; exit 2 ;;
  esac
done

: "${ERM_BASE_URL:?ERM_BASE_URL must be set}"
: "${ERM_TOKEN:?ERM_TOKEN must be set}"

case "$ERM_BASE_URL" in
  https://*) ;;
  *) echo "ERM_BASE_URL must use HTTPS" >&2; exit 2 ;;
esac

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
DIR="tmp/m8-page-production-acceptance-${RUN_ID}"
CONFIG="$DIR/page-acceptance.json"
EVIDENCE="$DIR/m8-page-production-acceptance-${RUN_ID}.json"
mkdir -p "$DIR"
chmod 700 "$DIR"

cat > "$CONFIG" <<'JSON'
{
  "site": "https://eduardoyauriluna.com",
  "page": {
    "key": "contact",
    "language": "en",
    "reason": "M8 real-site Page acceptance: temporary contact eyebrow verification",
    "slots": {
      "eyebrow": "Research contact — M8 Page acceptance"
    },
    "rollback_after_verify": true
  }
}
JSON
chmod 600 "$CONFIG"

ARGS=(page "--config=$CONFIG" "--evidence=$EVIDENCE")
if [[ "$APPLY" -eq 1 ]]; then
  ARGS+=(--apply)
fi

php scripts/m8-manager-safe.php "${ARGS[@]}"

php -r '
$path = $argv[1];
$apply = "1" === $argv[2];
$j = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if (($j["result"] ?? null) !== "pass") { fwrite(STDERR, "Acceptance evidence result is not pass.\n"); exit(1); }
$cycle = $j["page_cycle"] ?? null;
if (!is_array($cycle)) { fwrite(STDERR, "Missing page_cycle evidence.\n"); exit(1); }
$before = $cycle["before"]["data"] ?? null;
if (!is_array($before) || ($before["key"] ?? null) !== "contact" || ($before["language"] ?? null) !== "en") {
    fwrite(STDERR, "Baseline Page evidence is missing or unexpected.\n"); exit(1);
}
$requests = is_array($j["requests"] ?? null) ? $j["requests"] : [];
$byLabel = [];
foreach ($requests as $request) {
    if (is_array($request) && isset($request["label"])) { $byLabel[(string) $request["label"]] = $request; }
}
if (!$apply) {
    if (empty($cycle["apply_skipped"])) { fwrite(STDERR, "Preview did not record apply_skipped.\n"); exit(1); }
    foreach ($byLabel as $label => $request) {
        if (str_ends_with($label, "-apply") || str_contains($label, "rollback")) {
            fwrite(STDERR, "Preview unexpectedly executed a mutation request: {$label}\n"); exit(1);
        }
    }
    fwrite(STDOUT, "M8 Page production Preview: PASS\nNo production mutation was executed.\nEvidence: {$path}\n");
    exit(0);
}
foreach (["page-slots-update-apply", "page-slots-update-verify", "page-slots-update-rollback", "page-after"] as $required) {
    if (!isset($byLabel[$required]) || 200 !== (int) ($byLabel[$required]["http_status"] ?? 0)) {
        fwrite(STDERR, "Missing successful request evidence: {$required}\n"); exit(1);
    }
}
$verify = $byLabel["page-slots-update-verify"]["response"]["data"] ?? [];
if (($verify["status"] ?? null) !== "verified") { fwrite(STDERR, "Page operation status is not verified.\n"); exit(1); }
if (isset($verify["stored_verification"]) && empty($verify["stored_verification"]["verified"])) {
    fwrite(STDERR, "Stored Page verification failed.\n"); exit(1);
}
if (isset($verify["rendered_verification"])) {
    $rv = $verify["rendered_verification"];
    if (empty($rv["verified"]) && empty($rv["not_applicable"])) {
        fwrite(STDERR, "Rendered Page verification failed.\n"); exit(1);
    }
}
$after = $cycle["inspection_after"]["data"] ?? null;
if (!is_array($after)) { fwrite(STDERR, "Missing final Page inspection.\n"); exit(1); }
$baselineStored = $before["stored_slots"] ?? [];
$finalStored = $after["stored_slots"] ?? [];
$baselineEffective = $before["effective_slots"] ?? [];
$finalEffective = $after["effective_slots"] ?? [];
if ($baselineStored !== $finalStored || $baselineEffective !== $finalEffective) {
    fwrite(STDERR, "Rollback did not restore the exact Page slot baseline.\n"); exit(1);
}
if (($before["page_id"] ?? null) !== ($after["page_id"] ?? null)) {
    fwrite(STDERR, "Final Page identity differs from baseline.\n"); exit(1);
}
fwrite(STDOUT, "M8 Page reversible production acceptance: PASS\nApply → stored/rendered Verify → Rollback completed and exact Page baseline restored.\nEvidence: {$path}\n");
' "$EVIDENCE" "$APPLY"
