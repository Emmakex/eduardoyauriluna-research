<?php
/** Mutation-plan contract for Preview -> Apply -> Verify -> Rollback. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Plan {
    private const ALLOWED_OPTIONS = array(
        'eduardo_research_evidence',
        'eduardo_research_model',
        'eduardo_research_identity',
        'eduardo_research_native_languages',
    );

    private const ALLOWED_POST_FIELDS = array('post_title','post_excerpt','post_content','menu_order');

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
        $key = strtolower((string) ($action['key'] ?? $action['field'] ?? ''));
        foreach (array('doi','review_status','output_type','identifier','evidence','affiliation','award','grant') as $needle) {
            if (str_contains($key, $needle)) { return 'evidence-required'; }
        }
        if ('post_content' === $key) { return 'editorial-review'; }
        return 'standard';
    }

    private static function normalize_action(array $action): array|WP_Error {
        $type = sanitize_key((string) ($action['type'] ?? ''));
        if ('option' === $type) {
            $key = sanitize_key((string) ($action['key'] ?? ''));
            if (! in_array($key, self::ALLOWED_OPTIONS, true)) {
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
            return array('type'=>'post_field','post_id'=>$post_id,'field'=>$field,'value'=>self::sanitize_post_field_value($field, $action['value'] ?? ''));
        }

        return new WP_Error('research_manager_action_not_allowed', 'Unsupported mutation action type.');
    }

    private static function allowed_meta_key(string $key): bool {
        return str_starts_with($key, '_eduardo_research_') || str_starts_with($key, '_research_');
    }

    private static function sanitize_post_field_value(string $field, mixed $value): mixed {
        if ('menu_order' === $field) { return (int) $value; }
        if ('post_content' === $field) { return wp_kses_post((string) $value); }
        return sanitize_text_field((string) $value);
    }

    private static function target_signature(array $action): string {
        if ('option' === $action['type']) { return 'option:' . $action['key']; }
        if ('post_meta' === $action['type']) { return 'post_meta:' . $action['post_id'] . ':' . $action['key']; }
        return 'post_field:' . $action['post_id'] . ':' . $action['field'];
    }

    private static function checksum(array $payload): string {
        return hash('sha256', (string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
