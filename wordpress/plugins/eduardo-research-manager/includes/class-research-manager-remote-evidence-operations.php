<?php
/** Exact remote lifecycle for academic identity and Research evidence/provenance operations. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Evidence_Operations {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_evidence_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_evidence_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;
    private const PLAN_LIMIT = 60;
    private const OPERATION_LIMIT = 120;

    private Eduardo_Research_Manager_Evidence_Editor $editor;
    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(
        ?Eduardo_Research_Manager_Evidence_Editor $editor = null,
        ?Eduardo_Research_Manager_Remote_Audit $audit = null
    ) {
        $this->editor = $editor ?: Eduardo_Research_Manager::evidence_editor();
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
    }

    public function create_plan(string $operation, array $payload, array $actor): array|WP_Error {
        $operation = sanitize_key($operation);
        if (! in_array($operation, array('identity-update','evidence-create','evidence-update','evidence-delete'), true)) {
            return $this->error('validation_failed', 'Unsupported remote Research evidence operation.', 400);
        }

        $evidence_confirmed = ! empty($payload['evidence_confirmed']);
        $evidence_reference = sanitize_text_field((string) ($payload['evidence_reference'] ?? ''));
        $baseline_state = array();
        $target = array();
        $requested = array();

        if ('identity-update' === $operation) {
            $name = sanitize_text_field((string) ($payload['name'] ?? ''));
            if ('' === trim($name)) {
                return $this->error('validation_failed', 'identity-update requires a non-empty researcher name.', 400);
            }
            $before_identity = $this->editor->identity();
            $before_store = $this->editor->store();
            if (is_wp_error($before_identity)) { return $this->normalise_error($before_identity, 400); }
            if (is_wp_error($before_store)) { return $this->normalise_error($before_store, 400); }
            $prepared = $this->editor->preview_identity($name, $evidence_confirmed, $evidence_reference);
            if (is_wp_error($prepared)) { return $this->normalise_error($prepared, 400); }
            $target = array('resource'=>'identity');
            $requested = array('expected'=>(array) ($prepared['expected'] ?? array()));
            $baseline_state = array(
                'identity'=>is_array($before_identity['stored'] ?? null) ? $before_identity['stored'] : array(),
                'evidence'=>$before_store,
            );
        } else {
            $group = sanitize_key((string) ($payload['group'] ?? ''));
            if (! isset($this->editor->groups()[$group])) {
                return $this->error('validation_failed', 'Unsupported Research evidence group.', 400, array('supported_groups'=>array_keys($this->editor->groups())));
            }
            $before_store = $this->editor->store();
            if (is_wp_error($before_store)) { return $this->normalise_error($before_store, 400); }
            $record_id = sanitize_key((string) ($payload['record_id'] ?? ''));

            if ('evidence-create' === $operation) {
                $data = is_array($payload['data'] ?? null) ? $payload['data'] : array();
                if (! $data) { return $this->error('validation_failed', 'evidence-create requires a structured data object.', 400); }
                $prepared = $this->editor->preview_record($group, '', $data, $evidence_confirmed, $evidence_reference);
            } elseif ('evidence-update' === $operation) {
                $data = is_array($payload['data'] ?? null) ? $payload['data'] : array();
                if ('' === $record_id || ! $data) { return $this->error('validation_failed', 'evidence-update requires record_id and structured data.', 400); }
                $prepared = $this->editor->preview_record($group, $record_id, $data, $evidence_confirmed, $evidence_reference);
            } else {
                if ('' === $record_id) { return $this->error('validation_failed', 'evidence-delete requires record_id.', 400); }
                $prepared = $this->editor->preview_delete_record($group, $record_id, $evidence_confirmed, $evidence_reference);
            }
            if (is_wp_error($prepared)) { return $this->normalise_error($prepared, 400); }

            $record_id = sanitize_key((string) ($prepared['record_id'] ?? $record_id));
            $target = array('resource'=>'evidence','group'=>$group,'record_id'=>$record_id);
            $requested = array(
                'expected'=>is_array($prepared['expected'] ?? null) ? $prepared['expected'] : array(),
                'deleted'=>is_array($prepared['deleted'] ?? null) ? $prepared['deleted'] : array(),
            );
            $baseline_state = array('evidence'=>$before_store);
        }

        $now = time();
        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $request_id = sanitize_text_field((string) ($actor['request_id'] ?? ''));
        $risk = sanitize_key((string) ($prepared['risk'] ?? 'evidence-required')) ?: 'evidence-required';
        $material = array(
            'operation'=>$operation,
            'target'=>$target,
            'requested'=>$requested,
            'baseline_checksum'=>(string) ($prepared['baseline_checksum'] ?? ''),
            'evidence_reference'=>$evidence_reference,
            'connection_id'=>$connection_id,
        );
        $checksum = hash('sha256', (string) wp_json_encode($material));
        $plan_id = 'erm-evidence-plan-' . substr($checksum, 0, 18) . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
        $plan = array(
            'plan_id'=>$plan_id,
            'operation'=>$operation,
            'risk'=>$risk,
            'confirmation_required'=>true,
            'confirmation_class'=>'evidence-explicit',
            'target'=>$target,
            'requested'=>$requested,
            'prepared'=>$prepared,
            'preview'=>is_array($prepared['preview'] ?? null) ? $prepared['preview'] : array(),
            'apply_allowed'=>! empty($prepared['apply_allowed']),
            'baseline_checksum'=>(string) ($prepared['baseline_checksum'] ?? ''),
            'baseline_state'=>$baseline_state,
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
        $this->audit('remote-evidence-plan-created', 'success', $plan, $actor);
        return $this->public_plan($plan);
    }

    public function get_plan(string $plan_id): array|WP_Error {
        $plan = $this->find_plan($plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Research evidence plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put_plan($plan);
        }
        return $this->public_plan($plan);
    }

    public function apply(string $plan_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return $this->error('capability_unavailable', 'The local WordPress execution identity cannot apply Research evidence operations.', 403);
        }
        $plan = $this->find_plan($plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Research evidence plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This Research evidence plan belongs to a different remote connection.', 403);
        }
        if ('planned' !== (string) ($plan['status'] ?? '')) {
            if ('applied' === (string) ($plan['status'] ?? '') && '' !== (string) ($plan['operation_id'] ?? '')) {
                return $this->get_operation((string) $plan['operation_id']);
            }
            return $this->error('validation_failed', 'Only a planned Research evidence operation can be applied.', 409);
        }
        if (time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put_plan($plan);
            return $this->error('plan_expired', 'Research evidence plan has expired. Create a fresh Preview.', 409);
        }
        if (empty($plan['apply_allowed'])) {
            return $this->error('evidence_required', 'The stored Research evidence Preview does not permit Apply.', 409, array('confirmation_class'=>'evidence-explicit'));
        }
        if (! $confirmed) {
            return $this->error('confirmation_required', 'Research evidence Apply requires explicit confirmation.', 409, array('confirmation_class'=>'evidence-explicit'));
        }
        $prepared = is_array($plan['prepared'] ?? null) ? $plan['prepared'] : array();
        if (! $prepared) { return $this->error('validation_failed', 'Stored Research evidence Preview is incomplete.', 409); }

        // Evidence Editor enforces its own exact resource baseline here.
        $result = $this->editor->apply_preview($prepared);
        if (is_wp_error($result)) { return $this->normalise_error($result, 409); }

        $operation_id = 'erm-evidence-op-' . str_replace('-', '', wp_generate_uuid4());
        $operation = array(
            'operation_id'=>$operation_id,
            'plan_id'=>$plan_id,
            'operation'=>(string) $plan['operation'],
            'risk'=>(string) ($plan['risk'] ?? 'evidence-required'),
            'target'=>is_array($plan['target'] ?? null) ? $plan['target'] : array(),
            'requested'=>is_array($plan['requested'] ?? null) ? $plan['requested'] : array(),
            'baseline_checksum'=>(string) ($plan['baseline_checksum'] ?? ''),
            'baseline_state'=>is_array($plan['baseline_state'] ?? null) ? $plan['baseline_state'] : array(),
            'evidence_reference'=>(string) ($plan['evidence_reference'] ?? ''),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'apply_request_id'=>(string) ($actor['request_id'] ?? ''),
            'status'=>'applied',
            'result'=>$result,
            'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),
            'stored_verification'=>array(),
            'provenance'=>array(),
            'created_at'=>gmdate(DATE_W3C),
            'applied_at'=>gmdate(DATE_W3C),
            'verified_at'=>'',
            'rolled_back_at'=>'',
        );
        $this->put_operation($operation);
        $plan['status'] = 'applied';
        $plan['operation_id'] = $operation_id;
        $plan['applied_at'] = gmdate(DATE_W3C);
        $this->put_plan($plan);
        $this->audit('remote-evidence-operation-applied', 'success', $operation, $actor);
        return $this->public_operation($operation);
    }

    public function get_operation(string $operation_id): array|WP_Error {
        $operation = $this->find_operation($operation_id);
        return $operation ? $this->public_operation($operation) : $this->error('operation_not_found', 'Research evidence operation was not found.', 404);
    }

    public function verify(string $operation_id, array $actor): array|WP_Error {
        $operation = $this->find_operation($operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Research evidence operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This Research evidence operation belongs to a different remote connection.', 403);
        }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            return $this->error('validation_failed', 'Only applied Research evidence operations can be verified.', 409);
        }

        $semantic = $this->semantic_verification($operation);
        if (is_wp_error($semantic)) { return $this->normalise_error($semantic, 409); }
        $verified = ! empty($semantic['verified']) && ! empty($operation['result']['verified']);
        $operation['stored_verification'] = array(
            'verified'=>$verified,
            'apply_verified'=>! empty($operation['result']['verified']),
            'semantic'=>$semantic,
            'verified_at'=>gmdate(DATE_W3C),
        );
        $operation['provenance'] = $this->provenance($operation);
        $operation['verified_at'] = gmdate(DATE_W3C);
        $operation['status'] = $verified ? 'verified' : 'verification-failed';
        $this->put_operation($operation);
        $this->audit('remote-evidence-operation-verified', $verified ? 'success' : 'failed', $operation, $actor);
        return $this->public_operation($operation);
    }

    public function rollback(string $operation_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return $this->error('capability_unavailable', 'The local WordPress execution identity cannot rollback Research evidence operations.', 403);
        }
        $operation = $this->find_operation($operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Research evidence operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This Research evidence operation belongs to a different remote connection.', 403);
        }
        if (! $confirmed) { return $this->error('confirmation_required', 'Research evidence rollback requires explicit confirmation.', 409); }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            if ('rolled-back' === (string) ($operation['status'] ?? '')) { return $this->public_operation($operation); }
            return $this->error('rollback_unavailable', 'This Research evidence operation is not rollback-capable.', 409);
        }
        $snapshot_id = sanitize_text_field((string) ($operation['snapshot_id'] ?? ''));
        if ('' === $snapshot_id) { return $this->error('rollback_unavailable', 'No Research evidence rollback snapshot is available.', 409); }

        $result = $this->editor->rollback($snapshot_id);
        if (is_wp_error($result)) { return $this->normalise_error($result, 409); }
        $restored = $this->baseline_restored($operation);
        $operation['status'] = 'rolled-back';
        $operation['rollback_result'] = $result;
        $operation['rollback_restored_baseline'] = $restored;
        $operation['rolled_back_at'] = gmdate(DATE_W3C);
        $this->put_operation($operation);
        $this->audit('remote-evidence-operation-rolled-back', $restored ? 'success' : 'failed', $operation, $actor);
        return $this->public_operation($operation);
    }

    private function semantic_verification(array $operation): array|WP_Error {
        $target = is_array($operation['target'] ?? null) ? $operation['target'] : array();
        $expected = (array) ($operation['requested']['expected'] ?? array());
        if ('identity-update' === (string) ($operation['operation'] ?? '')) {
            return $this->editor->verify_identity($expected);
        }
        $group = sanitize_key((string) ($target['group'] ?? ''));
        $record_id = sanitize_key((string) ($target['record_id'] ?? ''));
        if ('evidence-delete' === (string) ($operation['operation'] ?? '')) {
            $record = $this->editor->inspect_record($group, $record_id);
            if (is_wp_error($record) && 'research_manager_evidence_record_missing' === $record->get_error_code()) {
                return array('verified'=>true,'checks'=>array('record_absent'=>true),'verified_at'=>gmdate(DATE_W3C));
            }
            return is_wp_error($record)
                ? $record
                : array('verified'=>false,'checks'=>array('record_absent'=>false),'verified_at'=>gmdate(DATE_W3C));
        }
        return $this->editor->verify_record($group, $record_id, $expected);
    }

    private function provenance(array $operation): array {
        $target = is_array($operation['target'] ?? null) ? $operation['target'] : array();
        $reference = (string) ($operation['evidence_reference'] ?? '');
        if ('identity' === (string) ($target['resource'] ?? '')) {
            $identity = $this->editor->identity();
            return is_wp_error($identity) ? array() : array(
                'resource'=>'identity',
                'evidence_reference'=>(string) ($identity['evidence_reference'] ?? $reference),
                'verified_at'=>(string) ($identity['verified_at'] ?? ''),
                'surfaces'=>array('about','contact'),
            );
        }
        $group = sanitize_key((string) ($target['group'] ?? ''));
        $record_id = sanitize_key((string) ($target['record_id'] ?? ''));
        $groups = $this->editor->groups();
        $record = $this->editor->inspect_record($group, $record_id);
        return array(
            'resource'=>'evidence',
            'group'=>$group,
            'record_id'=>$record_id,
            'status'=>is_wp_error($record) ? 'absent' : (string) ($record['status'] ?? ''),
            'evidence_reference'=>is_wp_error($record) ? $reference : (string) ($record['evidence_reference'] ?? $reference),
            'verified_at'=>is_wp_error($record) ? '' : (string) ($record['verified_at'] ?? ''),
            'surfaces'=>array_values((array) ($groups[$group]['surfaces'] ?? array())),
        );
    }

    private function baseline_restored(array $operation): bool {
        $baseline = is_array($operation['baseline_state'] ?? null) ? $operation['baseline_state'] : array();
        if ('identity-update' === (string) ($operation['operation'] ?? '')) {
            $identity = $this->editor->identity();
            $store = $this->editor->store();
            if (is_wp_error($identity) || is_wp_error($store)) { return false; }
            return maybe_serialize(is_array($identity['stored'] ?? null) ? $identity['stored'] : array()) === maybe_serialize((array) ($baseline['identity'] ?? array()))
                && maybe_serialize($store) === maybe_serialize((array) ($baseline['evidence'] ?? array()));
        }
        $store = $this->editor->store();
        return ! is_wp_error($store) && maybe_serialize($store) === maybe_serialize((array) ($baseline['evidence'] ?? array()));
    }

    private function audit(string $event, string $outcome, array $state, array $actor): void {
        $target = is_array($state['target'] ?? null) ? $state['target'] : array();
        $this->audit->record(array(
            'request_id'=>(string) ($actor['request_id'] ?? ''),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'event'=>$event,'outcome'=>$outcome,'scope'=>'evidence.write',
            'plan_id'=>(string) ($state['plan_id'] ?? ''),
            'operation_id'=>(string) ($state['operation_id'] ?? ''),
            'operation'=>(string) ($state['operation'] ?? ''),
            'group'=>(string) ($target['group'] ?? ''),
            'record_id'=>(string) ($target['record_id'] ?? ''),
            'evidence_reference'=>(string) ($state['evidence_reference'] ?? ''),
        ));
    }

    private function public_plan(array $plan): array {
        unset($plan['prepared'], $plan['baseline_state'], $plan['expires_unix']);
        return $plan;
    }

    private function public_operation(array $operation): array {
        unset($operation['baseline_state']);
        return $operation;
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
