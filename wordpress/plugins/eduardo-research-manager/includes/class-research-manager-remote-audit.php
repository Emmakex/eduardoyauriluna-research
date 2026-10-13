<?php
/** Bounded non-secret audit trail for the authenticated Research Manager bridge. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Audit {
    private const OPTION = 'eduardo_research_manager_remote_audit';
    private const MAX_RECORDS = 200;
    private const MAX_PAGE_SIZE = 100;

    public function __construct() { (new Eduardo_Research_Manager_Remote_Audit_REST($this))->register(); }

    public function record(array $record): array {
        $record=$this->enrich_from_operation_state($record);
        $entry=array(
            'event_id'=>'rma_'.str_replace('-','',wp_generate_uuid4()),'timestamp'=>gmdate(DATE_W3C),
            'request_id'=>$this->text($record['request_id']??''),'connection_id'=>$this->text($record['connection_id']??''),'wordpress_user_id'=>max(0,(int) ($record['wordpress_user_id']??0)),
            'method'=>strtoupper(substr($this->text($record['method']??''),0,12)),'route'=>substr($this->text($record['route']??''),0,220),'scope'=>substr($this->text($record['scope']??''),0,80),
            'event'=>substr($this->text($record['event']??'request'),0,80),'outcome'=>substr($this->text($record['outcome']??''),0,40),'error_code'=>substr(sanitize_key((string) ($record['error_code']??'')),0,80),
            'operation_id'=>substr($this->text($record['operation_id']??''),0,100),'plan_id'=>substr($this->text($record['plan_id']??''),0,100),'operation'=>substr(sanitize_key((string) ($record['operation']??'')),0,100),
            'target'=>$this->target_summary($record['target']??array()),'source_revision'=>substr($this->text($record['source_revision']??''),0,160),'resulting_revision'=>substr($this->text($record['resulting_revision']??$record['post_apply_revision']??''),0,160),
            'apply_status'=>substr(sanitize_key((string) ($record['apply_status']??'')),0,60),'lifecycle_status'=>substr(sanitize_key((string) ($record['lifecycle_status']??$record['status']??'')),0,60),
            'stored_verification_status'=>substr(sanitize_key((string) ($record['stored_verification_status']??'')),0,60),'rendered_verification_status'=>substr(sanitize_key((string) ($record['rendered_verification_status']??'')),0,60),
            'rollback_available'=>(bool) ($record['rollback_available']??false),'rollback_status'=>substr(sanitize_key((string) ($record['rollback_status']??'')),0,60),'reason'=>substr($this->text($record['reason']??''),0,500),
        );
        $records=get_option(self::OPTION,array());$records=is_array($records)?array_values($records):array();array_unshift($records,$entry);$records=array_slice($records,0,self::MAX_RECORDS);
        if(false===get_option(self::OPTION,false)){add_option(self::OPTION,$records,'',false);}else{update_option(self::OPTION,$records,false);}return $entry;
    }

    public function recent(int $limit=50): array {$result=$this->query(array('page'=>1,'per_page'=>$limit));return (array) ($result['items']??array());}
    public function query(array $args=array()): array {
        $records=get_option(self::OPTION,array());$records=is_array($records)?array_values($records):array();$page=max(1,(int) ($args['page']??1));$per_page=max(1,min(self::MAX_PAGE_SIZE,(int) ($args['per_page']??50)));
        $filters=array(
            'request_id'=>substr($this->text($args['request_id']??''),0,120),'operation_id'=>substr($this->text($args['operation_id']??''),0,100),'connection_id'=>substr($this->text($args['connection_id']??''),0,120),'outcome'=>substr($this->text($args['outcome']??''),0,40),
            'operation'=>substr(sanitize_key((string) ($args['operation']??'')),0,100),'apply_status'=>substr(sanitize_key((string) ($args['apply_status']??'')),0,60),'rollback_status'=>substr(sanitize_key((string) ($args['rollback_status']??'')),0,60),'lifecycle_status'=>substr(sanitize_key((string) ($args['lifecycle_status']??'')),0,60),
        );
        $filtered=array_values(array_filter($records,static function($record)use($filters):bool{if(!is_array($record)){return false;}foreach($filters as $field=>$value){if(''!==$value&&(string) ($record[$field]??'')!==$value){return false;}}return true;}));
        $total=count($filtered);$offset=($page-1)*$per_page;$items=$offset<$total?array_slice($filtered,$offset,$per_page):array();
        return array('page'=>$page,'per_page'=>$per_page,'total'=>$total,'total_pages'=>0===$total?0:(int) ceil($total/$per_page),'filters'=>array_filter($filters,static fn(string $value):bool=>''!==$value),'items'=>$items);
    }
    public function clear(): void {delete_option(self::OPTION);}

    private function enrich_from_operation_state(array $record): array {
        $operation_id=$this->text($record['operation_id']??''); if(''===$operation_id){return $record;}
        $state=$this->find_operation_state($operation_id); if(!$state){return $record;}
        foreach(array('plan_id','operation','target','source_revision','post_apply_revision') as $field){if((!isset($record[$field])||''===$record[$field]||array()===$record[$field])&&isset($state[$field])){$record[$field]=$state[$field];}}
        $status=sanitize_key((string) ($state['status']??''));$record['lifecycle_status']=$status;
        if(''===($record['apply_status']??'')){$record['apply_status']=in_array($status,array('applied','verified','verification-failed','rolled-back'),true)?'applied':$status;}
        if(''===($record['resulting_revision']??'')){$record['resulting_revision']=(string) ($state['post_apply_revision']??$state['resulting_revision']??'');}
        if(''===($record['stored_verification_status']??'')){$record['stored_verification_status']=$this->verification_status($state['stored_verification']??$state['verification']??array());}
        if(''===($record['rendered_verification_status']??'')){$record['rendered_verification_status']=$this->verification_status($state['rendered_verification']??array(),true);}
        $rollback_available=in_array($status,array('applied','verified','verification-failed'),true);$record['rollback_available']=$record['rollback_available']??$rollback_available;
        if(''===($record['rollback_status']??'')){$record['rollback_status']='rolled-back'===$status?'rolled-back':($rollback_available?'available':'unavailable');}
        return $record;
    }

    private function find_operation_state(string $operation_id): array {
        $stores=array(
            'eduardo_research_manager_remote_operations','eduardo_research_manager_remote_navigation_operations','eduardo_research_manager_remote_media_metadata_operations','eduardo_research_manager_remote_media_assignment_operations','eduardo_research_manager_remote_media_ingest_operations',
            'eduardo_research_manager_remote_insight_pairing_operations','eduardo_research_manager_remote_object_operations','eduardo_research_manager_remote_line_operations','eduardo_research_manager_remote_translation_operations','eduardo_research_manager_remote_evidence_operations',
            'eduardo_research_manager_remote_seo_geo_operations','eduardo_research_manager_remote_design_operations','eduardo_research_manager_remote_design_variant_operations','eduardo_research_manager_remote_page_state_operations',
        );
        foreach($stores as $option){$all=get_option($option,array());if(is_array($all)&&is_array($all[$operation_id]??null)){return $all[$operation_id];}}
        return array();
    }
    private function verification_status(mixed $value,bool $rendered=false): string {
        if(!is_array($value)||!$value){return $rendered?'not-requested':'pending';}
        if(array_key_exists('requested',$value)&&empty($value['requested'])){return 'not-requested';}
        if(!array_key_exists('verified',$value)||null===$value['verified']){return 'pending';}
        return !empty($value['verified'])?'verified':'failed';
    }
    private function target_summary(mixed $value): array {if(!is_array($value)){return array();}$allowed=array('resource','type','key','page_id','post_id','attachment_id','group','record_id','language','first_id','second_id');$summary=array();foreach($allowed as $key){if(!array_key_exists($key,$value)||!is_scalar($value[$key])){continue;}$summary[$key]=is_int($value[$key])?(int) $value[$key]:substr($this->text($value[$key]),0,120);}return $summary;}
    private function text(mixed $value): string {return is_scalar($value)?sanitize_text_field((string) $value):'';}
}

require_once __DIR__ . '/class-research-manager-remote-audit-rest.php';
