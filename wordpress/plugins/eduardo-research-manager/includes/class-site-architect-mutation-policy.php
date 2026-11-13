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
    /** Explicit finite post_meta keys, NEVER inherited from descriptive resources. */
    public function allowed_meta_keys(): array;
    /**
     * Classify an already authorized normalized action.
     * Only 'standard', 'editorial-review' or 'evidence-required' are valid;
     * unknown classifications are promoted to evidence-required by Plan.
     * The callback answers whether an ID is a page owned by the active Theme Contract.
     * A policy never authorizes an action merely by classifying its risk.
     */
    public function risk_for_action(array $action, callable $is_theme_owned_page): string;
    /** Human-readable blocker copy only; cannot bypass the Core evidence gate. */
    public function evidence_blocker_message(string $reason): string;
}
