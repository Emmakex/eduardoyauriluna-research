<?php
/** Blueprint preview is classification only; writes remain Executor-owned. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

$original = get_option('eduardo_research_manager_mode', false);
update_option('eduardo_research_manager_mode', 'greenfield', false);

$before = wp_count_posts('page');
$before_total = $before instanceof stdClass ? array_sum(array_map('intval', get_object_vars($before))) : 0;
$preview = Eduardo_Research_Manager::blueprint_compiler()->preview(array('version'=>1,'pages'=>array(array('key'=>'contact'))));
if (is_wp_error($preview)) { exit(1); }
$after = wp_count_posts('page');
$after_total = $after instanceof stdClass ? array_sum(array_map('intval', get_object_vars($after))) : 0;
if ($before_total !== $after_total) { exit(1); }

if (false === $original) {
    delete_option('eduardo_research_manager_mode');
} else {
    update_option('eduardo_research_manager_mode', $original, false);
}
