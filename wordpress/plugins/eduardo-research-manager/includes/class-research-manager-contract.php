<?php
/** Greenfield Theme contract adapter for Site Architect. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-resource-registry.php';

final class Eduardo_Research_Manager_Contract {
    public const EXPECTED_PRESET = 'research';
    public const EXPECTED_STYLESHEET = 'eduardo-research';
    public const MIN_PRESET_VERSION = 4;

    /**
     * Physical production storage aliases retained for backward compatibility.
     *
     * These are not the conceptual language model. They describe how the
     * currently accepted EN/ES Greenfield installation stores language-bound
     * state so callers do not independently reconstruct `_es` conventions.
     */
    private const LEGACY_LANGUAGE_STORAGE = array(
        'en'=>array('suffix'=>'','identity_headline_field'=>'headline'),
        'es'=>array('suffix'=>'_es','identity_headline_field'=>'headline_es'),
    );

    public function theme_available(): bool {
        return function_exists('eduardo_research_preset');
    }

    public function preset(): array {
        if (! $this->theme_available()) { return array(); }
        $preset = eduardo_research_preset();
        return is_array($preset) ? $preset : array();
    }

    public function compatible(): bool {
        $preset = $this->preset();
        return self::EXPECTED_PRESET === (string) ($preset['id'] ?? '')
            && self::MIN_PRESET_VERSION <= (int) ($preset['version'] ?? 0)
            && 'theme' === (string) ($preset['frontend_authority'] ?? '');
    }

    public function theme_version(): string {
        $theme = wp_get_theme();
        if (self::EXPECTED_STYLESHEET !== $theme->get_stylesheet()) {
            $theme = wp_get_theme(self::EXPECTED_STYLESHEET);
        }
        return $theme->exists() ? (string) $theme->get('Version') : '';
    }

    public function pages(): array {
        $pages = $this->preset()['pages'] ?? array();
        return is_array($pages) ? $pages : array();
    }

    public function page_id(string $key): int {
        $contract = $this->pages()[$key] ?? null;
        if (! is_array($contract)) { return 0; }
        $path = (string) ($contract['wp_slug'] ?? $contract['slug'] ?? $key);
        $page = get_page_by_path($path, OBJECT, 'page');
        return $page instanceof WP_Post ? (int) $page->ID : 0;
    }

    public function page_state(string $key): array {
        $contract = $this->pages()[$key] ?? null;
        if (! is_array($contract)) {
            return array('exists'=>false,'key'=>$key,'id'=>0,'contract'=>array());
        }
        $id = $this->page_id($key);
        if ($id <= 0) {
            return array('exists'=>false,'key'=>$key,'id'=>0,'contract'=>$contract);
        }
        $post = get_post($id);
        return array(
            'exists'=>$post instanceof WP_Post,
            'key'=>$key,
            'id'=>$id,
            'status'=>$post instanceof WP_Post ? (string) $post->post_status : '',
            'role'=>(string) get_post_meta($id, '_eduardo_research_role', true),
            'model'=>(string) get_post_meta($id, '_eduardo_research_model', true),
            'contract'=>$contract,
        );
    }

    public function research_post_types(): array {
        return Eduardo_Research_Manager_Resource_Registry::post_types();
    }

    public function languages(): array {
        $preset = $this->preset();
        $languages = $preset['languages'] ?? array();
        return is_array($languages) ? array_values(array_filter(array_map('sanitize_key', $languages))) : array();
    }

    public function default_language(): string {
        $preset = $this->preset();
        $languages = $this->languages();
        $declared = sanitize_key((string) ($preset['default_language'] ?? ''));
        if ('' !== $declared && in_array($declared, $languages, true)) { return $declared; }
        return isset($languages[0]) ? (string) $languages[0] : '';
    }

    public function insight_types(string $language = ''): array|WP_Error {
        if (! $this->theme_available() || ! function_exists('eduardo_research_insight_types')) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme editorial contract is required for Insight creation.');
        }
        $language = sanitize_key($language);
        if ('' === $language) { $language = $this->default_language(); }
        $types = eduardo_research_insight_types($language);
        return is_array($types) ? $types : array();
    }

    public function line_catalogs(string $language = ''): array|WP_Error {
        if (! $this->theme_available()
            || ! function_exists('eduardo_research_line_statuses')
            || ! function_exists('eduardo_research_line_evidence_statuses')) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme line contract is required.');
        }
        $language = sanitize_key($language);
        if ('' === $language) { $language = $this->default_language(); }
        $statuses = eduardo_research_line_statuses($language);
        $evidence_statuses = eduardo_research_line_evidence_statuses($language);
        return array(
            'statuses'=>is_array($statuses) ? $statuses : array(),
            'evidence_statuses'=>is_array($evidence_statuses) ? $evidence_statuses : array(),
        );
    }

    public function line_is_verified_public(int $line_id, string $language): bool {
        return function_exists('eduardo_research_line_is_verified_public')
            && eduardo_research_line_is_verified_public($line_id, $language);
    }

    public function collection_catalogs(string $surface, string $language = ''): array|WP_Error {
        if (! $this->theme_available() || ! function_exists('eduardo_research_collection_options')) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme collection contract is required.');
        }
        $surface = sanitize_key($surface);
        if (! in_array($surface, array('publications','projects','software','datasets'), true)) {
            return new WP_Error('research_manager_collection_surface_not_allowed', 'The requested Research Collection is outside the active Theme contract.');
        }
        $language = sanitize_key($language);
        if ('' === $language) { $language = $this->default_language(); }
        $options = eduardo_research_collection_options($surface, $language);
        return is_array($options) ? $options : array();
    }

    public function language_storage(string $language): array|WP_Error {
        $language = sanitize_key($language);
        if (! in_array($language, $this->languages(), true)) {
            return new WP_Error('research_manager_unknown_language', 'The requested language is outside the active Greenfield Theme contract.');
        }
        if (! isset(self::LEGACY_LANGUAGE_STORAGE[$language])) {
            return new WP_Error(
                'research_manager_language_storage_unmapped',
                sprintf('Language "%s" has no compatible production storage mapping.', $language)
            );
        }
        return self::LEGACY_LANGUAGE_STORAGE[$language];
    }

    public function language_storage_contract(): array {
        $contract = array();
        foreach ($this->languages() as $language) {
            if (isset(self::LEGACY_LANGUAGE_STORAGE[$language])) {
                $contract[$language] = self::LEGACY_LANGUAGE_STORAGE[$language];
            }
        }
        return $contract;
    }

    public function page_option_key(string $key, string $language): string|WP_Error {
        $storage = $this->language_storage($language);
        if (is_wp_error($storage)) { return $storage; }
        $suffix = (string) ($storage['suffix'] ?? '');
        $key = sanitize_key($key);
        if ('' === $key) {
            return new WP_Error('research_manager_page_key_required', 'A Theme Page key is required for storage resolution.');
        }
        return 'home' === $key
            ? 'eduardo_research_model' . $suffix
            : 'eduardo_research_surface_' . $key . $suffix;
    }

    public function identity_headline_field(string $language): string|WP_Error {
        $storage = $this->language_storage($language);
        if (is_wp_error($storage)) { return $storage; }
        $field = sanitize_key((string) ($storage['identity_headline_field'] ?? ''));
        return '' !== $field
            ? $field
            : new WP_Error('research_manager_identity_language_field_unmapped', 'The requested language has no compatible Identity headline field.');
    }

    public function evidence_store(): array {
        $value = get_option('eduardo_research_evidence', array());
        return is_array($value) ? $value : array();
    }

    public function snapshot(): array {
        $preset = $this->preset();
        $page_states = array();
        foreach (array_keys($this->pages()) as $key) {
            $page_states[$key] = $this->page_state((string) $key);
        }
        $types = array();
        foreach ($this->research_post_types() as $post_type) {
            $types[$post_type] = post_type_exists($post_type);
        }
        return array(
            'theme_available'=>$this->theme_available(),
            'compatible'=>$this->compatible(),
            'theme_version'=>$this->theme_version(),
            'preset_id'=>(string) ($preset['id'] ?? ''),
            'preset_version'=>(int) ($preset['version'] ?? 0),
            'frontend_authority'=>(string) ($preset['frontend_authority'] ?? ''),
            'pages'=>$page_states,
            'post_types'=>$types,
            'languages'=>$this->languages(),
            'default_language'=>$this->default_language(),
            'language_storage'=>$this->language_storage_contract(),
            'evidence_groups'=>array_keys($this->evidence_store()),
        );
    }
}
