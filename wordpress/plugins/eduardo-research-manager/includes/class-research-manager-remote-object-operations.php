<?php
/** Exact remote lifecycle for bounded Research Output / Project / Software / Dataset operations. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Object_Operations {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_object_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_object_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;
    private const PLAN_LIMIT = 60;
    private const OPERATION_LIMIT = 120;

    private Eduardo_Research_Manager_Object_Editor $editor;
    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(
        ?Eduardo_Research_Manager_Object_Editor $editor = null,
        ?Eduardo_Research_Manager_Remote_Audit $audit = null
    ) {
        $this->editor = $editor ?: Eduardo_Research_Manager::object_editor();
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
    }

    public function create_plan(string $operation, array $payload, array $actor): array|WP_Error {
        $operation = sanitize_key($operation);
        if (! in_array($operation, array('object-create','object-update'), true)) {
            return $this->error('validation_failed', 'Unsupported remote Research Object operation.', 400);
        }

        $kind = sanitize_key((string) ($payload['kind'] ?? ''));
        $spec = $this->kind_spec($kind);
        if (is_wp_error($spec)) { return $spec; }
        $evidence_confirmed = ! empty($payload['evidence_confirmed']);
        $evidence_reference = sanitize_text_field((string) ($payload['evidence_reference'] ?? ''));

        if ('object-create' === $operation) {
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : array();
            if (! $data) { return $this->error('validation_failed', 'object-create requires a bounded data object.', 400); }
            $prepared = $this->editor->preview_create($kind, $data, $evidence_confirmed, $evidence_reference);
            if (is_wp_error($prepared)) { return $this->normalise_error($prepared, 400); }
            $target = array('resource'=>'research-object','kind'=>$kind,'post_type'=>(string) $spec['post_type'],'post_id'=>0);
            $requested = array('data'=>(array) ($prepared['expected'] ?? array()));
        } else {
            $post_id = absint($payload['post_id'] ?? 0);
            $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : array();
            if ($post_id <= 0 || ! $changes) { return $this->error('validation_failed', 'object-update requires post_id and bounded changes.', 400); }
            $before = $this->editor->inspect($kind, $post_id);
            if (is_wp_error($before)) { return $this->normalise_error($before, 400); }
            $prepared = $this->editor->preview_update($kind, $post_id, $changes, $evidence_confirmed, $evidence_reference);
            if (is_wp_error($prepared)) { return $this->normalise_error($prepared, 400); }
            $target = array(
                'resource'=>'research-object','kind'=>$kind,'post_type'=>(string) $spec['post_type'],'post_id'=>$post_id,
                'language'=>(string) ($before['language'] ?? ''),
            );
            $requested = array('changes'=>(array) ($prepared['expected'] ?? $changes));
        }

        $source_revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        if (is_wp_error($source_revision)) { return $source_revision; }
        $now = time();
        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $request_id = sanitize_text_field((string) ($actor['request_id'] ?? ''));
        $risk = sanitize_key((string) ($prepared['risk'] ?? 'evidence-required')) ?: 'evidence-required';
        $material = array(
            'operation'=>$operation,
            'kind'=>$kind,
            'target'=>$target,
            'requested'=>$requested,
            'source_revision'=>$source_revision,
            'baseline_checksum'=>(string) ($prepared['baseline_checksum'] ?? ''),
            'evidence_reference'=>$evidence_reference,
            'connection_id'=>$connection_id,
        );
        $checksum = hash('sha256', (string) wp_json_encode($material));
        $plan_id = 'erm-object-plan-' . substr($checksum, 0, 18) . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
        $plan = array(
            'plan_id'=>$plan_id,
            'operation'=>$operation,
            'kind'=>$kind,
            'risk'=>$risk,
            'confirmation_required'=>true,
            'confirmation_class'=>'evidence-required' === $risk ? 'evidence-explicit' : 'explicit',
            'target'=>$target,
            'requested'=>$requested,
            'prepared'=>$prepared,
            'preview'=>is_array($prepared['preview'] ?? null) ? $prepared['preview'] : array(),
            'apply_allowed'=>! empty($prepared['apply_allowed']),
            'source_revision'=>$source_revision,
            'evidence_confirmed'=>$evidence_confirmed,
            'evidence_reference'=>$evidence_reference,
            'connection_id'=>$connection_id,
            'created_by'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'created_request_id'=>$request_id,
            'created_at'=>gmdate(DATE_W3C, $now),
            'expires_at'=>gmdate(DATE_W3C, $now + self::PLAN_TTL),
            'expires_unix'=>$now + self::PLAN_TTL,
            'status'=>'planned',
            'checksum'=>$checksum,
        );
        $this->put_plan($plan);
        $this->audit('remote-object-plan-created', 'success', $plan, $actor);
        return $this->public_plan($plan);
    }

    public function get_plan(string $plan_id): array|WP_Error {
        $plan = $this->find_plan($plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Research Object plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put_plan($plan);
        }
        return $this->public_plan($plan);
    }

    public function apply(string $plan_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return $this->error('capability_unavailable', 'The local WordPress execution identity cannot apply Research Object operations.', 403);
        }
        $plan = $this->find_plan($plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Research Object plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This Research Object plan belongs to a different remote connection.', 403);
        }
        if ('planned' !== (string) ($plan['status'] ?? '')) {
            if ('applied' === (string) ($plan['status'] ?? '') && '' !== (string) ($plan['operation_id'] ?? '')) {
                return $this->get_operation((string) $plan['operation_id']);
            }
            return $this->error('validation_failed', 'Only a planned Research Object operation can be applied.', 409);
        }
        if (time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put_plan($plan);
            return $this->error('plan_expired', 'Research Object plan has expired. Create a fresh Preview.', 409);
        }
        if (empty($plan['apply_allowed'])) {
            $code = 'evidence-required' === (string) ($plan['risk'] ?? '') ? 'evidence_required' : 'validation_failed';
            return $this->error($code, 'The stored Research Object Preview does not permit Apply.', 409, array(
                'confirmation_class'=>(string) ($plan['confirmation_class'] ?? 'explicit'),
            ));
        }
        if (! $confirmed) {
            return $this->error('confirmation_required', 'Research Object Apply requires explicit confirmation.', 409, array(
                'confirmation_class'=>(string) ($plan['confirmation_class'] ?? 'explicit'),
            ));
        }

        $revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        if (is_wp_error($revision)) { return $revision; }
        if (! hash_equals((string) ($plan['source_revision'] ?? ''), $revision)) {
            return $this->error('stale_revision', 'Managed WordPress state changed after Research Object Preview. Create a fresh plan.', 409);
        }
        $prepared = is_array($plan['prepared'] ?? null) ? $plan['prepared'] : array();
        if (! $prepared) { return $this->error('validation_failed', 'Stored Research Object Preview is incomplete.', 409); }

        $result = $this->editor->apply_preview($prepared);
        if (is_wp_error($result)) { return $this->normalise_error($result, 409); }
        $operation_id = 'erm-object-op-' . str_replace('-', '', wp_generate_uuid4());
        $target = is_array($plan['target'] ?? null) ? $plan['target'] : array();
        if ('object-create' === (string) $plan['operation'] && ! empty($result['post_id'])) {
            $target['post_id'] = (int) $result['post_id'];
        }
        $operation = array(
            'operation_id'=>$operation_id,
            'plan_id'=>$plan_id,
            'operation'=>(string) $plan['operation'],
            'kind'=>(string) $plan['kind'],
            'risk'=>(string) ($plan['risk'] ?? 'evidence-required'),
            'target'=>$target,
            'requested'=>is_array($plan['requested'] ?? null) ? $plan['requested'] : array(),
            'source_revision'=>(string) ($plan['source_revision'] ?? ''),
            'post_apply_revision'=>'',
            'evidence_reference'=>(string) ($plan['evidence_reference'] ?? ''),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'apply_request_id'=>(string) ($actor['request_id'] ?? ''),
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
        $post_revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        $operation['post_apply_revision'] = is_wp_error($post_revision) ? '' : $post_revision;
        $this->put_operation($operation);
        $plan['status'] = 'applied';
        $plan['operation_id'] = $operation_id;
        $plan['applied_at'] = gmdate(DATE_W3C);
        $this->put_plan($plan);
        $this->audit('remote-object-operation-applied', 'success', $operation, $actor);
        return $operation;
    }

    public function get_operation(string $operation_id): array|WP_Error {
        $operation = $this->find_operation($operation_id);
        return $operation ?: $this->error('operation_not_found', 'Research Object operation was not found.', 404);
    }

    public function verify(string $operation_id, bool $include_rendered, array $actor): array|WP_Error {
        $operation = $this->find_operation($operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Research Object operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This Research Object operation belongs to a different remote connection.', 403);
        }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            return $this->error('validation_failed', 'Only applied Research Object operations can be verified.', 409);
        }

        $kind = sanitize_key((string) ($operation['kind'] ?? ''));
        $post_id = absint($operation['target']['post_id'] ?? $operation['result']['post_id'] ?? 0);
        if ($post_id <= 0) { return $this->error('validation_failed', 'Research Object operation is missing its target.', 409); }
        $expected = 'object-create' === (string) $operation['operation']
            ? (array) ($operation['requested']['data'] ?? array())
            : (array) ($operation['requested']['changes'] ?? array());
        $semantic = $this->verify_object($kind, $post_id, $expected);
        if (is_wp_error($semantic)) { return $this->normalise_error($semantic, 409); }

        $current_revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        if (is_wp_error($current_revision)) { return $current_revision; }
        $post_apply_revision = (string) ($operation['post_apply_revision'] ?? '');
        $revision_matches = '' !== $post_apply_revision && hash_equals($post_apply_revision, $current_revision);
        $stored_verified = $revision_matches && ! empty($operation['result']['verified']) && ! empty($semantic['verified']);
        $stored = array(
            'verified'=>$stored_verified,
            'revision_matches'=>$revision_matches,
            'apply_verified'=>! empty($operation['result']['verified']),
            'semantic'=>$semantic,
            'expected_revision'=>$post_apply_revision,
            'current_revision'=>$current_revision,
            'evidence_reference'=>(string) ($operation['evidence_reference'] ?? ''),
            'verified_at'=>gmdate(DATE_W3C),
        );

        $rendered = array('requested'=>$include_rendered,'verified'=>null,'resources'=>array());
        if ($include_rendered) {
            $post = get_post($post_id);
            if (! $post instanceof WP_Post) {
                return $this->error('validation_failed', 'Research Object no longer exists for rendered verification.', 409);
            }
            if ('publish' !== (string) $post->post_status) {
                $rendered = array(
                    'requested'=>true,'verified'=>true,
                    'resources'=>array(array(
                        'resource'=>(string) $post->post_type . ':' . $post_id,
                        'post_id'=>$post_id,'verified'=>true,'skipped'=>true,'reason'=>'draft-or-non-public',
                    )),
                    'verified_at'=>gmdate(DATE_W3C),
                );
            } else {
                $result = Eduardo_Research_Manager::rendered()->verify_record($post_id);
                if (is_wp_error($result)) {
                    $rendered = array(
                        'requested'=>true,'verified'=>false,
                        'resources'=>array(array(
                            'resource'=>(string) $post->post_type . ':' . $post_id,
                            'post_id'=>$post_id,'verified'=>false,
                            'error_code'=>$result->get_error_code(),'error'=>$result->get_error_message(),
                        )),
                        'verified_at'=>gmdate(DATE_W3C),
                    );
                } else {
                    $rendered = array('requested'=>true,'verified'=>! empty($result['verified']),'resources'=>array($result),'verified_at'=>gmdate(DATE_W3C));
                }
            }
        }

        $verified = $stored_verified && (! $include_rendered || ! empty($rendered['verified']));
        $operation['stored_verification'] = $stored;
        $operation['rendered_verification'] = $rendered;
        $operation['verified_at'] = gmdate(DATE_W3C);
        $operation['status'] = $verified ? 'verified' : 'verification-failed';
        $this->put_operation($operation);
        $this->audit('remote-object-operation-verified', $verified ? 'success' : 'failed', $operation, $actor);
        return $operation;
    }

    public function rollback(string $operation_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return $this->error('capability_unavailable', 'The local WordPress execution identity cannot rollback Research Object operations.', 403);
        }
        $operation = $this->find_operation($operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Research Object operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This Research Object operation belongs to a different remote connection.', 403);
        }
        if (! $confirmed) { return $this->error('confirmation_required', 'Research Object rollback requires explicit confirmation.', 409); }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            if ('rolled-back' === (string) ($operation['status'] ?? '')) { return $operation; }
            return $this->error('rollback_unavailable', 'This Research Object operation is not rollback-capable.', 409);
        }
        $snapshot_id = sanitize_text_field((string) ($operation['snapshot_id'] ?? ''));
        if ('' === $snapshot_id) { return $this->error('rollback_unavailable', 'No Research Object rollback snapshot is available.', 409); }
        $result = $this->editor->rollback($snapshot_id);
        if (is_wp_error($result)) { return $this->normalise_error($result, 409); }

        $post_id = absint($operation['target']['post_id'] ?? 0);
        $kind = sanitize_key((string) ($operation['kind'] ?? ''));
        if ('object-create' === (string) $operation['operation']) {
            $restored = $post_id > 0 && ! (get_post($post_id) instanceof WP_Post);
        } else {
            $expected_source = (string) ($operation['source_revision'] ?? '');
            $revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
            $restored = ! is_wp_error($revision) && '' !== $expected_source && hash_equals($expected_source, $revision);
        }
        $operation['status'] = 'rolled-back';
        $operation['rollback_result'] = $result;
        $operation['rollback_restored_baseline'] = $restored;
        $operation['rolled_back_at'] = gmdate(DATE_W3C);
        $operation['post_rollback_revision'] = $this->safe_revision();
        $this->put_operation($operation);
        $this->audit('remote-object-operation-rolled-back', 'success', $operation, $actor);
        return $operation;
    }

    private function kind_spec(string $kind): array|WP_Error {
        $supported = $this->editor->supported_kinds();
        if (! isset($supported[$kind])) {
            return $this->error('validation_failed', 'Unsupported Research Object kind.', 400, array('supported_kinds'=>array_keys($supported)));
        }
        return $supported[$kind];
    }

    private function verify_object(string $kind, int $post_id, array $expected): array|WP_Error {
        return match ($kind) {
            'output' => Eduardo_Research_Manager::outputs()->verify($post_id, $expected),
            'project' => Eduardo_Research_Manager::projects()->verify($post_id, $expected),
            'software' => Eduardo_Research_Manager::software()->verify($post_id, $expected),
            'dataset' => Eduardo_Research_Manager::datasets()->verify($post_id, $expected),
            default => $this->error('validation_failed', 'Unsupported Research Object kind.', 400),
        };
    }

    private function audit(string $event, string $outcome, array $state, array $actor): void {
        $this->audit->record(array(
            'request_id'=>(string) ($actor['request_id'] ?? ''),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'event'=>$event,'outcome'=>$outcome,'scope'=>'research.write',
            'plan_id'=>(string) ($state['plan_id'] ?? ''),
            'operation_id'=>(string) ($state['operation_id'] ?? ''),
            'operation'=>(string) ($state['operation'] ?? ''),
            'kind'=>(string) ($state['kind'] ?? ''),
            'evidence_reference'=>(string) ($state['evidence_reference'] ?? ''),
        ));
    }

    private function safe_revision(): string {
        $revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        return is_wp_error($revision) ? '' : $revision;
    }

    private function public_plan(array $plan): array {
        unset($plan['prepared'], $plan['expires_unix']);
        return $plan;
    }

    private function find_plan(string $plan_id): array {
        $plans = get_option(self::PLANS_OPTION, array());
        $plans = is_array($plans) ? $plans : array();
        $plan = $plans[sanitize_text_field($plan_id)] ?? array();
        return is_array($plan) ? $plan : array();
    }

    private function find_operation(string $operation_id): array {
        $operations = get_option(self::OPERATIONS_OPTION, array());
        $operations = is_array($operations) ? $operations : array();
        $operation = $operations[sanitize_text_field($operation_id)] ?? array();
        return is_array($operation) ? $operation : array();
    }

    private function put_plan(array $plan): void {
        $plans = get_option(self::PLANS_OPTION, array());
        $plans = is_array($plans) ? $plans : array();
        $plans[(string) $plan['plan_id']] = $plan;
        while (count($plans) > self::PLAN_LIMIT) { array_shift($plans); }
        $this->save_option(self::PLANS_OPTION, $plans);
    }

    private function put_operation(array $operation): void {
        $operations = get_option(self::OPERATIONS_OPTION, array());
        $operations = is_array($operations) ? $operations : array();
        $operations[(string) $operation['operation_id']] = $operation;
        while (count($operations) > self::OPERATION_LIMIT) { array_shift($operations); }
        $this->save_option(self::OPERATIONS_OPTION, $operations);
    }

    private function save_option(string $name, array $value): void {
        if (false === get_option($name, false)) { add_option($name, $value, '', false); return; }
        update_option($name, $value, false);
    }

    private function normalise_error(WP_Error $error, int $status): WP_Error {
        $data = $error->get_error_data();
        if (! is_array($data) || ! isset($data['status'])) {
            $error->add_data(array_merge(is_array($data) ? $data : array(), array('status'=>$status)));
        }
        return $error;
    }

    private function error(string $code, string $message, int $status, array $extra = array()): WP_Error {
        return new WP_Error($code, $message, array_merge(array('status'=>$status), $extra));
    }
}
