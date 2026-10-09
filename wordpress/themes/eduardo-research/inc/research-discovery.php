<?php
/** Machine-readable discovery endpoints for research context. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_discovery_rewrites(): void {
    add_rewrite_rule('^llms\.txt$', 'index.php?eduardo_research_llms=1&research_lang=en', 'top');
    add_rewrite_rule('^research\.json$', 'index.php?eduardo_research_json=1&research_lang=en', 'top');
    add_rewrite_rule('^es/llms\.txt$', 'index.php?eduardo_research_llms=1&research_lang=es', 'top');
    add_rewrite_rule('^es/research\.json$', 'index.php?eduardo_research_json=1&research_lang=es', 'top');
}
add_action('init', 'eduardo_research_discovery_rewrites');

function eduardo_research_discovery_vars(array $vars): array {
    $vars[] = 'eduardo_research_llms';
    $vars[] = 'eduardo_research_json';
    return array_values(array_unique($vars));
}
add_filter('query_vars', 'eduardo_research_discovery_vars');

function eduardo_research_discovery_template(): void {
    $language = eduardo_research_current_language();
    if ((int) get_query_var('eduardo_research_llms') === 1) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Language: ' . $language);
        header('Cache-Control: public, max-age=3600');
        $identity = eduardo_research_identity();
        echo '# ' . $identity['name'] . "\n\n";
        echo '> ' . eduardo_research_slot('hero-lead') . "\n\n";
        echo ('es' === $language ? '## Descubrimiento de investigación' : '## Research discovery') . "\n";
        echo '- ' . eduardo_research_page_label('research') . ': ' . eduardo_research_page_url('research') . "\n";
        echo '- ' . eduardo_research_page_label('publications') . ': ' . eduardo_research_page_url('publications') . "\n";
        echo '- ' . eduardo_research_page_label('projects') . ': ' . eduardo_research_page_url('projects') . "\n";
        echo '- ' . eduardo_research_page_label('software') . ': ' . eduardo_research_page_url('software') . "\n";
        echo '- ' . eduardo_research_page_label('datasets') . ': ' . eduardo_research_page_url('datasets') . "\n";
        echo '- ' . ('es' === $language ? 'Índice legible por máquinas' : 'Machine-readable index') . ': ' . ('es' === $language ? home_url('/es/research.json') : home_url('/research.json')) . "\n";
        exit;
    }

    if ((int) get_query_var('eduardo_research_json') === 1) {
        $types = array('research_output','research_project','research_software','research_dataset');
        $items = array();
        $latest = 0;
        if ('en' === $language) {
            foreach ($types as $type) {
                $posts = get_posts(array('post_type'=>$type,'post_status'=>'publish','posts_per_page'=>100,'orderby'=>'modified','order'=>'DESC','no_found_rows'=>true));
                foreach ($posts as $post) {
                    $modified = get_post_modified_time('U', true, $post);
                    $latest = max($latest, (int) $modified);
                    $items[] = array('type'=>$type,'title'=>get_the_title($post),'url'=>get_permalink($post),'modified'=>get_post_modified_time(DATE_W3C, true, $post));
                }
            }
        }
        $identity = eduardo_research_identity();
        header('Content-Language: ' . $language);
        header('Cache-Control: public, max-age=900');
        wp_send_json(array('language'=>$language,'researcher'=>$identity,'generated'=>$latest > 0 ? gmdate(DATE_W3C, $latest) : null,'items'=>$items));
    }
}
add_action('template_redirect', 'eduardo_research_discovery_template', 0);
