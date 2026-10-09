<?php
/** Theme-owned semantic slot model. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

function eduardo_research_default_model(): array {
    return array(
        'hero-eyebrow' => 'Research profile',
        'researcher-name' => get_bloginfo('name') ?: 'Researcher',
        'researcher-headline' => get_bloginfo('description') ?: 'Research, software and evidence-driven digital systems',
        'hero-lead' => 'A structured research profile designed for scholarly discovery, reproducibility and machine-readable academic context.',
        'research-lines-heading' => 'Research agenda',
        'research-lines-intro' => 'Active research lines, questions and methods are published here with clear provenance.',
        'selected-outputs-heading' => 'Selected outputs',
        'selected-outputs-intro' => 'Publications, projects, software and datasets are represented as first-class research objects.',
        'academic-identifiers-heading' => 'Verified academic identity',
        'latest-insights-heading' => 'Latest research notes',
        'final-cta-heading' => 'Research and collaboration',
        'final-cta-body' => 'Explore the research agenda, outputs and reproducible artefacts.',
        'final-cta-button' => 'Explore research',
    );
}

function eduardo_research_model(): array {
    $defaults = eduardo_research_default_model();
    $stored = get_option('eduardo_research_model', array());
    if (! is_array($stored)) { return $defaults; }

    return array_replace($defaults, array_intersect_key($stored, $defaults));
}

function eduardo_research_slot(string $key): string {
    $model = eduardo_research_model();
    return isset($model[$key]) && is_scalar($model[$key]) ? (string) $model[$key] : '';
}
