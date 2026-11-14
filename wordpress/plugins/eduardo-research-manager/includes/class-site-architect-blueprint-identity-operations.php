<?php
/** Theme-owned bounded identity operations for a Greenfield Blueprint phase. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

/**
 * Describes the already-authorized identity mutation lifecycle for one Theme.
 * This is NOT a grant: concrete services still enforce evidence, Plan,
 * stale-state, Apply and Verify. Snapshot rollback remains in the Executor.
 */
interface Site_Architect_Blueprint_Identity_Operations {
    public function theme_id(): string;
    public function preview_update(array $changes, bool $evidence_confirmed, string $evidence_reference): array|WP_Error;
    public function apply_preview(array $prepared): array|WP_Error;
}
