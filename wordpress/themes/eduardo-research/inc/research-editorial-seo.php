<?php
/** Structured data for Theme-owned research Insights. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_insight_schema(): void {
    if (is_admin() || ! is_singular('post')) { return; }
    $post_id = get_queried_object_id();
    if ($post_id <= 0) { return; }
    $permalink = (string) get_permalink($post_id);
    $article = array(
        '@context'=>'https://schema.org',
        '@type'=>'Article',
        '@id'=>$permalink . '#insight',
        'headline'=>get_the_title($post_id),
        'url'=>$permalink,
        'inLanguage'=>eduardo_research_post_language($post_id),
        'datePublished'=>get_the_date(DATE_W3C, $post_id),
        'dateModified'=>get_the_modified_date(DATE_W3C, $post_id),
        'articleSection'=>eduardo_research_insight_type_label($post_id, eduardo_research_post_language($post_id)),
        'author'=>array('@id'=>home_url('/#researcher')),
        'mainEntityOfPage'=>array('@id'=>$permalink . '#webpage'),
    );
    $excerpt = trim((string) get_the_excerpt($post_id));
    if ('' !== $excerpt) { $article['description'] = wp_strip_all_tags($excerpt); }
    echo '<script type="application/ld+json">' . wp_json_encode($article, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}
add_action('wp_head', 'eduardo_research_insight_schema', 32);
