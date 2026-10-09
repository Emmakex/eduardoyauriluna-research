<?php
/** Resolve controlled WordPress pages to Theme-owned Research surfaces. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_current_page_key(): ?string {
    if (is_front_page()) { return 'home'; }
    if (! is_page()) { return null; }
    $post = get_queried_object();
    if (! $post instanceof WP_Post) { return null; }
    $slug = (string) $post->post_name;
    foreach (eduardo_research_preset()['pages'] as $key => $contract) {
        if (($contract['slug'] ?? null) === $slug) { return (string) $key; }
    }
    return null;
}

function eduardo_research_template_include(string $template): string {
    $key = eduardo_research_current_page_key();
    if (null === $key || 'home' === $key) { return $template; }
    $owned = get_template_directory() . '/research-templates/page-research-surface.php';
    return is_readable($owned) ? $owned : $template;
}
add_filter('template_include', 'eduardo_research_template_include', 99);

function eduardo_research_page_contract(string $key): ?array {
    $pages = eduardo_research_preset()['pages'];
    return isset($pages[$key]) && is_array($pages[$key]) ? $pages[$key] : null;
}
