<?php
/** Regression guard: mutable Media metadata operation names have one fail-closed authority. */

$repo_root = dirname(__DIR__, 2);
$operations_path = $repo_root . '/wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-media-metadata-operations.php';
$rest_path = $repo_root . '/wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-media-metadata-rest.php';

function fail_media_metadata_authority(string $message): never {
    fwrite(STDERR, "MEDIA METADATA AUTHORITY FAIL: {$message}\n");
    exit(1);
}

$operations = file_get_contents($operations_path);
$rest = file_get_contents($rest_path);

if (false === $operations || false === $rest) {
    fail_media_metadata_authority('unable to read Media metadata operation sources');
}

$authority_literal = "/private const MUTABLE_OPERATIONS\\s*=\\s*array\\s*\\(\\s*'media-metadata-update'\\s*,?\\s*\\)\\s*;/s";
if (1 !== preg_match($authority_literal, $operations)) {
    fail_media_metadata_authority('Remote_Media_Metadata_Operations does not own the canonical MUTABLE_OPERATIONS list');
}

$create_plan_authority = '/in_array\\s*\\(\\s*\\$operation\\s*,\\s*self::MUTABLE_OPERATIONS\\s*,\\s*true\\s*\\)/s';
if (1 !== preg_match($create_plan_authority, $operations)) {
    fail_media_metadata_authority('create_plan does not validate against MUTABLE_OPERATIONS');
}

if (! str_contains($operations, 'public static function mutable_operations(): array')) {
    fail_media_metadata_authority('Operations does not expose the canonical mutable operation list');
}

if (! str_contains($rest, "$media['operations'] = Eduardo_Research_Manager_Remote_Media_Metadata_Operations::mutable_operations();")) {
    fail_media_metadata_authority('Media Metadata REST capabilities do not consume Operations authority');
}

$duplicate_literal = "/(?:array\\s*\\(\\s*|\\[\\s*)'media-metadata-update'\\s*,?\\s*(?:\\)|\\])/s";
if (1 === preg_match($duplicate_literal, $rest)) {
    fail_media_metadata_authority('Media Metadata REST re-declares the mutable operation list');
}

fwrite(STDOUT, "Media metadata mutable operation authority: PASS\n");
