<?php
/** Typed Blueprint resource provider for Greenfield Site Architect Themes. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

/**
 * Describes a reviewed set of Blueprint resources and its installed services.
 * This catalog is NOT a mutation grant. Plan/Executor policy, Theme ownership,
 * scopes, evidence and snapshot verification still authorize every write.
 */
interface Site_Architect_Blueprint_Resource_Provider {
    /** Exact Theme identity that owns this vertical catalog. */
    public function theme_id(): string;
    /** Exact non-Page top-level Blueprint keys, in deterministic order. */
    public function resource_keys(): array;
    /** Human-readable label for a previously declared resource key. */
    public function resource_label(string $resource_key): string;
    /** Code-owned bindings for Pages and the declared vertical keys. */
    public function services(): array;
    /** Read-only duplicate preflight; [] means a new plan may be prepared. */
    public function existing_record(string $resource_key, array $record, array $services, string $default_language): array|WP_Error;
}
