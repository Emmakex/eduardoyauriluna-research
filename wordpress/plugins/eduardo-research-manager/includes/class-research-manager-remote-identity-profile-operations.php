<?php
/** Exact remote lifecycle for canonical Researcher Identity profile changes. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Identity_Profile_Operations {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_identity_profile_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_identity_profile_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;
    private const LIMIT = 80;

    private Eduardo_Research_Manager_Identity_Profile $identity;
    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(
        ?Eduardo_Research_Manager_Identity_Profile $identity = null,
        ?Eduardo_Research_Manager_Remote_Audit $audit = null
    ) {
        $this->identity = $identity ?: new Eduardo_Research_Manager_Identity_Profile();
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
    }

    public function create_plan(array $payload, array $actor): array|WP_Error {
        $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : array();
        if (! $changes) {
            foreach (array('name','headline','headline_es') as $field) {
                if (array_key_exists($field, $payload)) { $changes[$field] = $payload[$field]; }
            }
        }
        $prepared = $this->identity->preview_update(
            $changes,
            ! empty($payload['evidence_confirmed']),
            sanitize_text_field((string) ($payload['evidence_reference'] ?? ''))
        );
        if (is_wp_error($prepared)) { return $this->normalise($prepared, 400); }
        $now = time();
        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $source_revision = sanitize_text_field((string) ($prepared['baseline_revision'] ?? ''));
        $plan_id = 'erm-identity-plan-' . substr(hash('sha256', (string) wp_json_encode(array(
            'changes'=>$changes,'baseline'=>$source_revision,'connection_id'=>$connection_id,'nonce'=>wp_generate_uuid4(),
        ))), 0, 24);
        $plan = array(
            'plan_id'=>$plan_id,
            'operation'=>'identity-profile-update',
            'target'=>array('resource'=>'identity-profile'),
            'requested'=>array('changes'=>$changes),
            'prepared'=>$prepared,
            'source_revision'=>$source_revision,
            'risk'=>(string) ($prepared['risk'] ?? 'evidence-required'),
            'apply_allowed'=>! empty($prepared['apply_allowed']),
            'confirmation_required'=>true,
            'confirmation_class'=>'evidence-explicit',
            'connection_id'=>$connection_id,
            'created_by'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'created_request_id'=>sanitize_text_field((string) ($actor['request_id'] ?? '')),
            'created_at'=>gmdate(DATE_W3C, $now),
            'expires_at'=>gmdate(DATE_W3C, $now + self::PLAN_TTL),
            'expires_unix'=>$now + self::PLAN_TTL,
            'status'=>'planned',
        );
        $this->put(self::PLANS_OPTION, $plan_id, $plan);
        $this->audit('remote-identity-profile-plan-created','success',$plan,$actor);
        return $this->public_plan($plan);
    }

    public function get_plan(string $plan_id): array|WP_Error {
        $plan = $this->find(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Identity profile plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put(self::PLANS_OPTION, $plan_id, $plan);
        }
        return $this->public_plan($plan);
    }

    public function apply(string $plan_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) { return $this->error('capability_unavailable', 'Local execution identity cannot apply Identity changes.', 403); }
        $plan = $this->find(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Identity profile plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'Identity plan belongs to another connection.', 403); }
        if ('planned' !== (string) ($plan['status'] ?? '')) {
            if ('applied' === (string) ($plan['status'] ?? '') && '' !== (string) ($plan['operation_id'] ?? '')) { return $this->get_operation((string) $plan['operation_id']); }
            return $this->error('validation_failed', 'Only a planned Identity operation can be applied.', 409);
        }
        if (time() > (int) ($plan['expires_unix'] ?? 0)) { $plan['status']='expired'; $this->put(self::PLANS_OPTION,$plan_id,$plan); return $this->error('plan_expired','Identity plan has expired.',409); }
        if (! $confirmed) { return $this->error('confirmation_required', 'Identity Apply requires explicit evidence confirmation.', 409, array('confirmation_class'=>'evidence-explicit')); }
        if (empty($plan['apply_allowed'])) { return $this->error('evidence_required', 'Identity Preview does not permit Apply.', 409); }

        $result = $this->identity->apply_preview((array) ($plan['prepared'] ?? array()));
        if (is_wp_error($result)) { return $this->normalise($result, 409); }
        $after = $this->identity->inspect();
        if (is_wp_error($after)) {
            $snapshot = (string) ($result['snapshot_id'] ?? '');
            if ('' !== $snapshot) { $this->identity->rollback($snapshot); }
            return $this->normalise($after, 409);
        }
        $operation_id = 'erm-identity-op-' . str_replace('-', '', wp_generate_uuid4());
        $operation = array(
            'operation_id'=>$operation_id,'plan_id'=>$plan_id,'operation'=>'identity-profile-update',
            'target'=>array('resource'=>'identity-profile'),'requested'=>$plan['requested'] ?? array(),
            'source_revision'=>(string) ($plan['source_revision'] ?? ''),'post_apply_revision'=>(string) ($after['revision'] ?? ''),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'status'=>'applied','result'=>$result,'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),
            'stored_verification'=>is_array($result['verification'] ?? null) ? $result['verification'] : array(),
            'created_at'=>gmdate(DATE_W3C),'applied_at'=>gmdate(DATE_W3C),'verified_at'=>'','rolled_back_at'=>'',
        );
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation);
        $plan['status']='applied';$plan['operation_id']=$operation_id;$plan['applied_at']=gmdate(DATE_W3C);$this->put(self::PLANS_OPTION,$plan_id,$plan);
        $this->audit('remote-identity-profile-operation-applied','success',$operation,$actor);
        return $this->public_operation($operation);
    }

    public function get_operation(string $operation_id): array|WP_Error {
        $operation = $this->find(self::OPERATIONS_OPTION, $operation_id);
        return $operation ? $this->public_operation($operation) : $this->error('operation_not_found','Identity profile operation was not found.',404);
    }

    public function verify(string $operation_id, array $actor): array|WP_Error {
        $operation = $this->find(self::OPERATIONS_OPTION, $operation_id);
        if (! $operation) { return $this->error('operation_not_found','Identity profile operation was not found.',404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied','Identity operation belongs to another connection.',403); }
        $plan = $this->find(self::PLANS_OPTION, (string) ($operation['plan_id'] ?? ''));
        $expected = is_array($plan['prepared']['expected'] ?? null) ? $plan['prepared']['expected'] : array();
        $verification = $this->identity->verify($expected);
        if (is_wp_error($verification)) { return $this->normalise($verification,409); }
        $operation['stored_verification']=$verification;
        $operation['status']=! empty($verification['verified'])?'verified':'verification-failed';
        $operation['verified_at']=gmdate(DATE_W3C);
        $this->put(self::OPERATIONS_OPTION,$operation_id,$operation);
        $this->audit('remote-identity-profile-operation-verified',!empty($verification['verified'])?'success':'failed',$operation,$actor);
        return $this->public_operation($operation);
    }

    public function rollback(string $operation_id, bool $confirmed, array $actor): array|WP_Error {
        if (! $confirmed) { return $this->error('confirmation_required','Identity rollback requires explicit confirmation.',409); }
        $operation = $this->find(self::OPERATIONS_OPTION, $operation_id);
        if (! $operation) { return $this->error('operation_not_found','Identity profile operation was not found.',404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied','Identity operation belongs to another connection.',403); }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) { return $this->error('validation_failed','Identity operation is not rollback-eligible.',409); }
        $snapshot = (string) ($operation['snapshot_id'] ?? '');
        if ('' === $snapshot) { return $this->error('validation_failed','Identity operation has no rollback snapshot.',409); }
        $result = $this->identity->rollback($snapshot);
        if (is_wp_error($result)) { return $this->normalise($result,409); }
        $operation['status']='rolled-back';$operation['rolled_back_at']=gmdate(DATE_W3C);$operation['rollback_result']=$result;
        $this->put(self::OPERATIONS_OPTION,$operation_id,$operation);
        $this->audit('remote-identity-profile-operation-rolled-back','success',$operation,$actor);
        return $this->public_operation($operation);
    }

    private function public_plan(array $plan): array {
        return array_intersect_key($plan,array_flip(array('plan_id','operation','target','requested','source_revision','risk','apply_allowed','confirmation_required','confirmation_class','connection_id','created_at','expires_at','status','operation_id','applied_at')));
    }
    private function public_operation(array $operation): array {
        return array_intersect_key($operation,array_flip(array('operation_id','plan_id','operation','target','requested','source_revision','post_apply_revision','connection_id','status','stored_verification','created_at','applied_at','verified_at','rolled_back_at')));
    }
    private function find(string $option,string $id): array { $all=get_option($option,array()); return is_array($all)&&is_array($all[$id]??null)?$all[$id]:array(); }
    private function put(string $option,string $id,array $value): void { $all=get_option($option,array());$all=is_array($all)?$all:array();$all[$id]=$value;if(count($all)>self::LIMIT){$all=array_slice($all,-self::LIMIT,null,true);}if(false===get_option($option,false)){add_option($option,$all,'',false);}else{update_option($option,$all,false);} }
    private function audit(string $event,string $outcome,array $state,array $actor): void { $this->audit->record(array('request_id'=>(string)($actor['request_id']??''),'connection_id'=>(string)($actor['connection_id']??''),'wordpress_user_id'=>(int)($actor['wordpress_user_id']??0),'event'=>$event,'outcome'=>$outcome,'scope'=>'identity.write','plan_id'=>(string)($state['plan_id']??''),'operation_id'=>(string)($state['operation_id']??''),'operation'=>(string)($state['operation']??''),'target'=>$state['target']??array(),'source_revision'=>(string)($state['source_revision']??''),'resulting_revision'=>(string)($state['post_apply_revision']??''),'status'=>(string)($state['status']??''))); }
    private function normalise(WP_Error $error,int $status): WP_Error { $data=$error->get_error_data();if(!is_array($data)){$data=array();}$data['status']=$data['status']??$status;return new WP_Error($error->get_error_code(),$error->get_error_message(),$data); }
    private function error(string $code,string $message,int $status,array $extra=array()): WP_Error { return new WP_Error($code,$message,array_merge(array('status'=>$status),$extra)); }
}
