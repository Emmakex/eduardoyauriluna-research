<?php
/** Mutation-plan contract for Preview -> Apply -> Verify -> Rollback. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-site-architect-mutation-policy.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-research-mutation-policy.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-research-creation-normalizer.php';

final class Eduardo_Research_Manager_Plan {
    private const ALLOWED_POST_FIELDS = array('post_title','post_excerpt','post_content','menu_order','post_status');
    private const MAX_TITLE_BYTES = 200;
    private const MAX_EXCERPT_BYTES = 1000;
    private const MAX_CONTENT_BYTES = 60000;

    public static function create(string $intent, array $actions, array $context = array(), ?Site_Architect_Mutation_Policy $policy = null, ?Eduardo_Research_Manager_Contract $contract = null): array|WP_Error {
        $policy = $policy ?? new Eduardo_Research_Manager_Research_Mutation_Policy();
        $contract = $contract ?? new Eduardo_Research_Manager_Contract();
        $intent = sanitize_text_field($intent);
        if ('' === $intent) { return new WP_Error('research_manager_missing_intent', 'A human-readable mutation intent is required.'); }
        if (! $actions) { return new WP_Error('research_manager_empty_plan', 'At least one mutation action is required.'); }

        $clean = array();
        $targets = array();
        $risk = 'standard';
        foreach ($actions as $index => $action) {
            if (! is_array($action)) { return new WP_Error('research_manager_invalid_action', sprintf('Action %d must be an array.', $index)); }
            $normalized = self::normalize_action($action, $policy, $contract);
            if (is_wp_error($normalized)) { return $normalized; }
            $signature = self::target_signature($normalized);
            if (isset($targets[$signature])) { return new WP_Error('research_manager_duplicate_target', 'A mutation plan may change each target only once.'); }
            $targets[$signature] = true;
            $action_risk = self::action_risk($normalized, $policy, $contract);
            if ('evidence-required' === $action_risk) { $risk = 'evidence-required'; }
            elseif ('standard' === $risk && 'editorial-review' === $action_risk) { $risk = 'editorial-review'; }
            $clean[] = $normalized;
        }

        $evidence_confirmed = ! empty($context['evidence_confirmed']);
        $evidence_reference = sanitize_text_field((string) ($context['evidence_reference'] ?? ''));
        $payload = array(
            'version'=>1,'intent'=>$intent,'actions'=>$clean,'risk'=>$risk,
            'evidence_confirmed'=>$evidence_confirmed,'evidence_reference'=>$evidence_reference,
        );
        $checksum = self::checksum($payload);
        return array(
            'id'=>'erm-plan-' . substr($checksum, 0, 16),'version'=>1,'intent'=>$intent,'actions'=>$clean,'risk'=>$risk,
            'evidence_confirmed'=>$evidence_confirmed,'evidence_reference'=>$evidence_reference,
            'checksum'=>$checksum,'created_at'=>gmdate(DATE_W3C),
        );
    }

    public static function validate(array $plan, ?Site_Architect_Mutation_Policy $policy = null, ?Eduardo_Research_Manager_Contract $contract = null): bool|WP_Error {
        $required = array('id','version','intent','actions','risk','evidence_confirmed','evidence_reference','checksum');
        foreach ($required as $key) {
            if (! array_key_exists($key, $plan)) { return new WP_Error('research_manager_invalid_plan', 'Mutation plan is missing a required field: ' . $key); }
        }
        $rebuilt = self::create((string) $plan['intent'], is_array($plan['actions']) ? $plan['actions'] : array(), array(
            'evidence_confirmed'=>! empty($plan['evidence_confirmed']),
            'evidence_reference'=>(string) $plan['evidence_reference'],
        ), $policy, $contract);
        if (is_wp_error($rebuilt)) { return $rebuilt; }
        if (! hash_equals((string) $rebuilt['checksum'], (string) $plan['checksum'])
            || (string) $plan['risk'] !== (string) $rebuilt['risk']) {
            // Risk is a derived security property. A caller must not downgrade
            // evidence-required to standard while keeping a valid checksum.
            return new WP_Error('research_manager_plan_changed', 'Mutation plan checksum does not match its contents. Re-preview the change.');
        }
        return true;
    }

    public static function apply_gate(array $plan, ?Site_Architect_Mutation_Policy $policy = null, ?Eduardo_Research_Manager_Contract $contract = null): bool|WP_Error {
        $valid = self::validate($plan, $policy, $contract);
        if (is_wp_error($valid)) { return $valid; }
        $policy = $policy ?? new Eduardo_Research_Manager_Research_Mutation_Policy();
        if ('evidence-required' === (string) $plan['risk']) {
            if (empty($plan['evidence_confirmed'])) {
                return new WP_Error('research_manager_evidence_required', self::evidence_blocker_message($policy, 'confirmation'));
            }
            if ('' === trim((string) $plan['evidence_reference'])) {
                return new WP_Error('research_manager_evidence_reference_required', self::evidence_blocker_message($policy, 'reference'));
            }
        }
        return true;
    }

    /**
     * Generic risk gate; Research-only heuristics belong to the active policy.
     * The default Research policy preserves the installed 0.11.1 behavior.
     * Unregistered actions or invalid risk labels always require evidence.
     */
    public static function action_risk(array $action, ?Site_Architect_Mutation_Policy $policy = null, ?Eduardo_Research_Manager_Contract $contract = null): string {
        $policy = $policy ?? new Eduardo_Research_Manager_Research_Mutation_Policy();
        $type = sanitize_key((string) ($action['type'] ?? ''));
        if ('' === $type || ! in_array($type, $policy->allowed_action_types(), true)) {
            return 'evidence-required';
        }
        $contract = $contract ?? new Eduardo_Research_Manager_Contract();
        $rated = $policy->risk_for_action(
            $action,
            static fn(int $post_id): bool => self::is_theme_owned_page($post_id, $contract)
        );
        return in_array($rated, array('standard','editorial-review','evidence-required'), true)
            ? $rated
            : 'evidence-required';
    }

    /** The policy supplies copy, but the Core enforces the evidence requirement. */
    private static function evidence_blocker_message(Site_Architect_Mutation_Policy $policy, string $reason): string {
        $message = $policy->evidence_blocker_message($reason);
        if ('' !== trim($message)) { return $message; }
        return 'Evidence confirmation and a source reference are required before Apply.';
    }

    private static function normalize_action(array $action, Site_Architect_Mutation_Policy $policy, Eduardo_Research_Manager_Contract $contract): array|WP_Error {
        $type = sanitize_key((string) ($action['type'] ?? ''));
        // Explicit authorization before any normalization. No runtime request or Registry grants an operation.
        if (! in_array($type, $policy->allowed_action_types(), true)) {
            return new WP_Error('research_manager_action_not_allowed', 'Unsupported mutation action type.');
        }
        if ('option' === $type) {
            $key = sanitize_key((string) ($action['key'] ?? ''));
            if (! self::allowed_option_key($key, $policy, $contract)) {
                return new WP_Error('research_manager_option_not_allowed', 'This WordPress option is outside the Research Manager mutation contract.');
            }
            if ('show_on_front' === $key) {
                $value = sanitize_key((string) ($action['value'] ?? ''));
                if ('page' !== $value || self::theme_home_page_id() <= 0) {
                    return new WP_Error('research_manager_front_page_option_not_allowed', 'Research Manager may only set show_on_front=page when the contracted Research Home exists.');
                }
                return array('type'=>'option','key'=>$key,'value'=>'page');
            }
            if ('page_on_front' === $key) {
                $value = absint($action['value'] ?? 0);
                $home_id = self::theme_home_page_id();
                if ($home_id <= 0 || $value !== $home_id) {
                    return new WP_Error('research_manager_front_page_option_not_allowed', 'Research Manager may only assign the contracted Research Home as the static front page.');
                }
                return array('type'=>'option','key'=>$key,'value'=>$home_id);
            }
            return array('type'=>'option','key'=>$key,'value'=>$action['value'] ?? null);
        }
        if ('post_meta' === $type) {
            $post_id = absint($action['post_id'] ?? 0);
            $key = sanitize_key((string) ($action['key'] ?? ''));
            if ($post_id <= 0 || ! get_post($post_id)) { return new WP_Error('research_manager_invalid_post', 'Post metadata mutation requires an existing WordPress resource.'); }
            if (! self::allowed_meta_key($key, $policy)) { return new WP_Error('research_manager_meta_not_allowed', 'This metadata key is outside the Research Theme contract.'); }
            return array('type'=>'post_meta','post_id'=>$post_id,'key'=>$key,'value'=>$action['value'] ?? null);
        }
        if ('post_field' === $type) {
            $post_id = absint($action['post_id'] ?? 0);
            $field = sanitize_key((string) ($action['field'] ?? ''));
            if ($post_id <= 0 || ! get_post($post_id)) { return new WP_Error('research_manager_invalid_post', 'Post-field mutation requires an existing WordPress resource.'); }
            if (! in_array($field, self::ALLOWED_POST_FIELDS, true)) { return new WP_Error('research_manager_post_field_not_allowed', 'This post field cannot be mutated by the Manager foundation.'); }
            if ('post_status' === $field) {
                $value = sanitize_key((string) ($action['value'] ?? ''));
                if ('publish' !== $value || ! self::is_theme_owned_page($post_id)) {
                    return new WP_Error('research_manager_post_status_not_allowed', 'Research Manager may only publish an existing Theme-controlled Research Page during structural remediation.');
                }
                return array('type'=>'post_field','post_id'=>$post_id,'field'=>$field,'value'=>'publish');
            }
            $value = self::sanitize_post_field_value($field, $action['value'] ?? '');
            $bounded = self::validate_post_field_length($field, $value);
            if (is_wp_error($bounded)) { return $bounded; }
            return array('type'=>'post_field','post_id'=>$post_id,'field'=>$field,'value'=>$value);
        }
        if ('create_page' === $type) { return self::normalize_page_creation($action); }
        if ('create_insight' === $type) { return self::normalize_research_creation($type, $action, $policy, $contract); }
        if ('create_line' === $type) { return self::normalize_research_creation($type, $action, $policy, $contract); }
        if ('create_output' === $type) { return self::normalize_research_creation($type, $action, $policy, $contract); }
        if ('create_project' === $type) { return self::normalize_research_creation($type, $action, $policy, $contract); }
        if ('create_software' === $type) { return self::normalize_research_creation($type, $action, $policy, $contract); }
        if ('create_dataset' === $type) { return self::normalize_research_creation($type, $action, $policy, $contract); }
        return new WP_Error('research_manager_action_not_allowed', 'Unsupported mutation action type.');
    }

    private static function normalize_page_creation(array $action): array|WP_Error {
        $theme_contract = new Eduardo_Research_Manager_Contract();
        if (! $theme_contract->theme_available()) { return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme contract is required for Page creation.'); }
        $pages = $theme_contract->pages();
        $page_key = sanitize_key((string) ($action['page_key'] ?? ''));
        $contract = is_array($pages[$page_key] ?? null) ? $pages[$page_key] : array();
        if (! $contract) { return new WP_Error('research_manager_page_key_not_allowed', 'This Page key is outside the active Research preset creation contract.'); }
        $wp_slug = sanitize_title((string) ($action['wp_slug'] ?? ''));
        $title = sanitize_text_field((string) ($action['title'] ?? ''));
        $role = sanitize_key((string) ($action['role'] ?? ''));
        $model = sanitize_key((string) ($action['model'] ?? ''));
        $creation_token = sanitize_text_field((string) ($action['creation_token'] ?? ''));
        $expected_slug = sanitize_title((string) ($contract['wp_slug'] ?? $contract['slug'] ?? $page_key));
        $expected_role = sanitize_key((string) ($contract['role'] ?? ''));
        $expected_model = sanitize_key((string) ($contract['model'] ?? ''));
        $front_page = 'front-page' === $expected_role;
        if ('' === $wp_slug || '' === $title || '' === $role || '' === $model || ! self::valid_creation_token($creation_token)) {
            return new WP_Error('research_manager_invalid_page_creation', 'Page creation requires a valid key, slug, title, role, model and provenance token.');
        }
        if ($wp_slug !== $expected_slug || $role !== $expected_role || $model !== $expected_model) { return new WP_Error('research_manager_page_contract_mismatch', 'Page creation must match the active Research Theme contract exactly.'); }
        if ($front_page !== ! empty($action['front_page'])) { return new WP_Error('research_manager_front_page_contract_mismatch', 'Front-page creation state must match the active Research Theme role.'); }
        return array('type'=>'create_page','page_key'=>$page_key,'wp_slug'=>$wp_slug,'title'=>$title,'role'=>$role,'model'=>$model,'creation_token'=>$creation_token,'front_page'=>$front_page);
    }


    /**
     * Closed Research vertical seam. A foreign mutation policy may not borrow
     * Research creation schemas simply by declaring the same action names.
     * New verticals need independently reviewed Plan AND Executor adapters.
     */
    private static function normalize_research_creation(string $type, array $action, Site_Architect_Mutation_Policy $policy, Eduardo_Research_Manager_Contract $contract): array|WP_Error {
        // Both the executable policy and the active Theme preset must be Research.
        // A foreign Theme cannot borrow this vertical by injecting a Research policy.
        if (! ($policy instanceof Eduardo_Research_Manager_Research_Mutation_Policy)
            || 'research' !== (string) ($contract->preset()['id'] ?? '')) {
            return new WP_Error('research_manager_action_not_allowed', 'Research creation requires the explicit Research Theme and mutation policy.');
        }
        return Eduardo_Research_Manager_Research_Creation_Normalizer::normalize($type, $action);
    }

    private static function allowed_option_key(string $key, Site_Architect_Mutation_Policy $policy, Eduardo_Research_Manager_Contract $contract): bool {
        if (in_array($key, $policy->allowed_option_keys(), true)) { return true; }
        // Exact, finite page option set: declared pages × explicitly mapped languages.
        // Never use a prefix/wildcard check or infer unknown storage keys.
        foreach (array_keys($contract->pages()) as $page_key) {
            foreach ($contract->languages() as $language) {
                $option_key = $contract->page_option_key((string) $page_key, (string) $language);
                if (! is_wp_error($option_key) && $option_key === $key) { return true; }
            }
        }
        return false;
    }

    private static function allowed_meta_key(string $key, Site_Architect_Mutation_Policy $policy): bool {
        // Do not grant rights merely because a descriptive registry knows a key.
        return in_array($key, $policy->allowed_meta_keys(), true);
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
        if ($limit > 0 && strlen($value) > $limit) { return new WP_Error('research_manager_post_field_too_large', sprintf('The planned %s value exceeds the bounded Manager contract.', $field)); }
        return true;
    }

    private static function valid_creation_token(string $token): bool {
        return 1 === preg_match('/^[a-f0-9-]{32,64}$/i', $token);
    }

    private static function is_theme_owned_page(int $post_id, ?Eduardo_Research_Manager_Contract $contract = null): bool {
        if ($post_id <= 0) { return false; }
        $post = get_post($post_id);
        if (! $post instanceof WP_Post || 'page' !== $post->post_type) { return false; }
        $contract = $contract ?? new Eduardo_Research_Manager_Contract();
        foreach (array_keys($contract->pages()) as $key) {
            if ($contract->page_id((string) $key) === $post_id) { return true; }
        }
        return false;
    }

    private static function theme_home_page_id(): int {
        return (new Eduardo_Research_Manager_Contract())->page_id('home');
    }

    private static function target_signature(array $action): string {
        if ('option' === $action['type']) { return 'option:' . $action['key']; }
        if ('post_meta' === $action['type']) { return 'post_meta:' . $action['post_id'] . ':' . $action['key']; }
        if ('post_field' === $action['type']) { return 'post_field:' . $action['post_id'] . ':' . $action['field']; }
        if ('create_page' === $action['type']) { return 'create_page:' . $action['wp_slug']; }
        if ('create_insight' === $action['type']) { return 'create_insight:' . $action['slug']; }
        $resource = Eduardo_Research_Manager_Resource_Registry::by_creation_action((string) ($action['type'] ?? ''));
        if ($resource) { return (string) $resource['creation_action'] . ':' . $action['slug']; }
        return 'unknown:' . md5((string) wp_json_encode($action));
    }

    private static function checksum(array $payload): string {
        return hash('sha256', (string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
