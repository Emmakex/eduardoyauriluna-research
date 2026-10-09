<?php
/** Native SEO/GEO discovery runtime for the Research preset. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_current_url(): string {
    if (is_404()) { return ''; }
    if (is_front_page()) { return home_url('/'); }
    if (is_singular()) { return (string) get_permalink(); }
    if (is_post_type_archive()) {
        $type = get_query_var('post_type');
        if (is_array($type)) { $type = reset($type); }
        $url = get_post_type_archive_link((string) $type);
        return $url ? (string) $url : home_url('/');
    }
    if (is_search()) { return (string) get_search_link(get_search_query()); }
    if (is_archive()) { return (string) get_pagenum_link(max(1, (int) get_query_var('paged'))); }
    return home_url('/');
}

function eduardo_research_description(): string {
    if (is_singular() && has_excerpt()) { return wp_strip_all_tags(get_the_excerpt()); }
    if (is_front_page()) { return wp_strip_all_tags(eduardo_research_slot('hero-lead')); }
    if (is_archive()) { return wp_strip_all_tags((string) get_the_archive_description()); }
    return wp_strip_all_tags((string) get_bloginfo('description'));
}

function eduardo_research_identity(): array {
    $stored = get_option('eduardo_research_identity', array());
    if (! is_array($stored)) { $stored = array(); }
    $name = isset($stored['name']) && is_scalar($stored['name']) && '' !== trim((string) $stored['name'])
        ? trim((string) $stored['name'])
        : (string) get_bloginfo('name');
    return array('name' => $name, 'url' => home_url('/'));
}

function eduardo_research_output_schema_type(int $post_id): string {
    $verified = '1' === (string) get_post_meta($post_id, '_research_output_type_verified', true);
    if (! $verified) { return 'CreativeWork'; }
    $map = array(
        'scholarly_article' => 'ScholarlyArticle',
        'article' => 'Article',
        'report' => 'Report',
    );
    $type = sanitize_key((string) get_post_meta($post_id, '_research_output_type', true));
    return $map[$type] ?? 'CreativeWork';
}

function eduardo_research_head_metadata(): void {
    if (is_admin()) { return; }
    $url = eduardo_research_current_url();
    $description = eduardo_research_description();
    $title = wp_get_document_title();
    if ('' !== $url) { echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n"; }
    if ('' !== $description) { echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n"; }
    $article = is_singular(array('research_output', 'post'));
    echo '<meta property="og:type" content="' . esc_attr($article ? 'article' : 'website') . '">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr((string) get_bloginfo('name')) . '">' . "\n";
    if ('' !== $url) { echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n"; }
    if ('' !== $description) { echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n"; }
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
    if ('' !== $description) { echo '<meta name="twitter:description" content="' . esc_attr($description) . '">' . "\n"; }
}
add_action('wp_head', 'eduardo_research_head_metadata', 5);

function eduardo_research_schema_graph(): void {
    if (is_admin() || is_404()) { return; }
    $identity = eduardo_research_identity();
    $url = eduardo_research_current_url();
    $person = array('@type'=>'Person','@id'=>home_url('/#researcher'),'name'=>$identity['name'],'url'=>$identity['url']);

    $same_as = eduardo_research_verified_identifier_urls();
    if ($same_as) { $person['sameAs'] = $same_as; }

    $affiliations = array();
    foreach (eduardo_research_verified_evidence('affiliations') as $record) {
        $name = trim((string) ($record['title'] ?? $record['label'] ?? $record['value'] ?? ''));
        if ('' === $name) { continue; }
        $organization = array('@type'=>'Organization','name'=>$name);
        if (! empty($record['url']) && is_scalar($record['url'])) {
            $organization['url'] = esc_url_raw((string) $record['url']);
        }
        $affiliations[] = $organization;
    }
    if ($affiliations) { $person['affiliation'] = $affiliations; }

    $website = array('@type'=>'WebSite','@id'=>home_url('/#website'),'url'=>home_url('/'),'name'=>get_bloginfo('name'),'publisher'=>array('@id'=>home_url('/#researcher')));
    $page = array('@type'=>'WebPage','@id'=>$url . '#webpage','url'=>$url,'name'=>wp_get_document_title(),'isPartOf'=>array('@id'=>home_url('/#website')),'about'=>array('@id'=>home_url('/#researcher')));
    $graph = array($person, $website, $page);

    if (is_singular('research_output')) {
        $post_id = get_queried_object_id();
        $schema_type = eduardo_research_output_schema_type($post_id);
        $output = array(
            '@type'=>$schema_type,
            '@id'=>get_permalink() . '#research-output',
            'name'=>get_the_title(),
            'url'=>get_permalink(),
            'author'=>array('@id'=>home_url('/#researcher')),
            'mainEntityOfPage'=>array('@id'=>get_permalink() . '#webpage'),
            'datePublished'=>get_the_date(DATE_W3C),
            'dateModified'=>get_the_modified_date(DATE_W3C),
        );
        $doi = trim((string) get_post_meta($post_id, '_research_doi', true));
        if ('' !== $doi && '1' === (string) get_post_meta($post_id, '_research_doi_verified', true)) {
            $output['identifier'] = array('@type'=>'PropertyValue','propertyID'=>'DOI','value'=>$doi);
        }
        $graph[] = $output;
    } elseif (is_singular('research_dataset')) {
        $graph[] = array('@type'=>'Dataset','@id'=>get_permalink() . '#dataset','name'=>get_the_title(),'url'=>get_permalink(),'creator'=>array('@id'=>home_url('/#researcher')));
    } elseif (is_singular('research_software')) {
        $graph[] = array('@type'=>'SoftwareSourceCode','@id'=>get_permalink() . '#software','name'=>get_the_title(),'url'=>get_permalink(),'author'=>array('@id'=>home_url('/#researcher')));
    }

    echo '<script type="application/ld+json">' . wp_json_encode(array('@context'=>'https://schema.org','@graph'=>$graph), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}
add_action('wp_head', 'eduardo_research_schema_graph', 30);
