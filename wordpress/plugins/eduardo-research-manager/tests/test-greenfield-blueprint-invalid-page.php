<?php
/** Malformed Page declarations fail closed. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

$original = get_option('eduardo_research_manager_mode', false);
update_option('eduardo_research_manager_mode', 'greenfield', false);
$result = Eduardo_Research_Manager::blueprint_compiler()->preview(array('version'=>1,'pages'=>array(array('language'=>'en'))));
if (! is_wp_error($result) || 'research_manager_blueprint_page_invalid' !== $result->get_error_code()) { exit(1); }
if (false === $original) { delete_option('eduardo_research_manager_mode'); }
else { update_option('eduardo_research_manager_mode', $original, false); }
