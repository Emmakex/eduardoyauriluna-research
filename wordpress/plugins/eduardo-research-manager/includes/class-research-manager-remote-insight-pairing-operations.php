<?php
/** Exact remote lifecycle for bounded EN/ES Research Insight pairing. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Insight_Pairing_Operations {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_insight_pair_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_insight_pair_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;
    private const PLAN_LIMIT = 40;
    private const OPERATION_LIMIT = 80;

    private Eduardo_Research_Manager_Translation_Editor $editor;
    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(
        ?Eduardo_Research_Manager_Translation_Editor $editor = null,
        ?Eduardo_Research_Manager_Remote_Audit $audit = null
    ) {
        $this->editor = $editor ?: Eduardo_Research_Manager::translation_editor();
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
    }

    public function create_plan(string $operation, array $payload, array $actor): array|WP_Error {
        $operation = sanitize_key($operation);
        if (! in_array($operation, array('insight-pair','insight-unpair'), true)) {
            return $this->error('validation_failed', 'Unsupported Research Insight pairing operation.', 400);
        }

        if ('insight-pair' === $operation) {
            $first_id = absint($payload['first_id'] ?? 0);
            $second_id = absint($payload['second_id'] ?? 0);
            if ($first_id <= 0 || $second_id <= 0) {
                return $this->error('validation_failed', 'insight-pair requires first_id and second_id.', 400);
            }
            $first = $this->managed_insight($first_id);
            if (is_wp_error($first)) { return $this->normalise_error($first, 400); }
            $second = $this->managed_insight($second_id);
            if (is_wp_error($second)) { return $this->normalise_error($second, 400); }
            $prepared = $this->editor->preview_pair($first_id, $second_id);
            if (is_wp_error($prepared)) { return $this->normalise_error($prepared, 400); }
            $target = array(
                'resource'=>'insight-translation-pair',
                'first_id'=>$first_id,
                'second_id'=>$second_id,
                'first_language'=>(string) ($first['language'] ?? ''),
                'second_language'=>(string) ($second['language'] ?? ''),
            );
            $requested = array('first_id'=>$first_id,'second_id'=>$second_id);
        } else {
            $post_id = absint($payload['post_id'] ?? 0);
            if ($post_id <= 0) {
                return $this->error('validation_failed', 'insight-unpair requires post_id.', 400);
            }
            $first = $this->managed_insight($post_id);
            if (is_wp_error($first)) { return $this->normalise_error($first, 400); }
            $prepared = $this->editor->preview_unpair($post_id);
            if (is_wp_error($prepared)) { return $this->normalise_error($prepared, 400); }
            $second_id = absint($prepared['second_id'] ?? 0);
            $second = $this->managed_insight($second_id);
            if (is_wp_error($second)) { return $this->normalise_error($second, 400); }
            $target = array(
                'resource'=>'insight-translation-pair',
                'first_id'=>$post_id,
                'second_id'=>$second_id,
                'first_language'=>(string) ($first['language'] ?? ''),
                'second_language'=>(string) ($second['language'] ?? ''),
            );
            $requested = array('post_id'=>$post_id,'counterpart_id'=>$second_id);
        }

        $preview = is_array($prepared['preview'] ?? null) ? $prepared['preview'] : array();
        $source_revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        if (is_wp_error($source_revision)) { return $source_revision; }
        $now = time();
        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $request_id = sanitize_text_field((string) ($actor['request_id'] ?? ''));
        $risk = sanitize_key((string) ($prepared['risk'] ?? 'editorial-review'));
        if ('' === $risk) { $risk = 'editorial-review'; }
        $material = array(
            'operation'=>$operation,
            'target'=>$target,
            'requested'=>$requested,
            'baseline_checksum'=>(string) ($prepared['baseline_checksum'] ?? ''),
            'source_revision'=>$source_revision,
            'connection_id'=>$connection_id,
        );
        $checksum = hash('sha256', (string) wp_json_encode($material));
        $plan_id = 'erm-insight-pair-plan-' . substr($checksum, 0, 18) . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
        $plan = array(
            'plan_id'=>$plan_id,
            'operation'=>$operation,
            'risk'=>$risk,
            'confirmation_required'=>true,
            'confirmation_class'=>'editorial-review' === $risk ? 'explicit' : $risk,
            'target'=>$target,
            'requested'=>$requested,
            'prepared'=>$prepared,
            'preview'=>$preview,
            'apply_allowed'=>! empty($prepared['apply_allowed']),
            'source_revision'=>$source_revision,
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
        $this->audit('remote-insight-pair-plan-created', 'success', $plan, $actor);
        return $this->public_plan($plan);
    }

    public function get_plan(string $plan_id): array|WP_Error {
        $plan = $this->find_plan($plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Research Insight pairing plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put_plan($plan);
        }
        return $this->public_plan($plan);
    }

    public function apply(string $plan_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return $this->error('capability_unavailable', 'The local WordPress execution identity cannot apply Insight pairing.', 403);
        }
        $plan = $this->find_plan($plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Research Insight pairing plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This pairing plan belongs to a different remote connection.', 403);
        }
        if ('planned' !== (string) ($plan['status'] ?? '')) {
            if ('applied' === (string) ($plan['status'] ?? '') && '' !== (string) ($plan['operation_id'] ?? '')) {
                return $this->get_operation((string) $plan['operation_id']);
            }
            return $this->error('validation_failed', 'Only a planned pairing operation can be applied.', 409);
        }
        if (time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put_plan($plan);
            return $this->error('plan_expired', 'Research Insight pairing plan has expired. Create a fresh Preview.', 409);
        }
        if (empty($plan['apply_allowed'])) {
            return $this->error('validation_failed', 'The stored pairing Preview does not permit Apply.', 409);
        }
        if (! $confirmed) {
            return $this->error('confirmation_required', 'Insight pairing Apply requires explicit confirmation.', 409);
        }

        $prepared = is_array($plan['prepared'] ?? null) ? $plan['prepared'] : array();
        if (! $prepared) { return $this->error('validation_failed', 'Stored pairing Preview is incomplete.', 409); }
        $result = $this->editor->apply_preview($prepared);
        if (is_wp_error($result)) { return $this->normalise_error($result, 409); }

        $operation_id = 'erm-insight-pair-op-' . str_replace('-', '', wp_generate_uuid4());
        $operation = array(
            'operation_id'=>$operation_id,
            'plan_id'=>$plan_id,
            'operation'=>(string) $plan['operation'],
            'risk'=>(string) ($plan['risk'] ?? 'editorial-review'),
            'target'=>is_array($plan['target'] ?? null) ? $plan['target'] : array(),
            'requested'=>is_array($plan['requested'] ?? null) ? $plan['requested'] : array(),
            'source_revision'=>(string) ($plan['source_revision'] ?? ''),
            'post_apply_revision'=>'',
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
        $revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        $operation['post_apply_revision'] = is_wp_error($revision) ? '' : $revision;
        $this->put_operation($operation);
        $plan['status'] = 'applied';
        $plan['operation_id'] = $operation_id;
        $plan['applied_at'] = gmdate(DATE_W3C);
        $this->put_plan($plan);
        $this->audit('remote-insight-pair-operation-applied', 'success', $operation, $actor);
        return $operation;
    }

    public function get_operation(string $operation_id): array|WP_Error {
        $operation = $this->find_operation($operation_id);
        return $operation ?: $this->error('operation_not_found', 'Research Insight pairing operation was not found.', 404);
    }

    public function verify(string $operation_id, bool $include_rendered, array $actor): array|WP_Error {
        $operation = $this->find_operation($operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Research Insight pairing operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This pairing operation belongs to a different remote connection.', 403);
        }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            return $this->error('validation_failed', 'Only applied pairing operations can be verified.', 409);
        }
        $first_id = absint($operation['target']['first_id'] ?? 0);
        $second_id = absint($operation['target']['second_id'] ?? 0);
        if ($first_id <= 0 || $second_id <= 0) { return $this->error('validation_failed', 'Pairing operation is missing its Insight targets.', 409); }

        if ('insight-pair' === (string) $operation['operation']) {
            $semantic = Eduardo_Research_Manager::translations()->verify_pair($first_id, $second_id);
        } else {
            $first = Eduardo_Research_Manager::translations()->verify_unpaired($first_id);
            $second = Eduardo_Research_Manager::translations()->verify_unpaired($second_id);
            if (is_wp_error($first)) { return $this->normalise_error($first, 409); }
            if (is_wp_error($second)) { return $this->normalise_error($second, 409); }
            $semantic = array(
                'verified'=>! empty($first['verified']) && ! empty($second['verified']),
                'first'=>$first,
                'second'=>$second,
                'verified_at'=>gmdate(DATE_W3C),
            );
        }
        if (is_wp_error($semantic)) { return $this->normalise_error($semantic, 409); }

        $stored = array(
            'verified'=>! empty($semantic['verified']),
            'semantic'=>$semantic,
            'current_revision'=>$this->safe_revision(),
            'verified_at'=>gmdate(DATE_W3C),
        );
        $rendered = array('requested'=>$include_rendered,'verified'=>null,'resources'=>array());
        if ($include_rendered) {
            $rendered = $this->verify_rendered_pair($first_id, $second_id);
        }
        $verified = ! empty($stored['verified']) && (! $include_rendered || ! empty($rendered['verified']));
        $operation['stored_verification'] = $stored;
        $operation['rendered_verification'] = $rendered;
        $operation['verified_at'] = gmdate(DATE_W3C);
        $operation['status'] = $verified ? 'verified' : 'verification-failed';
        $this->put_operation($operation);
        $this->audit('remote-insight-pair-operation-verified', $verified ? 'success' : 'failed', $operation, $actor);
        return $operation;
    }

    public function rollback(string $operation_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return $this->error('capability_unavailable', 'The local WordPress execution identity cannot rollback Insight pairing.', 403);
        }
        $operation = $this->find_operation($operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Research Insight pairing operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This pairing operation belongs to a different remote connection.', 403);
        }
        if (! $confirmed) { return $this->error('confirmation_required', 'Insight pairing rollback requires explicit confirmation.', 409); }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            if ('rolled-back' === (string) ($operation['status'] ?? '')) { return $operation; }
            return $this->error('rollback_unavailable', 'This pairing operation is not rollback-capable.', 409);
        }
        $snapshot_id = sanitize_text_field((string) ($operation['snapshot_id'] ?? ''));
        if ('' === $snapshot_id) { return $this->error('rollback_unavailable', 'No pairing rollback snapshot is available.', 409); }
        $result = $this->editor->rollback($snapshot_id);
        if (is_wp_error($result)) { return $this->normalise_error($result, 409); }

        $first_id = absint($operation['target']['first_id'] ?? 0);
        $second_id = absint($operation['target']['second_id'] ?? 0);
        if ('insight-pair' === (string) $operation['operation']) {
            $first = Eduardo_Research_Manager::translations()->verify_unpaired($first_id);
            $second = Eduardo_Research_Manager::translations()->verify_unpaired($second_id);
            $restored = ! is_wp_error($first) && ! is_wp_error($second) && ! empty($first['verified']) && ! empty($second['verified']);
        } else {
            $pair = Eduardo_Research_Manager::translations()->verify_pair($first_id, $second_id);
            $restored = ! is_wp_error($pair) && ! empty($pair['verified']);
        }
        if (! $restored) { return $this->error('research_manager_translation_editor_verification_failed', 'Pairing rollback did not restore the previous translation state.', 409); }

        $operation['status'] = 'rolled-back';
        $operation['rollback_result'] = $result;
        $operation['post_rollback_revision'] = $this->safe_revision();
        $operation['rolled_back_at'] = gmdate(DATE_W3C);
        $this->put_operation($operation);
        $this->audit('remote-insight-pair-operation-rolled-back', 'success', $operation, $actor);
        return $operation;
    }

    private function managed_insight(int $post_id): array|WP_Error {
        if ($post_id <= 0) { return $this->error('validation_failed', 'A managed Research Insight ID is required.', 400); }
        $record = Eduardo_Research_Manager::insight_editor()->inspect($post_id);
        if (is_wp_error($record)) { return $record; }
        if ('publish' !== (string) ($record['status'] ?? '')) {
            return new WP_Error('research_manager_translation_not_public', 'Research Insights must be published before EN/ES pairing.', array('status'=>400));
        }
        return $record;
    }

    private function verify_rendered_pair(int $first_id, int $second_id): array {
        $resources = array();
        $verified = true;
        foreach (array($first_id, $second_id) as $post_id) {
            $result = Eduardo_Research_Manager::rendered()->verify_record($post_id);
            if (is_wp_error($result)) {
                $resources[] = array(
                    'resource'=>'insight:' . $post_id,
                    'post_id'=>$post_id,
                    'verified'=>false,
                    'error_code'=>$result->get_error_code(),
                    'error'=>$result->get_error_message(),
                );
                $verified = false;
                continue;
            }
            unset($result['body']);
            $resources[] = $result;
            $verified = $verified && ! empty($result['verified']);
        }
        return array('requested'=>true,'verified'=>$verified,'resources'=>$resources,'verified_at'=>gmdate(DATE_W3C));
    }

    private function safe_revision(): string {
        $revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        return is_wp_error($revision) ? '' : $revision;
    }

    private function audit(string $event, string $outcome, array $record, array $actor): void {
        $this->audit->record(array(
            'request_id'=>(string) ($actor['request_id'] ?? ''),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'event'=>$event,
            'outcome'=>$outcome,
            'scope'=>str_contains($event, 'rollback') ? 'operations.rollback' : (str_contains($event, 'verified') ? 'site.diagnostics' : 'operations.apply'),
            'plan_id'=>(string) ($record['plan_id'] ?? ''),
            'operation_id'=>(string) ($record['operation_id'] ?? ''),
            'operation'=>(string) ($record['operation'] ?? ''),
        ));
    }

    private function public_plan(array $plan): array {
        unset($plan['expires_unix'], $plan['prepared']);
        return $plan;
    }

    private function find_plan(string $plan_id): array {
        $plans = $this->plans();
        $plan = $plans[sanitize_text_field($plan_id)] ?? array();
        return is_array($plan) ? $plan : array();
    }

    private function find_operation(string $operation_id): array {
        $operations = $this->operations();
        $operation = $operations[sanitize_text_field($operation_id)] ?? array();
        return is_array($operation) ? $operation : array();
    }

    private function put_plan(array $plan): void {
        $plans = $this->plans();
        $plans[(string) $plan['plan_id']] = $plan;
        while (count($plans) > self::PLAN_LIMIT) { array_shift($plans); }
        $this->save_option(self::PLANS_OPTION, $plans);
    }

    private function put_operation(array $operation): void {
        $operations = $this->operations();
        $operations[(string) $operation['operation_id']] = $operation;
        while (count($operations) > self::OPERATION_LIMIT) { array_shift($operations); }
        $this->save_option(self::OPERATIONS_OPTION, $operations);
    }

    private function plans(): array {
        $plans = get_option(self::PLANS_OPTION, array());
        return is_array($plans) ? $plans : array();
    }

    private function operations(): array {
        $operations = get_option(self::OPERATIONS_OPTION, array());
        return is_array($operations) ? $operations : array();
    }

    private function save_option(string $name, array $value): void {
        if (false === get_option($name, false)) {
            add_option($name, $value, '', false);
            return;
        }
        update_option($name, $value, false);
    }

    private function normalise_error(WP_Error $error, int $status): WP_Error {
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
