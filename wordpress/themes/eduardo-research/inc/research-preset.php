<?php
/** Research preset contract. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

function eduardo_research_preset(): array {
    return array(
        'id' => 'research',
        'version' => 6,
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
        'primary_navigation' => array('about','research','cv','insights','contact'),
        'footer_navigation' => array('privacy-policy','legal-notice'),
        'evidence_required' => array(
            'degrees','affiliations','peer_review_status','doi','citation_metrics',
            'grants','awards','research_outcomes','academic_identifiers',
        ),
    );
}

function eduardo_research_navigation_contract(): array {
    $preset = eduardo_research_preset();
    return array(
        'version'=>1,
        'locations'=>array(
            'primary'=>array('default'=>array_values((array) ($preset['primary_navigation'] ?? array())), 'min_items'=>1),
            'footer'=>array('default'=>array_values((array) ($preset['footer_navigation'] ?? array())), 'min_items'=>1),
        ),
        'allowed_targets'=>array_values(array_keys((array) ($preset['pages'] ?? array()))),
        'arbitrary_urls'=>false,
        'language_aware_urls'=>true,
    );
}

function eduardo_research_navigation_sanitize_state(array $candidate): array {
    $contract = eduardo_research_navigation_contract();
    $allowed = array_fill_keys((array) ($contract['allowed_targets'] ?? array()), true);
    $state = array();
    foreach ((array) ($contract['locations'] ?? array()) as $location => $spec) {
        $default = array_values((array) ($spec['default'] ?? array()));
        $items = isset($candidate[$location]) && is_array($candidate[$location]) ? $candidate[$location] : $default;
        $clean = array();
        foreach ($items as $key) {
            $key = sanitize_key((string) $key);
            if ('' === $key || ! isset($allowed[$key]) || in_array($key, $clean, true)) { continue; }
            $clean[] = $key;
        }
        if (count($clean) < max(1, (int) ($spec['min_items'] ?? 1))) { $clean = $default; }
        $state[(string) $location] = $clean;
    }
    return $state;
}

function eduardo_research_navigation_state(): array {
    $stored = get_option('eduardo_research_navigation', array());
    return eduardo_research_navigation_sanitize_state(is_array($stored) ? $stored : array());
}

function eduardo_research_navigation_target_is_visible(string $key): bool {
    if (! function_exists('eduardo_research_collection_post_type')) { return true; }
    $post_type = eduardo_research_collection_post_type($key);
    if ('' === $post_type) { return true; }

    $language = function_exists('eduardo_research_current_language')
        ? eduardo_research_current_language()
        : sanitize_key((string) (eduardo_research_preset()['default_language'] ?? ''));
    static $cache = array();
    $cache_key = $language . ':' . $key;
    if (array_key_exists($cache_key, $cache)) { return (bool) $cache[$cache_key]; }

    $args = array(
        'post_type'=>$post_type,
        'post_status'=>'publish',
        'posts_per_page'=>1,
        'fields'=>'ids',
        'no_found_rows'=>true,
    );
    if (function_exists('eduardo_research_localized_query_args')) {
        $args = eduardo_research_localized_query_args($args, $language);
    }
    $query = new WP_Query($args);
    $cache[$cache_key] = $query->have_posts();
    return (bool) $cache[$cache_key];
}

function eduardo_research_navigation_keys(string $location): array {
    $state = eduardo_research_navigation_state();
    $keys = array_values((array) ($state[sanitize_key($location)] ?? array()));
    if ('primary' === sanitize_key($location)) {
        $keys = array_values(array_filter($keys, 'eduardo_research_navigation_target_is_visible'));
    }
    return $keys;
}

function eduardo_research_page_url(string $key, ?string $language = null): string {
    $language = $language ?: (function_exists('eduardo_research_current_language')
        ? eduardo_research_current_language()
        : sanitize_key((string) (eduardo_research_preset()['default_language'] ?? '')));
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
