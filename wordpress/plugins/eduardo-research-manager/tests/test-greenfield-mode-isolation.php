<?php
/** Scenario isolation assertions. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

$original = get_option('eduardo_research_manager_mode', false);

update_option('eduardo_research_manager_mode', 'greenfield', false);
$greenfield = Eduardo_Research_Manager::mode();
if ($greenfield['capabilities']['legacy_discovery'] || $greenfield['capabilities']['migration_preview']) { exit(1); }

update_option('eduardo_research_manager_mode', 'migration', false);
$migration = Eduardo_Research_Manager::mode();
if (! $migration['capabilities']['legacy_discovery'] || ! $migration['capabilities']['migration_preview']) { exit(1); }
if (is_wp_error(Eduardo_Research_Manager::greenfield()->ready()) === false) { exit(1); }

if (false === $original) {
    delete_option('eduardo_research_manager_mode');
} else {
    update_option('eduardo_research_manager_mode', $original, false);
}
