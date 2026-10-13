<?php
/** Remote lifecycle for shared managed Media binary ingest. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Media_Ingest_Operations {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_media_ingest_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_media_ingest_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;
    private const CLEANUP_HOOK = 'eduardo_research_manager_cleanup_media_ingest_plan';
    private const MAX_PLANS = 40;
    private const MAX_OPERATIONS = 80;

    private Eduardo_Research_Manager_Remote_Audit $audit;
    private Eduardo_Research_Manager_Media_Ingest $ingest;

    public function __construct(?Eduardo_Research_Manager_Remote_Audit $audit = null, ?Eduardo_Research_Manager_Media_Ingest $ingest = null) {
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
        $this->ingest = $ingest ?: new Eduardo_Research_Manager_Media_Ingest();
        add_action(self::CLEANUP_HOOK, array($this, 'cleanup_expired_plan'), 10, 1);
    }

    public function create_plan(array $file, array $input, array $actor): array|WP_Error {
        $prepared = $this->ingest->prepare($file, $input);
        if (is_wp_error($prepared)) { return $prepared; }
        $file_state = (array) ($prepared['file'] ?? array());
        $metadata = (array) ($prepared['metadata'] ?? array());
        $checksum = (string) ($file_state['sha256'] ?? '');
        $staged = (string) ($prepared['staged_path'] ?? '');
        if ('' === $checksum || '' === $staged) { $this->ingest->cleanup_stage($staged); return $this->error('validation_failed', 'Media ingest preparation did not produce a valid staged revision.', 409); }

        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $request_id = sanitize_text_field((string) ($actor['request_id'] ?? ''));
        $existing = $this->find_plan_by_request($connection_id, $request_id, $checksum);
        if ($existing) { $this->ingest->cleanup_stage($staged); return $this->public_plan($existing); }

        $now = time();
        $material = array('checksum'=>$checksum,'filename'=>$file_state['filename'] ?? '','mime_type'=>$file_state['mime_type'] ?? '','size'=>$file_state['size'] ?? 0,'connection_id'=>$connection_id,'request_id'=>$request_id);
        $plan_id = 'erm-media-ingest-plan-' . substr(hash('sha256', (string) wp_json_encode($material)), 0, 20) . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
        $plan = array(
            'plan_id'=>$plan_id,'operation'=>'media-ingest','risk'=>'file-ingest','confirmation_required'=>true,'confirmation_class'=>'media-ingest-explicit',
            'target'=>array('resource'=>'media:new'),'file'=>$file_state,'metadata'=>$metadata,
            'duplicate_candidates'=>array_values((array) ($prepared['duplicate_candidates'] ?? array())),'staged_path'=>$staged,'apply_allowed'=>true,
            'source_revision'=>$checksum,'connection_id'=>$connection_id,'created_by'=>(int) ($actor['wordpress_user_id'] ?? 0),'created_request_id'=>$request_id,
            'created_at'=>gmdate(DATE_W3C, $now),'expires_at'=>gmdate(DATE_W3C, $now + self::PLAN_TTL),'expires_unix'=>$now + self::PLAN_TTL,'status'=>'planned',
            'cleanup'=>array('policy'=>'scheduled-or-explicit-cancel','ttl_seconds'=>self::PLAN_TTL),
        );
        $this->put(self::PLANS_OPTION, $plan_id, $plan, self::MAX_PLANS);
        if (! wp_next_scheduled(self::CLEANUP_HOOK, array($plan_id))) { wp_schedule_single_event($now + self::PLAN_TTL, self::CLEANUP_HOOK, array($plan_id)); }
        $this->audit('remote-media-ingest-plan-created', 'success', $actor, $plan_id, '', 'Managed Media binary staged for explicit Apply', $plan);
        return $this->public_plan($plan);
    }

    public function get_plan(string $plan_id): array|WP_Error {
        $plan = $this->get(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Media ingest plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) { $plan = $this->expire_plan($plan_id, $plan); }
        return $this->public_plan($plan);
    }

    public function cancel(string $plan_id, array $actor): array|WP_Error {
        $plan = $this->get(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Media ingest plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This Media ingest plan belongs to another connection.', 403); }
        if ('cancelled' === (string) ($plan['status'] ?? '')) { return $this->public_plan($plan); }
        if ('planned' !== (string) ($plan['status'] ?? '')) { return $this->error('validation_failed', 'Only a planned Media ingest Preview can be cancelled.', 409); }
        $this->ingest->cleanup_stage((string) ($plan['staged_path'] ?? ''));
        wp_clear_scheduled_hook(self::CLEANUP_HOOK, array($plan_id));
        $plan['staged_path']='';$plan['status']='cancelled';$plan['cancelled_at']=gmdate(DATE_W3C);
        $this->put(self::PLANS_OPTION,$plan_id,$plan,self::MAX_PLANS);
        $this->audit('remote-media-ingest-plan-cancelled','success',$actor,$plan_id,'','Private staged Media Preview cancelled and removed',$plan);
        return $this->public_plan($plan);
    }

    public function cleanup_expired_plan(string $plan_id): void {
        $plan=$this->get(self::PLANS_OPTION,$plan_id);if(!$plan||'planned'!==(string) ($plan['status']??'')){return;}if(time()<(int) ($plan['expires_unix']??0)){return;}$this->expire_plan($plan_id,$plan);
    }

    public function apply(string $plan_id,bool $confirm,array $actor): array|WP_Error {
        $plan=$this->get(self::PLANS_OPTION,$plan_id);if(!$plan){return $this->error('operation_not_found','Media ingest plan was not found.',404);}
        if((string) ($plan['connection_id']??'')!==(string) ($actor['connection_id']??'')){return $this->error('scope_denied','This Media ingest plan belongs to another connection.',403);}
        if('planned'!==(string) ($plan['status']??'')){if('applied'===(string) ($plan['status']??'')&&''!==(string) ($plan['operation_id']??'')){return $this->get_operation((string) $plan['operation_id']);}return $this->error('validation_failed','Only a planned Media ingest can be applied.',409);}
        if(time()>(int) ($plan['expires_unix']??0)){$this->expire_plan($plan_id,$plan);return $this->error('plan_expired','Media ingest plan expired.',409);}
        if(!$confirm){return $this->error('confirmation_required','Media ingest Apply requires explicit confirmation.',409);}

        $operation_id='erm-media-ingest-op-'.str_replace('-','',wp_generate_uuid4());
        $created=$this->ingest->create_attachment(
            (string) ($plan['staged_path']??''),(array) ($plan['file']??array()),(array) ($plan['metadata']??array()),
            array('operation_id'=>$operation_id,'connection_id'=>(string) ($actor['connection_id']??''),'request_id'=>(string) ($actor['request_id']??''))
        );
        if(is_wp_error($created)){
            $this->ingest->cleanup_stage((string) ($plan['staged_path']??''));wp_clear_scheduled_hook(self::CLEANUP_HOOK,array($plan_id));$plan['staged_path']='';$plan['status']='apply-failed';$this->put(self::PLANS_OPTION,$plan_id,$plan,self::MAX_PLANS);return $this->normalise($created,409);
        }
        $attachment_id=absint($created['attachment_id']??0);$checksum=(string) ($plan['file']['sha256']??'');
        $operation=array(
            'operation_id'=>$operation_id,'plan_id'=>$plan_id,'operation'=>'media-ingest','target'=>array('resource'=>'media','attachment_id'=>$attachment_id),
            'file'=>(array) $plan['file'],'metadata'=>(array) $plan['metadata'],'source_revision'=>$checksum,'post_apply_revision'=>$checksum,
            'connection_id'=>(string) ($actor['connection_id']??''),'wordpress_user_id'=>(int) ($actor['wordpress_user_id']??0),'status'=>'applied','created_at'=>gmdate(DATE_W3C),'applied_at'=>gmdate(DATE_W3C),'verified_at'=>'','rolled_back_at'=>'',
        );
        $this->put(self::OPERATIONS_OPTION,$operation_id,$operation,self::MAX_OPERATIONS);
        $plan['status']='applied';$plan['operation_id']=$operation_id;$plan['attachment_id']=$attachment_id;$plan['staged_path']='';$plan['applied_at']=gmdate(DATE_W3C);$this->put(self::PLANS_OPTION,$plan_id,$plan,self::MAX_PLANS);wp_clear_scheduled_hook(self::CLEANUP_HOOK,array($plan_id));
        $this->audit('remote-media-ingest-applied','success',$actor,$plan_id,$operation_id,'Managed Media attachment created',$operation);
        return $operation;
    }

    public function get_operation(string $operation_id): array|WP_Error {$operation=$this->get(self::OPERATIONS_OPTION,$operation_id);return $operation?:$this->error('operation_not_found','Media ingest operation was not found.',404);}

    public function verify(string $operation_id,array $actor): array|WP_Error {
        $operation=$this->get(self::OPERATIONS_OPTION,$operation_id);if(!$operation){return $this->error('operation_not_found','Media ingest operation was not found.',404);}if((string) ($operation['connection_id']??'')!==(string) ($actor['connection_id']??'')){return $this->error('scope_denied','This Media ingest operation belongs to another connection.',403);}
        $verification=$this->ingest->verify_attachment(absint($operation['target']['attachment_id']??0),$operation_id,(array) ($operation['file']??array()),(array) ($operation['metadata']??array()));
        $operation['verification']=$verification;$operation['status']=!empty($verification['verified'])?'verified':'verification-failed';$operation['verified_at']=gmdate(DATE_W3C);$this->put(self::OPERATIONS_OPTION,$operation_id,$operation,self::MAX_OPERATIONS);
        $this->audit('remote-media-ingest-verified',!empty($verification['verified'])?'success':'failed',$actor,(string) ($operation['plan_id']??''),$operation_id,'Verify managed Media ingest',$operation);return $operation;
    }

    public function rollback(string $operation_id,bool $confirm,array $actor): array|WP_Error {
        if(!$confirm){return $this->error('confirmation_required','Media ingest rollback requires explicit confirmation.',409);}
        $operation=$this->get(self::OPERATIONS_OPTION,$operation_id);if(!$operation){return $this->error('operation_not_found','Media ingest operation was not found.',404);}if((string) ($operation['connection_id']??'')!==(string) ($actor['connection_id']??'')){return $this->error('scope_denied','This Media ingest operation belongs to another connection.',403);}
        if(!in_array((string) ($operation['status']??''),array('applied','verified','verification-failed'),true)){if('rolled-back'===(string) ($operation['status']??'')){return $operation;}return $this->error('rollback_unavailable','Media ingest operation is not rollback-capable.',409);}
        $rolled=$this->ingest->rollback_attachment(absint($operation['target']['attachment_id']??0),$operation_id);if(is_wp_error($rolled)){return $this->normalise($rolled,409);}
        $operation['status']='rolled-back';$operation['rolled_back_at']=gmdate(DATE_W3C);$operation['rollback_result']=$rolled;$this->put(self::OPERATIONS_OPTION,$operation_id,$operation,self::MAX_OPERATIONS);
        $this->audit('remote-media-ingest-rolled-back','success',$actor,(string) ($operation['plan_id']??''),$operation_id,'Rollback owned unused Media ingest',$operation);return $operation;
    }

    private function expire_plan(string $plan_id,array $plan): array {
        $this->ingest->cleanup_stage((string) ($plan['staged_path']??''));wp_clear_scheduled_hook(self::CLEANUP_HOOK,array($plan_id));$plan['staged_path']='';$plan['status']='expired';$plan['expired_at']=gmdate(DATE_W3C);$this->put(self::PLANS_OPTION,$plan_id,$plan,self::MAX_PLANS);
        $this->audit('remote-media-ingest-plan-expired','success',array('connection_id'=>(string) ($plan['connection_id']??''),'wordpress_user_id'=>(int) ($plan['created_by']??0),'request_id'=>(string) ($plan['created_request_id']??'')),$plan_id,'','Expired private staged Media Preview removed',$plan);return $plan;
    }

    private function find_plan_by_request(string $connection_id,string $request_id,string $checksum): array {
        if(''===$request_id){return array();}$plans=get_option(self::PLANS_OPTION,array());foreach(is_array($plans)?$plans:array() as $plan){if(!is_array($plan)){continue;}if((string) ($plan['connection_id']??'')===$connection_id&&(string) ($plan['created_request_id']??'')===$request_id&&(string) ($plan['file']['sha256']??'')===$checksum){return $plan;}}return array();
    }

    private function public_plan(array $plan): array {unset($plan['staged_path'],$plan['expires_unix']);return $plan;}
    private function get(string $option,string $id): array {$all=get_option($option,array());$value=is_array($all)?($all[sanitize_text_field($id)]??array()):array();return is_array($value)?$value:array();}
    private function put(string $option,string $id,array $value,int $limit): void {
        $all=get_option($option,array());$all=is_array($all)?$all:array();$all[$id]=$value;
        while(count($all)>$limit){$old_id=(string) array_key_first($all);$old=$all[$old_id]??array();unset($all[$old_id]);if(is_array($old)){$this->ingest->cleanup_stage((string) ($old['staged_path']??''));}if(''!==$old_id){wp_clear_scheduled_hook(self::CLEANUP_HOOK,array($old_id));}}
        if(false===get_option($option,false)){add_option($option,$all,'',false);}else{update_option($option,$all,false);}
    }
    private function audit(string $event,string $outcome,array $actor,string $plan_id,string $operation_id,string $reason,array $state=array()): void {
        $this->audit->record(array('request_id'=>(string) ($actor['request_id']??''),'connection_id'=>(string) ($actor['connection_id']??''),'wordpress_user_id'=>(int) ($actor['wordpress_user_id']??0),'event'=>$event,'outcome'=>$outcome,'scope'=>'media.write','plan_id'=>$plan_id,'operation_id'=>$operation_id,'operation'=>'media-ingest','target'=>is_array($state['target']??null)?$state['target']:array(),'source_revision'=>(string) ($state['source_revision']??$state['file']['sha256']??''),'resulting_revision'=>(string) ($state['post_apply_revision']??''),'apply_status'=>(string) ($state['status']??''),'reason'=>$reason));
    }
    private function normalise(WP_Error $error,int $status): WP_Error {$data=$error->get_error_data();if(!is_array($data)||!isset($data['status'])){$error->add_data(array_merge(is_array($data)?$data:array(),array('status'=>$status)));}return $error;}
    private function error(string $code,string $message,int $status): WP_Error {return new WP_Error($code,$message,array('status'=>$status));}
}
