<?php
/** Narrow vertical relationship phase contract for known Greenfield Themes. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

/**
 * Describes an explicitly selected Theme-owned relationship phase.
 *
 * The interface does not grant mutation permissions: the concrete vertical
 * still uses bounded Plan/Executor authorization, evidence, Verify and Rollback.
 */
interface Site_Architect_Greenfield_Relationship_Phase {
    public function theme_id(): string;
    public function preview(array $blueprint): array|WP_Error;
    public function apply(array $blueprint): array|WP_Error;
    public function rollback(array $snapshot_ids): array|WP_Error;
}
