<?php
/** Runtime assertions for declarative Research blueprints. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

$original = get_option('eduardo_research_manager_mode', false);
update_option('eduardo_research_manager_mode', 'greenfield', false);

$summary = Eduardo_Research_Manager::blueprint()->summarize(array(
    'version' => 1,
    'languages' => array('en','es'),
    'pages' => array(array('key'=>'home'), array('key'=>'about')),
    'insights' => array(),
    'outputs' => array(),
    'projects' => array(),
    'software' => array(),
    'datasets' => array(),
));
if (is_wp_error($summary)) { exit(1); }
if (2 !== $summary['total_resources']) { exit(1); }
if (! empty($summary['requires_legacy_discovery']) || ! empty($summary['requires_gutenberg_layout'])) { exit(1); }

$invalid = Eduardo_Research_Manager::blueprint()->validate(array('version'=>1,'legacy_scan'=>true));
if (! is_wp_error($invalid) || 'research_manager_blueprint_key_unknown' !== $invalid->get_error_code()) { exit(1); }

if (false === $original) {
    delete_option('eduardo_research_manager_mode');
} else {
    update_option('eduardo_research_manager_mode', $original, false);
}
