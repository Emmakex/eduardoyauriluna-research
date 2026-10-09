<?php
/** Eduardo Research theme bootstrap. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

require_once get_template_directory() . '/inc/research-preset.php';
require_once get_template_directory() . '/inc/research-language.php';
require_once get_template_directory() . '/inc/research-content-language.php';
require_once get_template_directory() . '/inc/research-model.php';
require_once get_template_directory() . '/inc/research-evidence.php';
require_once get_template_directory() . '/inc/research-objects.php';
require_once get_template_directory() . '/inc/research-relations.php';
require_once get_template_directory() . '/inc/research-collections.php';
require_once get_template_directory() . '/inc/research-surface-layouts.php';
require_once get_template_directory() . '/inc/research-router.php';
require_once get_template_directory() . '/inc/research-seo.php';
require_once get_template_directory() . '/inc/research-discovery.php';
require_once get_template_directory() . '/inc/research-breadcrumbs.php';
require_once get_template_directory() . '/inc/research-sitemap.php';
require_once get_template_directory() . '/inc/research-install.php';

function eduardo_research_setup(): void {
    load_theme_textdomain('eduardo-research', get_template_directory() . '/languages');
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('responsive-embeds');
    add_theme_support('html5', array('search-form', 'gallery', 'caption', 'style', 'script'));
    remove_action('wp_head', 'rel_canonical');
}
add_action('after_setup_theme', 'eduardo_research_setup');

function eduardo_research_assets(): void {
    $path = get_stylesheet_directory() . '/style.css';
    $version = is_readable($path) ? substr((string) hash_file('sha256', $path), 0, 16) : '0.1.0';
    wp_enqueue_style('eduardo-research', get_stylesheet_uri(), array(), $version);

    $polish_path = get_stylesheet_directory() . '/assets/visual-polish.css';
    if (is_readable($polish_path)) {
        $polish_version = substr((string) hash_file('sha256', $polish_path), 0, 16);
        wp_enqueue_style('eduardo-research-visual-polish', get_stylesheet_directory_uri() . '/assets/visual-polish.css', array('eduardo-research'), $polish_version);
    }

    $interior_path = get_stylesheet_directory() . '/assets/interior-surfaces.css';
    if (is_readable($interior_path)) {
        $interior_version = substr((string) hash_file('sha256', $interior_path), 0, 16);
        wp_enqueue_style('eduardo-research-interior-surfaces', get_stylesheet_directory_uri() . '/assets/interior-surfaces.css', array('eduardo-research'), $interior_version);
    }

    $objects_path = get_stylesheet_directory() . '/assets/research-objects.css';
    if (is_readable($objects_path)) {
        $objects_version = substr((string) hash_file('sha256', $objects_path), 0, 16);
        wp_enqueue_style('eduardo-research-objects', get_stylesheet_directory_uri() . '/assets/research-objects.css', array('eduardo-research'), $objects_version);
    }

    $collections_path = get_stylesheet_directory() . '/assets/research-collections.css';
    if (is_readable($collections_path)) {
        $collections_version = substr((string) hash_file('sha256', $collections_path), 0, 16);
        wp_enqueue_style('eduardo-research-collections', get_stylesheet_directory_uri() . '/assets/research-collections.css', array('eduardo-research'), $collections_version);
    }
}
add_action('wp_enqueue_scripts', 'eduardo_research_assets');

function eduardo_research_register_content_types(): void {
    $types = array(
        'research_line' => array('Research Lines', 'Research Line', 'dashicons-networking', false),
        'research_output' => array('Outputs', 'Output', 'dashicons-media-document', 'publications'),
        'research_project' => array('Projects', 'Project', 'dashicons-portfolio', 'projects'),
        'research_software' => array('Software', 'Software', 'dashicons-editor-code', 'software'),
        'research_dataset' => array('Datasets', 'Dataset', 'dashicons-database', 'datasets'),
    );
    foreach ($types as $type => $labels) {
        $supports = array('title', 'editor', 'excerpt', 'thumbnail', 'custom-fields');
        if ('research_line' === $type) { $supports[] = 'page-attributes'; }
        register_post_type($type, array(
            'labels' => array('name' => $labels[0], 'singular_name' => $labels[1]),
            'public' => true,
            'show_in_rest' => true,
            'has_archive' => false,
            'rewrite' => false === $labels[3] ? false : array('slug' => $labels[3], 'with_front' => false),
            'menu_icon' => $labels[2],
            'supports' => $supports,
        ));
    }
}
add_action('init', 'eduardo_research_register_content_types');
function eduardo_research_page_editor_support(): void { remove_post_type_support('page', 'editor'); }
add_action('init', 'eduardo_research_page_editor_support', 20);

/**
 * Keep researcher identity and Theme product ownership separate in wp-admin.
 * The site belongs to the researcher; the reusable Theme product is Emmake by Kairoseth.
 */
function eduardo_research_admin_theme_branding(array $themes): array {
    $stylesheet = get_stylesheet();
    if (! isset($themes[$stylesheet]) || ! is_array($themes[$stylesheet])) { return $themes; }

    $themes[$stylesheet]['author'] = 'Emmake by Kairoseth';
    $themes[$stylesheet]['authorAndUri'] = '<a href="https://kairoseth.com/">Emmake by Kairoseth</a>';
    $themes[$stylesheet]['description'] = 'Academic research Theme for Eduardo Research. Theme and product ownership: Emmake by Kairoseth.';

    return $themes;
}
add_filter('wp_prepare_themes_for_js', 'eduardo_research_admin_theme_branding');
