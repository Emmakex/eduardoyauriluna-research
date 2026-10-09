<?php
/** Mutation-plan contract for Preview -> Apply -> Verify -> Rollback. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Plan {
    private const ALLOWED_OPTIONS = array(
        'eduardo_research_evidence',
        'eduardo_research_model',
        'eduardo_research_model_es',
        'eduardo_research_identity',
        'eduardo_research_native_languages',
    );

    private const ALLOWED_SURFACE_KEYS = array(
        'about','research','publications','projects','software','datasets','cv','insights','contact','privacy-policy','legal-notice',
    );

    private const ALLOWED_POST_FIELDS = array('post_title','post_excerpt','post_content','menu_order');
    private const MAX_TITLE_BYTES = 200;
    private const MAX_EXCERPT_BYTES = 1000;
    private const MAX_CONTENT_BYTES = 60000;

    public static function create(string $intent, array $actions, array $context = array()): array|WP_Error {
        $intent = sanitize_text_field($intent);
        if ('' === $intent) {
            return new WP_Error('research_manager_missing_intent', 'A human-readable mutation intent is required.');
        }
        if (! $actions) {
            return new WP_Error('research_manager_empty_plan', 'At least one mutation action is required.');
        }

        $clean = array();
        $targets = array();
        $risk = 'standard';
        foreach ($actions as $index => $action) {
            if (! is_array($action)) {
                return new WP_Error('research_manager_invalid_action', sprintf('Action %d must be an array.', $index));
            }
            $normalized = self::normalize_action($action);
            if (is_wp_error($normalized)) { return $normalized; }
            $signature = self::target_signature($normalized);
            if (isset($targets[$signature])) {
                return new WP_Error('research_manager_duplicate_target', 'A mutation plan may change each target only once.');
            }
            $targets[$signature] = true;
            $action_risk = self::action_risk($normalized);
            if ('evidence-required' === $action_risk) { $risk = 'evidence-required'; }
            elseif ('standard' === $risk && 'editorial-review' === $action_risk) { $risk = 'editorial-review'; }
            $clean[] = $normalized;
        }

        $evidence_confirmed = ! empty($context['evidence_confirmed']);
        $evidence_reference = sanitize_text_field((string) ($context['evidence_reference'] ?? ''));
        $payload = array(
            'version'=>1,
            'intent'=>$intent,
            'actions'=>$clean,
            'risk'=>$risk,
            'evidence_confirmed'=>$evidence_confirmed,
            'evidence_reference'=>$evidence_reference,
        );
        $checksum = self::checksum($payload);
        return array(
            'id'=>'erm-plan-' . substr($checksum, 0, 16),
            'version'=>1,
            'intent'=>$intent,
            'actions'=>$clean,
            'risk'=>$risk,
            'evidence_confirmed'=>$evidence_confirmed,
            'evidence_reference'=>$evidence_reference,
            'checksum'=>$checksum,
            'created_at'=>gmdate(DATE_W3C),
        );
    }

    public static function validate(array $plan): bool|WP_Error {
        $required = array('id','version','intent','actions','risk','evidence_confirmed','evidence_reference','checksum');
        foreach ($required as $key) {
            if (! array_key_exists($key, $plan)) {
                return new WP_Error('research_manager_invalid_plan', 'Mutation plan is missing a required field: ' . $key);
            }
        }
        $rebuilt = self::create(
            (string) $plan['intent'],
            is_array($plan['actions']) ? $plan['actions'] : array(),
            array(
                'evidence_confirmed'=>! empty($plan['evidence_confirmed']),
                'evidence_reference'=>(string) $plan['evidence_reference'],
            )
        );
        if (is_wp_error($rebuilt)) { return $rebuilt; }
        if (! hash_equals((string) $rebuilt['checksum'], (string) $plan['checksum'])) {
            return new WP_Error('research_manager_plan_changed', 'Mutation plan checksum does not match its contents. Re-preview the change.');
        }
        return true;
    }

    public static function apply_gate(array $plan): bool|WP_Error {
        $valid = self::validate($plan);
        if (is_wp_error($valid)) { return $valid; }
        if ('evidence-required' === (string) $plan['risk']) {
            if (empty($plan['evidence_confirmed'])) {
                return new WP_Error(
                    'research_manager_evidence_required',
                    'This mutation changes an evidence-sensitive academic claim. Confirm evidence and rebuild the plan before Apply.'
                );
            }
            if ('' === trim((string) $plan['evidence_reference'])) {
                return new WP_Error(
                    'research_manager_evidence_reference_required',
                    'Evidence-sensitive mutations require a source or verification reference before Apply.'
                );
            }
        }
        return true;
    }

    public static function action_risk(array $action): string {
        $type = (string) ($action['type'] ?? '');
        if ('create_page' === $type) { return 'standard'; }
        if ('create_insight' === $type) { return 'editorial-review'; }
        $key = strtolower((string) ($action['key'] ?? $action['field'] ?? ''));
        foreach (array('doi','review_status','output_type','identifier','evidence','affiliation','award','grant') as $needle) {
            if (str_contains($key, $needle)) { return 'evidence-required'; }
        }
        if ('post_content' === $key || str_starts_with($key, 'eduardo_research_model') || str_starts_with($key, 'eduardo_research_surface_')) {
            return 'editorial-review';
        }
        return 'standard';
    }

    private static function normalize_action(array $action): array|WP_Error {
        $type = sanitize_key((string) ($action['type'] ?? ''));
        if ('option' === $type) {
            $key = sanitize_key((string) ($action['key'] ?? ''));
            if (! self::allowed_option_key($key)) {
                return new WP_Error('research_manager_option_not_allowed', 'This WordPress option is outside the Research Manager mutation contract.');
            }
            return array('type'=>'option','key'=>$key,'value'=>$action['value'] ?? null);
        }

        if ('post_meta' === $type) {
            $post_id = absint($action['post_id'] ?? 0);
            $key = sanitize_key((string) ($action['key'] ?? ''));
            if ($post_id <= 0 || ! get_post($post_id)) {
                return new WP_Error('research_manager_invalid_post', 'Post metadata mutation requires an existing WordPress resource.');
            }
            if (! self::allowed_meta_key($key)) {
                return new WP_Error('research_manager_meta_not_allowed', 'This metadata key is outside the Research Theme contract.');
            }
            return array('type'=>'post_meta','post_id'=>$post_id,'key'=>$key,'value'=>$action['value'] ?? null);
        }

        if ('post_field' === $type) {
            $post_id = absint($action['post_id'] ?? 0);
            $field = sanitize_key((string) ($action['field'] ?? ''));
            if ($post_id <= 0 || ! get_post($post_id)) {
                return new WP_Error('research_manager_invalid_post', 'Post-field mutation requires an existing WordPress resource.');
            }
            if (! in_array($field, self::ALLOWED_POST_FIELDS, true)) {
                return new WP_Error('research_manager_post_field_not_allowed', 'This post field cannot be mutated by the Manager foundation.');
            }
            $value = self::sanitize_post_field_value($field, $action['value'] ?? '');
            $bounded = self::validate_post_field_length($field, $value);
            if (is_wp_error($bounded)) { return $bounded; }
            return array('type'=>'post_field','post_id'=>$post_id,'field'=>$field,'value'=>$value);
        }

        if ('create_page' === $type) {
            return self::normalize_page_creation($action);
        }
        if ('create_insight' === $type) {
            return self::normalize_insight_creation($action);
        }

        return new WP_Error('research_manager_action_not_allowed', 'Unsupported mutation action type.');
    }

    private static function normalize_page_creation(array $action): array|WP_Error {
        if (! function_exists('eduardo_research_preset')) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme contract is required for Page creation.');
        }
        $preset = eduardo_research_preset();
        $pages = is_array($preset['pages'] ?? null) ? $preset['pages'] : array();
        $page_key = sanitize_key((string) ($action['page_key'] ?? ''));
        $contract = is_array($pages[$page_key] ?? null) ? $pages[$page_key] : array();
        if (! $contract) {
            return new WP_Error('research_manager_page_key_not_allowed', 'This Page key is outside the active Research preset creation contract.');
        }

        $wp_slug = sanitize_title((string) ($action['wp_slug'] ?? ''));
        $title = sanitize_text_field((string) ($action['title'] ?? ''));
        $role = sanitize_key((string) ($action['role'] ?? ''));
        $model = sanitize_key((string) ($action['model'] ?? ''));
        $creation_token = sanitize_text_field((string) ($action['creation_token'] ?? ''));
        $expected_slug = sanitize_title((string) ($contract['wp_slug'] ?? $contract['slug'] ?? $page_key));
        $expected_role = sanitize_key((string) ($contract['role'] ?? ''));
        $expected_model = sanitize_key((string) ($contract['model'] ?? ''));
        $front_page = 'front-page' === $expected_role;

        if ('' === $wp_slug || '' === $title || '' === $role || '' === $model || '' === $creation_token) {
            return new WP_Error('research_manager_invalid_page_creation', 'Page creation requires key, slug, title, role, model and a provenance token.');
        }
        if ($wp_slug !== $expected_slug || $role !== $expected_role || $model !== $expected_model) {
            return new WP_Error('research_manager_page_contract_mismatch', 'Page creation must match the active Research Theme contract exactly.');
        }
        if ($front_page !== ! empty($action['front_page'])) {
            return new WP_Error('research_manager_front_page_contract_mismatch', 'Front-page creation state must match the active Research Theme role.');
        }
        if (! self::valid_creation_token($creation_token)) {
            return new WP_Error('research_manager_invalid_creation_token', 'Page creation requires a stable UUID-like provenance token.');
        }

        return array(
            'type'=>'create_page',
            'page_key'=>$page_key,
            'wp_slug'=>$wp_slug,
            'title'=>$title,
            'role'=>$role,
            'model'=>$model,
            'creation_token'=>$creation_token,
            'front_page'=>$front_page,
        );
    }

    private static function normalize_insight_creation(array $action): array|WP_Error {
        if (! function_exists('eduardo_research_preset') || ! function_exists('eduardo_research_insight_types')) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme editorial contract is required for Insight creation.');
        }
        $preset = eduardo_research_preset();
        $languages = is_array($preset['languages'] ?? null) ? array_map('sanitize_key', $preset['languages']) : array();
        $types = eduardo_research_insight_types('en');
        $types = is_array($types) ? $types : array();

        $title = sanitize_text_field((string) ($action['title'] ?? ''));
        $slug = sanitize_title((string) ($action['slug'] ?? ''));
        $excerpt = sanitize_textarea_field((string) ($action['excerpt'] ?? ''));
        $content = wp_kses_post((string) ($action['content'] ?? ''));
        $language = sanitize_key((string) ($action['language'] ?? 'en'));
        $insight_type = sanitize_key((string) ($action['insight_type'] ?? 'research_note'));
        $status = sanitize_key((string) ($action['status'] ?? 'draft'));
        $creation_token = sanitize_text_field((string) ($action['creation_token'] ?? ''));

        if ('' === $title || '' === $slug || '' === $creation_token) {
            return new WP_Error('research_manager_invalid_insight_creation', 'Insight creation requires a title, slug and provenance token.');
        }
        if (strlen($title) > self::MAX_TITLE_BYTES || strlen($excerpt) > self::MAX_EXCERPT_BYTES || strlen($content) > self::MAX_CONTENT_BYTES) {
            return new WP_Error('research_manager_insight_content_too_large', 'Insight title, excerpt or body exceeds the bounded editorial contract.');
        }
        if (! in_array($language, $languages, true)) {
            return new WP_Error('research_manager_unknown_language', 'Insight language is outside the active Research preset.');
        }
        if (! array_key_exists($insight_type, $types)) {
            return new WP_Error('research_manager_unknown_insight_type', 'Insight type is outside the active Research Theme editorial contract.');
        }
        if (! in_array($status, array('draft','publish'), true)) {
            return new WP_Error('research_manager_invalid_insight_status', 'Insight creation only permits draft or publish status.');
        }
        if (! self::valid_creation_token($creation_token)) {
            return new WP_Error('research_manager_invalid_creation_token', 'Insight creation requires a stable UUID-like provenance token.');
        }

        return array(
            'type'=>'create_insight',
            'title'=>$title,
            'slug'=>$slug,
            'excerpt'=>$excerpt,
            'content'=>$content,
            'language'=>$language,
            'insight_type'=>$insight_type,
            'status'=>$status,
            'creation_token'=>$creation_token,
        );
    }

    private static function allowed_option_key(string $key): bool {
        if (in_array($key, self::ALLOWED_OPTIONS, true)) { return true; }
        foreach (self::ALLOWED_SURFACE_KEYS as $surface) {
            if ('eduardo_research_surface_' . $surface === $key || 'eduardo_research_surface_' . $surface . '_es' === $key) {
                return true;
            }
        }
        return false;
    }

    private static function allowed_meta_key(string $key): bool {
        return str_starts_with($key, '_eduardo_research_') || str_starts_with($key, '_research_');
    }

    private static function sanitize_post_field_value(string $field, mixed $value): mixed {
        if ('menu_order' === $field) { return (int) $value; }
        if ('post_content' === $field) { return wp_kses_post((string) $value); }
        if ('post_excerpt' === $field) { return sanitize_textarea_field((string) $value); }
        return sanitize_text_field((string) $value);
    }

    private static function validate_post_field_length(string $field, mixed $value): bool|WP_Error {
        if (! is_string($value)) { return true; }
        $limit = 'post_title' === $field ? self::MAX_TITLE_BYTES : ('post_excerpt' === $field ? self::MAX_EXCERPT_BYTES : ('post_content' === $field ? self::MAX_CONTENT_BYTES : 0));
        if ($limit > 0 && strlen($value) > $limit) {
            return new WP_Error('research_manager_post_field_too_large', sprintf('The planned %s value exceeds the bounded Manager contract.', $field));
        }
        return true;
    }

    private static function valid_creation_token(string $token): bool {
        return 1 === preg_match('/^[a-f0-9-]{32,64}$/i', $token);
    }

    private static function target_signature(array $action): string {
        if ('option' === $action['type']) { return 'option:' . $action['key']; }
        if ('post_meta' === $action['type']) { return 'post_meta:' . $action['post_id'] . ':' . $action['key']; }
        if ('post_field' === $action['type']) { return 'post_field:' . $action['post_id'] . ':' . $action['field']; }
        if ('create_page' === $action['type']) { return 'create_page:' . $action['wp_slug']; }
        if ('create_insight' === $action['type']) { return 'create_insight:' . $action['slug']; }
        return 'unknown:' . md5((string) wp_json_encode($action));
    }

    private static function checksum(array $payload): string {
        return hash('sha256', (string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
