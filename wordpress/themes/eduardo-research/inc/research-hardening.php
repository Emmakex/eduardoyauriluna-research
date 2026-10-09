<?php
/** Final public evidence gates and crawl hardening for the Research Theme. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_register_verification_meta(): void {
    foreach (array('_research_review_status_verified') as $key) {
        register_post_meta('research_output', $key, array(
            'type'=>'string','single'=>true,'show_in_rest'=>true,
            'sanitize_callback'=>static fn($value): string => '1' === (string) $value ? '1' : '0',
            'auth_callback'=>static fn() => current_user_can('edit_posts'),
        ));
    }
}
add_action('init', 'eduardo_research_register_verification_meta', 19);

/**
 * Public reads of academic classification/review claims are evidence-gated.
 * Editors retain access to the raw value so it can be reviewed before publication.
 */
function eduardo_research_gate_public_academic_meta($value, int $object_id, string $meta_key, bool $single) {
    if (is_admin() || current_user_can('edit_post', $object_id)) { return $value; }
    $gate_map = array(
        '_research_output_type'=>'_research_output_type_verified',
        '_research_review_status'=>'_research_review_status_verified',
    );
    if (! isset($gate_map[$meta_key])) { return $value; }
    if ('1' === (string) get_post_meta($object_id, $gate_map[$meta_key], true)) { return $value; }
    return $single ? '' : array();
}
add_filter('get_post_metadata', 'eduardo_research_gate_public_academic_meta', 20, 4);

function eduardo_research_is_exploration_query(): bool {
    $page_key = function_exists('eduardo_research_current_page_key') ? eduardo_research_current_page_key() : null;
    $collection_keys = array('publications','projects','software','datasets');
    if (in_array($page_key, $collection_keys, true)) {
        foreach (array('research_line','output_type','review_status','project_status','software_status','access_level','year','sort','research_page') as $key) {
            if (isset($_GET[$key]) && '' !== trim((string) wp_unslash($_GET[$key]))) { return true; }
        }
    }
    if ('insights' === $page_key) {
        foreach (array('insight_search','insight_type','insight_page') as $key) {
            if (isset($_GET[$key]) && '' !== trim((string) wp_unslash($_GET[$key]))) { return true; }
        }
    }
    return false;
}

/** Filter/search states are exploration views, not independent indexable documents. */
function eduardo_research_exploration_robots(array $robots): array {
    if (eduardo_research_is_exploration_query()) {
        $robots['noindex'] = true;
        unset($robots['index']);
    }
    return $robots;
}
add_filter('wp_robots', 'eduardo_research_exploration_robots', 20);

/** Release metadata exposed by the WordPress Appearance UI. */
function eduardo_research_release_theme_branding(array $themes): array {
    $stylesheet = get_stylesheet();
    if (! isset($themes[$stylesheet]) || ! is_array($themes[$stylesheet])) { return $themes; }
    $themes[$stylesheet]['version'] = '1.0.0';
    $themes[$stylesheet]['author'] = 'Emmake by Kairoseth';
    $themes[$stylesheet]['authorAndUri'] = '<a href="https://kairoseth.com/">Emmake by Kairoseth</a>';
    return $themes;
}
add_filter('wp_prepare_themes_for_js', 'eduardo_research_release_theme_branding', 30);
