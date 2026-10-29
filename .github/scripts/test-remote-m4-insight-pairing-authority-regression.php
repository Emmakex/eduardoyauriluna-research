<?php
/** Regression guard: mutable Insight pairing operation names have one fail-closed authority. */

$repo_root = dirname(__DIR__, 2);
$operations_path = $repo_root . '/wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-insight-pairing-operations.php';
$rest_path = $repo_root . '/wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-insights-rest.php';

function fail_m4_insight_pairing_authority(string $message): never {
    fwrite(STDERR, "M4 INSIGHT PAIRING AUTHORITY FAIL: {$message}\n");
    exit(1);
}

$operations = file_get_contents($operations_path);
$rest = file_get_contents($rest_path);

if (false === $operations || false === $rest) {
    fail_m4_insight_pairing_authority('unable to read Insight pairing operation sources');
}

$authority_literal = "/private const MUTABLE_OPERATIONS\\s*=\\s*array\\s*\\(\\s*'insight-pair'\\s*,\\s*'insight-unpair'\\s*,?\\s*\\)\\s*;/s";
if (1 !== preg_match($authority_literal, $operations)) {
    fail_m4_insight_pairing_authority('Remote_Insight_Pairing_Operations does not own the canonical MUTABLE_OPERATIONS list');
}

$create_plan_authority = '/in_array\\s*\\(\\s*\\$operation\\s*,\\s*self::MUTABLE_OPERATIONS\\s*,\\s*true\\s*\\)/s';
if (1 !== preg_match($create_plan_authority, $operations)) {
    fail_m4_insight_pairing_authority('create_plan does not validate against MUTABLE_OPERATIONS');
}

if (! str_contains($operations, 'public static function mutable_operations(): array')) {
    fail_m4_insight_pairing_authority('Operations does not expose the canonical mutable operation list');
}

if (! str_contains($rest, "'operations'=>Eduardo_Research_Manager_Remote_Insight_Pairing_Operations::mutable_operations()")) {
    fail_m4_insight_pairing_authority('Insights REST capabilities do not consume Insight Pairing Operations authority');
}

$duplicate_literal = "/(?:array\\s*\\(\\s*|\\[\\s*)'insight-pair'\\s*,\\s*'insight-unpair'\\s*,?\\s*(?:\\)|\\])/s";
if (1 === preg_match($duplicate_literal, $rest)) {
    fail_m4_insight_pairing_authority('Insights REST re-declares the mutable Insight pairing operation list');
}

fwrite(STDOUT, "M4 Insight pairing mutable operation authority: PASS\n");
