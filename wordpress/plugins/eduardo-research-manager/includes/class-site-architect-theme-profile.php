<?php
/** Reusable Greenfield Theme profile contract; no Research or client-specific policy. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

/**
 * A Theme profile supplies closed declarations to Site Architect.
 *
 * It does not authorize any mutation, broaden scopes or infer Theme structure.
 * Each supported Greenfield Theme must provide an explicit implementation.
 */
interface Site_Architect_Theme_Profile {
    public function available(): bool;
    public function preset(): array;
    public function preset_id(): string;
    public function minimum_preset_version(): int;
    public function stylesheet(): string;
    /** Physical language storage map owned by the selected instance adapter. */
    public function language_storage(): array;
    /** Resolve a previously validated page key using a declared physical suffix. */
    public function page_option_key(string $key, string $suffix): string;
}
