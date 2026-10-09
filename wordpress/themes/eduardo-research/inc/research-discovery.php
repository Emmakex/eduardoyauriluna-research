<?php
/** Machine-readable discovery endpoints for research context. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_discovery_rewrites(): void {
    add_rewrite_rule('^llms\.txt$', 'index.php?eduardo_research_llms=1', 'top');
    add_rewrite_rule('^research\.json$', 'index.php?eduardo_research_json=1', 'top');
}
add_action('init', 'eduardo_research_discovery_rewrites');

function eduardo_research_discovery_vars(array $vars): array {
    $vars[] = 'eduardo_research_llms';
    $vars[] = 'eduardo_research_json';
    return $vars;
}
add_filter('query_vars', 'eduardo_research_discovery_vars');

function eduardo_research_discovery_template(): void {
    if ((int) get_query_var('eduardo_research_llms') === 1) {
        header('Content-Type: text/plain; charset=utf-8');
        echo '# ' . get_bloginfo('name') . "\n\n";
        echo '> ' . eduardo_research_description() . "\n\n";
        echo '## Research discovery' . "\n";
        echo '- Research: ' . home_url('/research/') . "\n";
        echo '- Publications: ' . home_url('/publications/') . "\n";
        echo '- Projects: ' . home_url('/projects/') . "\n";
        echo '- Software: ' . home_url('/software/') . "\n";
        echo '- Datasets: ' . home_url('/datasets/') . "\n";
        echo '- Machine-readable index: ' . home_url('/research.json') . "\n";
        exit;
    }
    if ((int) get_query_var('eduardo_research_json') === 1) {
        $types = array('research_output','research_project','research_software','research_dataset');
        $items = array();
        foreach ($types as $type) {
            $posts = get_posts(array('post_type'=>$type,'post_status'=>'publish','posts_per_page'=>100,'orderby'=>'modified','order'=>'DESC'));
            foreach ($posts as $post) {
                $items[] = array('type'=>$type,'title'=>get_the_title($post),'url'=>get_permalink($post),'modified'=>get_post_modified_time(DATE_W3C, true, $post));
            }
        }
        wp_send_json(array('researcher'=>array('name'=>get_bloginfo('name'),'url'=>home_url('/')),'generated'=>gmdate(DATE_W3C),'items'=>$items));
    }
}
add_action('template_redirect', 'eduardo_research_discovery_template', 0);
