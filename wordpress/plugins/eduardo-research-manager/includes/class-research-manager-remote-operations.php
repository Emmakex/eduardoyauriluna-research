<?php
/** Exact Plan → Apply → Verify → Rollback lifecycle for bounded remote Manager operations. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Operations {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;
    private const PLAN_LIMIT = 60;
    private const OPERATION_LIMIT = 120;

    private Eduardo_Research_Manager_Greenfield_Pipeline $pipeline;
    private Eduardo_Research_Manager_Remote_Audit $audit;
    private Eduardo_Research_Manager_Page_Editor $page_editor;
    private Eduardo_Research_Manager_Insight_Editor $insight_editor;

    public function __construct(
        ?Eduardo_Research_Manager_Greenfield_Pipeline $pipeline = null,
        ?Eduardo_Research_Manager_Remote_Audit $audit = null,
        ?Eduardo_Research_Manager_Page_Editor $page_editor = null,
        ?Eduardo_Research_Manager_Insight_Editor $insight_editor = null
    ) {
        $this->pipeline = $pipeline ?: Eduardo_Research_Manager::pipeline();
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
        $this->page_editor = $page_editor ?: Eduardo_Research_Manager::page_editor();
        $this->insight_editor = $insight_editor ?: Eduardo_Research_Manager::insight_editor();
    }

    public function create_plan(string $operation, array $payload, array $actor): array|WP_Error {
        $operation = sanitize_key($operation);
        if (! in_array($operation, array(
            'greenfield-canonical','page-slots-update','page-create','page-remediate',
            'insight-create','insight-update',
        ), true)) {
            return $this->error('validation_failed', 'The requested operation is outside the bounded Remote Manager capability set.', 400);
        }

        $prepared_result = $this->prepare($operation, $payload);
        if (is_wp_error($prepared_result)) { return $this->normalise_service_error($prepared_result, 400); }
        $preview = is_array($prepared_result['preview'] ?? null) ? $prepared_result['preview'] : array();
        $prepared = is_array($prepared_result['prepared'] ?? null) ? $prepared_result['prepared'] : array();
        $target = is_array($prepared_result['target'] ?? null) ? $prepared_result['target'] : array();
        $requested = is_array($prepared_result['requested'] ?? null) ? $prepared_result['requested'] : array();
        $apply_allowed = ! empty($prepared_result['apply_allowed']);

        $source_revision = $this->revision_fingerprint();
        if (is_wp_error($source_revision)) { return $source_revision; }
        $risk = $this->preview_risk($preview ?: $prepared);
        $confirmation_class = 'evidence-required' === $risk ? 'evidence-explicit' : 'explicit';
        $now = time();
        $reason = sanitize_text_field((string) ($payload['reason'] ?? $this->default_reason($operation, $target)));
        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $request_id = sanitize_text_field((string) ($actor['request_id'] ?? ''));
        $blueprint_sha = 'greenfield-canonical' === $operation ? $this->blueprint_sha() : '';
        $contract_fingerprint = $this->contract_fingerprint();
        $material = array(
            'operation'=>$operation,
            'source_revision'=>$source_revision,
            'blueprint_sha256'=>$blueprint_sha,
            'contract_fingerprint'=>$contract_fingerprint,
            'prepared'=>$this->stable_value($prepared),
            'target'=>$target,
            'requested'=>$requested,
            'reason'=>$reason,
            'connection_id'=>$connection_id,
        );
        $checksum = hash('sha256', (string) wp_json_encode($material));
        $plan_id = 'erm-remote-plan-' . substr($checksum, 0, 20) . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
        $plan = array(
            'plan_id'=>$plan_id,
            'operation'=>$operation,
            'reason'=>$reason,
            'risk'=>$risk,
            'confirmation_class'=>$confirmation_class,
            'confirmation_required'=>true,
            'source_revision'=>$source_revision,
            'blueprint_sha256'=>$blueprint_sha,
            'contract_fingerprint'=>$contract_fingerprint,
            'target'=>$target,
            'requested'=>$requested,
            'prepared'=>$prepared,
            'preview'=>$preview,
            'apply_allowed'=>$apply_allowed,
            'created_at'=>gmdate(DATE_W3C, $now),
            'expires_at'=>gmdate(DATE_W3C, $now + self::PLAN_TTL),
            'expires_unix'=>$now + self::PLAN_TTL,
            'status'=>'planned',
            'connection_id'=>$connection_id,
            'created_by'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'created_request_id'=>$request_id,
            'checksum'=>$checksum,
        );
        $this->put_plan($plan);
        $this->audit->record(array(
            'request_id'=>$request_id,
            'connection_id'=>$connection_id,
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'event'=>'remote-plan-created',
            'outcome'=>'success',
            'scope'=>'operations.apply',
            'plan_id'=>$plan_id,
            'operation'=>$operation,
            'reason'=>$reason,
        ));
        return $this->public_plan($plan);
    }

    public function get_plan(string $plan_id): array|WP_Error {
        $plan = $this->find_plan($plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Remote Manager plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put_plan($plan);
        }
        return $this->public_plan($plan);
    }

    public function apply(string $plan_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return $this->error('capability_unavailable', 'The local WordPress execution identity cannot apply Manager operations.', 403);
        }
        $plan = $this->find_plan($plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Remote Manager plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This plan belongs to a different remote connection.', 403);
        }
        if ('planned' !== (string) ($plan['status'] ?? '')) {
            if ('applied' === (string) ($plan['status'] ?? '') && '' !== (string) ($plan['operation_id'] ?? '')) {
                return $this->get_operation((string) $plan['operation_id']);
            }
            return $this->error('validation_failed', 'Only a planned operation can be applied.', 409);
        }
        if (time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put_plan($plan);
            return $this->error('plan_expired', 'Remote Manager plan has expired. Create a fresh plan.', 409);
        }
        if (empty($plan['apply_allowed'])) {
            return $this->error('validation_failed', 'The stored Preview does not permit Apply.', 409);
        }
        if (! $confirmed) {
            return $this->error('confirmation_required', 'Exact-plan Apply requires explicit confirmation.', 409, array('confirmation_class'=>(string) ($plan['confirmation_class'] ?? 'explicit')));
        }

        $revision = $this->revision_fingerprint();
        if (is_wp_error($revision)) { return $revision; }
        if (! hash_equals((string) $plan['source_revision'], $revision)) {
            return $this->error('stale_revision', 'The managed WordPress state changed after Preview. Create a new plan before Apply.', 409, array(
                'planned_revision'=>(string) $plan['source_revision'],
                'current_revision'=>$revision,
            ));
        }
        if (! hash_equals((string) ($plan['contract_fingerprint'] ?? ''), $this->contract_fingerprint())) {
            return $this->error('stale_revision', 'The active Research Theme contract changed after Preview. Create a fresh plan.', 409);
        }

        $operation_type = (string) ($plan['operation'] ?? '');
        if ('greenfield-canonical' === $operation_type) {
            $blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
            if (is_wp_error($blueprint)) { return $blueprint; }
            if (! hash_equals((string) ($plan['blueprint_sha256'] ?? ''), $this->blueprint_sha())) {
                return $this->error('stale_revision', 'The canonical Research blueprint changed after Preview. Create a fresh plan.', 409);
            }
            $result = $this->pipeline->apply($blueprint);
        } elseif ('page-slots-update' === $operation_type) {
            $result = $this->page_editor->apply_preview((array) ($plan['prepared'] ?? array()));
        } elseif ('page-create' === $operation_type) {
            $result = $this->page_editor->apply_creation_preview((array) ($plan['prepared'] ?? array()));
        } elseif ('page-remediate' === $operation_type) {
            $prepared_plan = is_array($plan['prepared']['plan'] ?? null) ? $plan['prepared']['plan'] : array();
            if (! $prepared_plan) { return $this->error('validation_failed', 'Stored Page remediation plan is incomplete.', 409); }
            $result = Eduardo_Research_Manager::executor()->apply($prepared_plan);
            if (! is_wp_error($result)) { $result['verified'] = ! empty($result['verification']['verified']); }
        } elseif ($this->is_insight_operation($operation_type)) {
            $result = $this->insight_editor->apply_preview((array) ($plan['prepared'] ?? array()));
        } else {
            return $this->error('validation_failed', 'Stored remote operation type is unsupported.', 409);
        }
        if (is_wp_error($result)) { return $this->normalise_service_error($result, 409); }

        $operation_id = 'erm-operation-' . str_replace('-', '', wp_generate_uuid4());
        $operation = array(
            'operation_id'=>$operation_id,
            'plan_id'=>$plan_id,
            'operation'=>$operation_type,
            'reason'=>(string) ($plan['reason'] ?? ''),
            'risk'=>(string) ($plan['risk'] ?? 'standard'),
            'target'=>is_array($plan['target'] ?? null) ? $plan['target'] : array(),
            'requested'=>is_array($plan['requested'] ?? null) ? $plan['requested'] : array(),
            'source_revision'=>(string) $plan['source_revision'],
            'post_apply_revision'=>'',
            'contract_fingerprint'=>(string) ($plan['contract_fingerprint'] ?? ''),
            'blueprint_sha256'=>(string) ($plan['blueprint_sha256'] ?? ''),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'apply_request_id'=>(string) ($actor['request_id'] ?? ''),
            'status'=>'applied',
            'result'=>$result,
            'snapshots'=>is_array($result['snapshots'] ?? null) ? $result['snapshots'] : array(),
            'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),
            'stored_verification'=>array(),
            'rendered_verification'=>array(),
            'created_at'=>gmdate(DATE_W3C),
            'applied_at'=>gmdate(DATE_W3C),
            'verified_at'=>'',
            'rolled_back_at'=>'',
        );
        if ('insight-create' === $operation_type && ! empty($result['post_id'])) {
            $operation['target']['post_id'] = (int) $result['post_id'];
        }
        $post_revision = $this->revision_fingerprint();
        $operation['post_apply_revision'] = is_wp_error($post_revision) ? '' : $post_revision;
        $this->put_operation($operation);

        $plan['status'] = 'applied';
        $plan['operation_id'] = $operation_id;
        $plan['applied_at'] = gmdate(DATE_W3C);
        $this->put_plan($plan);
        $this->audit->record(array(
            'request_id'=>(string) ($actor['request_id'] ?? ''),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'event'=>'remote-operation-applied',
            'outcome'=>'success',
            'scope'=>'operations.apply',
            'plan_id'=>$plan_id,
            'operation_id'=>$operation_id,
            'operation'=>$operation_type,
            'reason'=>(string) ($plan['reason'] ?? ''),
        ));
        return $this->public_operation($operation);
    }

    public function get_operation(string $operation_id): array|WP_Error {
        $operation = $this->find_operation($operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Remote Manager operation was not found.', 404); }
        return $this->public_operation($operation);
    }

    public function verify(string $operation_id, bool $include_rendered, array $actor): array|WP_Error {
        $operation = $this->find_operation($operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Remote Manager operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This operation belongs to a different remote connection.', 403);
        }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            return $this->error('validation_failed', 'Only applied operations can be verified.', 409);
        }

        $current_revision = $this->revision_fingerprint();
        if (is_wp_error($current_revision)) { return $current_revision; }
        $post_apply_revision = (string) ($operation['post_apply_revision'] ?? '');
        $revision_matches = '' !== $post_apply_revision && hash_equals($post_apply_revision, $current_revision);
        $contract_matches = '' !== (string) ($operation['contract_fingerprint'] ?? '')
            && hash_equals((string) $operation['contract_fingerprint'], $this->contract_fingerprint());
        $apply_verified = ! empty($operation['result']['verified']);
        $type = (string) ($operation['operation'] ?? '');
        $semantic = array('verified'=>$apply_verified);
        $extra = array();

        if ('greenfield-canonical' === $type) {
            $diagnostics = Eduardo_Research_Manager::diagnostics()->run();
            $blueprint_matches = '' !== (string) ($operation['blueprint_sha256'] ?? '')
                && hash_equals((string) $operation['blueprint_sha256'], $this->blueprint_sha());
            $ready = ! empty($diagnostics['ready']);
            $semantic = array('verified'=>$apply_verified && $ready);
            $extra = array(
                'blueprint_matches'=>$blueprint_matches,
                'readiness'=>array(
                    'ready'=>$ready,
                    'summary'=>is_array($diagnostics['summary'] ?? null) ? $diagnostics['summary'] : array(),
                ),
            );
            $stored_verified = $revision_matches && $contract_matches && $blueprint_matches && ! empty($semantic['verified']);
        } elseif ('page-slots-update' === $type) {
            $target = (array) ($operation['target'] ?? array());
            $semantic = $this->page_editor->verify_slots(
                (string) ($target['key'] ?? ''),
                (string) ($target['language'] ?? ''),
                (array) ($operation['requested']['slots'] ?? array())
            );
            if (is_wp_error($semantic)) { return $this->normalise_service_error($semantic, 409); }
            $stored_verified = $revision_matches && $contract_matches && $apply_verified && ! empty($semantic['verified']);
        } elseif ('page-create' === $type) {
            $target = (array) ($operation['target'] ?? array());
            $semantic = $this->page_editor->verify_creation(
                (string) ($target['key'] ?? ''),
                (string) ($operation['result']['creation_token'] ?? '')
            );
            if (is_wp_error($semantic)) { return $this->normalise_service_error($semantic, 409); }
            $stored_verified = $revision_matches && $contract_matches && $apply_verified && ! empty($semantic['verified']);
        } elseif ('page-remediate' === $type) {
            $check_id = sanitize_key((string) ($operation['requested']['check_id'] ?? ''));
            $semantic = Eduardo_Research_Manager::remediation()->verify($check_id);
            if (is_wp_error($semantic)) { return $this->normalise_service_error($semantic, 409); }
            $stored_verified = $revision_matches && $contract_matches && $apply_verified && ! empty($semantic['verified']);
        } elseif ($this->is_insight_operation($type)) {
            $post_id = absint($operation['target']['post_id'] ?? $operation['result']['post_id'] ?? 0);
            $expected = 'insight-create' === $type
                ? (array) ($operation['requested']['data'] ?? array())
                : (array) ($operation['requested']['changes'] ?? array());
            if ($post_id <= 0 || ! $expected) {
                return $this->error('validation_failed', 'Stored Insight operation is missing its verification target.', 409);
            }
            $semantic = Eduardo_Research_Manager::insights()->verify($post_id, $expected);
            if (is_wp_error($semantic)) { return $this->normalise_service_error($semantic, 409); }
            $stored_verified = $revision_matches && $contract_matches && $apply_verified && ! empty($semantic['verified']);
        } else {
            return $this->error('validation_failed', 'Stored remote operation type is unsupported.', 409);
        }

        $stored = array_merge(array(
            'verified'=>$stored_verified,
            'revision_matches'=>$revision_matches,
            'contract_matches'=>$contract_matches,
            'apply_verified'=>$apply_verified,
            'expected_revision'=>$post_apply_revision,
            'current_revision'=>$current_revision,
            'semantic'=>$semantic,
            'verified_at'=>gmdate(DATE_W3C),
        ), $extra);

        $rendered = array('requested'=>$include_rendered,'verified'=>null,'resources'=>array());
        if ($include_rendered) {
            $rendered = $this->verify_rendered_operation($operation);
            if (is_wp_error($rendered)) { return $rendered; }
        }

        $verified = ! empty($stored['verified']) && (! $include_rendered || ! empty($rendered['verified']));
        $operation['stored_verification'] = $stored;
        $operation['rendered_verification'] = $rendered;
        $operation['verified_at'] = gmdate(DATE_W3C);
        $operation['status'] = $verified ? 'verified' : 'verification-failed';
        $this->put_operation($operation);
        $this->audit->record(array(
            'request_id'=>(string) ($actor['request_id'] ?? ''),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'event'=>'remote-operation-verified',
            'outcome'=>$verified ? 'success' : 'failed',
            'scope'=>'site.diagnostics',
            'plan_id'=>(string) ($operation['plan_id'] ?? ''),
            'operation_id'=>$operation_id,
            'operation'=>$type,
        ));
        return $this->public_operation($operation);
    }

    public function rollback(string $operation_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return $this->error('capability_unavailable', 'The local WordPress execution identity cannot rollback Manager operations.', 403);
        }
        $operation = $this->find_operation($operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Remote Manager operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This operation belongs to a different remote connection.', 403);
        }
        if (! $confirmed) {
            return $this->error('confirmation_required', 'Rollback requires explicit confirmation.', 409, array('confirmation_class'=>'explicit'));
        }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            if ('rolled-back' === (string) ($operation['status'] ?? '')) { return $this->public_operation($operation); }
            return $this->error('rollback_unavailable', 'This operation is not in a rollback-capable state.', 409);
        }

        $type = (string) ($operation['operation'] ?? '');
        if ('greenfield-canonical' === $type) {
            $snapshots = is_array($operation['snapshots'] ?? null) ? $operation['snapshots'] : array();
            if (! $this->has_snapshots($snapshots)) {
                return $this->error('rollback_unavailable', 'No rollback snapshots are available for this operation.', 409);
            }
            $result = $this->pipeline->rollback($snapshots);
        } else {
            $snapshot_id = (string) ($operation['snapshot_id'] ?? '');
            if ('' === $snapshot_id) {
                return $this->error('rollback_unavailable', 'No rollback snapshot is available for this operation.', 409);
            }
            $result = $this->is_insight_operation($type)
                ? $this->insight_editor->rollback($snapshot_id)
                : $this->page_editor->rollback($snapshot_id);
        }
        if (is_wp_error($result)) { return $this->normalise_service_error($result, 409); }

        $revision = $this->revision_fingerprint();
        $operation['status'] = 'rolled-back';
        $operation['rollback_result'] = $result;
        $operation['rolled_back_at'] = gmdate(DATE_W3C);
        $operation['post_rollback_revision'] = is_wp_error($revision) ? '' : $revision;
        $operation['rollback_restored_source_revision'] = ! is_wp_error($revision) && hash_equals((string) ($operation['source_revision'] ?? ''), $revision);
        $this->put_operation($operation);
        $this->audit->record(array(
            'request_id'=>(string) ($actor['request_id'] ?? ''),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'event'=>'remote-operation-rolled-back',
            'outcome'=>'success',
            'scope'=>'operations.rollback',
            'plan_id'=>(string) ($operation['plan_id'] ?? ''),
            'operation_id'=>$operation_id,
            'operation'=>$type,
        ));
        return $this->public_operation($operation);
    }

    public function revision_fingerprint(): string|WP_Error {
        $blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
        if (is_wp_error($blueprint)) { return $blueprint; }
        $preview = $this->pipeline->preview($blueprint);
        $preview_state = is_wp_error($preview)
            ? array(
                'degraded'=>true,
                'error_code'=>$preview->get_error_code(),
                'error_message'=>$preview->get_error_message(),
                'error_data'=>$this->stable_value($preview->get_error_data()),
            )
            : array(
                'degraded'=>false,
                'preview'=>$this->stable_value($preview),
            );
        $diagnostics = Eduardo_Research_Manager::diagnostics()->run();
        $state = array(
            'blueprint_sha256'=>$this->blueprint_sha(),
            'contract_fingerprint'=>$this->contract_fingerprint(),
            'mode'=>Eduardo_Research_Manager_Mode::current(),
            'pipeline_state'=>$preview_state,
            'diagnostics'=>$this->stable_value($diagnostics),
        );
        return hash('sha256', (string) wp_json_encode($state));
    }

    private function prepare(string $operation, array $payload): array|WP_Error {
        if ('greenfield-canonical' === $operation) {
            $blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
            if (is_wp_error($blueprint)) { return $blueprint; }
            $preview = $this->pipeline->preview($blueprint);
            if (is_wp_error($preview)) { return $preview; }
            return array(
                'prepared'=>array(),
                'preview'=>$preview,
                'target'=>array('resource'=>'site'),
                'requested'=>array(),
                'apply_allowed'=>! empty($preview['apply_allowed']),
            );
        }

        if ('insight-create' === $operation) {
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : array();
            if (! $data) { return $this->error('validation_failed', 'insight-create requires a bounded Insight data object.', 400); }
            $prepared = $this->insight_editor->preview_create($data);
            if (is_wp_error($prepared)) { return $prepared; }
            $expected = is_array($prepared['expected'] ?? null) ? $prepared['expected'] : array();
            return array(
                'prepared'=>$prepared,
                'preview'=>is_array($prepared['preview'] ?? null) ? $prepared['preview'] : array(),
                'target'=>array('resource'=>'insight','post_id'=>0,'language'=>(string) ($expected['language'] ?? 'en')),
                'requested'=>array('data'=>$expected),
                'apply_allowed'=>! empty($prepared['apply_allowed']),
            );
        }

        if ('insight-update' === $operation) {
            $post_id = absint($payload['post_id'] ?? 0);
            $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : array();
            if ($post_id <= 0 || ! $changes) { return $this->error('validation_failed', 'insight-update requires a managed Insight post_id and at least one bounded change.', 400); }
            $before = $this->insight_editor->inspect($post_id);
            if (is_wp_error($before)) { return $before; }
            $prepared = $this->insight_editor->preview_update($post_id, $changes);
            if (is_wp_error($prepared)) { return $prepared; }
            $expected = is_array($prepared['expected'] ?? null) ? $prepared['expected'] : array();
            return array(
                'prepared'=>$prepared,
                'preview'=>is_array($prepared['preview'] ?? null) ? $prepared['preview'] : array(),
                'target'=>array('resource'=>'insight','post_id'=>$post_id,'language'=>(string) ($before['language'] ?? 'en')),
                'requested'=>array('changes'=>$expected),
                'apply_allowed'=>! empty($prepared['apply_allowed']),
            );
        }

        $key = sanitize_key((string) ($payload['key'] ?? ''));
        if ('' === $key) { return $this->error('validation_failed', 'A Theme Page key is required.', 400); }
        if (! isset(Eduardo_Research_Manager::contract()->pages()[$key])) {
            return $this->error('validation_failed', 'The requested Page key is outside the active Research preset.', 400);
        }
        if ('page-create' === $operation) {
            $prepared = $this->page_editor->preview_creation($key);
            if (is_wp_error($prepared)) { return $prepared; }
            return array(
                'prepared'=>$prepared,
                'preview'=>is_array($prepared['preview'] ?? null) ? $prepared['preview'] : array(),
                'target'=>array('resource'=>'page','key'=>$key,'languages'=>Eduardo_Research_Manager::contract()->languages()),
                'requested'=>array(),
                'apply_allowed'=>! empty($prepared['apply_allowed']),
            );
        }
        if ('page-remediate' === $operation) {
            $check_id = sanitize_key((string) ($payload['check_id'] ?? ('page-' . $key)));
            $allowed_checks = array('page-' . $key);
            if ('home' === $key) { $allowed_checks[] = 'front-page'; }
            if (! in_array($check_id, $allowed_checks, true)) {
                return $this->error('validation_failed', 'Page remediation may target only the Page structural check, plus front-page routing for Research Home.', 400);
            }
            $plan = Eduardo_Research_Manager::remediation()->build_plan(
                $check_id,
                sanitize_text_field((string) ($payload['reason'] ?? $this->default_reason($operation, array('key'=>$key))))
            );
            if (is_wp_error($plan)) { return $plan; }
            $preview = Eduardo_Research_Manager::executor()->preview($plan);
            if (is_wp_error($preview)) { return $preview; }
            return array(
                'prepared'=>array('plan'=>$plan,'check_id'=>$check_id),
                'preview'=>$preview,
                'target'=>array('resource'=>'page','key'=>$key,'languages'=>Eduardo_Research_Manager::contract()->languages()),
                'requested'=>array('check_id'=>$check_id),
                'apply_allowed'=>! empty($preview['apply_allowed']),
            );
        }

        $language = sanitize_key((string) ($payload['language'] ?? 'en'));
        $slots = is_array($payload['slots'] ?? null) ? $payload['slots'] : array();
        if (! $slots) { return $this->error('validation_failed', 'page-slots-update requires at least one Theme-owned slot.', 400); }
        $prepared = $this->page_editor->preview($key, $language, $slots);
        if (is_wp_error($prepared)) { return $prepared; }
        return array(
            'prepared'=>$prepared,
            'preview'=>is_array($prepared['preview'] ?? null) ? $prepared['preview'] : array(),
            'target'=>array('resource'=>'page','key'=>$key,'language'=>$language),
            'requested'=>array('slots'=>$slots),
            'apply_allowed'=>! empty($prepared['apply_allowed']),
        );
    }

    private function verify_rendered_operation(array $operation): array|WP_Error {
        $type = (string) ($operation['operation'] ?? '');
        if ('greenfield-canonical' === $type) { return $this->verify_rendered_site(); }
        if ($this->is_insight_operation($type)) {
            $post_id = absint($operation['target']['post_id'] ?? $operation['result']['post_id'] ?? 0);
            if ($post_id <= 0) { return $this->error('validation_failed', 'Insight operation is missing its rendered verification target.', 409); }
            $record = $this->insight_editor->inspect($post_id);
            if (is_wp_error($record)) { return $this->normalise_service_error($record, 409); }
            if ('publish' !== (string) ($record['status'] ?? '')) {
                return array(
                    'requested'=>true,
                    'verified'=>true,
                    'resources'=>array(array(
                        'resource'=>'insight:' . $post_id,
                        'post_id'=>$post_id,
                        'verified'=>true,
                        'skipped'=>true,
                        'reason'=>'draft-or-non-public',
                    )),
                    'verified_at'=>gmdate(DATE_W3C),
                );
            }
            $result = Eduardo_Research_Manager::rendered()->verify_record($post_id);
            if (is_wp_error($result)) {
                return array(
                    'requested'=>true,
                    'verified'=>false,
                    'resources'=>array(array(
                        'resource'=>'insight:' . $post_id,
                        'post_id'=>$post_id,
                        'verified'=>false,
                        'error_code'=>$result->get_error_code(),
                        'error'=>$result->get_error_message(),
                    )),
                    'verified_at'=>gmdate(DATE_W3C),
                );
            }
            unset($result['body']);
            return array('requested'=>true,'verified'=>! empty($result['verified']),'resources'=>array($result),'verified_at'=>gmdate(DATE_W3C));
        }

        $target = is_array($operation['target'] ?? null) ? $operation['target'] : array();
        $key = sanitize_key((string) ($target['key'] ?? ''));
        if ('' === $key) { return $this->error('validation_failed', 'Page operation is missing its rendered verification target.', 409); }
        $languages = in_array($type, array('page-create','page-remediate'), true)
            ? Eduardo_Research_Manager::contract()->languages()
            : array(sanitize_key((string) ($target['language'] ?? 'en')));
        $resources = array();
        $verified = true;
        foreach ($languages as $language) {
            $result = Eduardo_Research_Manager::rendered()->verify_page($key, (string) $language);
            if (is_wp_error($result)) {
                $resources[] = array('resource'=>'page:' . $key,'language'=>$language,'verified'=>false,'error_code'=>$result->get_error_code(),'error'=>$result->get_error_message());
                $verified = false;
                continue;
            }
            unset($result['body']);
            $resources[] = $result;
            $verified = $verified && ! empty($result['verified']);
        }
        return array('requested'=>true,'verified'=>$verified,'resources'=>$resources,'verified_at'=>gmdate(DATE_W3C));
    }

    private function verify_rendered_site(): array|WP_Error {
        $resources = array();
        $verified = true;
        foreach (Eduardo_Research_Manager::contract()->pages() as $key => $definition) {
            foreach (Eduardo_Research_Manager::contract()->languages() as $language) {
                $result = Eduardo_Research_Manager::rendered()->verify_page((string) $key, (string) $language);
                if (is_wp_error($result)) {
                    $resources[] = array('resource'=>'page:' . $key,'language'=>$language,'verified'=>false,'error_code'=>$result->get_error_code(),'error'=>$result->get_error_message());
                    $verified = false;
                    continue;
                }
                unset($result['body']);
                $resources[] = $result;
                $verified = $verified && ! empty($result['verified']);
            }
        }
        return array('requested'=>true,'verified'=>$verified,'resources'=>$resources,'verified_at'=>gmdate(DATE_W3C));
    }

    private function preview_risk(array $preview): string {
        $risk = 'standard';
        $walk = function (mixed $value) use (&$walk, &$risk): void {
            if (! is_array($value)) { return; }
            foreach ($value as $key => $item) {
                if ('risk' === $key && is_scalar($item)) {
                    if ('evidence-required' === (string) $item) { $risk = 'evidence-required'; }
                    elseif ('standard' === $risk && 'editorial-review' === (string) $item) { $risk = 'editorial-review'; }
                }
                if (is_array($item)) { $walk($item); }
            }
        };
        $walk($preview);
        return $risk;
    }

    private function default_reason(string $operation, array $target): string {
        if ('page-slots-update' === $operation) {
            return sprintf('Remote structured Page update: %s (%s)', (string) ($target['key'] ?? ''), strtoupper((string) ($target['language'] ?? 'en')));
        }
        if ('page-create' === $operation) {
            return sprintf('Remote recovery of missing Theme Page: %s', (string) ($target['key'] ?? ''));
        }
        if ('page-remediate' === $operation) {
            return sprintf('Remote bounded Page readiness remediation: %s', (string) ($target['key'] ?? ''));
        }
        if ('insight-create' === $operation) {
            return 'Remote creation of bounded Research Insight';
        }
        if ('insight-update' === $operation) {
            return sprintf('Remote bounded Research Insight update: #%d', absint($target['post_id'] ?? 0));
        }
        return 'Remote canonical Greenfield operation';
    }

    private function is_insight_operation(string $type): bool {
        return in_array($type, array('insight-create','insight-update'), true);
    }

    private function blueprint_sha(): string {
        $metadata = Eduardo_Research_Manager::blueprint_store()->metadata();
        return is_wp_error($metadata) ? '' : (string) ($metadata['sha256'] ?? '');
    }

    private function contract_fingerprint(): string {
        $preset = Eduardo_Research_Manager::contract()->preset();
        $state = array(
            'theme_version'=>Eduardo_Research_Manager::contract()->theme_version(),
            'preset_id'=>(string) ($preset['id'] ?? ''),
            'preset_version'=>(int) ($preset['version'] ?? 0),
            'languages'=>Eduardo_Research_Manager::contract()->languages(),
            'pages'=>Eduardo_Research_Manager::contract()->pages(),
        );
        return hash('sha256', (string) wp_json_encode($this->stable_value($state)));
    }

    private function stable_value(mixed $value): mixed {
        if (! is_array($value)) { return $value; }
        $result = array();
        foreach ($value as $key => $item) {
            if (in_array((string) $key, array(
                'created_at','generated_at','verified_at','applied_at','rolled_back_at',
                'snapshot_id','snapshot_ids','plan_id','checksum','creation_token'
            ), true)) { continue; }
            $result[$key] = $this->stable_value($item);
        }
        if (array_is_list($result)) { return array_values($result); }
        ksort($result);
        return $result;
    }

    private function public_plan(array $plan): array {
        unset($plan['expires_unix'], $plan['prepared']);
        return $plan;
    }
    private function public_operation(array $operation): array { return $operation; }
    private function find_plan(string $plan_id): array { $plans = $this->plans(); $plan = $plans[sanitize_text_field($plan_id)] ?? array(); return is_array($plan) ? $plan : array(); }
    private function find_operation(string $operation_id): array { $operations = $this->operations(); $operation = $operations[sanitize_text_field($operation_id)] ?? array(); return is_array($operation) ? $operation : array(); }
    private function put_plan(array $plan): void { $plans = $this->plans(); $plans[(string) $plan['plan_id']] = $plan; while (count($plans) > self::PLAN_LIMIT) { array_shift($plans); } $this->save_option(self::PLANS_OPTION, $plans); }
    private function put_operation(array $operation): void { $operations = $this->operations(); $operations[(string) $operation['operation_id']] = $operation; while (count($operations) > self::OPERATION_LIMIT) { array_shift($operations); } $this->save_option(self::OPERATIONS_OPTION, $operations); }
    private function plans(): array { $plans = get_option(self::PLANS_OPTION, array()); return is_array($plans) ? $plans : array(); }
    private function operations(): array { $operations = get_option(self::OPERATIONS_OPTION, array()); return is_array($operations) ? $operations : array(); }
    private function save_option(string $name, array $value): void { if (false === get_option($name, false)) { add_option($name, $value, '', false); return; } update_option($name, $value, false); }
    private function has_snapshots(array $snapshots): bool { foreach ($snapshots as $ids) { if (is_array($ids) && $ids) { return true; } } return false; }
    private function normalise_service_error(WP_Error $error, int $status): WP_Error {
        $data = $error->get_error_data();
        if (! is_array($data) || ! isset($data['status'])) {
            $error->add_data(array_merge(is_array($data) ? $data : array(), array('status'=>$status)));
        }
        return $error;
    }
    private function error(string $code, string $message, int $status, array $extra = array()): WP_Error { return new WP_Error($code, $message, array_merge(array('status'=>$status), $extra)); }
}
