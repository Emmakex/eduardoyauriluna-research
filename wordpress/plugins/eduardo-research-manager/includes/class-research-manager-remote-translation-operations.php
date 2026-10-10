<?php
/** Exact remote lifecycle for structured EN/ES Research translations. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Translation_Operations {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_translation_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_translation_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;
    private const ALLOWED_TYPES = array('research_line','research_output','research_project','research_software','research_dataset');

    private Eduardo_Research_Manager_Translation_Editor $editor;
    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(
        ?Eduardo_Research_Manager_Translation_Editor $editor = null,
        ?Eduardo_Research_Manager_Remote_Audit $audit = null
    ) {
        $this->editor = $editor ?: Eduardo_Research_Manager::translation_editor();
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
    }

    public static function allowed_types(): array { return self::ALLOWED_TYPES; }

    public function create_plan(string $operation, array $payload, array $actor): array|WP_Error {
        $operation = sanitize_key($operation);
        if (! in_array($operation, array('translation-pair','translation-unpair'), true)) {
            return $this->error('validation_failed', 'Unsupported Research translation operation.', 400);
        }
        $evidence_confirmed = ! empty($payload['evidence_confirmed']);
        $evidence_reference = sanitize_text_field((string) ($payload['evidence_reference'] ?? ''));

        if ('translation-pair' === $operation) {
            $first_id = absint($payload['first_id'] ?? 0);
            $second_id = absint($payload['second_id'] ?? 0);
            if ($first_id <= 0 || $second_id <= 0) {
                return $this->error('validation_failed', 'translation-pair requires first_id and second_id.', 400);
            }
            $first = $this->structured_record($first_id);
            if (is_wp_error($first)) { return $first; }
            $second = $this->structured_record($second_id);
            if (is_wp_error($second)) { return $second; }
            $prepared = $this->editor->preview_pair($first_id, $second_id, $evidence_confirmed, $evidence_reference);
            if (is_wp_error($prepared)) { return $this->normalise($prepared, 400); }
            $target = array(
                'resource'=>'research-translation-pair',
                'post_type'=>(string) ($first['post_type'] ?? ''),
                'first_id'=>$first_id,'second_id'=>$second_id,
                'first_language'=>(string) ($first['language'] ?? ''),
                'second_language'=>(string) ($second['language'] ?? ''),
            );
            $requested = array('first_id'=>$first_id,'second_id'=>$second_id);
        } else {
            $post_id = absint($payload['post_id'] ?? 0);
            if ($post_id <= 0) { return $this->error('validation_failed', 'translation-unpair requires post_id.', 400); }
            $first = $this->structured_record($post_id);
            if (is_wp_error($first)) { return $first; }
            $prepared = $this->editor->preview_unpair($post_id, $evidence_confirmed, $evidence_reference);
            if (is_wp_error($prepared)) { return $this->normalise($prepared, 400); }
            $second_id = absint($prepared['second_id'] ?? 0);
            $second = $this->structured_record($second_id);
            if (is_wp_error($second)) { return $second; }
            $target = array(
                'resource'=>'research-translation-pair',
                'post_type'=>(string) ($first['post_type'] ?? ''),
                'first_id'=>$post_id,'second_id'=>$second_id,
                'first_language'=>(string) ($first['language'] ?? ''),
                'second_language'=>(string) ($second['language'] ?? ''),
            );
            $requested = array('post_id'=>$post_id,'counterpart_id'=>$second_id);
        }

        if ((string) ($first['post_type'] ?? '') !== (string) ($second['post_type'] ?? '')) {
            return $this->error('validation_failed', 'Research translation targets must use the same supported type.', 400);
        }
        $revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        if (is_wp_error($revision)) { return $revision; }
        $now = time();
        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $risk = sanitize_key((string) ($prepared['risk'] ?? 'editorial-review')) ?: 'editorial-review';
        $material = array(
            'operation'=>$operation,'target'=>$target,'requested'=>$requested,
            'baseline_checksum'=>(string) ($prepared['baseline_checksum'] ?? ''),
            'source_revision'=>$revision,'evidence_reference'=>$evidence_reference,'connection_id'=>$connection_id,
        );
        $plan_id = 'erm-translation-plan-' . substr(hash('sha256', (string) wp_json_encode($material)), 0, 18) . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
        $plan = array(
            'plan_id'=>$plan_id,'operation'=>$operation,'risk'=>$risk,'confirmation_required'=>true,
            'confirmation_class'=>'evidence-required' === $risk ? 'evidence-explicit' : 'explicit',
            'target'=>$target,'requested'=>$requested,'prepared'=>$prepared,'preview'=>(array) ($prepared['preview'] ?? array()),
            'apply_allowed'=>! empty($prepared['apply_allowed']),'source_revision'=>$revision,
            'evidence_confirmed'=>$evidence_confirmed,'evidence_reference'=>$evidence_reference,
            'connection_id'=>$connection_id,'created_by'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'created_request_id'=>(string) ($actor['request_id'] ?? ''),'created_at'=>gmdate(DATE_W3C, $now),
            'expires_at'=>gmdate(DATE_W3C, $now + self::PLAN_TTL),'expires_unix'=>$now + self::PLAN_TTL,'status'=>'planned',
        );
        $this->put(self::PLANS_OPTION, $plan_id, $plan, 60);
        $this->audit('remote-translation-plan-created', 'success', $plan, $actor);
        return $this->public_plan($plan);
    }

    public function get_plan(string $id): array|WP_Error {
        $plan = $this->get(self::PLANS_OPTION, $id);
        if (! $plan) { return $this->error('operation_not_found', 'Research translation plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired'; $this->put(self::PLANS_OPTION, $id, $plan, 60);
        }
        return $this->public_plan($plan);
    }

    public function apply(string $id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) { return $this->error('capability_unavailable', 'Local execution identity cannot apply Research translations.', 403); }
        $plan = $this->get(self::PLANS_OPTION, $id);
        if (! $plan) { return $this->error('operation_not_found', 'Research translation plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This translation plan belongs to another connection.', 403); }
        if ('planned' !== (string) ($plan['status'] ?? '')) {
            if ('applied' === (string) ($plan['status'] ?? '') && ! empty($plan['operation_id'])) { return $this->get_operation((string) $plan['operation_id']); }
            return $this->error('validation_failed', 'Only a planned Research translation can be applied.', 409);
        }
        if (time() > (int) ($plan['expires_unix'] ?? 0)) { $plan['status']='expired'; $this->put(self::PLANS_OPTION,$id,$plan,60); return $this->error('plan_expired','Research translation plan expired.',409); }
        if (empty($plan['apply_allowed'])) { return $this->error('validation_failed', 'Stored Research translation Preview does not permit Apply.', 409); }
        if (! $confirmed) { return $this->error('confirmation_required', 'Research translation Apply requires explicit confirmation.', 409); }
        $revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        if (is_wp_error($revision)) { return $revision; }
        if (! hash_equals((string) ($plan['source_revision'] ?? ''), $revision)) { return $this->error('stale_revision', 'Managed WordPress state changed after translation Preview.', 409); }
        $result = $this->editor->apply_preview((array) ($plan['prepared'] ?? array()));
        if (is_wp_error($result)) { return $this->normalise($result, 409); }
        $operation_id = 'erm-translation-op-' . str_replace('-', '', wp_generate_uuid4());
        $post_revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        $operation = array(
            'operation_id'=>$operation_id,'plan_id'=>$id,'operation'=>(string) $plan['operation'],'risk'=>(string) ($plan['risk'] ?? 'editorial-review'),
            'target'=>(array) $plan['target'],'requested'=>(array) $plan['requested'],'source_revision'=>(string) $plan['source_revision'],
            'post_apply_revision'=>is_wp_error($post_revision) ? '' : $post_revision,
            'evidence_reference'=>(string) ($plan['evidence_reference'] ?? ''),'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),'status'=>'applied','result'=>$result,
            'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),'stored_verification'=>array(),'rendered_verification'=>array(),
            'created_at'=>gmdate(DATE_W3C),'applied_at'=>gmdate(DATE_W3C),'verified_at'=>'','rolled_back_at'=>'',
        );
        $this->put(self::OPERATIONS_OPTION,$operation_id,$operation,120);
        $plan['status']='applied'; $plan['operation_id']=$operation_id; $plan['applied_at']=gmdate(DATE_W3C); $this->put(self::PLANS_OPTION,$id,$plan,60);
        $this->audit('remote-translation-operation-applied','success',$operation,$actor);
        return $operation;
    }

    public function get_operation(string $id): array|WP_Error {
        $operation=$this->get(self::OPERATIONS_OPTION,$id);
        return $operation ?: $this->error('operation_not_found','Research translation operation was not found.',404);
    }

    public function verify(string $id, bool $rendered, array $actor): array|WP_Error {
        $operation=$this->get(self::OPERATIONS_OPTION,$id);
        if (!$operation) return $this->error('operation_not_found','Research translation operation was not found.',404);
        if ((string)$operation['connection_id']!==(string)($actor['connection_id']??'')) return $this->error('scope_denied','This translation operation belongs to another connection.',403);
        $first_id=absint($operation['target']['first_id']??0); $second_id=absint($operation['target']['second_id']??0);
        if($first_id<=0||$second_id<=0) return $this->error('validation_failed','Translation operation is missing targets.',409);
        if('translation-pair'===(string)$operation['operation']) {
            $semantic=Eduardo_Research_Manager::translations()->verify_pair($first_id,$second_id);
        } else {
            $first=Eduardo_Research_Manager::translations()->verify_unpaired($first_id); $second=Eduardo_Research_Manager::translations()->verify_unpaired($second_id);
            if(is_wp_error($first)) return $this->normalise($first,409); if(is_wp_error($second)) return $this->normalise($second,409);
            $semantic=array('verified'=>!empty($first['verified'])&&!empty($second['verified']),'first'=>$first,'second'=>$second,'verified_at'=>gmdate(DATE_W3C));
        }
        if(is_wp_error($semantic)) return $this->normalise($semantic,409);
        $stored=array('verified'=>!empty($semantic['verified']),'semantic'=>$semantic,'evidence_reference'=>(string)($operation['evidence_reference']??''),'current_revision'=>$this->safe_revision(),'verified_at'=>gmdate(DATE_W3C));
        $render=array('requested'=>$rendered,'verified'=>null,'resources'=>array());
        if($rendered){ $resources=array(); $ok=true; foreach(array($first_id,$second_id) as $post_id){ $r=Eduardo_Research_Manager::rendered()->verify_record($post_id); if(is_wp_error($r)){ $resources[]=array('post_id'=>$post_id,'verified'=>false,'error_code'=>$r->get_error_code(),'error'=>$r->get_error_message()); $ok=false; } else { $resources[]=$r; $ok=$ok&&!empty($r['verified']); } } $render=array('requested'=>true,'verified'=>$ok,'resources'=>$resources,'verified_at'=>gmdate(DATE_W3C)); }
        $verified=!empty($stored['verified'])&&(!$rendered||!empty($render['verified'])); $operation['stored_verification']=$stored; $operation['rendered_verification']=$render; $operation['status']=$verified?'verified':'verification-failed'; $operation['verified_at']=gmdate(DATE_W3C); $this->put(self::OPERATIONS_OPTION,$id,$operation,120); $this->audit('remote-translation-operation-verified',$verified?'success':'failed',$operation,$actor); return $operation;
    }

    public function rollback(string $id, bool $confirmed, array $actor): array|WP_Error {
        if(!$confirmed) return $this->error('confirmation_required','Research translation rollback requires explicit confirmation.',409);
        $operation=$this->get(self::OPERATIONS_OPTION,$id); if(!$operation) return $this->error('operation_not_found','Research translation operation was not found.',404);
        if((string)$operation['connection_id']!==(string)($actor['connection_id']??'')) return $this->error('scope_denied','This translation operation belongs to another connection.',403);
        if(!in_array((string)$operation['status'],array('applied','verified','verification-failed'),true)){ if('rolled-back'===(string)$operation['status']) return $operation; return $this->error('rollback_unavailable','Research translation operation is not rollback-capable.',409); }
        $snapshot=(string)($operation['snapshot_id']??''); if(''===$snapshot) return $this->error('rollback_unavailable','No Research translation rollback snapshot is available.',409);
        $result=$this->editor->rollback($snapshot); if(is_wp_error($result)) return $this->normalise($result,409);
        $first_id=absint($operation['target']['first_id']??0); $second_id=absint($operation['target']['second_id']??0);
        if('translation-pair'===(string)$operation['operation']) { $a=Eduardo_Research_Manager::translations()->verify_unpaired($first_id); $b=Eduardo_Research_Manager::translations()->verify_unpaired($second_id); $restored=!is_wp_error($a)&&!is_wp_error($b)&&!empty($a['verified'])&&!empty($b['verified']); }
        else { $pair=Eduardo_Research_Manager::translations()->verify_pair($first_id,$second_id); $restored=!is_wp_error($pair)&&!empty($pair['verified']); }
        $operation['status']='rolled-back'; $operation['rollback_result']=$result; $operation['rollback_restored_baseline']=$restored; $operation['rolled_back_at']=gmdate(DATE_W3C); $this->put(self::OPERATIONS_OPTION,$id,$operation,120); $this->audit('remote-translation-operation-rolled-back','success',$operation,$actor); return $operation;
    }

    private function structured_record(int $post_id): array|WP_Error {
        $post=get_post($post_id); if(!$post instanceof WP_Post || !in_array((string)$post->post_type,self::ALLOWED_TYPES,true)) return $this->error('validation_failed','Remote Research translations support Lines, Outputs, Projects, Software and Datasets only.',400);
        $state=$this->editor->inspect($post_id); if(is_wp_error($state)) return $this->normalise($state,400); return $state;
    }
    private function audit(string $event,string $outcome,array $state,array $actor): void { $this->audit->record(array('request_id'=>(string)($actor['request_id']??''),'connection_id'=>(string)($actor['connection_id']??''),'wordpress_user_id'=>(int)($actor['wordpress_user_id']??0),'event'=>$event,'outcome'=>$outcome,'scope'=>'translations.write','plan_id'=>(string)($state['plan_id']??''),'operation_id'=>(string)($state['operation_id']??''),'operation'=>(string)($state['operation']??''),'evidence_reference'=>(string)($state['evidence_reference']??''))); }
    private function safe_revision(): string { $r=Eduardo_Research_Manager::remote_operations()->revision_fingerprint(); return is_wp_error($r)?'':$r; }
    private function public_plan(array $p): array { unset($p['prepared'],$p['expires_unix']); return $p; }
    private function get(string $option,string $id): array { $all=get_option($option,array()); $all=is_array($all)?$all:array(); $v=$all[sanitize_text_field($id)]??array(); return is_array($v)?$v:array(); }
    private function put(string $option,string $id,array $value,int $limit): void { $all=get_option($option,array()); $all=is_array($all)?$all:array(); $all[$id]=$value; while(count($all)>$limit) array_shift($all); if(false===get_option($option,false)) add_option($option,$all,'',false); else update_option($option,$all,false); }
    private function normalise(WP_Error $e,int $status): WP_Error { $d=$e->get_error_data(); if(!is_array($d)||!isset($d['status'])) $e->add_data(array_merge(is_array($d)?$d:array(),array('status'=>$status))); return $e; }
    private function error(string $code,string $message,int $status): WP_Error { return new WP_Error($code,$message,array('status'=>$status)); }
}
