<?php
/** Native SEO/GEO discovery runtime for the Research preset. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_current_url(): string {
    if (is_singular()) { return (string) get_permalink(); }
    if (is_post_type_archive()) { return (string) get_post_type_archive_link((string) get_query_var('post_type')); }
    return home_url(add_query_arg(array(), $GLOBALS['wp']->request ?? ''));
}

function eduardo_research_description(): string {
    if (is_singular() && has_excerpt()) { return wp_strip_all_tags(get_the_excerpt()); }
    if (is_front_page()) { return wp_strip_all_tags(eduardo_research_slot('hero-lead')); }
    if (is_archive()) { return wp_strip_all_tags((string) get_the_archive_description()); }
    return wp_strip_all_tags((string) get_bloginfo('description'));
}

function eduardo_research_head_metadata(): void {
    if (is_admin()) { return; }
    $url = eduardo_research_current_url();
    $description = eduardo_research_description();
    $title = wp_get_document_title();
    echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
    if ('' !== $description) { echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n"; }
    echo '<meta property="og:type" content="' . esc_attr(is_singular('research_output') ? 'article' : 'website') . '">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    if ('' !== $description) { echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n"; }
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
}
add_action('wp_head', 'eduardo_research_head_metadata', 5);

function eduardo_research_schema_graph(): void {
    if (is_admin()) { return; }
    $person = array('@type'=>'Person','@id'=>home_url('/#researcher'),'name'=>get_bloginfo('name'),'url'=>home_url('/'));
    $website = array('@type'=>'WebSite','@id'=>home_url('/#website'),'url'=>home_url('/'),'name'=>get_bloginfo('name'),'publisher'=>array('@id'=>home_url('/#researcher')));
    $page = array('@type'=>'WebPage','@id'=>eduardo_research_current_url() . '#webpage','url'=>eduardo_research_current_url(),'name'=>wp_get_document_title(),'isPartOf'=>array('@id'=>home_url('/#website')),'about'=>array('@id'=>home_url('/#researcher')));
    $graph = array($person, $website, $page);
    if (is_singular('research_output')) {
        $graph[] = array('@type'=>'ScholarlyArticle','@id'=>get_permalink() . '#scholarly-article','headline'=>get_the_title(),'url'=>get_permalink(),'author'=>array('@id'=>home_url('/#researcher')),'mainEntityOfPage'=>array('@id'=>get_permalink() . '#webpage'),'datePublished'=>get_the_date(DATE_W3C),'dateModified'=>get_the_modified_date(DATE_W3C));
    } elseif (is_singular('research_dataset')) {
        $graph[] = array('@type'=>'Dataset','@id'=>get_permalink() . '#dataset','name'=>get_the_title(),'url'=>get_permalink(),'creator'=>array('@id'=>home_url('/#researcher')));
    } elseif (is_singular('research_software')) {
        $graph[] = array('@type'=>'SoftwareSourceCode','@id'=>get_permalink() . '#software','name'=>get_the_title(),'url'=>get_permalink(),'author'=>array('@id'=>home_url('/#researcher')));
    }
    echo '<script type="application/ld+json">' . wp_json_encode(array('@context'=>'https://schema.org','@graph'=>$graph), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}
add_action('wp_head', 'eduardo_research_schema_graph', 30);
