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
        'evidence_aware'=>array('en'=>'Autonomous AI','es'=>'IA autónoma'),
        'machine_readable'=>array('en'=>'Human–AI collaboration','es'=>'Colaboración humano–IA'),
        'repro_first'=>array('en'=>'Digital transformation','es'=>'Transformación digital'),
        'research'=>array('en'=>'Research','es'=>'Investigación'),
        'outputs'=>array('en'=>'Outputs','es'=>'Resultados'),
        'identity'=>array('en'=>'Academic profiles','es'=>'Perfiles académicos'),
        'editorial'=>array('en'=>'Research notes','es'=>'Notas de investigación'),
        'contact'=>array('en'=>'Contact','es'=>'Contacto'),
        'evidence_ready'=>array('en'=>'Research agenda','es'=>'Agenda de investigación'),
        'research_ready_title'=>array('en'=>'Research directions','es'=>'Líneas de investigación'),
        'research_ready_body'=>array('en'=>'Research lines and questions will be added as the agenda develops.','es'=>'Las líneas y preguntas de investigación se incorporarán a medida que avance la agenda.'),
        'research_collection'=>array('en'=>'Research collection','es'=>'Colección de investigación'),
        'explore'=>array('en'=>'Explore','es'=>'Explorar'),
        'verified_identifier'=>array('en'=>'Academic profile','es'=>'Perfil académico'),
        'open_verified_profile'=>array('en'=>'Open profile','es'=>'Abrir perfil'),
        'verification_policy'=>array('en'=>'Academic profiles','es'=>'Perfiles académicos'),
        'no_fabricated'=>array('en'=>'Academic identifiers','es'=>'Identificadores académicos'),
        'no_fabricated_body'=>array('en'=>'ORCID and other academic profiles will appear here as they become available.','es'=>'ORCID y otros perfiles académicos aparecerán aquí a medida que estén disponibles.'),
        'evidence'=>array('en'=>'Research','es'=>'Investigación'),
        'review'=>array('en'=>'Methods','es'=>'Métodos'),
        'publish'=>array('en'=>'Outputs','es'=>'Resultados'),
        'all_insights'=>array('en'=>'All research notes','es'=>'Todas las notas'),
        'editorial_system'=>array('en'=>'Research notes','es'=>'Notas de investigación'),
        'notes_ready'=>array('en'=>'Research notes','es'=>'Notas de investigación'),
        'notes_ready_body'=>array('en'=>'Published research notes will appear here.','es'=>'Las notas de investigación publicadas aparecerán aquí.'),
        'start_conversation'=>array('en'=>'Get in touch','es'=>'Contactar'),
        'research_system'=>array('en'=>'Research agenda','es'=>'Agenda de investigación'),
        'structured_knowledge'=>array('en'=>'Applied research','es'=>'Investigación aplicada'),
        'human_machine'=>array('en'=>'AI and organisations','es'=>'IA y organizaciones'),
        'software'=>array('en'=>'Software','es'=>'Software'),
        'datasets'=>array('en'=>'Datasets','es'=>'Datos'),
        'publications'=>array('en'=>'Publications','es'=>'Publicaciones'),
        'projects'=>array('en'=>'Projects','es'=>'Proyectos'),
        'verified_evidence'=>array('en'=>'Profile information','es'=>'Información del perfil'),
        'open_source'=>array('en'=>'View record','es'=>'Ver registro'),
        'no_verified_evidence'=>array('en'=>'No entries have been published in this section yet.','es'=>'Todavía no se han publicado elementos en esta sección.'),
        'evidence_index'=>array('en'=>'Research index','es'=>'Índice de investigación'),
        'no_records'=>array('en'=>'No research records have been published yet.','es'=>'Todavía no se han publicado registros de investigación.'),
        'editorial_ready'=>array('en'=>'Research notes','es'=>'Notas de investigación'),
        'editorial_ready_body'=>array('en'=>'Research notes will appear here when published.','es'=>'Las notas de investigación aparecerán aquí cuando se publiquen.'),
        'structured_ready'=>array('en'=>'More information will be added as the research agenda develops.','es'=>'Se añadirá más información a medida que avance la agenda de investigación.'),
        'insight'=>array('en'=>'Research note','es'=>'Nota de investigación'),
        'ai_agents'=>array('en'=>'Autonomous agents','es'=>'Agentes autónomos'),
        'human_ai'=>array('en'=>'Human–AI collaboration','es'=>'Colaboración humano–IA'),
        'smes'=>array('en'=>'SME transformation','es'=>'Transformación de pymes'),
        'ai_search'=>array('en'=>'AI-mediated discovery','es'=>'Descubrimiento mediado por IA'),
        'research_agenda'=>array('en'=>'Research agenda','es'=>'Agenda de investigación'),
        'applied_ai_systems'=>array('en'=>'Four research lines','es'=>'Cuatro líneas de investigación'),
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
