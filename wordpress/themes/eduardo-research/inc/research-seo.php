<?php
/** Lightweight native Research discovery metadata. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_schema(): void {
    if (is_admin()) { return; }
    $person = array(
        '@context' => 'https://schema.org',
        '@type' => 'Person',
        '@id' => home_url('/#researcher'),
        'name' => get_bloginfo('name'),
        'url' => home_url('/'),
    );
    if (is_front_page()) {
        echo '<script type="application/ld+json">' . wp_json_encode($person, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
}
add_action('wp_head', 'eduardo_research_schema', 30);

function eduardo_research_meta_description(): void {
    if (is_admin()) { return; }
    $description = '';
    if (is_singular() && has_excerpt()) { $description = get_the_excerpt(); }
    if ('' === $description && is_front_page()) { $description = eduardo_research_slot('hero-lead'); }
    if ('' !== $description) { echo '<meta name="description" content="' . esc_attr(wp_strip_all_tags($description)) . '">' . "\n"; }
}
add_action('wp_head', 'eduardo_research_meta_description', 5);
