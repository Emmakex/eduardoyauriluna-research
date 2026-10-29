<?php
/** Regression guard: mutable Evidence operation names have one fail-closed authority. */

$repo_root = dirname(__DIR__, 2);
$operations_path = $repo_root . '/wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-evidence-operations.php';
$rest_path = $repo_root . '/wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-evidence-rest.php';

function fail_m5_evidence_authority(string $message): never {
    fwrite(STDERR, "M5 EVIDENCE AUTHORITY FAIL: {$message}\n");
    exit(1);
}

$operations = file_get_contents($operations_path);
$rest = file_get_contents($rest_path);

if (false === $operations || false === $rest) {
    fail_m5_evidence_authority('unable to read Evidence operation sources');
}

$canonical_operations = array(
    'identity-update',
    'evidence-create',
    'evidence-update',
    'evidence-delete',
);

if (!str_contains($operations, 'private const MUTABLE_OPERATIONS = [')) {
    fail_m5_evidence_authority('Remote_Evidence_Operations does not declare MUTABLE_OPERATIONS');
}

foreach ($canonical_operations as $operation) {
    if (!str_contains($operations, "'{$operation}'")) {
        fail_m5_evidence_authority("MUTABLE_OPERATIONS is missing {$operation}");
    }
}

if (!str_contains($operations, 'in_array( $operation, self::MUTABLE_OPERATIONS, true )')) {
    fail_m5_evidence_authority('create_plan does not validate against MUTABLE_OPERATIONS');
}

if (!str_contains($operations, "private const GROUP_ROUTE = 'eduardo/v1/research/groups/';")) {
    fail_m5_evidence_authority('GROUP_ROUTE changed unexpectedly');
}

if (!str_contains($rest, '$this->operations->mutable_operations()')) {
    fail_m5_evidence_authority('REST capabilities do not consume Operations mutable authority');
}

$duplicate_literal = "/\\[\\s*'identity-update'\\s*,\\s*'evidence-create'\\s*,\\s*'evidence-update'\\s*,\\s*'evidence-delete'\\s*,?\\s*\\]/s";
if (1 === preg_match($duplicate_literal, $rest)) {
    fail_m5_evidence_authority('REST re-declares the mutable Evidence operation list');
}

fwrite(STDOUT, "M5 Evidence mutable operation authority: PASS\n");
