<?php
/** Resolve controlled WordPress pages to Theme-owned Research surfaces. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_current_page_key(): ?string {
    $pages = eduardo_research_page_contracts();
    $localized = sanitize_key((string) get_query_var('research_page_key'));
    if ('' !== $localized && isset($pages[$localized])) { return $localized; }
    if (is_front_page()) { return 'home'; }
    if (! is_page()) { return null; }
    $post = get_queried_object();
    if (! $post instanceof WP_Post) { return null; }
    $slug = (string) $post->post_name;
    foreach ($pages as $key => $contract) {
        $expected = (string) ($contract['wp_slug'] ?? $contract['slug'] ?? '');
        if ('' !== $expected && $expected === $slug) { return (string) $key; }
    }
    return null;
}

function eduardo_research_template_include(string $template): string {
    $key = eduardo_research_current_page_key();
    if (null === $key || 'home' === $key) { return $template; }

    $language = function_exists('eduardo_research_current_language') ? eduardo_research_current_language() : null;
    if (! eduardo_research_page_is_publicly_available($key, $language)) {
        global $wp_query;
        if ($wp_query instanceof WP_Query) { $wp_query->set_404(); }
        status_header(404);
        nocache_headers();
        $not_found = get_404_template();
        return is_string($not_found) && '' !== $not_found ? $not_found : $template;
    }

    $owned = get_template_directory() . '/research-templates/page-research-surface.php';
    return is_readable($owned) ? $owned : $template;
}
add_filter('template_include', 'eduardo_research_template_include', 99);
