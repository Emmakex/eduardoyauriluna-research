<?php
/** Regression guard: mutable Research Line operation names have one fail-closed authority. */

$repo_root = dirname(__DIR__, 2);
$operations_path = $repo_root . '/wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-line-operations.php';
$rest_path = $repo_root . '/wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-lines-rest.php';

function fail_m5_lines_authority(string $message): never {
    fwrite(STDERR, "M5 LINES AUTHORITY FAIL: {$message}\n");
    exit(1);
}

$operations = file_get_contents($operations_path);
$rest = file_get_contents($rest_path);

if (false === $operations || false === $rest) {
    fail_m5_lines_authority('unable to read Research Line operation sources');
}

$authority_literal = "/private const MUTABLE_OPERATIONS\\s*=\\s*array\\s*\\(\\s*'line-create'\\s*,\\s*'line-update'\\s*,?\\s*\\)\\s*;/s";
if (1 !== preg_match($authority_literal, $operations)) {
    fail_m5_lines_authority('Remote_Line_Operations does not own the canonical MUTABLE_OPERATIONS list');
}

$create_plan_authority = '/in_array\\s*\\(\\s*\\$operation\\s*,\\s*self::MUTABLE_OPERATIONS\\s*,\\s*true\\s*\\)/s';
if (1 !== preg_match($create_plan_authority, $operations)) {
    fail_m5_lines_authority('create_plan does not validate against MUTABLE_OPERATIONS');
}

if (!str_contains($rest, "'operations'=>\$this->operations->mutable_operations()")) {
    fail_m5_lines_authority('REST capabilities do not consume Operations mutable authority');
}

$duplicate_literal = "/(?:array\\s*\\(\\s*|\\[\\s*)'line-create'\\s*,\\s*'line-update'\\s*,?\\s*(?:\\)|\\])/s";
if (1 === preg_match($duplicate_literal, $rest)) {
    fail_m5_lines_authority('REST re-declares the mutable Research Line operation list');
}

fwrite(STDOUT, "M5 Research Line mutable operation authority: PASS\n");
