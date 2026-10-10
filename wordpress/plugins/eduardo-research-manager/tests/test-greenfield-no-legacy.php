<?php
/** Greenfield must not infer Migration from existing WordPress content. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

$original = get_option('eduardo_research_manager_mode', false);
delete_option('eduardo_research_manager_mode');

// Existing content does not change the scenario. The scenario is a product/runtime decision.
$post_id = wp_insert_post(array('post_type'=>'post','post_status'=>'draft','post_title'=>'Existing fixture'));
if (is_wp_error($post_id) || $post_id <= 0) { exit(1); }
if (! Eduardo_Research_Manager_Mode::is_greenfield()) { exit(1); }
if (Eduardo_Research_Manager_Mode::capabilities()['legacy_discovery']) { exit(1); }
wp_delete_post((int) $post_id, true);

if (false === $original) {
    delete_option('eduardo_research_manager_mode');
} else {
    update_option('eduardo_research_manager_mode', $original, false);
}
