<?php
/** Research preset contract. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

function eduardo_research_preset(): array {
    return array(
        'id' => 'research',
        'version' => 4,
        'frontend_authority' => 'theme',
        'default_language' => 'en',
        'languages' => array('en','es'),
        'pages' => array(
            'home' => array('role'=>'front-page','slug'=>'','wp_slug'=>'home','model'=>'research-home-v1','label'=>'Home','labels'=>array('en'=>'Home','es'=>'Inicio'),'slugs'=>array('en'=>'','es'=>'')),
            'about' => array('role'=>'profile','slug'=>'about','wp_slug'=>'about','model'=>'research-about-v1','label'=>'About','labels'=>array('en'=>'About','es'=>'Perfil'),'slugs'=>array('en'=>'about','es'=>'perfil')),
            'research' => array('role'=>'research-hub','slug'=>'research','wp_slug'=>'research','model'=>'research-hub-v1','label'=>'Research','labels'=>array('en'=>'Research','es'=>'Investigación'),'slugs'=>array('en'=>'research','es'=>'investigacion')),
            'publications' => array('role'=>'output-index','slug'=>'publications','wp_slug'=>'publications','model'=>'research-publications-v1','label'=>'Publications','labels'=>array('en'=>'Publications','es'=>'Publicaciones'),'slugs'=>array('en'=>'publications','es'=>'publicaciones')),
            'projects' => array('role'=>'project-index','slug'=>'projects','wp_slug'=>'projects','model'=>'research-projects-v1','label'=>'Projects','labels'=>array('en'=>'Projects','es'=>'Proyectos'),'slugs'=>array('en'=>'projects','es'=>'proyectos')),
            'software' => array('role'=>'software-index','slug'=>'software','wp_slug'=>'software','model'=>'research-software-v1','label'=>'Software','labels'=>array('en'=>'Software','es'=>'Software'),'slugs'=>array('en'=>'software','es'=>'software')),
            'datasets' => array('role'=>'dataset-index','slug'=>'datasets','wp_slug'=>'datasets','model'=>'research-datasets-v1','label'=>'Datasets','labels'=>array('en'=>'Datasets','es'=>'Datos'),'slugs'=>array('en'=>'datasets','es'=>'datos')),
            'cv' => array('role'=>'academic-cv','slug'=>'cv','wp_slug'=>'cv','model'=>'research-cv-v1','label'=>'CV','labels'=>array('en'=>'CV','es'=>'CV'),'slugs'=>array('en'=>'cv','es'=>'cv')),
            'insights' => array('role'=>'editorial-index','slug'=>'insights','wp_slug'=>'insights','model'=>'research-insights-v1','label'=>'Insights','labels'=>array('en'=>'Insights','es'=>'Notas'),'slugs'=>array('en'=>'insights','es'=>'notas')),
            'contact' => array('role'=>'contact','slug'=>'contact','wp_slug'=>'contact','model'=>'research-contact-v1','label'=>'Contact','labels'=>array('en'=>'Contact','es'=>'Contacto'),'slugs'=>array('en'=>'contact','es'=>'contacto')),
            'privacy-policy' => array('role'=>'legal','slug'=>'privacy-policy','wp_slug'=>'privacy-policy','model'=>'research-legal-v1','label'=>'Privacy','labels'=>array('en'=>'Privacy','es'=>'Privacidad'),'slugs'=>array('en'=>'privacy-policy','es'=>'privacidad')),
            'legal-notice' => array('role'=>'legal','slug'=>'legal-notice','wp_slug'=>'legal-notice','model'=>'research-legal-v1','label'=>'Legal notice','labels'=>array('en'=>'Legal notice','es'=>'Aviso legal'),'slugs'=>array('en'=>'legal-notice','es'=>'aviso-legal')),
        ),
        'primary_navigation' => array('about','research','publications','projects','software','datasets','cv','insights','contact'),
        'footer_navigation' => array('privacy-policy','legal-notice'),
        'evidence_required' => array(
            'degrees','affiliations','peer_review_status','doi','citation_metrics',
            'grants','awards','research_outcomes','academic_identifiers',
        ),
    );
}

function eduardo_research_page_url(string $key, ?string $language = null): string {
    $language = $language ?: (function_exists('eduardo_research_current_language') ? eduardo_research_current_language() : 'en');
    $preset = eduardo_research_preset();
    $contract = $preset['pages'][$key] ?? null;
    if (! is_array($contract)) { return 'es' === $language ? home_url('/es/') : home_url('/'); }
    if ('home' === $key) { return 'es' === $language ? home_url('/es/') : home_url('/'); }

    if ('es' === $language) {
        $slug = function_exists('eduardo_research_page_slug') ? eduardo_research_page_slug($key, 'es') : (string) ($contract['slug'] ?? $key);
        return home_url('/es/' . trim($slug, '/') . '/');
    }

    $path = (string) ($contract['wp_slug'] ?? $contract['slug'] ?? $key);
    $page = get_page_by_path($path, OBJECT, 'page');
    if ($page instanceof WP_Post) { return (string) get_permalink($page); }
    return home_url('/' . trim((string) ($contract['slug'] ?? $key), '/') . '/');
}
