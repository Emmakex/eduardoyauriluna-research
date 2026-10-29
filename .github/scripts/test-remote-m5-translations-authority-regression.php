<?php
/** Regression guard: mutable Translation operation names have one fail-closed authority. */

$repo_root = dirname(__DIR__, 2);
$operations_path = $repo_root . '/wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-translation-operations.php';
$rest_path = $repo_root . '/wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-translations-rest.php';

function fail_m5_translations_authority(string $message): never {
    fwrite(STDERR, "M5 TRANSLATIONS AUTHORITY FAIL: {$message}\n");
    exit(1);
}

$operations = file_get_contents($operations_path);
$rest = file_get_contents($rest_path);

if (false === $operations || false === $rest) {
    fail_m5_translations_authority('unable to read Translation operation sources');
}

$authority_literal = "/private const MUTABLE_OPERATIONS\\s*=\\s*array\\s*\\(\\s*'translation-pair'\\s*,\\s*'translation-unpair'\\s*,?\\s*\\)\\s*;/s";
if (1 !== preg_match($authority_literal, $operations)) {
    fail_m5_translations_authority('Remote_Translation_Operations does not own the canonical MUTABLE_OPERATIONS list');
}

$create_plan_authority = '/in_array\\s*\\(\\s*\\$operation\\s*,\\s*self::MUTABLE_OPERATIONS\\s*,\\s*true\\s*\\)/s';
if (1 !== preg_match($create_plan_authority, $operations)) {
    fail_m5_translations_authority('create_plan does not validate against MUTABLE_OPERATIONS');
}

if (!str_contains($rest, "'operations'=>\$this->operations->mutable_operations()")) {
    fail_m5_translations_authority('REST capabilities do not consume Operations mutable authority');
}

$duplicate_literal = "/(?:array\\s*\\(\\s*|\\[\\s*)'translation-pair'\\s*,\\s*'translation-unpair'\\s*,?\\s*(?:\\)|\\])/s";
if (1 === preg_match($duplicate_literal, $rest)) {
    fail_m5_translations_authority('REST re-declares the mutable Translation operation list');
}

fwrite(STDOUT, "M5 Translation mutable operation authority: PASS\n");
