<?php
/** Exact remote lifecycle for bounded Theme-owned Media assignment. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Media_Assignment_Operations {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_media_assignment_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_media_assignment_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;

    private Eduardo_Research_Manager_Media_Assignment_Editor $editor;
    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(
        ?Eduardo_Research_Manager_Media_Assignment_Editor $editor = null,
        ?Eduardo_Research_Manager_Remote_Audit $audit = null
    ) {
        $this->editor = $editor ?: new Eduardo_Research_Manager_Media_Assignment_Editor();
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
    }

    public function create_plan(array $payload, array $actor): array|WP_Error {
        $target = is_array($payload['target'] ?? null) ? $payload['target'] : array();
        $attachment_id = absint($payload['attachment_id'] ?? 0);
        $slot = sanitize_key((string) ($payload['slot'] ?? Eduardo_Research_Manager_Media_Assignment_Editor::SLOT));
        if (! $target || $attachment_id <= 0) {
            return $this->error('validation_failed', 'media-assignment requires target and attachment_id.', 400);
        }
        $prepared = $this->editor->preview_assign($target, $attachment_id, $slot);
        if (is_wp_error($prepared)) { return $this->normalise($prepared, 400); }

        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $now = time();
        $plan_id = 'erm-media-assignment-plan-' . substr(hash('sha256', (string) wp_json_encode(array(
            $prepared['target'], $attachment_id, $slot, $prepared['target_revision'], $prepared['attachment_revision'], $connection_id,
        ))), 0, 20) . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);

        $plan = array(
            'plan_id'=>$plan_id,
            'operation'=>'media-assignment',
            'target'=>$prepared['target'],
            'attachment_id'=>$attachment_id,
            'slot'=>$slot,
            'prepared'=>$prepared,
            'preview'=>$prepared['preview'],
            'status'=>'planned',
            'apply_allowed'=>! empty($prepared['apply_allowed']),
            'risk'=>'editorial-review',
            'confirmation_required'=>true,
            'semantic_scope'=>(string) $prepared['semantic_scope'],
            'read_scope'=>(string) $prepared['read_scope'],
            'source_target_revision'=>(string) $prepared['target_revision'],
            'source_attachment_revision'=>(string) $prepared['attachment_revision'],
            'connection_id'=>$connection_id,
            'created_by'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'created_request_id'=>(string) ($actor['request_id'] ?? ''),
            'created_at'=>gmdate(DATE_W3C, $now),
            'expires_at'=>gmdate(DATE_W3C, $now + self::PLAN_TTL),
            'expires_unix'=>$now + self::PLAN_TTL,
        );
        $this->put(self::PLANS_OPTION, $plan_id, $plan, 60);
        $this->audit('remote-media-assignment-plan-created', 'success', $plan, $actor, 'media.write');
        return $this->public_plan($plan);
    }

    public function get_plan(string $id): array|WP_Error {
        $plan = $this->get(self::PLANS_OPTION, $id);
        if (! $plan) { return $this->error('operation_not_found', 'Media assignment plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put(self::PLANS_OPTION, $id, $plan, 60);
        }
        return $this->public_plan($plan);
    }

    public function apply(string $id, bool $confirm, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return $this->error('capability_unavailable', 'Local execution identity cannot apply Media assignment.', 403);
        }
        $plan = $this->get(self::PLANS_OPTION, $id);
        if (! $plan) { return $this->error('operation_not_found', 'Media assignment plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This Media assignment plan belongs to another connection.', 403);
        }
        if ('planned' !== (string) ($plan['status'] ?? '')) {
            if ('applied' === (string) ($plan['status'] ?? '') && ! empty($plan['operation_id'])) {
                return $this->get_operation((string) $plan['operation_id']);
            }
            return $this->error('validation_failed', 'Only a planned Media assignment can be applied.', 409);
        }
        if (time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put(self::PLANS_OPTION, $id, $plan, 60);
            return $this->error('plan_expired', 'Media assignment plan expired.', 409);
        }
        if (empty($plan['apply_allowed'])) {
            return $this->error('validation_failed', 'Stored Media assignment Preview does not permit Apply.', 409);
        }
        if (! $confirm) {
            return $this->error('confirmation_required', 'Media assignment Apply requires explicit confirmation.', 409);
        }

        $result = $this->editor->apply_preview((array) ($plan['prepared'] ?? array()));
        if (is_wp_error($result)) { return $this->normalise($result, 409); }
        $operation_id = 'erm-media-assignment-op-' . str_replace('-', '', wp_generate_uuid4());
        $operation = array(
            'operation_id'=>$operation_id,
            'plan_id'=>$id,
            'operation'=>'media-assignment',
            'target'=>(array) $plan['target'],
            'attachment_id'=>(int) $plan['attachment_id'],
            'slot'=>(string) $plan['slot'],
            'semantic_scope'=>(string) $plan['semantic_scope'],
            'read_scope'=>(string) $plan['read_scope'],
            'source_target_revision'=>(string) $plan['source_target_revision'],
            'source_attachment_revision'=>(string) $plan['source_attachment_revision'],
            'post_apply_target_revision'=>(string) ($result['post_apply_target_revision'] ?? ''),
            'post_apply_attachment_revision'=>(string) ($result['post_apply_attachment_revision'] ?? ''),
            'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),
            'result'=>$result,
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'status'=>'applied',
            'stored_verification'=>array(),
            'rendered_verification'=>array(),
            'created_at'=>gmdate(DATE_W3C),
            'applied_at'=>gmdate(DATE_W3C),
            'verified_at'=>'',
            'rolled_back_at'=>'',
        );
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation, 120);
        $plan['status'] = 'applied';
        $plan['operation_id'] = $operation_id;
        $plan['applied_at'] = gmdate(DATE_W3C);
        $this->put(self::PLANS_OPTION, $id, $plan, 60);
        $this->audit('remote-media-assignment-applied', 'success', $operation, $actor, 'media.write');
        return $operation;
    }

    public function get_operation(string $id): array|WP_Error {
        $operation = $this->get(self::OPERATIONS_OPTION, $id);
        return $operation ?: $this->error('operation_not_found', 'Media assignment operation was not found.', 404);
    }

    public function verify(string $id, bool $rendered, array $actor): array|WP_Error {
        $operation = $this->get(self::OPERATIONS_OPTION, $id);
        if (! $operation) { return $this->error('operation_not_found', 'Media assignment operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This Media assignment operation belongs to another connection.', 403);
        }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            return $this->error('validation_failed', 'Only applied Media assignment operations can be verified.', 409);
        }
        $verification = $this->editor->verify(
            (array) $operation['target'],
            (int) $operation['attachment_id'],
            (string) $operation['slot'],
            $rendered
        );
        if (is_wp_error($verification)) { return $this->normalise($verification, 409); }
        $operation['stored_verification'] = array('verified'=>! empty($verification['stored_verified']));
        $operation['rendered_verification'] = (array) ($verification['rendered_verification'] ?? array());
        $operation['status'] = ! empty($verification['verified']) ? 'verified' : 'verification-failed';
        $operation['verified_at'] = gmdate(DATE_W3C);
        $this->put(self::OPERATIONS_OPTION, $id, $operation, 120);
        $this->audit('remote-media-assignment-verified', 'verified' === $operation['status'] ? 'success' : 'failed', $operation, $actor, 'site.diagnostics');
        return $operation;
    }

    public function rollback(string $id, bool $confirm, array $actor): array|WP_Error {
        if (! $confirm) {
            return $this->error('confirmation_required', 'Media assignment rollback requires explicit confirmation.', 409);
        }
        $operation = $this->get(self::OPERATIONS_OPTION, $id);
        if (! $operation) { return $this->error('operation_not_found', 'Media assignment operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This Media assignment operation belongs to another connection.', 403);
        }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            if ('rolled-back' === (string) ($operation['status'] ?? '')) { return $operation; }
            return $this->error('rollback_unavailable', 'Media assignment operation is not rollback-capable.', 409);
        }
        $snapshot_id = (string) ($operation['snapshot_id'] ?? '');
        if ('' === $snapshot_id) {
            return $this->error('rollback_unavailable', 'No Media assignment rollback snapshot is available.', 409);
        }

        $result = $this->editor->rollback_snapshot(
            $snapshot_id,
            (string) ($operation['post_apply_target_revision'] ?? ''),
            (string) ($operation['post_apply_attachment_revision'] ?? '')
        );
        if (is_wp_error($result)) { return $this->normalise($result, 409); }
        $operation['status'] = 'rolled-back';
        $operation['rollback_result'] = $result;
        $operation['rolled_back_at'] = gmdate(DATE_W3C);
        $operation['rollback_restored_relationship'] = ! empty($result['verified']);
        $this->put(self::OPERATIONS_OPTION, $id, $operation, 120);
        $this->audit('remote-media-assignment-rolled-back', 'success', $operation, $actor, 'media.write');
        return $operation;
    }

    private function audit(string $event, string $outcome, array $state, array $actor, string $scope): void {
        $this->audit->record(array(
            'request_id'=>(string) ($actor['request_id'] ?? ''),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'event'=>$event,
            'outcome'=>$outcome,
            'scope'=>$scope,
            'plan_id'=>(string) ($state['plan_id'] ?? ''),
            'operation_id'=>(string) ($state['operation_id'] ?? ''),
            'operation'=>(string) ($state['operation'] ?? ''),
        ));
    }

    private function public_plan(array $plan): array {
        unset($plan['prepared'], $plan['expires_unix']);
        return $plan;
    }

    private function get(string $option, string $id): array {
        $all = get_option($option, array());
        $all = is_array($all) ? $all : array();
        $value = $all[sanitize_text_field($id)] ?? array();
        return is_array($value) ? $value : array();
    }

    private function put(string $option, string $id, array $value, int $limit): void {
        $all = get_option($option, array());
        $all = is_array($all) ? $all : array();
        $all[$id] = $value;
        while (count($all) > $limit) { array_shift($all); }
        if (false === get_option($option, false)) { add_option($option, $all, '', false); }
        else { update_option($option, $all, false); }
    }

    private function normalise(WP_Error $error, int $status): WP_Error {
        $data = $error->get_error_data();
        if (! is_array($data) || ! isset($data['status'])) {
            $error->add_data(array_merge(is_array($data) ? $data : array(), array('status'=>$status)));
        }
        return $error;
    }

    private function error(string $code, string $message, int $status): WP_Error {
        return new WP_Error($code, $message, array('status'=>$status));
    }
}
