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

    private function validate_target(string $key, string $language): true|WP_Error {
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
