<?php
/** Runtime assertions for direct Greenfield orchestration. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

$original = get_option('eduardo_research_manager_mode', false);
update_option('eduardo_research_manager_mode', 'greenfield', false);

$greenfield = Eduardo_Research_Manager::greenfield();
$status = $greenfield->status();
if ('greenfield' !== $status['mode']) { exit(1); }
if (! empty($status['requires_legacy_discovery']) || ! empty($status['requires_legacy_mapping'])) { exit(1); }

$resources = $greenfield->resources();
if (! is_wp_error($resources)) {
    foreach (array('pages','insights','outputs','projects','software','datasets') as $key) {
        if (! isset($resources[$key]) || ! is_object($resources[$key])) { exit(1); }
    }
}

update_option('eduardo_research_manager_mode', 'migration', false);
$blocked = $greenfield->ready();
if (! is_wp_error($blocked) || 'research_manager_greenfield_mode_required' !== $blocked->get_error_code()) { exit(1); }

if (false === $original) {
    delete_option('eduardo_research_manager_mode');
} else {
    update_option('eduardo_research_manager_mode', $original, false);
}
