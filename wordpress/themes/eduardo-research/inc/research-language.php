<?php
/** Native bilingual runtime for the Research Theme. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_languages(): array {
    return array(
        'en'=>array('locale'=>'en_US','label'=>'English','short'=>'EN','prefix'=>''),
        'es'=>array('locale'=>'es_ES','label'=>'Español','short'=>'ES','prefix'=>'es'),
    );
}

function eduardo_research_default_language(): string { return 'en'; }

function eduardo_research_request_language(): string {
    $path = trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
    if ('es' === $path || str_starts_with($path, 'es/')) { return 'es'; }
    $query = (string) get_query_var('research_lang');
    if (isset(eduardo_research_languages()[$query])) { return $query; }
    return eduardo_research_default_language();
}

function eduardo_research_current_language(): string { return eduardo_research_request_language(); }
function eduardo_research_current_locale(): string { return (string) eduardo_research_languages()[eduardo_research_current_language()]['locale']; }

function eduardo_research_locale_filter(string $locale): string {
    if (is_admin()) { return $locale; }
    return (string) eduardo_research_languages()[eduardo_research_request_language()]['locale'];
}
add_filter('locale', 'eduardo_research_locale_filter', 20);

function eduardo_research_language_query_vars(array $vars): array {
    $vars[] = 'research_lang';
    $vars[] = 'research_page_key';
    return array_values(array_unique($vars));
}
add_filter('query_vars', 'eduardo_research_language_query_vars');

function eduardo_research_page_slug(string $key, ?string $language = null): string {
    $language = $language ?: eduardo_research_current_language();
    $contract = eduardo_research_preset()['pages'][$key] ?? array();
    $slugs = is_array($contract['slugs'] ?? null) ? $contract['slugs'] : array();
    return (string) ($slugs[$language] ?? $contract['slug'] ?? $key);
}

function eduardo_research_page_label(string $key, ?string $language = null): string {
    $language = $language ?: eduardo_research_current_language();
    $contract = eduardo_research_preset()['pages'][$key] ?? array();
    $labels = is_array($contract['labels'] ?? null) ? $contract['labels'] : array();
    return (string) ($labels[$language] ?? $contract['label'] ?? ucfirst($key));
}

function eduardo_research_multilingual_rewrites(): void {
    foreach ((array) (eduardo_research_preset()['pages'] ?? array()) as $key => $contract) {
        $wp_slug = (string) ($contract['wp_slug'] ?? $contract['slug'] ?? $key);
        $page = get_page_by_path($wp_slug, OBJECT, 'page');
        if (! $page instanceof WP_Post) { continue; }
        $slug = eduardo_research_page_slug((string) $key, 'es');
        $pattern = 'home' === $key ? '^es/?$' : '^es/' . preg_quote(trim($slug, '/'), '#') . '/?$';
        add_rewrite_rule($pattern, 'index.php?page_id=' . (int) $page->ID . '&research_lang=es&research_page_key=' . rawurlencode((string) $key), 'top');
    }
}
add_action('init', 'eduardo_research_multilingual_rewrites', 12);

function eduardo_research_preserve_localized_canonical($redirect, string $requested) {
    if (false === $redirect || 'es' !== eduardo_research_request_language()) { return $redirect; }
    $target_path = trim((string) wp_parse_url((string) $redirect, PHP_URL_PATH), '/');
    if ('es' !== $target_path && ! str_starts_with($target_path, 'es/')) { return false; }
    return $redirect;
}
add_filter('redirect_canonical', 'eduardo_research_preserve_localized_canonical', 20, 2);

function eduardo_research_translation_url(string $language, ?string $key = null): string {
    $language = isset(eduardo_research_languages()[$language]) ? $language : eduardo_research_default_language();
    $key = $key ?: eduardo_research_current_page_key();
    if ($key) { return eduardo_research_page_url($key, $language); }

    if (is_singular(array('research_output','research_project','research_software','research_dataset','post'))) {
        $post_id = get_queried_object_id();
        if ($post_id > 0 && function_exists('eduardo_research_post_language')) {
            $record_language = eduardo_research_post_language($post_id);
            if ($record_language === $language) { return (string) get_permalink($post_id); }
            if (function_exists('eduardo_research_record_translation_url')) {
                $alternate = eduardo_research_record_translation_url($post_id, $language);
                if ('' !== $alternate) { return $alternate; }
            }
            $type_map = array(
                'research_output'=>'publications','research_project'=>'projects','research_software'=>'software',
                'research_dataset'=>'datasets','post'=>'insights',
            );
            $index = $type_map[get_post_type($post_id)] ?? 'home';
            return eduardo_research_page_url($index, $language);
        }
    }

    return 'es' === $language ? home_url('/es/') : home_url('/');
}

function eduardo_research_dictionary(): array {
    return array(
        'skip'=>array('en'=>'Skip to content','es'=>'Saltar al contenido'),
        'primary_nav'=>array('en'=>'Primary navigation','es'=>'Navegación principal'),
        'legal_nav'=>array('en'=>'Legal navigation','es'=>'Navegación legal'),
        'language_nav'=>array('en'=>'Language','es'=>'Idioma'),
        'view_cv'=>array('en'=>'View CV','es'=>'Ver CV'),
        'evidence_aware'=>array('en'=>'Evidence-aware','es'=>'Basado en evidencia'),
        'machine_readable'=>array('en'=>'Machine-readable','es'=>'Legible por máquinas'),
        'repro_first'=>array('en'=>'Reproducibility-first','es'=>'Reproducibilidad primero'),
        'research'=>array('en'=>'Research','es'=>'Investigación'),
        'outputs'=>array('en'=>'Outputs','es'=>'Resultados'),
        'identity'=>array('en'=>'Identity','es'=>'Identidad'),
        'editorial'=>array('en'=>'Editorial','es'=>'Editorial'),
        'contact'=>array('en'=>'Contact','es'=>'Contacto'),
        'evidence_ready'=>array('en'=>'Evidence-ready','es'=>'Preparado para evidencia'),
        'research_ready_title'=>array('en'=>'Research lines are ready for verified content','es'=>'Las líneas de investigación están preparadas para contenido verificado'),
        'research_ready_body'=>array('en'=>'The Theme reserves the semantic structure without publishing unsupported research claims.','es'=>'El Theme reserva la estructura semántica sin publicar afirmaciones de investigación no verificadas.'),
        'research_collection'=>array('en'=>'Research collection','es'=>'Colección de investigación'),
        'explore'=>array('en'=>'Explore','es'=>'Explorar'),
        'verified_identifier'=>array('en'=>'Verified identifier','es'=>'Identificador verificado'),
        'open_verified_profile'=>array('en'=>'Open verified profile','es'=>'Abrir perfil verificado'),
        'verification_policy'=>array('en'=>'Verification policy','es'=>'Política de verificación'),
        'no_fabricated'=>array('en'=>'No fabricated identifiers','es'=>'Sin identificadores inventados'),
        'no_fabricated_body'=>array('en'=>'ORCID, affiliations, degrees, metrics and similar claims remain unpublished until explicitly verified.','es'=>'ORCID, afiliaciones, títulos, métricas y afirmaciones similares permanecen sin publicar hasta que se verifiquen explícitamente.'),
        'evidence'=>array('en'=>'Evidence','es'=>'Evidencia'),
        'review'=>array('en'=>'Review','es'=>'Revisión'),
        'publish'=>array('en'=>'Publish','es'=>'Publicar'),
        'all_insights'=>array('en'=>'All insights','es'=>'Todas las notas'),
        'editorial_system'=>array('en'=>'Editorial system','es'=>'Sistema editorial'),
        'notes_ready'=>array('en'=>'Research notes ready','es'=>'Notas de investigación preparadas'),
        'notes_ready_body'=>array('en'=>'Published insights will appear here automatically.','es'=>'Las notas publicadas aparecerán aquí automáticamente.'),
        'start_conversation'=>array('en'=>'Start a conversation','es'=>'Iniciar una conversación'),
        'research_system'=>array('en'=>'Research system','es'=>'Sistema de investigación'),
        'structured_knowledge'=>array('en'=>'Structured knowledge','es'=>'Conocimiento estructurado'),
        'human_machine'=>array('en'=>'Human + machine discovery','es'=>'Descubrimiento humano + máquina'),
        'software'=>array('en'=>'Software','es'=>'Software'),
        'datasets'=>array('en'=>'Datasets','es'=>'Datos'),
        'publications'=>array('en'=>'Publications','es'=>'Publicaciones'),
        'projects'=>array('en'=>'Projects','es'=>'Proyectos'),
        'verified_evidence'=>array('en'=>'Verified evidence','es'=>'Evidencia verificada'),
        'open_source'=>array('en'=>'Open source','es'=>'Abrir fuente'),
        'no_verified_evidence'=>array('en'=>'No verified evidence is published in this section yet.','es'=>'Todavía no hay evidencia verificada publicada en esta sección.'),
        'evidence_index'=>array('en'=>'Evidence-ready index','es'=>'Índice preparado para evidencia'),
        'no_records'=>array('en'=>'No research records have been published yet. The Theme keeps this surface structurally complete without inventing research claims.','es'=>'Todavía no se han publicado registros de investigación. El Theme mantiene esta superficie completa sin inventar afirmaciones de investigación.'),
        'editorial_ready'=>array('en'=>'Editorial surface ready','es'=>'Superficie editorial preparada'),
        'editorial_ready_body'=>array('en'=>'Research notes will appear here when published.','es'=>'Las notas de investigación aparecerán aquí cuando se publiquen.'),
        'structured_ready'=>array('en'=>'This Theme-owned section is ready for structured content.','es'=>'Esta sección controlada por el Theme está preparada para contenido estructurado.'),
        'insight'=>array('en'=>'Insight','es'=>'Nota'),
    );
}

function eduardo_research_t(string $key, ?string $language = null): string {
    $language = $language ?: eduardo_research_current_language();
    $dictionary = eduardo_research_dictionary();
    return (string) ($dictionary[$key][$language] ?? $dictionary[$key]['en'] ?? $key);
}

function eduardo_research_localize_record(array $record, ?string $language = null): array {
    $language = $language ?: eduardo_research_current_language();
    if ('en' === $language) { return $record; }
    if (isset($record['translations'][$language]) && is_array($record['translations'][$language])) {
        $record = array_replace($record, $record['translations'][$language]);
    }
    foreach (array('title','label','summary','value') as $field) {
        $localized = $field . '_' . $language;
        if (isset($record[$localized]) && is_scalar($record[$localized])) { $record[$field] = $record[$localized]; }
    }
    return $record;
}

function eduardo_research_verified_localized_evidence(string $group): array {
    return array_map('eduardo_research_localize_record', eduardo_research_verified_evidence($group));
}

function eduardo_research_document_title_parts(array $parts): array {
    $key = eduardo_research_current_page_key();
    if ($key && 'home' !== $key) { $parts['title'] = eduardo_research_page_label($key); }
    return $parts;
}
add_filter('document_title_parts', 'eduardo_research_document_title_parts', 20);
