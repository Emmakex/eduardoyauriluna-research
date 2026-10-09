<?php
/** Theme-owned semantic slot models. */
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

function eduardo_research_surface_models(): array {
    return array(
        'about' => array('eyebrow'=>'Profile','lead'=>'Researcher profile, background and verified professional or academic context.','sections'=>array('Profile','Background','Verified identity')),
        'research' => array('eyebrow'=>'Research agenda','lead'=>'Research lines, questions, methods and reproducible work organised for people and machines.','sections'=>array('Research lines','Methods','Current questions')),
        'publications' => array('eyebrow'=>'Research outputs','lead'=>'Scholarly and research outputs with explicit status, provenance and citation guidance.'),
        'projects' => array('eyebrow'=>'Projects','lead'=>'Research projects with objectives, methods, collaborators and verifiable outcomes.'),
        'software' => array('eyebrow'=>'Research software','lead'=>'Software and technical artefacts supporting reproducible research and experimentation.'),
        'datasets' => array('eyebrow'=>'Datasets','lead'=>'Research datasets with provenance, scope, access conditions and reuse context.'),
        'cv' => array('eyebrow'=>'Curriculum vitae','lead'=>'Academic and research curriculum generated from verified structured evidence.','sections'=>array('Experience','Education','Research outputs','Selected projects')),
        'insights' => array('eyebrow'=>'Insights','lead'=>'Research notes, explainers and working ideas inside a Theme-owned editorial surface.'),
        'contact' => array('eyebrow'=>'Contact','lead'=>'Research contact channels and verified academic profile identifiers.','sections'=>array('Research enquiries','Academic profiles')),
        'privacy-policy' => array('eyebrow'=>'Legal','lead'=>'Privacy information for this research website.','sections'=>array('Data handling','Contact')),
        'legal-notice' => array('eyebrow'=>'Legal','lead'=>'Legal and ownership information for this research website.','sections'=>array('Site ownership','Terms of use')),
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

function eduardo_research_surface_model(string $key): array {
    $models = eduardo_research_surface_models();
    $defaults = $models[$key] ?? array('eyebrow'=>'Research','lead'=>'Research profile surface.');
    $stored = get_option('eduardo_research_surface_' . sanitize_key($key), array());
    return is_array($stored) ? array_replace($defaults, array_intersect_key($stored, $defaults)) : $defaults;
}
