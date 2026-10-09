<?php
/** Structured Page resource service for Theme-owned Research surfaces. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Page_Resource {
    private Eduardo_Research_Manager_Contract $contract;

    public function __construct(?Eduardo_Research_Manager_Contract $contract = null) {
        $this->contract = $contract ?: new Eduardo_Research_Manager_Contract();
    }

    public function inspect(string $key, string $language = 'en'): array|WP_Error {
        $valid = $this->validate_target($key, $language);
        if (is_wp_error($valid)) { return $valid; }

        $state = $this->contract->page_state($key);
        if (empty($state['exists'])) {
            return new WP_Error(
                'research_manager_page_missing',
                sprintf('The Theme-controlled page "%s" does not exist. Create the resource before hydration.', $key),
                array('next_action'=>'create-resource','resource'=>'page:' . $key)
            );
        }

        $defaults = $this->slot_schema($key, $language);
        $option_key = $this->option_key($key, $language);
        $stored = get_option($option_key, array());
        $stored = is_array($stored) ? array_intersect_key($stored, $defaults) : array();
        $effective = array_replace($defaults, $stored);
        $contract = is_array($state['contract'] ?? null) ? $state['contract'] : array();

        return array(
            'key'=>$key,
            'language'=>$language,
            'page_id'=>(int) $state['id'],
            'url'=>$this->resource_url($key, $language),
            'role'=>(string) ($state['role'] ?? ''),
            'expected_role'=>(string) ($contract['role'] ?? ''),
            'model'=>(string) ($state['model'] ?? ''),
            'expected_model'=>(string) ($contract['model'] ?? ''),
            'contract_aligned'=>(string) ($state['role'] ?? '') === (string) ($contract['role'] ?? '')
                && (string) ($state['model'] ?? '') === (string) ($contract['model'] ?? ''),
            'option_key'=>$option_key,
            'allowed_slots'=>array_keys($defaults),
            'stored_slots'=>$stored,
            'effective_slots'=>$effective,
        );
    }

    public function build_creation_plan(string $key, string $intent = ''): array|WP_Error {
        $valid = $this->validate_target($key, 'en');
        if (is_wp_error($valid)) { return $valid; }

        $state = $this->contract->page_state($key);
        if (! empty($state['exists'])) {
            return new WP_Error(
                'research_manager_page_exists',
                sprintf('The Theme-controlled page "%s" already exists. Use hydration or repair instead of creation.', $key)
            );
        }

        $definition = $this->contract->pages()[$key] ?? array();
        if (! is_array($definition)) {
            return new WP_Error('research_manager_page_contract_missing', 'The active Research preset does not expose this Page contract.');
        }
        $labels = is_array($definition['labels'] ?? null) ? $definition['labels'] : array();
        $title = sanitize_text_field((string) ($labels['en'] ?? $definition['label'] ?? ucfirst($key)));
        $slug = sanitize_title((string) ($definition['wp_slug'] ?? $definition['slug'] ?? $key));
        $role = sanitize_key((string) ($definition['role'] ?? ''));
        $model = sanitize_key((string) ($definition['model'] ?? ''));
        if ('' === $slug || '' === $title || '' === $role || '' === $model) {
            return new WP_Error('research_manager_invalid_page_contract', 'The Theme Page contract is incomplete and cannot be created safely.');
        }

        $creation_token = wp_generate_uuid4();
        $action = array(
            'type'=>'create_page',
            'page_key'=>$key,
            'wp_slug'=>$slug,
            'title'=>$title,
            'role'=>$role,
            'model'=>$model,
            'creation_token'=>$creation_token,
            'front_page'=>'front-page' === $role,
        );
        $intent = '' !== trim($intent)
            ? $intent
            : sprintf('Create missing Theme-owned %s Page from the active Research contract', $key);

        return Eduardo_Research_Manager_Plan::create($intent, array($action));
    }

    public function verify_creation(string $key, string $creation_token): array|WP_Error {
        $valid = $this->validate_target($key, 'en');
        if (is_wp_error($valid)) { return $valid; }

        $state = $this->contract->page_state($key);
        if (empty($state['exists'])) {
            return new WP_Error('research_manager_page_missing', 'The planned Theme-controlled Page does not exist after Apply.');
        }
        $definition = is_array($state['contract'] ?? null) ? $state['contract'] : array();
        $page_id = (int) ($state['id'] ?? 0);
        $page = $page_id > 0 ? get_post($page_id) : null;
        if (! $page instanceof WP_Post) {
            return new WP_Error('research_manager_page_missing', 'The created WordPress Page cannot be loaded.');
        }

        $stored_token = (string) get_post_meta($page_id, '_eduardo_research_manager_creation_token', true);
        $expected_role = (string) ($definition['role'] ?? '');
        $expected_model = (string) ($definition['model'] ?? '');
        $expected_slug = (string) ($definition['wp_slug'] ?? $definition['slug'] ?? $key);
        $front_expected = 'front-page' === $expected_role;
        $front_actual = 'page' === (string) get_option('show_on_front', 'posts')
            && $page_id === (int) get_option('page_on_front', 0);
        $url = $this->resource_url($key, 'en');
        $checks = array(
            'published'=>'publish' === (string) $page->post_status,
            'slug'=>$expected_slug === (string) $page->post_name,
            'role'=>$expected_role === (string) ($state['role'] ?? ''),
            'model'=>$expected_model === (string) ($state['model'] ?? ''),
            'provenance'=>'' !== $creation_token && hash_equals($creation_token, $stored_token),
            'route'=>'' !== $url,
            'front_page'=>$front_expected ? $front_actual : true,
        );

        return array(
            'verified'=>! in_array(false, $checks, true),
            'key'=>$key,
            'page_id'=>$page_id,
            'url'=>$url,
            'checks'=>$checks,
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    public function build_hydration_plan(
        string $key,
        string $language,
        array $slots,
        string $intent = '',
        array $context = array()
    ): array|WP_Error {
        $resource = $this->inspect($key, $language);
        if (is_wp_error($resource)) { return $resource; }
        if (! $slots) {
            return new WP_Error('research_manager_empty_hydration', 'At least one Theme-owned slot must be supplied.');
        }

        $defaults = $this->slot_schema($key, $language);
        $clean = $this->normalize_slots($defaults, $slots);
        if (is_wp_error($clean)) { return $clean; }

        $actions = array();
        $page_id = (int) $resource['page_id'];
        if ((string) $resource['role'] !== (string) $resource['expected_role']) {
            $actions[] = array(
                'type'=>'post_meta',
                'post_id'=>$page_id,
                'key'=>'_eduardo_research_role',
                'value'=>(string) $resource['expected_role'],
            );
        }
        if ((string) $resource['model'] !== (string) $resource['expected_model']) {
            $actions[] = array(
                'type'=>'post_meta',
                'post_id'=>$page_id,
                'key'=>'_eduardo_research_model',
                'value'=>(string) $resource['expected_model'],
            );
        }

        $stored = is_array($resource['stored_slots']) ? $resource['stored_slots'] : array();
        $next = array_replace($stored, $clean);
        if ($next !== $stored) {
            $actions[] = array(
                'type'=>'option',
                'key'=>(string) $resource['option_key'],
                'value'=>$next,
            );
        }

        if (! $actions) {
            return new WP_Error('research_manager_no_change', 'The requested hydration already matches stored Theme state.');
        }

        $intent = '' !== trim($intent)
            ? $intent
            : sprintf('Hydrate Theme-owned %s page slots (%s)', $key, strtoupper($language));

        return Eduardo_Research_Manager_Plan::create($intent, $actions, $context);
    }

    public function verify_hydration(string $key, string $language, array $expected_slots): array|WP_Error {
        $resource = $this->inspect($key, $language);
        if (is_wp_error($resource)) { return $resource; }
        $defaults = $this->slot_schema($key, $language);
        $clean = $this->normalize_slots($defaults, $expected_slots);
        if (is_wp_error($clean)) { return $clean; }

        $results = array();
        $verified = ! empty($resource['contract_aligned']);
        foreach ($clean as $slot => $expected) {
            $actual = $resource['effective_slots'][$slot] ?? null;
            $matches = maybe_serialize($actual) === maybe_serialize($expected);
            $verified = $verified && $matches;
            $results[$slot] = array('matches'=>$matches,'expected'=>$expected,'actual'=>$actual);
        }

        return array(
            'verified'=>$verified,
            'key'=>$key,
            'language'=>$language,
            'page_id'=>$resource['page_id'],
            'url'=>$resource['url'],
            'contract_aligned'=>$resource['contract_aligned'],
            'slots'=>$results,
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    public function slot_schema(string $key, string $language): array {
        if ('home' === $key && function_exists('eduardo_research_default_model')) {
            $schema = eduardo_research_default_model($language);
            return is_array($schema) ? $schema : array();
        }
        if (function_exists('eduardo_research_surface_models')) {
            $models = eduardo_research_surface_models($language);
            $schema = is_array($models) && is_array($models[$key] ?? null) ? $models[$key] : array();
            return $schema;
        }
        return array();
    }

    public function option_key(string $key, string $language): string {
        if ('home' === $key) {
            return 'es' === $language ? 'eduardo_research_model_es' : 'eduardo_research_model';
        }
        return 'eduardo_research_surface_' . sanitize_key($key) . ('es' === $language ? '_es' : '');
    }

    private function validate_target(string $key, string $language): bool|WP_Error {
        if (! $this->contract->compatible()) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme contract is required.');
        }
        if (! isset($this->contract->pages()[$key])) {
            return new WP_Error('research_manager_unknown_page', 'The requested Page key is outside the active Research preset.');
        }
        if (! in_array($language, $this->contract->languages(), true)) {
            return new WP_Error('research_manager_unknown_language', 'The requested language is outside the active Research preset.');
        }
        if (! $this->slot_schema($key, $language)) {
            return new WP_Error('research_manager_page_model_unavailable', 'The Theme does not expose a structured slot schema for this page.');
        }
        return true;
    }

    private function normalize_slots(array $schema, array $slots): array|WP_Error {
        $unknown = array_diff(array_keys($slots), array_keys($schema));
        if ($unknown) {
            return new WP_Error(
                'research_manager_unknown_slot',
                'Hydration contains slots outside the Theme model: ' . implode(', ', array_map('sanitize_key', $unknown))
            );
        }

        $clean = array();
        foreach ($slots as $slot => $value) {
            $default = $schema[$slot];
            if (is_array($default)) {
                if (! is_array($value)) {
                    return new WP_Error('research_manager_invalid_slot_value', sprintf('Slot "%s" must be an array.', $slot));
                }
                $clean[$slot] = array_values(array_filter(array_map(
                    static fn($item): string => is_scalar($item) ? sanitize_text_field((string) $item) : '',
                    $value
                ), static fn(string $item): bool => '' !== $item));
                continue;
            }
            if (! is_scalar($value)) {
                return new WP_Error('research_manager_invalid_slot_value', sprintf('Slot "%s" must be scalar text.', $slot));
            }
            $clean[$slot] = sanitize_textarea_field((string) $value);
        }
        return $clean;
    }

    private function resource_url(string $key, string $language): string {
        if (function_exists('eduardo_research_page_url')) {
            return (string) eduardo_research_page_url($key, $language);
        }
        $id = $this->contract->page_id($key);
        return $id > 0 ? (string) get_permalink($id) : '';
    }
}
