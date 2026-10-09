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
        header('Cache-Control: public, max-age=3600');
        $identity = eduardo_research_identity();
        echo '# ' . $identity['name'] . "\n\n";
        echo '> ' . eduardo_research_description() . "\n\n";
        echo '## Research discovery' . "\n";
        echo '- Research: ' . eduardo_research_page_url('research') . "\n";
        echo '- Publications: ' . eduardo_research_page_url('publications') . "\n";
        echo '- Projects: ' . eduardo_research_page_url('projects') . "\n";
        echo '- Software: ' . eduardo_research_page_url('software') . "\n";
        echo '- Datasets: ' . eduardo_research_page_url('datasets') . "\n";
        echo '- Machine-readable index: ' . home_url('/research.json') . "\n";
        exit;
    }

    if ((int) get_query_var('eduardo_research_json') === 1) {
        $types = array('research_output','research_project','research_software','research_dataset');
        $items = array();
        $latest = 0;
        foreach ($types as $type) {
            $posts = get_posts(array(
                'post_type'=>$type,
                'post_status'=>'publish',
                'posts_per_page'=>100,
                'orderby'=>'modified',
                'order'=>'DESC',
                'no_found_rows'=>true,
            ));
            foreach ($posts as $post) {
                $modified = get_post_modified_time('U', true, $post);
                $latest = max($latest, (int) $modified);
                $items[] = array(
                    'type'=>$type,
                    'title'=>get_the_title($post),
                    'url'=>get_permalink($post),
                    'modified'=>get_post_modified_time(DATE_W3C, true, $post),
                );
            }
        }
        $identity = eduardo_research_identity();
        header('Cache-Control: public, max-age=900');
        wp_send_json(array(
            'researcher'=>$identity,
            'generated'=>$latest > 0 ? gmdate(DATE_W3C, $latest) : null,
            'items'=>$items,
        ));
    }
}
add_action('template_redirect', 'eduardo_research_discovery_template', 0);
