<?php
/** Research preset contract. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

function eduardo_research_preset(): array {
    return array(
        'id' => 'research',
        'version' => 3,
        'frontend_authority' => 'theme',
        'pages' => array(
            'home' => array('role' => 'front-page', 'slug' => '', 'wp_slug' => 'home', 'model' => 'research-home-v1', 'label' => 'Home'),
            'about' => array('role' => 'profile', 'slug' => 'about', 'wp_slug' => 'about', 'model' => 'research-about-v1', 'label' => 'About'),
            'research' => array('role' => 'research-hub', 'slug' => 'research', 'wp_slug' => 'research', 'model' => 'research-hub-v1', 'label' => 'Research'),
            'publications' => array('role' => 'output-index', 'slug' => 'publications', 'wp_slug' => 'publications', 'model' => 'research-publications-v1', 'label' => 'Publications'),
            'projects' => array('role' => 'project-index', 'slug' => 'projects', 'wp_slug' => 'projects', 'model' => 'research-projects-v1', 'label' => 'Projects'),
            'software' => array('role' => 'software-index', 'slug' => 'software', 'wp_slug' => 'software', 'model' => 'research-software-v1', 'label' => 'Software'),
            'datasets' => array('role' => 'dataset-index', 'slug' => 'datasets', 'wp_slug' => 'datasets', 'model' => 'research-datasets-v1', 'label' => 'Datasets'),
            'cv' => array('role' => 'academic-cv', 'slug' => 'cv', 'wp_slug' => 'cv', 'model' => 'research-cv-v1', 'label' => 'CV'),
            'insights' => array('role' => 'editorial-index', 'slug' => 'insights', 'wp_slug' => 'insights', 'model' => 'research-insights-v1', 'label' => 'Insights'),
            'contact' => array('role' => 'contact', 'slug' => 'contact', 'wp_slug' => 'contact', 'model' => 'research-contact-v1', 'label' => 'Contact'),
            'privacy-policy' => array('role' => 'legal', 'slug' => 'privacy-policy', 'wp_slug' => 'privacy-policy', 'model' => 'research-legal-v1', 'label' => 'Privacy'),
            'legal-notice' => array('role' => 'legal', 'slug' => 'legal-notice', 'wp_slug' => 'legal-notice', 'model' => 'research-legal-v1', 'label' => 'Legal notice'),
        ),
        'primary_navigation' => array('about', 'research', 'publications', 'projects', 'software', 'datasets', 'cv', 'insights', 'contact'),
        'footer_navigation' => array('privacy-policy', 'legal-notice'),
        'evidence_required' => array(
            'degrees', 'affiliations', 'peer_review_status', 'doi', 'citation_metrics',
            'grants', 'awards', 'research_outcomes', 'academic_identifiers',
        ),
    );
}

function eduardo_research_page_url(string $key): string {
    $preset = eduardo_research_preset();
    $contract = $preset['pages'][$key] ?? null;
    if (! is_array($contract)) { return home_url('/'); }
    if ('home' === $key) { return home_url('/'); }

    $path = (string) ($contract['wp_slug'] ?? $contract['slug'] ?? $key);
    $page = get_page_by_path($path, OBJECT, 'page');
    if ($page instanceof WP_Post) { return (string) get_permalink($page); }
    return home_url('/' . trim((string) ($contract['slug'] ?? $key), '/') . '/');
}
