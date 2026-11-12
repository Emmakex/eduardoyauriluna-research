<?php
/** Closed declarative mutation policy seam. Theme descriptions are not permissions. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

/**
 * Code-owned allowlists, never taken from requests or arbitrary Theme preset data.
 * Each declared action must ALSO have an explicit Plan normalizer and Executor path.
 * Scopes, capabilities, bounds, evidence and lifecycle checks remain independent.
 */
interface Site_Architect_Mutation_Policy {
    /** Exact action type names; no wildcard/prefix authorization. */
    public function allowed_action_types(): array;
    /** Exact WordPress option names; page slots are separately contract-resolved. */
    public function allowed_option_keys(): array;
}
