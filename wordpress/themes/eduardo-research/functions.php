<?php
/** Eduardo Research theme bootstrap. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

require_once get_template_directory() . '/inc/research-preset.php';
require_once get_template_directory() . '/inc/research-content-types.php';
require_once get_template_directory() . '/inc/research-language.php';
require_once get_template_directory() . '/inc/research-content-language.php';
require_once get_template_directory() . '/inc/research-model.php';
require_once get_template_directory() . '/inc/research-evidence.php';
require_once get_template_directory() . '/inc/research-objects.php';
require_once get_template_directory() . '/inc/research-media.php';
require_once get_template_directory() . '/inc/research-scholar.php';
require_once get_template_directory() . '/inc/research-lines.php';
require_once get_template_directory() . '/inc/research-relations.php';
require_once get_template_directory() . '/inc/research-collections.php';
require_once get_template_directory() . '/inc/research-insights.php';
require_once get_template_directory() . '/inc/research-cv.php';
require_once get_template_directory() . '/inc/research-surface-layouts.php';
require_once get_template_directory() . '/inc/research-router.php';
require_once get_template_directory() . '/inc/research-seo.php';
require_once get_template_directory() . '/inc/research-editorial-seo.php';
require_once get_template_directory() . '/inc/research-discovery.php';
require_once get_template_directory() . '/inc/research-breadcrumbs.php';
require_once get_template_directory() . '/inc/research-sitemap.php';
require_once get_template_directory() . '/inc/research-install.php';
require_once get_template_directory() . '/inc/research-hardening.php';
require_once get_template_directory() . '/inc/research-design.php';
require_once get_template_directory() . '/inc/research-design-variants.php';
require_once get_template_directory() . '/inc/research-governance.php';

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
    $version = is_readable($path) ? substr((string) hash_file('sha256', $path), 0, 16) : (string) wp_get_theme()->get('Version');
    wp_enqueue_style('eduardo-research', get_stylesheet_uri(), array(), $version);

    $styles = array(
        'eduardo-research-visual-polish'=>'assets/visual-polish.css',
        'eduardo-research-interior-surfaces'=>'assets/interior-surfaces.css',
        'eduardo-research-objects'=>'assets/research-objects.css',
        'eduardo-research-collections'=>'assets/research-collections.css',
        'eduardo-research-editorial-cv'=>'assets/research-editorial-cv.css',
        'eduardo-research-navigation'=>'assets/research-navigation.css',
        'eduardo-research-media'=>'assets/research-media.css',
    );
    foreach ($styles as $handle => $relative) {
        $asset_path = get_stylesheet_directory() . '/' . $relative;
        if (! is_readable($asset_path)) { continue; }
        $asset_version = substr((string) hash_file('sha256', $asset_path), 0, 16);
        wp_enqueue_style($handle, get_stylesheet_directory_uri() . '/' . $relative, array('eduardo-research'), $asset_version);
    }

    $navigation_path = get_stylesheet_directory() . '/assets/research-navigation.js';
    if (is_readable($navigation_path)) {
        $navigation_version = substr((string) hash_file('sha256', $navigation_path), 0, 16);
        wp_enqueue_script('eduardo-research-navigation', get_stylesheet_directory_uri() . '/assets/research-navigation.js', array(), $navigation_version, true);
    }

    if ('cv' === eduardo_research_current_page_key()) {
        $script_path = get_stylesheet_directory() . '/assets/research-cv.js';
        if (is_readable($script_path)) {
            $script_version = substr((string) hash_file('sha256', $script_path), 0, 16);
            wp_enqueue_script('eduardo-research-cv', get_stylesheet_directory_uri() . '/assets/research-cv.js', array(), $script_version, true);
        }
    }
}
add_action('wp_enqueue_scripts', 'eduardo_research_assets');

function eduardo_research_page_editor_support(): void { remove_post_type_support('page', 'editor'); }
add_action('init', 'eduardo_research_page_editor_support', 20);
