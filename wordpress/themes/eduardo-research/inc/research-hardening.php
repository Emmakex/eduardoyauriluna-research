<?php
/** Final public evidence gates and crawl hardening for the Research Theme. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_register_verification_meta(): void {
    register_post_meta('research_output', '_research_review_status_verified', array(
        'type'=>'string','single'=>true,'show_in_rest'=>true,
        'sanitize_callback'=>static fn($value): string => '1' === (string) $value ? '1' : '0',
        'auth_callback'=>static fn() => current_user_can('edit_posts'),
    ));
}
add_action('init', 'eduardo_research_register_verification_meta', 19);

/**
 * Public frontend reads of academic classification/review claims are evidence-gated.
 * Raw values remain available inside wp-admin so they can be reviewed before publication.
 */
function eduardo_research_gate_public_academic_meta($value, int $object_id, string $meta_key, bool $single) {
    if (is_admin()) { return $value; }
    $gate_map = array(
        '_research_output_type'=>'_research_output_type_verified',
        '_research_review_status'=>'_research_review_status_verified',
    );
    if (! isset($gate_map[$meta_key])) { return $value; }
    if ('1' === (string) get_post_meta($object_id, $gate_map[$meta_key], true)) { return $value; }
    return $single ? '' : array();
}
add_filter('get_post_metadata', 'eduardo_research_gate_public_academic_meta', 20, 4);

function eduardo_research_meta_query_has_key(array $meta_query, string $key): bool {
    foreach ($meta_query as $clause_key => $clause) {
        if ('relation' === $clause_key) { continue; }
        if (! is_array($clause)) { continue; }
        if (isset($clause['key']) && $key === (string) $clause['key']) { return true; }
        if (eduardo_research_meta_query_has_key($clause, $key)) { return true; }
    }
    return false;
}

/**
 * Build mandatory evidence clauses from the query itself rather than from the request.
 * This protects every public Research Output query, not only the collection form.
 */
function eduardo_research_publication_query_verification_clauses(array $meta_query): array {
    $clauses = array();
    $requirements = array(
        '_research_output_type'=>'_research_output_type_verified',
        '_research_review_status'=>'_research_review_status_verified',
    );
    foreach ($requirements as $claim_key => $verified_key) {
        if (! eduardo_research_meta_query_has_key($meta_query, $claim_key)) { continue; }
        if (eduardo_research_meta_query_has_key($meta_query, $verified_key)) { continue; }
        $clauses[] = array('key'=>$verified_key,'value'=>'1','compare'=>'=');
    }
    return $clauses;
}

/** Prevent filtered collection counts/results from revealing unverified academic claims. */
function eduardo_research_harden_publication_filter_query(WP_Query $query): void {
    if (is_admin()) { return; }
    $type = $query->get('post_type');
    $is_output_query = 'research_output' === $type || (is_array($type) && in_array('research_output', $type, true));
    if (! $is_output_query) { return; }

    $existing = $query->get('meta_query');
    $existing = is_array($existing) ? $existing : array();
    $clauses = eduardo_research_publication_query_verification_clauses($existing);
    if (! $clauses) { return; }

    $combined = array('relation'=>'AND');
    if ($existing) { $combined[] = $existing; }
    foreach ($clauses as $clause) { $combined[] = $clause; }
    $query->set('meta_query', $combined);
}
add_action('pre_get_posts', 'eduardo_research_harden_publication_filter_query', 30);

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
        $robots['follow'] = true;
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
