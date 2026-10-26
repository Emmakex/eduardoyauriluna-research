<?php
/** Native bilingual runtime and bounded UI copy contract for the Research Theme. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_languages(): array {
    return array(
        'en'=>array('locale'=>'en_US','label'=>'English','short'=>'EN','prefix'=>''),
        'es'=>array('locale'=>'es_ES','label'=>'Español','short'=>'ES','prefix'=>'es'),
    );
}

function eduardo_research_default_language(): string {
    $declared = sanitize_key((string) (eduardo_research_preset()['default_language'] ?? ''));
    $languages = eduardo_research_languages();
    if ('' !== $declared && isset($languages[$declared])) { return $declared; }
    $fallback = array_key_first($languages);
    return is_string($fallback) ? $fallback : '';
}

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
    $contract = eduardo_research_page_contract($key) ?? array();
    $slugs = is_array($contract['slugs'] ?? null) ? $contract['slugs'] : array();
    return (string) ($slugs[$language] ?? $contract['slug'] ?? $key);
}

function eduardo_research_page_label(string $key, ?string $language = null): string {
    $language = $language ?: eduardo_research_current_language();
    $contract = eduardo_research_page_contract($key) ?? array();
    $labels = is_array($contract['labels'] ?? null) ? $contract['labels'] : array();
    return (string) ($labels[$language] ?? $contract['label'] ?? ucfirst($key));
}

function eduardo_research_multilingual_rewrites(): void {
    foreach (eduardo_research_page_contracts() as $key => $contract) {
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

    $singular_types = array_merge(eduardo_research_surface_content_types(), array('post'));
    if (is_singular($singular_types)) {
        $post_id = get_queried_object_id();
        if ($post_id > 0 && function_exists('eduardo_research_post_language')) {
            $record_language = eduardo_research_post_language($post_id);
            if ($record_language === $language) { return (string) get_permalink($post_id); }
            if (function_exists('eduardo_research_record_translation_url')) {
                $alternate = eduardo_research_record_translation_url($post_id, $language);
                if ('' !== $alternate) { return $alternate; }
            }
            $post_type = (string) get_post_type($post_id);
            $index = 'post' === $post_type ? 'insights' : eduardo_research_content_type_surface($post_type);
            if ('' === $index) { $index = 'home'; }
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
        'menu_open'=>array('en'=>'Menu','es'=>'Menú'),
        'menu_close'=>array('en'=>'Close','es'=>'Cerrar'),
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

function eduardo_research_ui_copy_contract(): array {
    $languages = array_values(array_keys(eduardo_research_languages()));
    $keys = array();
    foreach (eduardo_research_dictionary() as $key => $translations) {
        if (! is_array($translations)) { continue; }
        $keys[(string) $key] = array(
            'languages'=>$languages,
            'max_length'=>str_ends_with((string) $key, '_body') ? 500 : 160,
        );
    }
    return array(
        'version'=>1,
        'option'=>'eduardo_research_ui_copy',
        'keys'=>$keys,
        'constraints'=>array(
            'registered_keys_only'=>true,
            'languages'=>$languages,
            'html'=>false,
            'shortcodes'=>false,
            'arbitrary_option'=>false,
            'empty_override'=>false,
        ),
    );
}

function eduardo_research_ui_copy_sanitize_state($value): array {
    $value = is_array($value) ? $value : array();
    $contract = eduardo_research_ui_copy_contract();
    $clean = array();
    foreach ((array) $contract['keys'] as $key => $spec) {
        if (! is_array($value[$key] ?? null)) { continue; }
        $limit = max(1, (int) ($spec['max_length'] ?? 160));
        foreach ((array) ($spec['languages'] ?? array()) as $language) {
            if (! isset($value[$key][$language]) || ! is_scalar($value[$key][$language])) { continue; }
            $text = trim(sanitize_text_field((string) $value[$key][$language]));
            if ('' === $text) { continue; }
            if (function_exists('mb_substr')) { $text = mb_substr($text, 0, $limit); }
            else { $text = substr($text, 0, $limit); }
            $clean[(string) $key][(string) $language] = $text;
        }
    }
    return $clean;
}

function eduardo_research_ui_copy_state(): array {
    $contract = eduardo_research_ui_copy_contract();
    $stored = get_option((string) $contract['option'], array());
    return eduardo_research_ui_copy_sanitize_state($stored);
}

function eduardo_research_ui_copy_public_contract(): array {
    $contract = eduardo_research_ui_copy_contract();
    return array(
        'version'=>(int) $contract['version'],
        'state'=>eduardo_research_ui_copy_state(),
        'editable_keys'=>(array) $contract['keys'],
        'constraints'=>(array) $contract['constraints'],
    );
}

function eduardo_research_t(string $key, ?string $language = null): string {
    $language = $language ?: eduardo_research_current_language();
    $dictionary = eduardo_research_dictionary();
    $overrides = eduardo_research_ui_copy_state();
    if (isset($overrides[$key][$language]) && is_scalar($overrides[$key][$language])) {
        $override = trim((string) $overrides[$key][$language]);
        if ('' !== $override) { return $override; }
    }
    $default = eduardo_research_default_language();
    return (string) ($dictionary[$key][$language] ?? $dictionary[$key][$default] ?? $key);
}

function eduardo_research_localize_record(array $record, ?string $language = null): array {
    $language = $language ?: eduardo_research_current_language();
    if (eduardo_research_default_language() === $language) { return $record; }
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
