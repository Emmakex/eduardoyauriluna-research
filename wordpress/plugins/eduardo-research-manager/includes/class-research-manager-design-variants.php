<?php
/** Exact bounded lifecycle for Theme-owned component presentation variants. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Design_Variants {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_design_variant_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_design_variant_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;
    private const PLAN_LIMIT = 40;
    private const OPERATION_LIMIT = 80;

    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(?Eduardo_Research_Manager_Remote_Audit $audit = null) {
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
    }

    public function inspect(): array|WP_Error {
        if (! function_exists('eduardo_research_design_variant_public_contract')) {
            return $this->error('capability_unavailable', 'The active Research Theme does not expose bounded component variants.', 409);
        }
        return eduardo_research_design_variant_public_contract();
    }

    public function create_plan(array $changes, string $intent, array $actor): array|WP_Error {
        $contract = $this->contract();
        if (is_wp_error($contract)) { return $contract; }
        if (! $changes) { return $this->error('validation_failed', 'Component variant Preview requires at least one bounded change.', 400); }

        $allowed = array('hero','sections','cards','collections');
        $clean = array();
        foreach ($changes as $dimension => $variant) {
            $dimension = sanitize_key((string) $dimension);
            if (! in_array($dimension, $allowed, true)) {
                return $this->error('validation_failed', 'Variant changes may only target hero, sections, cards or collections.', 400);
            }
            $variant = sanitize_key((string) $variant);
            if (! isset($contract[$dimension][$variant])) {
                return $this->error('validation_failed', sprintf('Unknown bounded %s variant.', $dimension), 400);
            }
            $clean[$dimension] = $variant;
        }

        $before = eduardo_research_design_variant_state();
        $after = eduardo_research_design_variant_sanitize_state(array_merge($before, $clean));
        if ($before === $after) { return $this->error('research_manager_remediation_not_needed', 'Requested component variants are already active.', 409); }

        $now = time();
        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $source_revision = $this->revision_fingerprint();
        $intent = sanitize_text_field($intent ?: 'Update bounded Research Theme component variants');
        $material = array('before'=>$before,'after'=>$after,'source_revision'=>$source_revision,'connection_id'=>$connection_id,'intent'=>$intent);
        $checksum = hash('sha256', (string) wp_json_encode($material));
        $plan_id = 'erm-design-variant-plan-' . substr($checksum, 0, 18) . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
        $plan = array(
            'plan_id'=>$plan_id,
            'operation'=>'design-variant-update',
            'intent'=>$intent,
            'risk'=>'standard',
            'confirmation_required'=>true,
            'confirmation_class'=>'design-explicit',
            'target'=>array('resource'=>'theme:design:variants'),
            'requested'=>$clean,
            'before'=>$before,
            'after'=>$after,
            'preview'=>array(
                'changed_dimensions'=>array_keys($clean),
                'before'=>$before,
                'after'=>$after,
                'body_classes_after'=>$this->body_classes($after),
                'semantic_markup_unchanged'=>true,
                'content_order_unchanged'=>true,
                'arbitrary_html'=>false,
                'arbitrary_class'=>false,
                'arbitrary_css'=>false,
            ),
            'apply_allowed'=>true,
            'source_revision'=>$source_revision,
            'connection_id'=>$connection_id,
            'created_by'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'created_request_id'=>(string) ($actor['request_id'] ?? ''),
            'created_at'=>gmdate(DATE_W3C, $now),
            'expires_at'=>gmdate(DATE_W3C, $now + self::PLAN_TTL),
            'expires_unix'=>$now + self::PLAN_TTL,
            'status'=>'planned',
            'checksum'=>$checksum,
        );
        $this->put(self::PLANS_OPTION, $plan_id, $plan, self::PLAN_LIMIT);
        $this->audit('remote-design-variant-plan-created', 'success', $actor, $plan_id, '', $intent);
        return $plan;
    }

    public function get_plan(string $plan_id): array|WP_Error {
        $plan = $this->find(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Component variant plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put(self::PLANS_OPTION, $plan_id, $plan, self::PLAN_LIMIT);
        }
        return $plan;
    }

    public function apply(string $plan_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) { return $this->error('capability_unavailable', 'The local WordPress execution identity cannot apply component variants.', 403); }
        $plan = $this->find(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Component variant plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This component variant plan belongs to a different remote connection.', 403); }
        if ('planned' !== (string) ($plan['status'] ?? '')) {
            if ('applied' === (string) ($plan['status'] ?? '') && '' !== (string) ($plan['operation_id'] ?? '')) { return $this->get_operation((string) $plan['operation_id']); }
            return $this->error('validation_failed', 'Only a planned component variant operation can be applied.', 409);
        }
        if (time() > (int) ($plan['expires_unix'] ?? 0)) { return $this->error('plan_expired', 'Component variant plan has expired. Create a fresh Preview.', 409); }
        if (! $confirmed) { return $this->error('confirmation_required', 'Component variant Apply requires explicit confirmation.', 409, array('confirmation_class'=>'design-explicit')); }
        if (! hash_equals((string) ($plan['source_revision'] ?? ''), $this->revision_fingerprint())) {
            return $this->error('stale_revision', 'Theme component variant state changed after Preview. Create a fresh plan.', 409);
        }

        $after = eduardo_research_design_variant_sanitize_state((array) ($plan['after'] ?? array()));
        update_option('eduardo_research_design_variants', $after, false);
        $stored = eduardo_research_design_variant_state();
        if ($stored !== $after) {
            update_option('eduardo_research_design_variants', (array) ($plan['before'] ?? array()), false);
            return $this->error('research_manager_write_failed', 'WordPress did not persist the exact component variant state.', 409);
        }

        $operation_id = 'erm-design-variant-op-' . str_replace('-', '', wp_generate_uuid4());
        $operation = array(
            'operation_id'=>$operation_id,'plan_id'=>$plan_id,'operation'=>'design-variant-update',
            'target'=>array('resource'=>'theme:design:variants'),'before'=>(array) $plan['before'],'after'=>$after,
            'source_revision'=>(string) $plan['source_revision'],'post_apply_revision'=>$this->revision_fingerprint(),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'status'=>'applied','result'=>array('verified'=>true,'state'=>$stored,'body_classes'=>$this->body_classes($stored)),
            'created_at'=>gmdate(DATE_W3C),'applied_at'=>gmdate(DATE_W3C),'verified_at'=>'','rolled_back_at'=>'',
        );
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation, self::OPERATION_LIMIT);
        $plan['status'] = 'applied'; $plan['operation_id'] = $operation_id; $plan['applied_at'] = gmdate(DATE_W3C);
        $this->put(self::PLANS_OPTION, $plan_id, $plan, self::PLAN_LIMIT);
        $this->audit('remote-design-variant-operation-applied', 'success', $actor, $plan_id, $operation_id, (string) $plan['intent']);
        return $operation;
    }

    public function get_operation(string $operation_id): array|WP_Error {
        $operation = $this->find(self::OPERATIONS_OPTION, $operation_id);
        return $operation ?: $this->error('operation_not_found', 'Component variant operation was not found.', 404);
    }

    public function verify(string $operation_id, array $actor): array|WP_Error {
        $operation = $this->find(self::OPERATIONS_OPTION, $operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Component variant operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This component variant operation belongs to a different remote connection.', 403); }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) { return $this->error('validation_failed', 'Only applied component variant operations can be verified.', 409); }

        $state = eduardo_research_design_variant_state();
        $state_matches = $state === (array) ($operation['after'] ?? array());
        $revision_matches = hash_equals((string) ($operation['post_apply_revision'] ?? ''), $this->revision_fingerprint());
        $classes = $this->body_classes($state);
        $classes_safe = $this->classes_safe($classes);
        $css = eduardo_research_design_variant_css();
        $safe_css = false === stripos($css, 'url(') && false === stripos($css, '@import') && false === stripos($css, '<script');
        $verified = $state_matches && $revision_matches && $classes_safe && $safe_css;
        $operation['verification'] = array(
            'verified'=>$verified,'state_matches'=>$state_matches,'revision_matches'=>$revision_matches,
            'body_classes'=>$classes,'classes_safe'=>$classes_safe,'safe_css'=>$safe_css,'css_sha256'=>hash('sha256', $css),
            'semantic_markup_unchanged'=>true,'content_order_unchanged'=>true,'verified_at'=>gmdate(DATE_W3C),
        );
        $operation['status'] = $verified ? 'verified' : 'verification-failed';
        $operation['verified_at'] = gmdate(DATE_W3C);
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation, self::OPERATION_LIMIT);
        $this->audit('remote-design-variant-operation-verified', $verified ? 'success' : 'failed', $actor, (string) $operation['plan_id'], $operation_id, 'Verify bounded Theme component variants');
        return $operation;
    }

    public function rollback(string $operation_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) { return $this->error('capability_unavailable', 'The local WordPress execution identity cannot rollback component variants.', 403); }
        $operation = $this->find(self::OPERATIONS_OPTION, $operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Component variant operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This component variant operation belongs to a different remote connection.', 403); }
        if (! $confirmed) { return $this->error('confirmation_required', 'Component variant rollback requires explicit confirmation.', 409); }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            if ('rolled-back' === (string) ($operation['status'] ?? '')) { return $operation; }
            return $this->error('rollback_unavailable', 'This component variant operation cannot be rolled back.', 409);
        }
        if (! hash_equals((string) ($operation['post_apply_revision'] ?? ''), $this->revision_fingerprint())) {
            return $this->error('stale_revision', 'Theme component variant state changed after Apply. Rollback was not performed.', 409);
        }
        $before = eduardo_research_design_variant_sanitize_state((array) ($operation['before'] ?? array()));
        update_option('eduardo_research_design_variants', $before, false);
        if (eduardo_research_design_variant_state() !== $before) { return $this->error('research_manager_write_failed', 'Component variant rollback did not restore the baseline.', 409); }
        $operation['status'] = 'rolled-back';
        $operation['rolled_back_at'] = gmdate(DATE_W3C);
        $operation['rollback_result'] = array('verified'=>true,'state'=>$before,'revision'=>$this->revision_fingerprint());
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation, self::OPERATION_LIMIT);
        $this->audit('remote-design-variant-operation-rolled-back', 'success', $actor, (string) $operation['plan_id'], $operation_id, 'Rollback bounded Theme component variants');
        return $operation;
    }

    public function revision_fingerprint(): string {
        $contract = function_exists('eduardo_research_design_variant_contract') ? eduardo_research_design_variant_contract() : array('version'=>0);
        $state = function_exists('eduardo_research_design_variant_state') ? eduardo_research_design_variant_state() : array();
        return hash('sha256', (string) wp_json_encode(array('contract_version'=>(int) ($contract['version'] ?? 0),'state'=>$state)));
    }

    private function body_classes(array $state): array {
        $state = eduardo_research_design_variant_sanitize_state($state);
        $classes = array();
        foreach ($state as $dimension => $variant) { $classes[] = 'research-variant-' . sanitize_html_class((string) $dimension) . '-' . sanitize_html_class((string) $variant); }
        return $classes;
    }

    private function classes_safe(array $classes): bool {
        foreach ($classes as $class) {
            if (1 !== preg_match('/^research-variant-(hero|sections|cards|collections)-[a-z0-9-]+$/', (string) $class)) { return false; }
        }
        return 4 === count($classes);
    }

    private function contract(): array|WP_Error {
        if (! function_exists('eduardo_research_design_variant_contract') || ! function_exists('eduardo_research_design_variant_state')) {
            return $this->error('capability_unavailable', 'Compatible Research Theme component variant contract is required.', 409);
        }
        return eduardo_research_design_variant_contract();
    }

    private function find(string $option, string $id): array {
        $rows = get_option($option, array());
        return is_array($rows) && is_array($rows[$id] ?? null) ? $rows[$id] : array();
    }

    private function put(string $option, string $id, array $value, int $limit): void {
        $rows = get_option($option, array());
        $rows = is_array($rows) ? $rows : array();
        $rows[$id] = $value;
        if (count($rows) > $limit) { $rows = array_slice($rows, -$limit, null, true); }
        update_option($option, $rows, false);
    }

    private function audit(string $event, string $outcome, array $actor, string $plan_id, string $operation_id, string $reason): void {
        $this->audit->record(array(
            'request_id'=>(string) ($actor['request_id'] ?? ''),'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),'scope'=>'design.write','event'=>$event,'outcome'=>$outcome,
            'plan_id'=>$plan_id,'operation_id'=>$operation_id,'reason'=>$reason,
        ));
    }

    private function error(string $code, string $message, int $status, array $data = array()): WP_Error {
        return new WP_Error($code, $message, array_merge(array('status'=>$status), $data));
    }
}
