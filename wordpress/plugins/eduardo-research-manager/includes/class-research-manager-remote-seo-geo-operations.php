<?php
/** Exact remote lifecycle for deterministic SEO/GEO readiness remediation. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_SEO_GEO_Operations {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_seo_geo_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_seo_geo_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;
    private const LIMIT = 80;

    private Eduardo_Research_Manager_Remediation $remediation;
    private Eduardo_Research_Manager_Executor $executor;
    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(
        ?Eduardo_Research_Manager_Remediation $remediation = null,
        ?Eduardo_Research_Manager_Executor $executor = null,
        ?Eduardo_Research_Manager_Remote_Audit $audit = null
    ) {
        $this->remediation = $remediation ?: Eduardo_Research_Manager::remediation();
        $this->executor = $executor ?: Eduardo_Research_Manager::executor();
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
    }

    public function create_plan(string $check_id, string $intent, array $actor): array|WP_Error {
        $check_id = sanitize_key($check_id);
        if ('' === $check_id) { return $this->error('validation_failed', 'SEO/GEO remediation requires a diagnostic check ID.', 400); }
        $candidate = $this->candidate($check_id);
        if (is_wp_error($candidate)) { return $candidate; }
        $plan = $this->remediation->build_plan($check_id, sanitize_text_field($intent));
        if (is_wp_error($plan)) { return $this->normalise_error($plan, 409); }
        $preview = $this->executor->preview($plan);
        if (is_wp_error($preview)) { return $this->normalise_error($preview, 409); }

        $now = time();
        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $remote_plan_id = 'erm-seo-plan-' . substr(hash('sha256', (string) wp_json_encode(array($check_id,$plan['checksum'] ?? '',$connection_id,microtime(true)))), 0, 24);
        $stored = array(
            'plan_id'=>$remote_plan_id,
            'operation'=>'seo-remediate',
            'check_id'=>$check_id,
            'candidate'=>$candidate,
            'intent'=>(string) ($plan['intent'] ?? $intent),
            'risk'=>(string) ($plan['risk'] ?? 'low'),
            'confirmation_required'=>true,
            'confirmation_class'=>'exact-remediation',
            'apply_allowed'=>! empty($preview['apply_allowed']),
            'manager_plan'=>$plan,
            'preview'=>$preview,
            'baseline_signature'=>$this->preview_signature($preview),
            'connection_id'=>$connection_id,
            'created_by'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'created_request_id'=>(string) ($actor['request_id'] ?? ''),
            'status'=>'planned',
            'created_at'=>gmdate(DATE_W3C, $now),
            'expires_at'=>gmdate(DATE_W3C, $now + self::PLAN_TTL),
            'expires_unix'=>$now + self::PLAN_TTL,
        );
        $this->put(self::PLANS_OPTION, $remote_plan_id, $stored);
        $this->audit('remote-seo-plan-created', 'success', $stored, $actor);
        return $this->public_plan($stored);
    }

    public function get_plan(string $plan_id): array|WP_Error {
        $plan = $this->find(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'SEO/GEO remediation plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired'; $this->put(self::PLANS_OPTION, $plan_id, $plan);
        }
        return $this->public_plan($plan);
    }

    public function apply(string $plan_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) { return $this->error('capability_unavailable', 'Local WordPress execution identity cannot apply SEO/GEO remediation.', 403); }
        $plan = $this->find(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'SEO/GEO remediation plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This SEO/GEO plan belongs to a different connection.', 403); }
        if ('applied' === (string) ($plan['status'] ?? '') && '' !== (string) ($plan['operation_id'] ?? '')) { return $this->get_operation((string) $plan['operation_id']); }
        if ('planned' !== (string) ($plan['status'] ?? '')) { return $this->error('validation_failed', 'Only a planned SEO/GEO remediation can be applied.', 409); }
        if (time() > (int) ($plan['expires_unix'] ?? 0)) { $plan['status']='expired'; $this->put(self::PLANS_OPTION,$plan_id,$plan); return $this->error('plan_expired','SEO/GEO plan expired. Create a fresh Preview.',409); }
        if (! $confirmed) { return $this->error('confirmation_required', 'SEO/GEO remediation requires explicit confirmation.', 409, array('confirmation_class'=>'exact-remediation')); }
        if (empty($plan['apply_allowed'])) { return $this->error('validation_failed', 'Stored SEO/GEO Preview is not applyable.', 409); }

        $manager_plan = is_array($plan['manager_plan'] ?? null) ? $plan['manager_plan'] : array();
        $fresh_preview = $this->executor->preview($manager_plan);
        if (is_wp_error($fresh_preview)) { return $this->normalise_error($fresh_preview, 409); }
        if (! hash_equals((string) $plan['baseline_signature'], $this->preview_signature($fresh_preview))) {
            return $this->error('stale_revision', 'SEO/GEO target changed after Preview. Create a fresh remediation plan.', 409);
        }

        $result = $this->executor->apply($manager_plan);
        if (is_wp_error($result)) { return $this->normalise_error($result, 409); }
        $operation_id = 'erm-seo-op-' . str_replace('-', '', wp_generate_uuid4());
        $operation = array(
            'operation_id'=>$operation_id,'plan_id'=>$plan_id,'operation'=>'seo-remediate','check_id'=>(string) $plan['check_id'],
            'candidate'=>$plan['candidate'],'connection_id'=>(string) $plan['connection_id'],'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'status'=>'applied','manager_plan'=>$manager_plan,'result'=>$result,'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),
            'baseline_signature'=>(string) $plan['baseline_signature'],'stored_verification'=>array(),'rendered_verification'=>array(),
            'applied_at'=>gmdate(DATE_W3C),'verified_at'=>'','rolled_back_at'=>'',
        );
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation);
        $plan['status']='applied'; $plan['operation_id']=$operation_id; $this->put(self::PLANS_OPTION,$plan_id,$plan);
        $this->audit('remote-seo-operation-applied', 'success', $operation, $actor);
        return $this->public_operation($operation);
    }

    public function get_operation(string $operation_id): array|WP_Error {
        $operation = $this->find(self::OPERATIONS_OPTION, $operation_id);
        return $operation ? $this->public_operation($operation) : $this->error('operation_not_found', 'SEO/GEO operation was not found.', 404);
    }

    public function verify(string $operation_id, bool $rendered, array $actor): array|WP_Error {
        $operation = $this->find(self::OPERATIONS_OPTION, $operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'SEO/GEO operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This SEO/GEO operation belongs to a different connection.', 403); }
        $generic = $this->executor->verify((array) $operation['manager_plan']);
        if (is_wp_error($generic)) { return $this->normalise_error($generic, 409); }
        $check = $this->remediation->verify((string) $operation['check_id']);
        if (is_wp_error($check)) { return $this->normalise_error($check, 409); }
        $stored_ok = ! empty($generic['verified']) && ! empty($check['verified']);
        $operation['stored_verification'] = array('verified'=>$stored_ok,'plan'=>$generic,'diagnostic'=>$check,'verified_at'=>gmdate(DATE_W3C));
        $operation['rendered_verification'] = $rendered ? $this->rendered_verification($operation) : array('skipped'=>true,'reason'=>'not-requested');
        $rendered_ok = ! $rendered || ! empty($operation['rendered_verification']['verified']) || ! empty($operation['rendered_verification']['not_applicable']);
        $operation['status'] = ($stored_ok && $rendered_ok) ? 'verified' : 'verification-failed';
        $operation['verified_at'] = gmdate(DATE_W3C);
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation);
        $this->audit('remote-seo-operation-verified', 'verified' === $operation['status'] ? 'success' : 'failed', $operation, $actor);
        return $this->public_operation($operation);
    }

    public function rollback(string $operation_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) { return $this->error('capability_unavailable', 'Local WordPress execution identity cannot rollback SEO/GEO remediation.', 403); }
        $operation = $this->find(self::OPERATIONS_OPTION, $operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'SEO/GEO operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This SEO/GEO operation belongs to a different connection.', 403); }
        if (! $confirmed) { return $this->error('confirmation_required', 'SEO/GEO rollback requires explicit confirmation.', 409); }
        if ('rolled-back' === (string) ($operation['status'] ?? '')) { return $this->public_operation($operation); }
        $snapshot_id = (string) ($operation['snapshot_id'] ?? '');
        if ('' === $snapshot_id) { return $this->error('rollback_unavailable', 'No SEO/GEO rollback snapshot is available.', 409); }
        $result = $this->executor->rollback($snapshot_id);
        if (is_wp_error($result)) { return $this->normalise_error($result, 409); }
        $fresh_preview = $this->executor->preview((array) $operation['manager_plan']);
        $restored = ! is_wp_error($fresh_preview) && hash_equals((string) $operation['baseline_signature'], $this->preview_signature($fresh_preview));
        $operation['status']='rolled-back'; $operation['rollback_result']=$result; $operation['rollback_restored_baseline']=$restored; $operation['rolled_back_at']=gmdate(DATE_W3C);
        $this->put(self::OPERATIONS_OPTION,$operation_id,$operation);
        $this->audit('remote-seo-operation-rolled-back', $restored ? 'success' : 'failed', $operation, $actor);
        return $this->public_operation($operation);
    }

    private function candidate(string $check_id): array|WP_Error {
        foreach ((array) ($this->remediation->inspect()['items'] ?? array()) as $item) {
            if (is_array($item) && $check_id === (string) ($item['check_id'] ?? '')) {
                if (empty($item['auto_remediable'])) { return $this->error('manual_action_required', 'This SEO/GEO finding is not safe for deterministic remediation.', 409); }
                return $item;
            }
        }
        return $this->error('operation_not_found', 'The requested SEO/GEO finding is not currently actionable.', 404);
    }

    private function rendered_verification(array $operation): array {
        $candidate = is_array($operation['candidate'] ?? null) ? $operation['candidate'] : array();
        $resource = (string) ($candidate['resource'] ?? '');
        $check_id = (string) ($operation['check_id'] ?? '');
        $key = '';
        if (str_starts_with($resource, 'page:')) { $key = sanitize_key(substr($resource, 5)); }
        elseif ('front-page' === $check_id) { $key = 'home'; }
        if ('' === $key) { return array('not_applicable'=>true,'verified'=>true,'reason'=>'finding-has-no-single-rendered-page'); }
        $rows = array(); $verified = true;
        foreach (Eduardo_Research_Manager::contract()->languages() as $language) {
            $result = Eduardo_Research_Manager::rendered()->verify_page($key, (string) $language);
            if (is_wp_error($result)) { $rows[$language]=array('verified'=>false,'error_code'=>$result->get_error_code(),'error'=>$result->get_error_message()); $verified=false; }
            else { unset($result['body']); $rows[$language]=$result; $verified=$verified && ! empty($result['verified']); }
        }
        return array('verified'=>$verified,'page'=>$key,'languages'=>$rows,'verified_at'=>gmdate(DATE_W3C));
    }

    private function preview_signature(array $preview): string {
        $baseline = array();
        foreach ((array) ($preview['actions'] ?? array()) as $row) {
            if (! is_array($row)) { continue; }
            $baseline[] = array('action'=>$row['action'] ?? array(),'before'=>$row['before'] ?? null);
        }
        return hash('sha256', (string) wp_json_encode($baseline));
    }

    private function audit(string $event, string $outcome, array $state, array $actor): void {
        $this->audit->record(array('request_id'=>(string)($actor['request_id']??''),'connection_id'=>(string)($actor['connection_id']??''),'wordpress_user_id'=>(int)($actor['wordpress_user_id']??0),'event'=>$event,'outcome'=>$outcome,'scope'=>'seo.write','plan_id'=>(string)($state['plan_id']??''),'operation_id'=>(string)($state['operation_id']??''),'check_id'=>(string)($state['check_id']??'')));
    }

    private function public_plan(array $plan): array { unset($plan['manager_plan'],$plan['expires_unix']); return $plan; }
    private function public_operation(array $operation): array { unset($operation['manager_plan']); return $operation; }
    private function find(string $option, string $id): array { $all=get_option($option,array()); $all=is_array($all)?$all:array(); $row=$all[sanitize_text_field($id)]??array(); return is_array($row)?$row:array(); }
    private function put(string $option, string $id, array $row): void { $all=get_option($option,array()); $all=is_array($all)?$all:array(); $all[$id]=$row; while(count($all)>self::LIMIT){array_shift($all);} if(false===get_option($option,false)){add_option($option,$all,'',false);}else{update_option($option,$all,false);} }
    private function normalise_error(WP_Error $error,int $status): WP_Error { $data=$error->get_error_data(); if(!is_array($data)||!isset($data['status'])){$error->add_data(array_merge(is_array($data)?$data:array(),array('status'=>$status)));} return $error; }
    private function error(string $code,string $message,int $status,array $extra=array()): WP_Error { return new WP_Error($code,$message,array_merge(array('status'=>$status),$extra)); }
}
