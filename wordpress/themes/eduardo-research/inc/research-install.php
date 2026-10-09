<?php
/** One-time Theme bootstrap for a clean WordPress installation. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_install_page(string $key, array $contract): int {
    $path = (string) ($contract['wp_slug'] ?? $contract['slug'] ?? $key);
    $existing = get_page_by_path($path, OBJECT, 'page');
    if ($existing instanceof WP_Post) {
        update_post_meta($existing->ID, '_eduardo_research_role', (string) ($contract['role'] ?? ''));
        update_post_meta($existing->ID, '_eduardo_research_model', (string) ($contract['model'] ?? ''));
        return (int) $existing->ID;
    }

    $id = wp_insert_post(array(
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => (string) ($contract['label'] ?? ucfirst($key)),
        'post_name' => $path,
        'post_content' => '',
        'post_excerpt' => '',
    ), true);

    if (is_wp_error($id)) { return 0; }
    update_post_meta((int) $id, '_eduardo_research_role', (string) ($contract['role'] ?? ''));
    update_post_meta((int) $id, '_eduardo_research_model', (string) ($contract['model'] ?? ''));
    return (int) $id;
}

function eduardo_research_install(): void {
    $preset = eduardo_research_preset();
    $page_ids = array();
    foreach ((array) ($preset['pages'] ?? array()) as $key => $contract) {
        $page_ids[$key] = eduardo_research_install_page((string) $key, (array) $contract);
    }

    if (! empty($page_ids['home'])) {
        update_option('show_on_front', 'page');
        update_option('page_on_front', (int) $page_ids['home']);
    }
    if (! empty($page_ids['privacy-policy']) && 0 === (int) get_option('wp_page_for_privacy_policy')) {
        update_option('wp_page_for_privacy_policy', (int) $page_ids['privacy-policy']);
    }

    eduardo_research_register_content_types();
    eduardo_research_discovery_rewrites();
    flush_rewrite_rules(false);
    update_option('eduardo_research_theme_bootstrap_version', '2');
}
add_action('after_switch_theme', 'eduardo_research_install');
