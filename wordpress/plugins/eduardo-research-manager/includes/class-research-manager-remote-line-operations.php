<?php
/** Exact remote lifecycle for first-class Research Lines. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Line_Operations {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_line_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_line_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;
    private Eduardo_Research_Manager_Line_Editor $editor;
    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(?Eduardo_Research_Manager_Line_Editor $editor = null, ?Eduardo_Research_Manager_Remote_Audit $audit = null) {
        $this->editor = $editor ?: Eduardo_Research_Manager::line_editor();
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
    }

    public function create_plan(string $operation, array $payload, array $actor): array|WP_Error {
        $operation = sanitize_key($operation);
        if (! in_array($operation, array('line-create','line-update'), true)) {
            return $this->error('validation_failed', 'Unsupported remote Research Line operation.', 400);
        }
        $confirmed = ! empty($payload['evidence_confirmed']);
        $reference = sanitize_text_field((string) ($payload['evidence_reference'] ?? ''));
        if ('line-create' === $operation) {
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : array();
            if (! $data) { return $this->error('validation_failed', 'line-create requires a bounded data object.', 400); }
            $prepared = $this->editor->preview_create($data, $confirmed, $reference);
            if (is_wp_error($prepared)) { return $this->normalise($prepared, 400); }
            $target = array('resource'=>'research-line','post_id'=>0,'language'=>(string) (($prepared['expected']['language'] ?? 'en')));
            $requested = array('data'=>(array) ($prepared['expected'] ?? array()));
        } else {
            $post_id = absint($payload['post_id'] ?? 0);
            $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : array();
            if ($post_id <= 0 || ! $changes) { return $this->error('validation_failed', 'line-update requires post_id and bounded changes.', 400); }
            $before = $this->editor->inspect($post_id);
            if (is_wp_error($before)) { return $this->normalise($before, 400); }
            $prepared = $this->editor->preview_update($post_id, $changes, $confirmed, $reference);
            if (is_wp_error($prepared)) { return $this->normalise($prepared, 400); }
            $target = array('resource'=>'research-line','post_id'=>$post_id,'language'=>(string) ($before['language'] ?? 'en'));
            $requested = array('changes'=>(array) ($prepared['expected'] ?? $changes));
        }
        $revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        if (is_wp_error($revision)) { return $revision; }
        $now = time();
        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $risk = sanitize_key((string) ($prepared['risk'] ?? 'standard')) ?: 'standard';
        $plan_id = 'erm-line-plan-' . substr(hash('sha256', wp_json_encode(array($operation,$target,$requested,$revision,$reference,$connection_id))), 0, 20) . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
        $plan = array(
            'plan_id'=>$plan_id,'operation'=>$operation,'target'=>$target,'requested'=>$requested,'prepared'=>$prepared,
            'preview'=>(array) ($prepared['preview'] ?? array()),'apply_allowed'=>! empty($prepared['apply_allowed']),
            'risk'=>$risk,'confirmation_required'=>true,'confirmation_class'=>'evidence-required' === $risk ? 'evidence-explicit' : 'explicit',
            'source_revision'=>$revision,'evidence_confirmed'=>$confirmed,'evidence_reference'=>$reference,
            'connection_id'=>$connection_id,'created_by'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'created_request_id'=>(string) ($actor['request_id'] ?? ''),'created_at'=>gmdate(DATE_W3C,$now),
            'expires_at'=>gmdate(DATE_W3C,$now+self::PLAN_TTL),'expires_unix'=>$now+self::PLAN_TTL,'status'=>'planned',
        );
        $this->put(self::PLANS_OPTION,$plan_id,$plan,60);
        $this->audit('remote-line-plan-created','success',$plan,$actor);
        return $this->public_plan($plan);
    }

    public function get_plan(string $id): array|WP_Error {
        $plan=$this->get(self::PLANS_OPTION,$id);
        if (!$plan) { return $this->error('operation_not_found','Research Line plan was not found.',404); }
        if ('planned'===(string)($plan['status']??'') && time()>(int)($plan['expires_unix']??0)) { $plan['status']='expired'; $this->put(self::PLANS_OPTION,$id,$plan,60); }
        return $this->public_plan($plan);
    }

    public function apply(string $id, bool $confirm, array $actor): array|WP_Error {
        if (!current_user_can('manage_options')) { return $this->error('capability_unavailable','Local execution identity cannot apply Research Line operations.',403); }
        $plan=$this->get(self::PLANS_OPTION,$id);
        if (!$plan) { return $this->error('operation_not_found','Research Line plan was not found.',404); }
        if ((string)$plan['connection_id']!==(string)($actor['connection_id']??'')) { return $this->error('scope_denied','This Research Line plan belongs to another connection.',403); }
        if ('planned'!==(string)$plan['status']) {
            if ('applied'===(string)$plan['status'] && !empty($plan['operation_id'])) { return $this->get_operation((string)$plan['operation_id']); }
            return $this->error('validation_failed','Only a planned Research Line operation can be applied.',409);
        }
        if (time()>(int)$plan['expires_unix']) { $plan['status']='expired'; $this->put(self::PLANS_OPTION,$id,$plan,60); return $this->error('plan_expired','Research Line plan expired.',409); }
        if (empty($plan['apply_allowed'])) { return $this->error('evidence-required'===(string)$plan['risk']?'evidence_required':'validation_failed','Stored Research Line Preview does not permit Apply.',409); }
        if (!$confirm) { return $this->error('confirmation_required','Research Line Apply requires explicit confirmation.',409); }
        $revision=Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        if (is_wp_error($revision)) { return $revision; }
        if (!hash_equals((string)$plan['source_revision'],$revision)) { return $this->error('stale_revision','Managed WordPress state changed after Research Line Preview.',409); }
        $result=$this->editor->apply_preview((array)$plan['prepared']);
        if (is_wp_error($result)) { return $this->normalise($result,409); }
        $op_id='erm-line-op-'.str_replace('-','',wp_generate_uuid4());
        $target=(array)$plan['target'];
        if ('line-create'===(string)$plan['operation'] && !empty($result['post_id'])) { $target['post_id']=(int)$result['post_id']; }
        $post_revision=Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        $operation=array(
            'operation_id'=>$op_id,'plan_id'=>$id,'operation'=>(string)$plan['operation'],'target'=>$target,'requested'=>(array)$plan['requested'],
            'source_revision'=>(string)$plan['source_revision'],'post_apply_revision'=>is_wp_error($post_revision)?'':$post_revision,
            'evidence_reference'=>(string)$plan['evidence_reference'],'connection_id'=>(string)($actor['connection_id']??''),
            'wordpress_user_id'=>(int)($actor['wordpress_user_id']??0),'status'=>'applied','result'=>$result,
            'snapshot_id'=>(string)($result['snapshot_id']??''),'stored_verification'=>array(),'rendered_verification'=>array(),
            'created_at'=>gmdate(DATE_W3C),'applied_at'=>gmdate(DATE_W3C),'verified_at'=>'','rolled_back_at'=>'',
        );
        $this->put(self::OPERATIONS_OPTION,$op_id,$operation,120);
        $plan['status']='applied'; $plan['operation_id']=$op_id; $plan['applied_at']=gmdate(DATE_W3C); $this->put(self::PLANS_OPTION,$id,$plan,60);
        $this->audit('remote-line-operation-applied','success',$operation,$actor);
        return $operation;
    }

    public function get_operation(string $id): array|WP_Error {
        $op=$this->get(self::OPERATIONS_OPTION,$id);
        return $op ?: $this->error('operation_not_found','Research Line operation was not found.',404);
    }

    public function verify(string $id, bool $rendered, array $actor): array|WP_Error {
        $op=$this->get(self::OPERATIONS_OPTION,$id);
        if (!$op) { return $this->error('operation_not_found','Research Line operation was not found.',404); }
        if ((string)$op['connection_id']!==(string)($actor['connection_id']??'')) { return $this->error('scope_denied','This Research Line operation belongs to another connection.',403); }
        $post_id=absint($op['target']['post_id']??0);
        $expected='line-create'===(string)$op['operation']?(array)($op['requested']['data']??array()):(array)($op['requested']['changes']??array());
        if ($post_id<=0 || !$expected) { return $this->error('validation_failed','Research Line operation is missing verification target.',409); }
        $semantic=Eduardo_Research_Manager::lines()->verify($post_id,$expected);
        if (is_wp_error($semantic)) { return $this->normalise($semantic,409); }
        $current=Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        if (is_wp_error($current)) { return $current; }
        $revision_matches=!empty($op['post_apply_revision']) && hash_equals((string)$op['post_apply_revision'],$current);
        $stored_verified=$revision_matches && !empty($op['result']['verified']) && !empty($semantic['verified']);
        $stored=array('verified'=>$stored_verified,'revision_matches'=>$revision_matches,'semantic'=>$semantic,'evidence_reference'=>(string)$op['evidence_reference'],'verified_at'=>gmdate(DATE_W3C));
        $render=array('requested'=>$rendered,'verified'=>null,'resources'=>array());
        if ($rendered) {
            $post=get_post($post_id);
            if ($post instanceof WP_Post && 'publish'===$post->post_status) {
                $r=Eduardo_Research_Manager::rendered()->verify_record($post_id);
                $render=is_wp_error($r)?array('requested'=>true,'verified'=>false,'resources'=>array(array('post_id'=>$post_id,'verified'=>false,'error'=>$r->get_error_message())), 'verified_at'=>gmdate(DATE_W3C)):array('requested'=>true,'verified'=>!empty($r['verified']),'resources'=>array($r),'verified_at'=>gmdate(DATE_W3C));
            } else {
                $render=array('requested'=>true,'verified'=>true,'resources'=>array(array('post_id'=>$post_id,'verified'=>true,'skipped'=>true,'reason'=>'draft-or-non-public')),'verified_at'=>gmdate(DATE_W3C));
            }
        }
        $verified=$stored_verified && (!$rendered || !empty($render['verified']));
        $op['stored_verification']=$stored; $op['rendered_verification']=$render; $op['status']=$verified?'verified':'verification-failed'; $op['verified_at']=gmdate(DATE_W3C);
        $this->put(self::OPERATIONS_OPTION,$id,$op,120); $this->audit('remote-line-operation-verified',$verified?'success':'failed',$op,$actor);
        return $op;
    }

    public function rollback(string $id, bool $confirm, array $actor): array|WP_Error {
        if (!$confirm) { return $this->error('confirmation_required','Research Line rollback requires explicit confirmation.',409); }
        $op=$this->get(self::OPERATIONS_OPTION,$id);
        if (!$op) { return $this->error('operation_not_found','Research Line operation was not found.',404); }
        if ((string)$op['connection_id']!==(string)($actor['connection_id']??'')) { return $this->error('scope_denied','This Research Line operation belongs to another connection.',403); }
        if (!in_array((string)$op['status'],array('applied','verified','verification-failed'),true)) { if ('rolled-back'===(string)$op['status']) return $op; return $this->error('rollback_unavailable','Research Line operation is not rollback-capable.',409); }
        $snapshot=(string)($op['snapshot_id']??''); if (''===$snapshot) return $this->error('rollback_unavailable','No Research Line rollback snapshot is available.',409);
        $result=$this->editor->rollback($snapshot); if (is_wp_error($result)) return $this->normalise($result,409);
        $op['status']='rolled-back'; $op['rollback_result']=$result; $op['rolled_back_at']=gmdate(DATE_W3C); $this->put(self::OPERATIONS_OPTION,$id,$op,120); $this->audit('remote-line-operation-rolled-back','success',$op,$actor); return $op;
    }

    private function audit(string $event,string $outcome,array $state,array $actor): void { $this->audit->record(array('request_id'=>(string)($actor['request_id']??''),'connection_id'=>(string)($actor['connection_id']??''),'wordpress_user_id'=>(int)($actor['wordpress_user_id']??0),'event'=>$event,'outcome'=>$outcome,'scope'=>'research.write','plan_id'=>(string)($state['plan_id']??''),'operation_id'=>(string)($state['operation_id']??''),'operation'=>(string)($state['operation']??''),'evidence_reference'=>(string)($state['evidence_reference']??''))); }
    private function public_plan(array $plan): array { unset($plan['prepared'],$plan['expires_unix']); return $plan; }
    private function get(string $option,string $id): array { $all=get_option($option,array()); $all=is_array($all)?$all:array(); $v=$all[sanitize_text_field($id)]??array(); return is_array($v)?$v:array(); }
    private function put(string $option,string $id,array $value,int $limit): void { $all=get_option($option,array()); $all=is_array($all)?$all:array(); $all[$id]=$value; while(count($all)>$limit) array_shift($all); if(false===get_option($option,false)) add_option($option,$all,'',false); else update_option($option,$all,false); }
    private function normalise(WP_Error $e,int $status): WP_Error { $d=$e->get_error_data(); if(!is_array($d)||!isset($d['status'])) $e->add_data(array_merge(is_array($d)?$d:array(),array('status'=>$status))); return $e; }
    private function error(string $code,string $message,int $status): WP_Error { return new WP_Error($code,$message,array('status'=>$status)); }
}
