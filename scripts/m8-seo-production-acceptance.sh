#!/usr/bin/env bash
set -euo pipefail

APPLY=0
CHECK_ID=""
for arg in "$@"; do
  case "$arg" in
    --apply) APPLY=1 ;;
    --check-id=*) CHECK_ID="${arg#--check-id=}" ;;
    -h|--help)
      cat <<'EOF'
Usage:
  bash scripts/m8-seo-production-acceptance.sh
  bash scripts/m8-seo-production-acceptance.sh --check-id=<safe-check-id>
  bash scripts/m8-seo-production-acceptance.sh --check-id=<safe-check-id> --apply

Default mode is read-only Discovery. It records live SEO/GEO readiness and lists only
safe site-diagnostics remediations delegated to the bounded seo-geo gateway.

With --check-id, the same live Discovery is rerun first and the requested check must
still be an eligible safe remediation. Preview creates only the exact remediation plan.
No production remediation is applied unless --apply is also supplied.

With --apply, the existing M8 safe client performs Plan → Apply → rendered Verify →
readiness rerun → Rollback → final readiness. This wrapper then checks that the final
semantic SEO/GEO readiness baseline matches the pre-operation baseline.

Requires ERM_BASE_URL and ERM_TOKEN in the local environment. The token is never
written to config or evidence.
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

if [[ -n "$CHECK_ID" && ! "$CHECK_ID" =~ ^[a-z0-9][a-z0-9-]*$ ]]; then
  echo "--check-id must contain only lowercase letters, numbers and hyphens." >&2
  exit 2
fi
if [[ "$APPLY" -eq 1 && -z "$CHECK_ID" ]]; then
  echo "--apply requires an explicit --check-id selected from Discovery." >&2
  exit 2
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
DIR="tmp/m8-seo-production-acceptance-${RUN_ID}"
DISCOVERY_CONFIG="$DIR/seo-discovery.json"
DISCOVERY_EVIDENCE="$DIR/m8-seo-discovery-${RUN_ID}.json"
ACCEPTANCE_CONFIG="$DIR/seo-acceptance.json"
ACCEPTANCE_EVIDENCE="$DIR/m8-seo-production-acceptance-${RUN_ID}.json"
mkdir -p "$DIR"
chmod 700 "$DIR"

cat > "$DISCOVERY_CONFIG" <<'JSON'
{
  "site": "https://eduardoyauriluna.com"
}
JSON
chmod 600 "$DISCOVERY_CONFIG"

php scripts/m8-manager-safe.php preflight \
  "--config=$DISCOVERY_CONFIG" \
  "--evidence=$DISCOVERY_EVIDENCE"

DISCOVERY_RESULT="$(php -r '
$path = $argv[1];
$requested = $argv[2];
$j = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if (($j["result"] ?? null) !== "pass") { fwrite(STDERR, "SEO/GEO discovery evidence result is not pass.\n"); exit(1); }
$seo = $j["preflight"]["seo"]["data"] ?? null;
if (!is_array($seo)) { fwrite(STDERR, "Missing SEO/GEO readiness in preflight evidence.\n"); exit(1); }
$safe = $seo["residual_actions"]["safe_remediation"] ?? [];
if (!is_array($safe)) { $safe = []; }
$candidates = [];
foreach ($safe as $item) {
    if (!is_array($item)) { continue; }
    $action = is_array($item["action"] ?? null) ? $item["action"] : [];
    $required = is_array($action["required_input"] ?? null) ? array_values($action["required_input"]) : [];
    $operation = (string) ($action["operation"] ?? "");
    $check = (string) ($item["check_id"] ?? "");
    if ($check === ""
        || (string) ($item["source"] ?? "") !== "site-diagnostics"
        || (string) ($action["gateway"] ?? "") !== "seo-geo"
        || !in_array($operation, ["seo-remediate", "search-indexability"], true)
        || !empty($action["requires_evidence"])
        || $required) {
        continue;
    }
    $candidates[$check] = [
        "check_id" => $check,
        "operation" => $operation,
        "status" => (string) ($item["status"] ?? ""),
        "reason" => (string) ($item["reason"] ?? ""),
    ];
}
ksort($candidates);
fwrite(STDOUT, "SEO/GEO production Discovery: PASS\n");
fwrite(STDOUT, "Readiness: " . (!empty($seo["ready"]) ? "ready" : "not-ready")
    . "; blocking=" . (int) ($seo["blocking_count"] ?? 0)
    . "; advisories=" . (int) ($seo["advisory_count"] ?? 0) . "\n");
fwrite(STDOUT, "Safe SEO/GEO candidates: " . count($candidates) . "\n");
foreach ($candidates as $candidate) {
    fwrite(STDOUT, "- {$candidate["check_id"]} | {$candidate["operation"]} | {$candidate["status"]} | {$candidate["reason"]}\n");
}
fwrite(STDOUT, "Discovery evidence: {$path}\n");
if ($requested !== "" && !isset($candidates[$requested])) {
    fwrite(STDERR, "Requested check_id is not an eligible live safe SEO/GEO remediation: {$requested}\n");
    exit(3);
}
echo $requested === "" ? "DISCOVERY_ONLY" : "ELIGIBLE";
' "$DISCOVERY_EVIDENCE" "$CHECK_ID")"

if grep -q 'erm1_' "$DISCOVERY_EVIDENCE"; then
  echo "Discovery evidence unexpectedly contains bearer-like material." >&2
  exit 1
fi

if [[ -z "$CHECK_ID" ]]; then
  echo "M8 SEO/GEO production Discovery: COMPLETE"
  echo "No production mutation was executed."
  echo "Evidence directory: $DIR"
  exit 0
fi

cat > "$ACCEPTANCE_CONFIG" <<JSON
{
  "site": "https://eduardoyauriluna.com",
  "seo": {
    "check_id": "$CHECK_ID",
    "intent": "M8 real-site SEO/GEO acceptance: reversible bounded remediation",
    "rollback_after_verify": true
  }
}
JSON
chmod 600 "$ACCEPTANCE_CONFIG"

ARGS=(seo "--config=$ACCEPTANCE_CONFIG" "--evidence=$ACCEPTANCE_EVIDENCE")
if [[ "$APPLY" -eq 1 ]]; then
  ARGS+=(--apply)
fi
php scripts/m8-manager-safe.php "${ARGS[@]}"

php -r '
$discoveryPath = $argv[1];
$acceptancePath = $argv[2];
$apply = "1" === $argv[3];
$checkId = $argv[4];
$d = json_decode((string) file_get_contents($discoveryPath), true, 512, JSON_THROW_ON_ERROR);
$j = json_decode((string) file_get_contents($acceptancePath), true, 512, JSON_THROW_ON_ERROR);
if (($j["result"] ?? null) !== "pass") { fwrite(STDERR, "SEO/GEO acceptance evidence result is not pass.\n"); exit(1); }
$cycle = $j["seo_cycle"] ?? null;
if (!is_array($cycle)) { fwrite(STDERR, "Missing seo_cycle evidence.\n"); exit(1); }
$requests = is_array($j["requests"] ?? null) ? $j["requests"] : [];
$byLabel = [];
foreach ($requests as $request) {
    if (is_array($request) && isset($request["label"])) { $byLabel[(string) $request["label"]] = $request; }
}
if (!isset($byLabel["seo-plan"]) || 200 !== (int) ($byLabel["seo-plan"]["http_status"] ?? 0)) {
    fwrite(STDERR, "Missing successful exact SEO/GEO plan request.\n"); exit(1);
}
if (!$apply) {
    if (empty($cycle["apply_skipped"])) { fwrite(STDERR, "SEO/GEO Preview did not record apply_skipped.\n"); exit(1); }
    foreach ($byLabel as $label => $request) {
        if (in_array($label, ["seo-apply", "seo-verify", "seo-rollback"], true)) {
            fwrite(STDERR, "SEO/GEO Preview unexpectedly executed mutation lifecycle step: {$label}\n"); exit(1);
        }
    }
    fwrite(STDOUT, "M8 SEO/GEO production Preview: PASS\nEligible check_id: {$checkId}\nNo production remediation was applied.\nEvidence: {$acceptancePath}\n");
    exit(0);
}
foreach (["seo-apply", "seo-verify", "seo-readiness-after", "seo-rollback", "seo-readiness-final"] as $required) {
    if (!isset($byLabel[$required]) || 200 !== (int) ($byLabel[$required]["http_status"] ?? 0)) {
        fwrite(STDERR, "Missing successful SEO/GEO lifecycle evidence: {$required}\n"); exit(1);
    }
}
$verify = $byLabel["seo-verify"]["response"]["data"] ?? [];
if (($verify["status"] ?? null) !== "verified") {
    fwrite(STDERR, "SEO/GEO operation status is not verified.\n"); exit(1);
}
if (isset($verify["rendered_verification"])) {
    $rv = $verify["rendered_verification"];
    if (empty($rv["verified"]) && empty($rv["not_applicable"])) {
        fwrite(STDERR, "Rendered SEO/GEO verification failed.\n"); exit(1);
    }
}
$baseline = $d["preflight"]["seo"]["data"] ?? null;
$final = $cycle["readiness_final"]["data"] ?? null;
if (!is_array($baseline) || !is_array($final)) {
    fwrite(STDERR, "Missing baseline or final SEO/GEO readiness.\n"); exit(1);
}
$normalise = static function(array $seo): array {
    $safe = $seo["residual_actions"]["safe_remediation"] ?? [];
    $candidates = [];
    if (is_array($safe)) {
        foreach ($safe as $item) {
            if (!is_array($item)) { continue; }
            $action = is_array($item["action"] ?? null) ? $item["action"] : [];
            if ((string) ($item["source"] ?? "") !== "site-diagnostics" || (string) ($action["gateway"] ?? "") !== "seo-geo") { continue; }
            $id = (string) ($item["check_id"] ?? "");
            if ($id !== "") { $candidates[$id] = (string) ($action["operation"] ?? ""); }
        }
    }
    ksort($candidates);
    return [
        "ready" => !empty($seo["ready"]),
        "optimization_complete" => !empty($seo["optimization_complete"]),
        "blocking_count" => (int) ($seo["blocking_count"] ?? 0),
        "advisory_count" => (int) ($seo["advisory_count"] ?? 0),
        "site_ready" => !empty($seo["site"]["ready"]),
        "site_summary" => $seo["site"]["summary"] ?? [],
        "indexability_ready" => !empty($seo["indexability"]["ready"]),
        "residual_summary" => $seo["residual_actions"]["summary"] ?? [],
        "safe_site_checks" => $candidates,
    ];
};
if ($normalise($baseline) !== $normalise($final)) {
    fwrite(STDERR, "Rollback did not restore the semantic SEO/GEO readiness baseline.\n"); exit(1);
}
fwrite(STDOUT, "M8 SEO/GEO reversible production acceptance: PASS\nPlan → Apply → rendered Verify → readiness rerun → Rollback completed; semantic readiness baseline restored.\nEvidence: {$acceptancePath}\n");
' "$DISCOVERY_EVIDENCE" "$ACCEPTANCE_EVIDENCE" "$APPLY" "$CHECK_ID"

if grep -q 'erm1_' "$ACCEPTANCE_EVIDENCE"; then
  echo "Acceptance evidence unexpectedly contains bearer-like material." >&2
  exit 1
fi

echo "Evidence directory: $DIR"
