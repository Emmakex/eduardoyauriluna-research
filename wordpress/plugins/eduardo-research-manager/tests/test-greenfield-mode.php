<?php
/** Minimal runtime assertions for the Research Manager operating scenario. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

$original = get_option('eduardo_research_manager_mode', false);
delete_option('eduardo_research_manager_mode');

$default = Eduardo_Research_Manager_Mode::describe();
if ('greenfield' !== $default['mode'] || empty($default['greenfield']) || ! empty($default['migration'])) { exit(1); }
if (empty($default['capabilities']['direct_contract_creation'])) { exit(1); }
if (! empty($default['capabilities']['legacy_discovery']) || ! empty($default['capabilities']['legacy_mapping'])) { exit(1); }
if (! empty($default['capabilities']['legacy_url_preservation']) || ! empty($default['capabilities']['migration_preview'])) { exit(1); }

update_option('eduardo_research_manager_mode', 'migration', false);
$migration = Eduardo_Research_Manager::mode();
if ('migration' !== $migration['mode'] || empty($migration['migration']) || ! empty($migration['greenfield'])) { exit(1); }
if (empty($migration['capabilities']['legacy_discovery']) || empty($migration['capabilities']['legacy_mapping'])) { exit(1); }

update_option('eduardo_research_manager_mode', 'unsupported-value', false);
if ('greenfield' !== Eduardo_Research_Manager_Mode::current()) { exit(1); }

if (false === $original) {
    delete_option('eduardo_research_manager_mode');
} else {
    update_option('eduardo_research_manager_mode', $original, false);
}
