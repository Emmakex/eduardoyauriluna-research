<?php
/** Remote lifecycle wrapper for the shared bounded Page state editor. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Page_State_Operations {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_page_state_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_page_state_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;
    private const MAX_PLANS = 60;
    private const MAX_OPERATIONS = 120;

    private Eduardo_Research_Manager_Page_State_Editor $editor;
    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(
        ?Eduardo_Research_Manager_Page_State_Editor $editor = null,
        ?Eduardo_Research_Manager_Remote_Audit $audit = null
    ) {
        $this->editor = $editor ?: new Eduardo_Research_Manager_Page_State_Editor();
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
    }

    public function create_plan(string $key, array $changes, array $actor, string $reason = ''): array|WP_Error {
        $prepared = $this->editor->preview_update($key, $changes, $reason);
        if (is_wp_error($prepared)) { return $this->normalise($prepared, 400); }
        $now = time();
        $connection = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $material = array('key'=>$key,'expected'=>$prepared['expected'] ?? array(),'baseline'=>$prepared['baseline_revision'] ?? '','connection_id'=>$connection);
        $plan_id = 'erm-page-state-plan-' . substr(hash('sha256', (string) wp_json_encode($material)), 0, 20) . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
        $plan = array(
            'plan_id'=>$plan_id,'operation'=>'page-state-update','target'=>array('resource'=>'page','key'=>sanitize_key($key),'page_id'=>(int) ($prepared['page_id'] ?? 0)),
            'changes'=>$changes,'prepared'=>$prepared,'source_revision'=>(string) ($prepared['baseline_revision'] ?? ''),'expected'=>(array) ($prepared['expected'] ?? array()),
            'risk'=>(string) ($prepared['risk'] ?? 'editorial-review'),'confirmation_required'=>true,'confirmation_class'=>'explicit','apply_allowed'=>! empty($prepared['apply_allowed']),
            'connection_id'=>$connection,'created_by'=>(int) ($actor['wordpress_user_id'] ?? 0),'created_request_id'=>(string) ($actor['request_id'] ?? ''),
            'reason'=>sanitize_text_field($reason),'status'=>'planned','created_at'=>gmdate(DATE_W3C, $now),'expires_at'=>gmdate(DATE_W3C, $now + self::PLAN_TTL),'expires_unix'=>$now + self::PLAN_TTL,
        );
        $this->put(self::PLANS_OPTION, $plan_id, $plan, self::MAX_PLANS);
        $this->audit('remote-page-state-plan-created','success',$actor,$plan_id,'','Plan bounded Page state update',$plan);
        return $this->public_plan($plan);
    }

    public function get_plan(string $plan_id): array|WP_Error {
        $plan = $this->get(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Page state plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired'; $this->put(self::PLANS_OPTION, $plan_id, $plan, self::MAX_PLANS);
        }
        return $this->public_plan($plan);
    }

    public function apply(string $plan_id, bool $confirm, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) { return $this->error('capability_unavailable','Local execution identity cannot update Page state.',403); }
        $plan = $this->get(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found','Page state plan was not found.',404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied','This Page state plan belongs to another connection.',403); }
        if ('planned' !== (string) ($plan['status'] ?? '')) {
            if ('applied' === (string) ($plan['status'] ?? '') && '' !== (string) ($plan['operation_id'] ?? '')) { return $this->get_operation((string) $plan['operation_id']); }
            return $this->error('validation_failed','Only a planned Page state operation can be applied.',409);
        }
        if (time() > (int) ($plan['expires_unix'] ?? 0)) { $plan['status']='expired'; $this->put(self::PLANS_OPTION,$plan_id,$plan,self::MAX_PLANS); return $this->error('plan_expired','Page state plan expired.',409); }
        if (! $confirm) { return $this->error('confirmation_required','Page state Apply requires explicit confirmation.',409); }
        if (empty($plan['apply_allowed'])) { return $this->error('validation_failed','Stored Page state Preview does not permit Apply.',409); }
        $result = $this->editor->apply_preview((array) ($plan['prepared'] ?? array()));
        if (is_wp_error($result)) { return $this->normalise($result,409); }
        $operation_id = 'erm-page-state-op-' . str_replace('-', '', wp_generate_uuid4());
        $operation = array(
            'operation_id'=>$operation_id,'plan_id'=>$plan_id,'operation'=>'page-state-update','target'=>(array) $plan['target'],'source_revision'=>(string) $plan['source_revision'],
            'expected'=>(array) $plan['expected'],'before'=>(array) ($plan['prepared']['before'] ?? array()),'result'=>$result,'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),'status'=>'applied','created_at'=>gmdate(DATE_W3C),'applied_at'=>gmdate(DATE_W3C),'verified_at'=>'','rolled_back_at'=>'',
        );
        $this->put(self::OPERATIONS_OPTION,$operation_id,$operation,self::MAX_OPERATIONS);
        $plan['status']='applied'; $plan['operation_id']=$operation_id; $plan['applied_at']=gmdate(DATE_W3C); $this->put(self::PLANS_OPTION,$plan_id,$plan,self::MAX_PLANS);
        $this->audit('remote-page-state-applied','success',$actor,$plan_id,$operation_id,'Apply bounded Page state update',$operation);
        return $this->public_operation($operation);
    }

    public function get_operation(string $operation_id): array|WP_Error {
        $operation = $this->get(self::OPERATIONS_OPTION,$operation_id);
        return $operation ? $this->public_operation($operation) : $this->error('operation_not_found','Page state operation was not found.',404);
    }

    public function verify(string $operation_id, array $actor): array|WP_Error {
        $operation = $this->get(self::OPERATIONS_OPTION,$operation_id);
        if (! $operation) { return $this->error('operation_not_found','Page state operation was not found.',404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied','This Page state operation belongs to another connection.',403); }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) { return $this->error('validation_failed','Only applied Page state operations can be verified.',409); }
        $key = sanitize_key((string) ($operation['target']['key'] ?? ''));
        $verification = $this->editor->verify($key,(array) ($operation['expected'] ?? array()));
        if (is_wp_error($verification)) { return $this->normalise($verification,409); }
        $operation['verification']=$verification; $operation['verified_at']=gmdate(DATE_W3C); $operation['status']=! empty($verification['verified']) ? 'verified' : 'verification-failed';
        $this->put(self::OPERATIONS_OPTION,$operation_id,$operation,self::MAX_OPERATIONS);
        $this->audit('remote-page-state-verified',! empty($verification['verified'])?'success':'failed',$actor,(string) ($operation['plan_id'] ?? ''),$operation_id,'Verify bounded Page state update',$operation);
        return $this->public_operation($operation);
    }

    public function rollback(string $operation_id, bool $confirm, array $actor): array|WP_Error {
        if (! $confirm) { return $this->error('confirmation_required','Page state rollback requires explicit confirmation.',409); }
        $operation = $this->get(self::OPERATIONS_OPTION,$operation_id);
        if (! $operation) { return $this->error('operation_not_found','Page state operation was not found.',404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied','This Page state operation belongs to another connection.',403); }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            if ('rolled-back' === (string) ($operation['status'] ?? '')) { return $this->public_operation($operation); }
            return $this->error('rollback_unavailable','Page state operation is not rollback-capable.',409);
        }
        $snapshot = (string) ($operation['snapshot_id'] ?? '');
        if ('' === $snapshot) { return $this->error('rollback_unavailable','Page state operation has no rollback snapshot.',409); }
        $rolled = $this->editor->rollback($snapshot); if (is_wp_error($rolled)) { return $this->normalise($rolled,409); }
        $key = sanitize_key((string) ($operation['target']['key'] ?? '')); $baseline = (array) ($operation['before'] ?? array());
        $restored = $this->editor->verify($key,array('title'=>$baseline['title'] ?? '', 'status'=>$baseline['status'] ?? ''));
        if (is_wp_error($restored) || empty($restored['verified'])) { return $this->error('rollback_failed','Page state rollback did not restore the exact bounded baseline.',409); }
        $operation['status']='rolled-back'; $operation['rollback_result']=$rolled; $operation['rollback_verification']=$restored; $operation['rolled_back_at']=gmdate(DATE_W3C);
        $this->put(self::OPERATIONS_OPTION,$operation_id,$operation,self::MAX_OPERATIONS);
        $this->audit('remote-page-state-rolled-back','success',$actor,(string) ($operation['plan_id'] ?? ''),$operation_id,'Rollback bounded Page state update',$operation);
        return $this->public_operation($operation);
    }

    private function public_plan(array $plan): array { unset($plan['expires_unix']); return $plan; }
    private function public_operation(array $operation): array { return $operation; }
    private function get(string $option,string $id): array { $all=get_option($option,array()); $value=is_array($all)?($all[sanitize_text_field($id)]??array()):array(); return is_array($value)?$value:array(); }
    private function put(string $option,string $id,array $value,int $limit): void { $all=get_option($option,array()); $all=is_array($all)?$all:array(); $all[$id]=$value; while(count($all)>$limit){array_shift($all);} if(false===get_option($option,false)){add_option($option,$all,'',false);}else{update_option($option,$all,false);} }
    private function normalise(WP_Error $error,int $status): WP_Error { $data=$error->get_error_data(); if(!is_array($data)||!isset($data['status'])){$error->add_data(array_merge(is_array($data)?$data:array(),array('status'=>$status)));} return $error; }
    private function error(string $code,string $message,int $status): WP_Error { return new WP_Error($code,$message,array('status'=>$status)); }
    private function audit(string $event,string $outcome,array $actor,string $plan_id,string $operation_id,string $reason,array $state): void {
        $this->audit->record(array('request_id'=>(string) ($actor['request_id'] ?? ''),'connection_id'=>(string) ($actor['connection_id'] ?? ''),'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),'event'=>$event,'outcome'=>$outcome,'scope'=>'pages.write','plan_id'=>$plan_id,'operation_id'=>$operation_id,'operation'=>'page-state-update','reason'=>$reason,'target'=>is_array($state['target'] ?? null)?$state['target']:array(),'source_revision'=>(string) ($state['source_revision'] ?? ''),'apply_status'=>(string) ($state['status'] ?? '')));
    }
}
