<?php
/** Explicit Theme/resource ownership for bounded post mutations. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
/** A mutation action/key alone is not an ownership grant. */
interface Site_Architect_Resource_Ownership_Policy {
    public function owns_resource(string $theme_id, string $post_type, bool $is_theme_owned_page): bool;
    public function allowed_resource_meta_keys(string $post_type): array;
    public function allowed_resource_post_fields(string $post_type): array;
}
