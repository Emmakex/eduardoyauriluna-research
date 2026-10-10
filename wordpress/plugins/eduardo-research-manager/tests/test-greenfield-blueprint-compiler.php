<?php
/** Runtime assertions for non-mutating Greenfield blueprint compilation. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

$original = get_option('eduardo_research_manager_mode', false);
update_option('eduardo_research_manager_mode', 'greenfield', false);

$preview = Eduardo_Research_Manager::blueprint_compiler()->preview(array(
    'version'=>1,
    'languages'=>array('en','es'),
    'pages'=>array(array('key'=>'home'),array('key'=>'about')),
));
if (is_wp_error($preview)) { exit(1); }
if ('greenfield' !== $preview['mode'] || 2 !== count($preview['operations'])) { exit(1); }
foreach ($preview['operations'] as $operation) {
    if (! in_array($operation['status'], array('create','already-matching','blocked'), true)) { exit(1); }
}

if (false === $original) {
    delete_option('eduardo_research_manager_mode');
} else {
    update_option('eduardo_research_manager_mode', $original, false);
}
