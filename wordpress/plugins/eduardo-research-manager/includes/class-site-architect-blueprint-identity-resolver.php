<?php
/** Narrow Theme-owned Blueprint identity resolver, not a mutation capability. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

/**
 * Supplies explicitly selected identity source and legacy slot alias rules.
 * Actual writes remain subject to the existing Profile, Plan, Executor,
 * evidence, verification, snapshot and rollback authorization.
 */
interface Site_Architect_Blueprint_Identity_Resolver {
    public function theme_id(): string;
    /** A read-only proposed canonical identity seed, or WP_Error. */
    public function seed(array $normalized): array|WP_Error;
    public function has_seed_data(array $normalized): bool;
    /** Remove only Theme-declared aliases before Page plan creation. */
    public function filter_page_slots(string $page_key, array $values): array;
}
