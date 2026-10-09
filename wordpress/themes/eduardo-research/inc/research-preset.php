<?php
/** Research preset contract. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

function eduardo_research_preset(): array {
    return array(
        'id' => 'research',
        'version' => 1,
        'frontend_authority' => 'theme',
        'pages' => array(
            'home' => array('role' => 'front-page', 'slug' => '', 'model' => 'research-home-v1'),
            'about' => array('role' => 'profile', 'slug' => 'about', 'model' => 'research-about-v1'),
            'research' => array('role' => 'research-hub', 'slug' => 'research', 'model' => 'research-hub-v1'),
            'publications' => array('role' => 'output-index', 'slug' => 'publications', 'model' => 'research-publications-v1'),
            'projects' => array('role' => 'project-index', 'slug' => 'projects', 'model' => 'research-projects-v1'),
            'software' => array('role' => 'software-index', 'slug' => 'software', 'model' => 'research-software-v1'),
            'datasets' => array('role' => 'dataset-index', 'slug' => 'datasets', 'model' => 'research-datasets-v1'),
            'cv' => array('role' => 'academic-cv', 'slug' => 'cv', 'model' => 'research-cv-v1'),
            'insights' => array('role' => 'editorial-index', 'slug' => 'insights', 'model' => 'research-insights-v1'),
            'contact' => array('role' => 'contact', 'slug' => 'contact', 'model' => 'research-contact-v1'),
        ),
        'evidence_required' => array(
            'degrees', 'affiliations', 'peer_review_status', 'doi', 'citation_metrics',
            'grants', 'awards', 'research_outcomes', 'academic_identifiers',
        ),
    );
}
