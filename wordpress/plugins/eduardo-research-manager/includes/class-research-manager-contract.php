<?php
/** Theme contract adapter for the Research Manager. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Contract {
    public const EXPECTED_PRESET = 'research';
    public const MIN_PRESET_VERSION = 4;

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
        if ('eduardo-research' !== $theme->get_stylesheet()) {
            $theme = wp_get_theme('eduardo-research');
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
        return array('research_line','research_output','research_project','research_software','research_dataset');
    }

    public function languages(): array {
        $preset = $this->preset();
        $languages = $preset['languages'] ?? array();
        return is_array($languages) ? array_values(array_map('sanitize_key', $languages)) : array();
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
            'evidence_groups'=>array_keys($this->evidence_store()),
        );
    }
}
