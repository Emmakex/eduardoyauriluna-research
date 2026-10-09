<?php
/** Locale ownership for research records and editorial posts. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_post_language(int $post_id): string {
    $language = sanitize_key((string) get_post_meta($post_id, '_research_language', true));
    return in_array($language, array('en','es'), true) ? $language : 'en';
}

function eduardo_research_content_language_meta_query(?string $language = null): array {
    $language = $language ?: eduardo_research_current_language();
    if ('es' === $language) {
        return array(array('key'=>'_research_language','value'=>'es','compare'=>'='));
    }
    return array(
        'relation'=>'OR',
        array('key'=>'_research_language','compare'=>'NOT EXISTS'),
        array('key'=>'_research_language','value'=>'en','compare'=>'='),
    );
}

function eduardo_research_localized_query_args(array $args, ?string $language = null): array {
    $args['meta_query'] = eduardo_research_content_language_meta_query($language);
    return $args;
}

function eduardo_research_content_language_rewrites(): void {
    $routes = array(
        'publications'=>'research_output',
        'projects'=>'research_project',
        'software'=>'research_software',
        'datasets'=>'research_dataset',
    );
    foreach ($routes as $key => $type) {
        $slug = preg_quote(eduardo_research_page_slug($key, 'es'), '#');
        add_rewrite_rule('^es/' . $slug . '/([^/]+)/?$', 'index.php?post_type=' . $type . '&name=$matches[1]&research_lang=es', 'top');
    }
    $insights = preg_quote(eduardo_research_page_slug('insights', 'es'), '#');
    add_rewrite_rule('^es/' . $insights . '/([^/]+)/?$', 'index.php?post_type=post&name=$matches[1]&research_lang=es', 'top');
}
add_action('init', 'eduardo_research_content_language_rewrites', 13);

function eduardo_research_spanish_record_url(WP_Post $post): string {
    $map = array(
        'research_output'=>'publications',
        'research_project'=>'projects',
        'research_software'=>'software',
        'research_dataset'=>'datasets',
        'post'=>'insights',
    );
    $key = $map[$post->post_type] ?? null;
    if (! $key) { return ''; }
    return home_url('/es/' . trim(eduardo_research_page_slug($key, 'es'), '/') . '/' . $post->post_name . '/');
}

function eduardo_research_post_type_link(string $url, WP_Post $post): string {
    if ('es' !== eduardo_research_post_language((int) $post->ID)) { return $url; }
    $localized = eduardo_research_spanish_record_url($post);
    return '' !== $localized ? $localized : $url;
}
add_filter('post_type_link', 'eduardo_research_post_type_link', 20, 2);

function eduardo_research_post_link(string $url, WP_Post $post): string {
    if ('post' !== $post->post_type || 'es' !== eduardo_research_post_language((int) $post->ID)) { return $url; }
    $localized = eduardo_research_spanish_record_url($post);
    return '' !== $localized ? $localized : $url;
}
add_filter('post_link', 'eduardo_research_post_link', 20, 2);

function eduardo_research_restrict_spanish_singular_query(WP_Query $query): void {
    if (is_admin() || ! $query->is_main_query() || 'es' !== eduardo_research_request_language()) { return; }
    $type = $query->get('post_type');
    $supported = array('research_output','research_project','research_software','research_dataset','post');
    if (is_array($type)) { $matched = (bool) array_intersect($supported, $type); }
    else { $matched = in_array((string) $type, $supported, true); }
    if ($matched && '' !== (string) $query->get('name')) {
        $query->set('meta_query', eduardo_research_content_language_meta_query('es'));
    }
}
add_action('pre_get_posts', 'eduardo_research_restrict_spanish_singular_query');

function eduardo_research_translation_post_id(int $post_id, string $language): int {
    $current = eduardo_research_post_language($post_id);
    if ($current === $language) { return $post_id; }
    $target = (int) get_post_meta($post_id, '_research_translation_' . sanitize_key($language), true);
    if ($target <= 0) { return 0; }
    $post = get_post($target);
    if (! $post instanceof WP_Post || 'publish' !== $post->post_status || $post->post_type !== get_post_type($post_id)) { return 0; }
    return eduardo_research_post_language($target) === $language ? $target : 0;
}

function eduardo_research_record_translation_url(int $post_id, string $language): string {
    $target = eduardo_research_translation_post_id($post_id, $language);
    return $target > 0 ? (string) get_permalink($target) : '';
}

function eduardo_research_enforce_record_locale(): void {
    if (is_admin() || ! is_singular(array('research_output','research_project','research_software','research_dataset','post'))) { return; }
    $post_id = get_queried_object_id();
    if ($post_id <= 0) { return; }
    $record_language = eduardo_research_post_language($post_id);
    if ('es' === $record_language && 'es' !== eduardo_research_current_language()) {
        $target = get_permalink($post_id);
        if ($target) { wp_safe_redirect($target, 301); exit; }
    }
}
add_action('template_redirect', 'eduardo_research_enforce_record_locale', 5);
