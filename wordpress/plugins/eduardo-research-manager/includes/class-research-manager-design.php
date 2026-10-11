<?php
/** Exact bounded lifecycle for Theme-native design presets. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Design {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_design_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_design_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;
    private const PLAN_LIMIT = 40;
    private const OPERATION_LIMIT = 80;

    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(?Eduardo_Research_Manager_Remote_Audit $audit = null) {
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
    }

    public function inspect(): array|WP_Error {
        if (! function_exists('eduardo_research_design_public_contract')) {
            return $this->error('capability_unavailable', 'The active Research Theme does not expose the bounded design contract.', 409);
        }
        return eduardo_research_design_public_contract();
    }

    public function create_plan(array $changes, string $intent, array $actor): array|WP_Error {
        $contract = $this->contract();
        if (is_wp_error($contract)) { return $contract; }
        if (! $changes) { return $this->error('validation_failed', 'Design Preview requires at least one bounded preset change.', 400); }
        $allowed = array('palette','typography','density');
        $clean = array();
        foreach ($changes as $dimension => $preset) {
            $dimension = sanitize_key((string) $dimension);
            if (! in_array($dimension, $allowed, true)) {
                return $this->error('validation_failed', 'Design changes may only target palette, typography or density.', 400);
            }
            $preset = sanitize_key((string) $preset);
            if (! isset($contract[$dimension][$preset])) {
                return $this->error('validation_failed', sprintf('Unknown bounded %s preset.', $dimension), 400);
            }
            if ('palette' === $dimension) {
                $accessibility = eduardo_research_design_palette_accessibility($preset);
                if (empty($accessibility['accessible'])) {
                    return $this->error('validation_failed', 'The requested palette does not satisfy the Theme accessibility contrast contract.', 409);
                }
            }
            $clean[$dimension] = $preset;
        }

        $before = eduardo_research_design_state();
        $after = eduardo_research_design_sanitize_state(array_merge($before, $clean));
        if ($before === $after) { return $this->error('research_manager_remediation_not_needed', 'Requested Theme design presets are already active.', 409); }

        $now = time();
        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $source_revision = $this->revision_fingerprint();
        $intent = sanitize_text_field($intent ?: 'Update bounded Research Theme design presets');
        $material = array('before'=>$before,'after'=>$after,'source_revision'=>$source_revision,'connection_id'=>$connection_id,'intent'=>$intent);
        $checksum = hash('sha256', (string) wp_json_encode($material));
        $plan_id = 'erm-design-plan-' . substr($checksum, 0, 18) . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
        $plan = array(
            'plan_id'=>$plan_id,
            'operation'=>'design-preset-update',
            'intent'=>$intent,
            'risk'=>'standard',
            'confirmation_required'=>true,
            'confirmation_class'=>'design-explicit',
            'target'=>array('resource'=>'theme:design'),
            'requested'=>$clean,
            'before'=>$before,
            'after'=>$after,
            'preview'=>array(
                'changed_dimensions'=>array_keys($clean),
                'before'=>$before,
                'after'=>$after,
                'tokens_after'=>eduardo_research_design_tokens($after),
                'palette_accessibility'=>eduardo_research_design_palette_accessibility((string) $after['palette']),
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
        $this->audit('remote-design-plan-created', 'success', $actor, $plan_id, '', $intent);
        return $this->public_plan($plan);
    }

    public function get_plan(string $plan_id): array|WP_Error {
        $plan = $this->find(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Design plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put(self::PLANS_OPTION, $plan_id, $plan, self::PLAN_LIMIT);
        }
        return $this->public_plan($plan);
    }

    public function apply(string $plan_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) { return $this->error('capability_unavailable', 'The local WordPress execution identity cannot apply Theme design operations.', 403); }
        $plan = $this->find(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Design plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This design plan belongs to a different remote connection.', 403); }
        if ('planned' !== (string) ($plan['status'] ?? '')) {
            if ('applied' === (string) ($plan['status'] ?? '') && '' !== (string) ($plan['operation_id'] ?? '')) { return $this->get_operation((string) $plan['operation_id']); }
            return $this->error('validation_failed', 'Only a planned Theme design operation can be applied.', 409);
        }
        if (time() > (int) ($plan['expires_unix'] ?? 0)) { return $this->error('plan_expired', 'Design plan has expired. Create a fresh Preview.', 409); }
        if (! $confirmed) { return $this->error('confirmation_required', 'Theme design Apply requires explicit confirmation.', 409, array('confirmation_class'=>'design-explicit')); }
        if (! hash_equals((string) ($plan['source_revision'] ?? ''), $this->revision_fingerprint())) {
            return $this->error('stale_revision', 'Theme design state changed after Preview. Create a fresh design plan.', 409);
        }

        $contract = $this->contract();
        if (is_wp_error($contract)) { return $contract; }
        $after = eduardo_research_design_sanitize_state((array) ($plan['after'] ?? array()));
        $accessibility = eduardo_research_design_palette_accessibility((string) $after['palette']);
        if (empty($accessibility['accessible'])) { return $this->error('validation_failed', 'Stored design plan no longer satisfies the Theme accessibility contract.', 409); }

        update_option('eduardo_research_design', $after, false);
        $stored = eduardo_research_design_state();
        if ($stored !== $after) {
            update_option('eduardo_research_design', (array) ($plan['before'] ?? array()), false);
            return $this->error('research_manager_write_failed', 'WordPress did not persist the exact bounded Theme design state.', 409);
        }

        $operation_id = 'erm-design-op-' . str_replace('-', '', wp_generate_uuid4());
        $operation = array(
            'operation_id'=>$operation_id,'plan_id'=>$plan_id,'operation'=>'design-preset-update',
            'target'=>array('resource'=>'theme:design'),'before'=>(array) $plan['before'],'after'=>$after,
            'source_revision'=>(string) $plan['source_revision'],'post_apply_revision'=>$this->revision_fingerprint(),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'status'=>'applied','result'=>array('verified'=>true,'state'=>$stored,'tokens'=>eduardo_research_design_tokens($stored)),
            'created_at'=>gmdate(DATE_W3C),'applied_at'=>gmdate(DATE_W3C),'verified_at'=>'','rolled_back_at'=>'',
        );
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation, self::OPERATION_LIMIT);
        $plan['status'] = 'applied'; $plan['operation_id'] = $operation_id; $plan['applied_at'] = gmdate(DATE_W3C);
        $this->put(self::PLANS_OPTION, $plan_id, $plan, self::PLAN_LIMIT);
        $this->audit('remote-design-operation-applied', 'success', $actor, $plan_id, $operation_id, (string) $plan['intent']);
        return $this->public_operation($operation);
    }

    public function get_operation(string $operation_id): array|WP_Error {
        $operation = $this->find(self::OPERATIONS_OPTION, $operation_id);
        return $operation ? $this->public_operation($operation) : $this->error('operation_not_found', 'Design operation was not found.', 404);
    }

    public function verify(string $operation_id, array $actor): array|WP_Error {
        $operation = $this->find(self::OPERATIONS_OPTION, $operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Design operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This design operation belongs to a different remote connection.', 403); }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) { return $this->error('validation_failed', 'Only applied Theme design operations can be verified.', 409); }
        $state = eduardo_research_design_state();
        $state_matches = $state === (array) ($operation['after'] ?? array());
        $revision_matches = hash_equals((string) ($operation['post_apply_revision'] ?? ''), $this->revision_fingerprint());
        $accessibility = eduardo_research_design_palette_accessibility((string) $state['palette']);
        $css = eduardo_research_design_css($state);
        $safe_css = false === stripos($css, 'url(') && false === stripos($css, '@import') && false === stripos($css, '<script');
        $verified = $state_matches && $revision_matches && ! empty($accessibility['accessible']) && $safe_css;
        $operation['verification'] = array(
            'verified'=>$verified,'state_matches'=>$state_matches,'revision_matches'=>$revision_matches,
            'palette_accessibility'=>$accessibility,'safe_css'=>$safe_css,'css_sha256'=>hash('sha256', $css),'verified_at'=>gmdate(DATE_W3C),
        );
        $operation['status'] = $verified ? 'verified' : 'verification-failed';
        $operation['verified_at'] = gmdate(DATE_W3C);
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation, self::OPERATION_LIMIT);
        $this->audit('remote-design-operation-verified', $verified ? 'success' : 'failed', $actor, (string) $operation['plan_id'], $operation_id, 'Verify bounded Theme design');
        return $this->public_operation($operation);
    }

    public function rollback(string $operation_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) { return $this->error('capability_unavailable', 'The local WordPress execution identity cannot rollback Theme design operations.', 403); }
        $operation = $this->find(self::OPERATIONS_OPTION, $operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Design operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This design operation belongs to a different remote connection.', 403); }
        if (! $confirmed) { return $this->error('confirmation_required', 'Theme design rollback requires explicit confirmation.', 409); }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            if ('rolled-back' === (string) ($operation['status'] ?? '')) { return $this->public_operation($operation); }
            return $this->error('rollback_unavailable', 'This Theme design operation cannot be rolled back.', 409);
        }
        if (! hash_equals((string) ($operation['post_apply_revision'] ?? ''), $this->revision_fingerprint())) {
            return $this->error('stale_revision', 'Theme design changed after Apply. Rollback was not performed.', 409);
        }
        $before = eduardo_research_design_sanitize_state((array) ($operation['before'] ?? array()));
        update_option('eduardo_research_design', $before, false);
        if (eduardo_research_design_state() !== $before) { return $this->error('research_manager_write_failed', 'Theme design rollback did not restore the baseline.', 409); }
        $operation['status'] = 'rolled-back';
        $operation['rolled_back_at'] = gmdate(DATE_W3C);
        $operation['rollback_result'] = array('verified'=>true,'state'=>$before,'revision'=>$this->revision_fingerprint());
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation, self::OPERATION_LIMIT);
        $this->audit('remote-design-operation-rolled-back', 'success', $actor, (string) $operation['plan_id'], $operation_id, 'Rollback bounded Theme design');
        return $this->public_operation($operation);
    }

    public function revision_fingerprint(): string {
        $contract = function_exists('eduardo_research_design_contract') ? eduardo_research_design_contract() : array('version'=>0);
        $state = function_exists('eduardo_research_design_state') ? eduardo_research_design_state() : array();
        return hash('sha256', (string) wp_json_encode(array('contract_version'=>(int) ($contract['version'] ?? 0),'state'=>$state)));
    }

    private function contract(): array|WP_Error {
        if (! function_exists('eduardo_research_design_contract') || ! function_exists('eduardo_research_design_state')) {
            return $this->error('capability_unavailable', 'Compatible Research Theme design contract is required.', 409);
        }
        return eduardo_research_design_contract();
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

    private function public_plan(array $plan): array { return $plan; }
    private function public_operation(array $operation): array { return $operation; }

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
