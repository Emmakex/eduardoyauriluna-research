<?php
/** Regression guard: Page State mutation contract has one fail-closed authority. */

declare(strict_types=1);

$repo_root = dirname(__DIR__, 2);
$editor_path = $repo_root . '/wordpress/plugins/eduardo-research-manager/includes/class-research-manager-page-state-editor.php';
$rest_path = $repo_root . '/wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-page-state-rest.php';

function fail_page_state_authority(string $message): never {
    fwrite(STDERR, "PAGE STATE AUTHORITY FAIL: {$message}\n");
    exit(1);
}

$editor = file_get_contents($editor_path);
$rest = file_get_contents($rest_path);

if (false === $editor || false === $rest) {
    fail_page_state_authority('unable to read Page State sources');
}

if (1 !== preg_match("/private const MUTABLE_FIELDS\\s*=\\s*array\\s*\\(\\s*'title'\\s*,\\s*'status'\\s*,?\\s*\\)\\s*;/s", $editor)) {
    fail_page_state_authority('Page_State_Editor does not own the canonical MUTABLE_FIELDS list');
}

if (1 !== preg_match("/private const ALLOWED_STATUSES\\s*=\\s*array\\s*\\(\\s*'draft'\\s*,\\s*'publish'\\s*,?\\s*\\)\\s*;/s", $editor)) {
    fail_page_state_authority('Page_State_Editor does not own the canonical ALLOWED_STATUSES list');
}

if (! str_contains($editor, 'public static function mutable_fields(): array')) {
    fail_page_state_authority('Page_State_Editor does not expose mutable_fields()');
}

if (! str_contains($editor, 'public static function allowed_statuses(): array')) {
    fail_page_state_authority('Page_State_Editor does not expose allowed_statuses()');
}

if (1 !== preg_match('/array_merge\\s*\\(\\s*self::MUTABLE_FIELDS\\s*,\\s*array\\s*\\(\\s*\'slug\'\\s*\\)\\s*\\)/s', $editor)) {
    fail_page_state_authority('preview_update does not derive its known-field boundary from MUTABLE_FIELDS');
}

if (! str_contains($editor, 'array_intersect(self::ALLOWED_STATUSES')) {
    fail_page_state_authority('inspect does not constrain active Theme policy through ALLOWED_STATUSES');
}

if (! str_contains($editor, "array_key_exists('slug', \$changes)")) {
    fail_page_state_authority('explicit immutable slug boundary is missing');
}

if (! str_contains($editor, 'Theme contract Page slugs are immutable through the Manager.')) {
    fail_page_state_authority('immutable slug error contract changed or disappeared');
}

if (! str_contains($rest, "'mutable_fields'=>Eduardo_Research_Manager_Page_State_Editor::mutable_fields()")) {
    fail_page_state_authority('Page State REST capabilities do not consume mutable_fields() authority');
}

if (! str_contains($rest, "'allowed_statuses'=>Eduardo_Research_Manager_Page_State_Editor::allowed_statuses()")) {
    fail_page_state_authority('Page State REST capabilities do not consume allowed_statuses() authority');
}

if (1 === preg_match("/(?:array\\s*\\(\\s*|\\[\\s*)'title'\\s*,\\s*'status'\\s*,?\\s*(?:\\)|\\])/s", $rest)) {
    fail_page_state_authority('Page State REST re-declares the mutable field list');
}

if (1 === preg_match("/(?:array\\s*\\(\\s*|\\[\\s*)'draft'\\s*,\\s*'publish'\\s*,?\\s*(?:\\)|\\])/s", $rest)) {
    fail_page_state_authority('Page State REST re-declares the allowed status list');
}

fwrite(STDOUT, "Page State mutation contract authority: PASS\n");
