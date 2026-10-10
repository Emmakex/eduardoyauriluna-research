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

    public function __construct(
        ?Eduardo_Research_Manager_Greenfield_Pipeline $pipeline = null,
        ?Eduardo_Research_Manager_Remote_Audit $audit = null
    ) {
        $this->pipeline = $pipeline ?: Eduardo_Research_Manager::pipeline();
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
    }

    public function create_plan(string $operation, array $payload, array $actor): array|WP_Error {
        $operation = sanitize_key($operation);
        if ('greenfield-canonical' !== $operation) {
            return $this->error('validation_failed', 'M2 only permits the bounded greenfield-canonical operation. Page/content operations are introduced in later milestones.', 400);
        }

        $blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
        if (is_wp_error($blueprint)) { return $blueprint; }
        $preview = $this->pipeline->preview($blueprint);
        if (is_wp_error($preview)) { return $preview; }

        $source_revision = $this->revision_fingerprint();
        if (is_wp_error($source_revision)) { return $source_revision; }
        $risk = $this->preview_risk($preview);
        $confirmation_class = 'evidence-required' === $risk ? 'evidence-explicit' : 'explicit';
        $now = time();
        $reason = sanitize_text_field((string) ($payload['reason'] ?? 'Remote canonical Greenfield operation'));
        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $request_id = sanitize_text_field((string) ($actor['request_id'] ?? ''));
        $material = array(
            'operation'=>$operation,
            'source_revision'=>$source_revision,
            'blueprint_sha256'=>$this->blueprint_sha(),
            'preview'=>$this->stable_value($preview),
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
            'blueprint_sha256'=>$this->blueprint_sha(),
            'preview'=>$preview,
            'apply_allowed'=>! empty($preview['apply_allowed']),
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

        $blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
        if (is_wp_error($blueprint)) { return $blueprint; }
        if (! hash_equals((string) ($plan['blueprint_sha256'] ?? ''), $this->blueprint_sha())) {
            return $this->error('stale_revision', 'The canonical Research blueprint changed after Preview. Create a fresh plan.', 409);
        }

        $result = $this->pipeline->apply($blueprint);
        if (is_wp_error($result)) { return $result; }
        $operation_id = 'erm-operation-' . str_replace('-', '', wp_generate_uuid4());
        $operation = array(
            'operation_id'=>$operation_id,
            'plan_id'=>$plan_id,
            'operation'=>(string) $plan['operation'],
            'reason'=>(string) ($plan['reason'] ?? ''),
            'risk'=>(string) ($plan['risk'] ?? 'standard'),
            'source_revision'=>(string) $plan['source_revision'],
            'post_apply_revision'=>'',
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'apply_request_id'=>(string) ($actor['request_id'] ?? ''),
            'status'=>'applied',
            'result'=>$result,
            'snapshots'=>is_array($result['snapshots'] ?? null) ? $result['snapshots'] : array(),
            'stored_verification'=>array(),
            'rendered_verification'=>array(),
            'created_at'=>gmdate(DATE_W3C),
            'applied_at'=>gmdate(DATE_W3C),
            'verified_at'=>'',
            'rolled_back_at'=>'',
        );
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

        $blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
        if (is_wp_error($blueprint)) { return $blueprint; }
        $preview = $this->pipeline->preview($blueprint);
        if (is_wp_error($preview)) { return $preview; }
        $diagnostics = Eduardo_Research_Manager::diagnostics()->run();
        $pending = $this->preview_change_count($preview);
        $stored = array(
            'verified'=>0 === $pending && ! empty($diagnostics['ready']),
            'pending_operation_count'=>$pending,
            'readiness'=>array(
                'ready'=>! empty($diagnostics['ready']),
                'summary'=>is_array($diagnostics['summary'] ?? null) ? $diagnostics['summary'] : array(),
            ),
            'verified_at'=>gmdate(DATE_W3C),
        );

        $rendered = array('requested'=>$include_rendered,'verified'=>null,'resources'=>array());
        if ($include_rendered) {
            $rendered = $this->verify_rendered_site();
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
        $snapshots = is_array($operation['snapshots'] ?? null) ? $operation['snapshots'] : array();
        if (! $this->has_snapshots($snapshots)) {
            return $this->error('rollback_unavailable', 'No rollback snapshots are available for this operation.', 409);
        }

        $result = $this->pipeline->rollback($snapshots);
        if (is_wp_error($result)) { return $result; }
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
        ));
        return $this->public_operation($operation);
    }

    public function revision_fingerprint(): string|WP_Error {
        $blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
        if (is_wp_error($blueprint)) { return $blueprint; }
        $preview = $this->pipeline->preview($blueprint);
        if (is_wp_error($preview)) { return $preview; }
        $diagnostics = Eduardo_Research_Manager::diagnostics()->run();
        $state = array(
            'blueprint_sha256'=>$this->blueprint_sha(),
            'mode'=>Eduardo_Research_Manager_Mode::current(),
            'preview'=>$this->stable_value($preview),
            'diagnostics'=>$this->stable_value($diagnostics),
        );
        return hash('sha256', (string) wp_json_encode($state));
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

    private function preview_change_count(array $preview): int {
        $count = 0;
        foreach ((array) ($preview['phases'] ?? array()) as $phase) {
            if ('deferred' === (string) ($phase['status'] ?? '')) { continue; }
            $count += max(0, (int) ($phase['operation_count'] ?? 0));
        }
        return $count;
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

    private function blueprint_sha(): string {
        $metadata = Eduardo_Research_Manager::blueprint_store()->metadata();
        return is_wp_error($metadata) ? '' : (string) ($metadata['sha256'] ?? '');
    }

    private function stable_value(mixed $value): mixed {
        if (! is_array($value)) { return $value; }
        $result = array();
        foreach ($value as $key => $item) {
            if (in_array((string) $key, array('created_at','generated_at','verified_at','applied_at','rolled_back_at','snapshot_id','snapshot_ids'), true)) { continue; }
            $result[$key] = $this->stable_value($item);
        }
        if (array_is_list($result)) { return array_values($result); }
        ksort($result);
        return $result;
    }

    private function public_plan(array $plan): array { unset($plan['expires_unix']); return $plan; }
    private function public_operation(array $operation): array { return $operation; }
    private function find_plan(string $plan_id): array { $plans = $this->plans(); $plan = $plans[sanitize_text_field($plan_id)] ?? array(); return is_array($plan) ? $plan : array(); }
    private function find_operation(string $operation_id): array { $operations = $this->operations(); $operation = $operations[sanitize_text_field($operation_id)] ?? array(); return is_array($operation) ? $operation : array(); }
    private function put_plan(array $plan): void { $plans = $this->plans(); $plans[(string) $plan['plan_id']] = $plan; while (count($plans) > self::PLAN_LIMIT) { array_shift($plans); } $this->save_option(self::PLANS_OPTION, $plans); }
    private function put_operation(array $operation): void { $operations = $this->operations(); $operations[(string) $operation['operation_id']] = $operation; while (count($operations) > self::OPERATION_LIMIT) { array_shift($operations); } $this->save_option(self::OPERATIONS_OPTION, $operations); }
    private function plans(): array { $plans = get_option(self::PLANS_OPTION, array()); return is_array($plans) ? $plans : array(); }
    private function operations(): array { $operations = get_option(self::OPERATIONS_OPTION, array()); return is_array($operations) ? $operations : array(); }
    private function save_option(string $name, array $value): void { if (false === get_option($name, false)) { add_option($name, $value, '', false); return; } update_option($name, $value, false); }
    private function has_snapshots(array $snapshots): bool { foreach ($snapshots as $ids) { if (is_array($ids) && $ids) { return true; } } return false; }
    private function error(string $code, string $message, int $status, array $extra = array()): WP_Error { return new WP_Error($code, $message, array_merge(array('status'=>$status), $extra)); }
}
