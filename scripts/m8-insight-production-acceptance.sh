#!/usr/bin/env bash
set -euo pipefail

APPLY=0
for arg in "$@"; do
  case "$arg" in
    --apply) APPLY=1 ;;
    -h|--help)
      cat <<'EOF'
Usage: bash scripts/m8-insight-production-acceptance.sh [--apply]

Runs a bounded real-site Insight acceptance using a temporary English Research Insight.
Preview is the default and executes no production mutation.
With --apply, the existing M8 safe client performs Plan → Apply → stored/rendered Verify → authenticated inspection → Rollback.
The orchestrator then reruns an authenticated preflight and proves the created Insight no longer exists in the managed EN inventory.

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
DIR="tmp/m8-insight-production-acceptance-${RUN_ID}"
CONFIG="$DIR/insight-acceptance.json"
EVIDENCE="$DIR/m8-insight-production-acceptance-${RUN_ID}.json"
FINAL_EVIDENCE="$DIR/m8-insight-final-inventory-${RUN_ID}.json"
mkdir -p "$DIR"
chmod 700 "$DIR"

cat > "$CONFIG" <<'JSON'
{
  "site": "https://eduardoyauriluna.com",
  "insight": {
    "reason": "M8 real-site Insight acceptance: temporary reversible published Insight",
    "data": {
      "title": "Temporary M8 Insight acceptance — reversible production proof",
      "slug": "m8-insight-production-acceptance-20261012",
      "excerpt": "Temporary production acceptance entry used only to verify the authenticated Research Manager Insight lifecycle.",
      "content": "This temporary Research Insight exists only for the M8 production acceptance of the authenticated Research Manager. It verifies bounded creation, stored state, public rendering, authenticated inspection and safe rollback. The acceptance workflow removes this entry immediately after verification.",
      "language": "en",
      "insight_type": "research_note",
      "status": "publish"
    },
    "rollback_after_verify": true
  }
}
JSON
chmod 600 "$CONFIG"

ARGS=(insight "--config=$CONFIG" "--evidence=$EVIDENCE")
if [[ "$APPLY" -eq 1 ]]; then
  ARGS+=(--apply)
fi

php scripts/m8-manager-safe.php "${ARGS[@]}"

php -r '
$path = $argv[1];
$apply = "1" === $argv[2];
$j = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if (($j["result"] ?? null) !== "pass") { fwrite(STDERR, "Insight acceptance evidence result is not pass.\n"); exit(1); }
$cycle = $j["insight_cycle"] ?? null;
if (!is_array($cycle)) { fwrite(STDERR, "Missing insight_cycle evidence.\n"); exit(1); }
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
    fwrite(STDOUT, "M8 Insight production Preview: PASS\nNo production mutation was executed.\nEvidence: {$path}\n");
    exit(0);
}
foreach (["insight-create-apply", "insight-create-verify", "insight-inspect", "insight-seo-geo", "insight-create-rollback"] as $required) {
    if (!isset($byLabel[$required]) || 200 !== (int) ($byLabel[$required]["http_status"] ?? 0)) {
        fwrite(STDERR, "Missing successful request evidence: {$required}\n"); exit(1);
    }
}
$verify = $byLabel["insight-create-verify"]["response"]["data"] ?? [];
if (($verify["status"] ?? null) !== "verified") { fwrite(STDERR, "Insight operation status is not verified.\n"); exit(1); }
if (isset($verify["stored_verification"]) && empty($verify["stored_verification"]["verified"])) {
    fwrite(STDERR, "Stored Insight verification failed.\n"); exit(1);
}
if (isset($verify["rendered_verification"])) {
    $rv = $verify["rendered_verification"];
    if (empty($rv["verified"]) && empty($rv["not_applicable"])) {
        fwrite(STDERR, "Rendered Insight verification failed.\n"); exit(1);
    }
}
$applyData = $byLabel["insight-create-apply"]["response"]["data"] ?? [];
$postId = (int) ($applyData["target"]["post_id"] ?? $applyData["result"]["post_id"] ?? 0);
if ($postId <= 0) { fwrite(STDERR, "Insight Apply evidence does not expose the created post_id.\n"); exit(1); }
$inspect = $byLabel["insight-inspect"]["response"]["data"] ?? [];
if ((int) ($inspect["post_id"] ?? 0) !== $postId || ($inspect["status"] ?? null) !== "publish") {
    fwrite(STDERR, "Applied Insight inspection is missing or not published.\n"); exit(1);
}
$seo = $byLabel["insight-seo-geo"]["response"]["data"] ?? [];
if ((int) ($seo["post_id"] ?? 0) !== $postId || empty($seo["stored_ready"])) {
    fwrite(STDERR, "Applied Insight SEO/GEO stored readiness did not pass.\n"); exit(1);
}
if (empty($seo["rendered"]["available"]) || empty($seo["rendered"]["verified"])) {
    fwrite(STDERR, "Applied Insight public rendered verification did not pass.\n"); exit(1);
}
fwrite(STDOUT, (string) $postId);
' "$EVIDENCE" "$APPLY" > "$DIR/post-id.txt"

if [[ "$APPLY" -eq 0 ]]; then
  cat "$DIR/post-id.txt"
  exit 0
fi

POST_ID="$(cat "$DIR/post-id.txt")"
rm -f "$DIR/post-id.txt"

php scripts/m8-manager-client.php preflight "--config=$CONFIG" "--evidence=$FINAL_EVIDENCE" >/dev/null

php -r '
$primary = $argv[1];
$final = $argv[2];
$postId = (int) $argv[3];
$slug = "m8-insight-production-acceptance-20261012";
$j = json_decode((string) file_get_contents($final), true, 512, JSON_THROW_ON_ERROR);
if (($j["result"] ?? null) !== "pass") { fwrite(STDERR, "Final authenticated preflight did not pass.\n"); exit(1); }
$items = $j["preflight"]["insightsEn"]["data"]["items"] ?? null;
if (!is_array($items)) { fwrite(STDERR, "Final EN Insight inventory is missing.\n"); exit(1); }
foreach ($items as $item) {
    if (!is_array($item)) { continue; }
    if ((int) ($item["post_id"] ?? 0) === $postId || (string) ($item["slug"] ?? "") === $slug) {
        fwrite(STDERR, "Rollback failed absence proof: temporary Insight is still present in managed inventory.\n"); exit(1);
    }
}
foreach ([$primary, $final] as $path) {
    $raw = (string) file_get_contents($path);
    if (preg_match("/erm1_[A-Za-z0-9_-]{16,}/", $raw)) {
        fwrite(STDERR, "Evidence unexpectedly contains bearer-like material.\n"); exit(1);
    }
}
fwrite(STDOUT, "M8 Insight reversible production acceptance: PASS\nApply → stored/rendered Verify → authenticated inspection → Rollback completed; created Insight is absent from final managed inventory.\nEvidence: {$primary}\nFinal inventory evidence: {$final}\n");
' "$EVIDENCE" "$FINAL_EVIDENCE" "$POST_ID"
