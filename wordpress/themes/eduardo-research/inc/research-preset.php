<?php
/** Research preset contract. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

function eduardo_research_preset(): array {
    return array(
        'id' => 'research',
        'version' => 2,
        'frontend_authority' => 'theme',
        'pages' => array(
            'home' => array('role' => 'front-page', 'slug' => '', 'model' => 'research-home-v1', 'label' => 'Home'),
            'about' => array('role' => 'profile', 'slug' => 'about', 'model' => 'research-about-v1', 'label' => 'About'),
            'research' => array('role' => 'research-hub', 'slug' => 'research', 'model' => 'research-hub-v1', 'label' => 'Research'),
            'publications' => array('role' => 'output-index', 'slug' => 'publications', 'model' => 'research-publications-v1', 'label' => 'Publications'),
            'projects' => array('role' => 'project-index', 'slug' => 'projects', 'model' => 'research-projects-v1', 'label' => 'Projects'),
            'software' => array('role' => 'software-index', 'slug' => 'software', 'model' => 'research-software-v1', 'label' => 'Software'),
            'datasets' => array('role' => 'dataset-index', 'slug' => 'datasets', 'model' => 'research-datasets-v1', 'label' => 'Datasets'),
            'cv' => array('role' => 'academic-cv', 'slug' => 'cv', 'model' => 'research-cv-v1', 'label' => 'CV'),
            'insights' => array('role' => 'editorial-index', 'slug' => 'insights', 'model' => 'research-insights-v1', 'label' => 'Insights'),
            'contact' => array('role' => 'contact', 'slug' => 'contact', 'model' => 'research-contact-v1', 'label' => 'Contact'),
            'privacy-policy' => array('role' => 'legal', 'slug' => 'privacy-policy', 'model' => 'research-legal-v1', 'label' => 'Privacy'),
            'legal-notice' => array('role' => 'legal', 'slug' => 'legal-notice', 'model' => 'research-legal-v1', 'label' => 'Legal notice'),
        ),
        'primary_navigation' => array('about', 'research', 'publications', 'projects', 'software', 'datasets', 'cv', 'insights', 'contact'),
        'footer_navigation' => array('privacy-policy', 'legal-notice'),
        'evidence_required' => array(
            'degrees', 'affiliations', 'peer_review_status', 'doi', 'citation_metrics',
            'grants', 'awards', 'research_outcomes', 'academic_identifiers',
        ),
    );
}
