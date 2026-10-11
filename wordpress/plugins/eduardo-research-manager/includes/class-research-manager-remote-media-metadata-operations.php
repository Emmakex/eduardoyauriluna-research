<?php
/** Exact remote lifecycle for bounded Media metadata mutation. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Media_Metadata_Operations {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_media_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_media_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;

    private Eduardo_Research_Manager_Media_Editor $editor;
    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(
        ?Eduardo_Research_Manager_Media_Editor $editor = null,
        ?Eduardo_Research_Manager_Remote_Audit $audit = null
    ) {
        $this->editor = $editor ?: new Eduardo_Research_Manager_Media_Editor();
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
    }

    public function create_plan(string $operation, array $payload, array $actor): array|WP_Error {
        $operation = sanitize_key($operation);
        if ('media-metadata-update' !== $operation) {
            return $this->error('validation_failed', 'Unsupported remote Media operation.', 400);
        }
        $attachment_id = absint($payload['attachment_id'] ?? 0);
        $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : array();
        if ($attachment_id <= 0 || ! $changes) {
            return $this->error('validation_failed', 'media-metadata-update requires attachment_id and bounded changes.', 400);
        }

        $prepared = $this->editor->preview_update($attachment_id, $changes);
        if (is_wp_error($prepared)) { return $this->normalise($prepared, 400); }
        $source_revision = (string) ($prepared['baseline_checksum'] ?? '');
        if ('' === $source_revision) {
            return $this->error('validation_failed', 'Media Preview did not produce an attachment revision.', 409);
        }

        $target = array('resource'=>'media', 'attachment_id'=>$attachment_id);
        $requested = array('changes'=>(array) ($prepared['expected'] ?? array()));
        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $now = time();
        $plan_id = 'erm-media-plan-' . substr(hash('sha256', (string) wp_json_encode(array(
            $operation, $target, $requested, $source_revision, $connection_id,
        ))), 0, 20) . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);

        $plan = array(
            'plan_id'=>$plan_id,
            'operation'=>$operation,
            'target'=>$target,
            'requested'=>$requested,
            'prepared'=>$prepared,
            'preview'=>(array) ($prepared['preview'] ?? array()),
            'apply_allowed'=>! empty($prepared['apply_allowed']),
            'risk'=>'editorial-review',
            'confirmation_required'=>true,
            'confirmation_class'=>'explicit',
            'source_revision'=>$source_revision,
            'connection_id'=>$connection_id,
            'created_by'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'created_request_id'=>(string) ($actor['request_id'] ?? ''),
            'created_at'=>gmdate(DATE_W3C, $now),
            'expires_at'=>gmdate(DATE_W3C, $now + self::PLAN_TTL),
            'expires_unix'=>$now + self::PLAN_TTL,
            'status'=>'planned',
        );
        $this->put(self::PLANS_OPTION, $plan_id, $plan, 60);
        $this->audit('remote-media-plan-created', 'success', $plan, $actor, 'media.write');
        return $this->public_plan($plan);
    }

    public function get_plan(string $id): array|WP_Error {
        $plan = $this->get(self::PLANS_OPTION, $id);
        if (! $plan) { return $this->error('operation_not_found', 'Media metadata plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put(self::PLANS_OPTION, $id, $plan, 60);
        }
        return $this->public_plan($plan);
    }

    public function apply(string $id, bool $confirm, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return $this->error('capability_unavailable', 'Local execution identity cannot apply Media metadata operations.', 403);
        }
        $plan = $this->get(self::PLANS_OPTION, $id);
        if (! $plan) { return $this->error('operation_not_found', 'Media metadata plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This Media metadata plan belongs to another connection.', 403);
        }
        if ('planned' !== (string) ($plan['status'] ?? '')) {
            if ('applied' === (string) ($plan['status'] ?? '') && ! empty($plan['operation_id'])) {
                return $this->get_operation((string) $plan['operation_id']);
            }
            return $this->error('validation_failed', 'Only a planned Media metadata operation can be applied.', 409);
        }
        if (time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put(self::PLANS_OPTION, $id, $plan, 60);
            return $this->error('plan_expired', 'Media metadata plan expired.', 409);
        }
        if (empty($plan['apply_allowed'])) {
            return $this->error('validation_failed', 'Stored Media metadata Preview does not permit Apply.', 409);
        }
        if (! $confirm) {
            return $this->error('confirmation_required', 'Media metadata Apply requires explicit confirmation.', 409);
        }

        $result = $this->editor->apply_preview((array) ($plan['prepared'] ?? array()));
        if (is_wp_error($result)) { return $this->normalise($result, 409); }
        $operation_id = 'erm-media-op-' . str_replace('-', '', wp_generate_uuid4());
        $operation = array(
            'operation_id'=>$operation_id,
            'plan_id'=>$id,
            'operation'=>(string) $plan['operation'],
            'target'=>(array) $plan['target'],
            'requested'=>(array) $plan['requested'],
            'source_revision'=>(string) $plan['source_revision'],
            'post_apply_revision'=>(string) ($result['post_apply_revision'] ?? ''),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'status'=>'applied',
            'result'=>$result,
            'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),
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
        $this->audit('remote-media-operation-applied', 'success', $operation, $actor, 'media.write');
        return $operation;
    }

    public function get_operation(string $id): array|WP_Error {
        $operation = $this->get(self::OPERATIONS_OPTION, $id);
        return $operation ?: $this->error('operation_not_found', 'Media metadata operation was not found.', 404);
    }

    public function verify(string $id, bool $rendered, array $actor): array|WP_Error {
        $operation = $this->get(self::OPERATIONS_OPTION, $id);
        if (! $operation) { return $this->error('operation_not_found', 'Media metadata operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This Media metadata operation belongs to another connection.', 403);
        }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            return $this->error('validation_failed', 'Only applied Media metadata operations can be verified.', 409);
        }

        $attachment_id = absint($operation['target']['attachment_id'] ?? 0);
        $expected = (array) ($operation['requested']['changes'] ?? array());
        if ($attachment_id <= 0 || ! $expected) {
            return $this->error('validation_failed', 'Media metadata operation is missing verification target.', 409);
        }
        $semantic = $this->editor->verify($attachment_id, $expected);
        if (is_wp_error($semantic)) { return $this->normalise($semantic, 409); }
        $revision_matches = '' !== (string) ($operation['post_apply_revision'] ?? '')
            && hash_equals((string) $operation['post_apply_revision'], (string) ($semantic['revision'] ?? ''));
        $stored_verified = $revision_matches && ! empty($operation['result']['verified']) && ! empty($semantic['verified']);
        $stored = array(
            'verified'=>$stored_verified,
            'revision_matches'=>$revision_matches,
            'semantic'=>$semantic,
            'verified_at'=>gmdate(DATE_W3C),
        );
        $rendered_state = array('requested'=>$rendered, 'verified'=>null, 'resources'=>array());
        if ($rendered) {
            $rendered_state = array(
                'requested'=>true,
                'verified'=>true,
                'resources'=>array(array(
                    'resource'=>'media:' . $attachment_id,
                    'attachment_id'=>$attachment_id,
                    'verified'=>true,
                    'skipped'=>true,
                    'reason'=>'media-metadata-has-no-theme-owned-rendered-target-until-assignment',
                )),
                'verified_at'=>gmdate(DATE_W3C),
            );
        }
        $verified = $stored_verified && (! $rendered || ! empty($rendered_state['verified']));
        $operation['stored_verification'] = $stored;
        $operation['rendered_verification'] = $rendered_state;
        $operation['status'] = $verified ? 'verified' : 'verification-failed';
        $operation['verified_at'] = gmdate(DATE_W3C);
        $this->put(self::OPERATIONS_OPTION, $id, $operation, 120);
        $this->audit('remote-media-operation-verified', $verified ? 'success' : 'failed', $operation, $actor, 'site.diagnostics');
        return $operation;
    }

    public function rollback(string $id, bool $confirm, array $actor): array|WP_Error {
        if (! $confirm) {
            return $this->error('confirmation_required', 'Media metadata rollback requires explicit confirmation.', 409);
        }
        $operation = $this->get(self::OPERATIONS_OPTION, $id);
        if (! $operation) { return $this->error('operation_not_found', 'Media metadata operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This Media metadata operation belongs to another connection.', 403);
        }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            if ('rolled-back' === (string) ($operation['status'] ?? '')) { return $operation; }
            return $this->error('rollback_unavailable', 'Media metadata operation is not rollback-capable.', 409);
        }
        $snapshot_id = (string) ($operation['snapshot_id'] ?? '');
        if ('' === $snapshot_id) {
            return $this->error('rollback_unavailable', 'No Media metadata rollback snapshot is available.', 409);
        }
        $result = $this->editor->rollback($snapshot_id);
        if (is_wp_error($result)) { return $this->normalise($result, 409); }
        $operation['status'] = 'rolled-back';
        $operation['rollback_result'] = $result;
        $operation['rolled_back_at'] = gmdate(DATE_W3C);
        $operation['rollback_restored_source_revision'] = ! empty($result['verified'])
            && hash_equals((string) ($operation['source_revision'] ?? ''), (string) ($result['restored_revision'] ?? ''));
        $this->put(self::OPERATIONS_OPTION, $id, $operation, 120);
        $this->audit('remote-media-operation-rolled-back', 'success', $operation, $actor, 'media.write');
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

    private function public_plan(array $plan): array { unset($plan['prepared'], $plan['expires_unix']); return $plan; }
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
