<?php
/** Fresh-WordPress acceptance for M5 remote Research Lines. */
wp_set_current_user(1);

function m5l_fail(string $message, mixed $context=null): never { fwrite(STDERR,"M5 LINES FAIL: {$message}\n"); if(null!==$context) fwrite(STDERR,wp_json_encode($context,JSON_PRETTY_PRINT)."\n"); exit(1); }
function m5l_request(string $method,string $route,string $token,array $body=array(),string $request_id=''): WP_REST_Response {
    $r=new WP_REST_Request($method,'/research-manager/v1/'.ltrim($route,'/'));
    $r->set_header('Authorization','Bearer '.$token); $r->set_header('X-Research-Manager-Request-Id',''!==$request_id?$request_id:'req-'.wp_generate_uuid4()); $r->set_header('X-Research-Manager-Nonce','nonce-'.str_replace('-','',wp_generate_uuid4())); $r->set_header('X-Research-Manager-Timestamp',(string)time());
    if($body){$r->set_header('Content-Type','application/json');$r->set_body((string)wp_json_encode($body));} return rest_do_request($r);
}
function m5l_plan(string $token,string $operation,array $payload,string $rid=''): array { $r=m5l_request('POST','research-lines/plan',$token,array('operation'=>$operation,'payload'=>$payload),$rid); if(200!==$r->get_status())m5l_fail('plan failed',$r->get_data()); return (array)($r->get_data()['data']??array()); }
function m5l_apply(string $token,string $plan_id): array { $r=m5l_request('POST','research-lines/plans/'.rawurlencode($plan_id).'/apply',$token,array('confirm'=>true)); if(200!==$r->get_status())m5l_fail('apply failed',$r->get_data()); $d=(array)($r->get_data()['data']??array()); if(empty($d['operation_id'])||empty($d['result']['verified']))m5l_fail('apply result invalid',$d); return $d; }
function m5l_verify(string $token,string $op,bool $rendered=true): array { $r=m5l_request('POST','research-lines/operations/'.rawurlencode($op).'/verify',$token,array('rendered'=>$rendered)); if(200!==$r->get_status())m5l_fail('verify request failed',$r->get_data()); $d=(array)($r->get_data()['data']??array()); if(empty($d['stored_verification']['verified'])||($rendered&&empty($d['rendered_verification']['verified'])))m5l_fail('verify failed',$d); return $d; }
function m5l_rollback(string $token,string $op): array { $r=m5l_request('POST','research-lines/operations/'.rawurlencode($op).'/rollback',$token,array('confirm'=>true)); if(200!==$r->get_status())m5l_fail('rollback failed',$r->get_data()); $d=(array)($r->get_data()['data']??array()); if('rolled-back'!==(string)($d['status']??''))m5l_fail('rollback status invalid',$d); return $d; }

$blueprint=Eduardo_Research_Manager::blueprint_store()->canonical(); if(is_wp_error($blueprint))m5l_fail('blueprint unavailable'); $seed=Eduardo_Research_Manager::pipeline()->apply($blueprint); if(is_wp_error($seed)||empty($seed['verified']))m5l_fail('seed failed',$seed); rest_get_server();

$issued=Eduardo_Research_Manager::remote_credentials()->issue_token(array('site.read','site.diagnostics','research.read','research.write','operations.apply','operations.rollback'),1,'CI M5 Lines'); if(is_wp_error($issued)||empty($issued['token']))m5l_fail('token issuance failed'); $token=(string)$issued['token'];
$cap=m5l_request('GET','capabilities',$token); $control=(array)($cap->get_data()['data']['research_line_control']??array()); if(200!==$cap->get_status()||'M5'!==(string)($control['milestone']??'')||empty($control['inventory'])||empty($control['evidence_gate']['required_for_creation'])||!in_array('line-create',(array)($control['operations']??array()),true)||!in_array('line-update',(array)($control['operations']??array()),true))m5l_fail('capabilities incomplete',$cap->get_data());

$data=array('title'=>'M5 Remote Research Line','slug'=>'m5-remote-line-'.substr(str_replace('-','',wp_generate_uuid4()),0,8),'excerpt'=>'Bounded line fixture.','content'=>'<p>Research Line content.</p>','language'=>'en','status'=>'draft','evidence_status'=>'unverified','research_status'=>'planned','central_question'=>'How can bounded research systems be operated safely?','order'=>'90','topics'=>array('AI','systems'),'methods'=>array('design research'));
$blocked=m5l_plan($token,'line-create',array('data'=>$data)); if(!empty($blocked['apply_allowed'])||'evidence-required'!==(string)($blocked['risk']??''))m5l_fail('evidence gate did not block creation',$blocked);
$blocked_apply=m5l_request('POST','research-lines/plans/'.rawurlencode((string)$blocked['plan_id']).'/apply',$token,array('confirm'=>true)); if(409!==$blocked_apply->get_status())m5l_fail('blocked line unexpectedly applied',$blocked_apply->get_data());

$rid='req-m5-line-'.substr(str_replace('-','',wp_generate_uuid4()),0,20); $payload=array('data'=>$data,'evidence_confirmed'=>true,'evidence_reference'=>'ci:m5-line:create'); $plan=m5l_plan($token,'line-create',$payload,$rid); if(empty($plan['apply_allowed']))m5l_fail('confirmed line plan blocked',$plan);
$replay=m5l_request('POST','research-lines/plan',$token,array('operation'=>'line-create','payload'=>$payload),$rid); if(200!==$replay->get_status()||empty($replay->get_data()['idempotent_replay'])||(string)($replay->get_data()['data']['plan_id']??'')!==(string)$plan['plan_id'])m5l_fail('idempotent plan replay failed',$replay->get_data());
$created=m5l_apply($token,(string)$plan['plan_id']); $create_op=(string)$created['operation_id']; $line_id=(int)($created['target']['post_id']??0); if($line_id<=0)m5l_fail('creation missing line id',$created);
$verified=m5l_verify($token,$create_op,true); $render=(array)($verified['rendered_verification']['resources'][0]??array()); if(empty($render['skipped'])||'draft-or-non-public'!==(string)($render['reason']??''))m5l_fail('draft rendered skip invalid',$verified);
$inspect=m5l_request('GET','research-lines/'.$line_id,$token); if(200!==$inspect->get_status()||$line_id!==(int)($inspect->get_data()['data']['post_id']??0))m5l_fail('inspect failed',$inspect->get_data());

$changes=array('title'=>'M5 Remote Research Line Updated','evidence_status'=>'verified','research_status'=>'active','central_question'=>'How can ChatGPT govern bounded research operations?','topics'=>array('AI','governance'),'methods'=>array('design research','verification'));
$update_plan=m5l_plan($token,'line-update',array('post_id'=>$line_id,'changes'=>$changes,'evidence_confirmed'=>true,'evidence_reference'=>'ci:m5-line:update')); if(empty($update_plan['apply_allowed']))m5l_fail('update plan blocked',$update_plan);
$update=m5l_apply($token,(string)$update_plan['plan_id']); $update_op=(string)$update['operation_id']; m5l_verify($token,$update_op,false); $after=Eduardo_Research_Manager::line_editor()->inspect($line_id); if(is_wp_error($after)||'verified'!==(string)($after['evidence_status']??'')||'active'!==(string)($after['research_status']??'')||array('AI','governance')!==(array)($after['topics']??array()))m5l_fail('updated line state mismatch',$after);

$inventory=m5l_request('GET','research-lines',$token); $items=(array)($inventory->get_data()['data']['items']??array()); $found=false; foreach($items as $row){if((int)($row['post_id']??0)===$line_id)$found=true;} if(200!==$inventory->get_status()||!$found)m5l_fail('line inventory incomplete',$inventory->get_data());

$stale=m5l_plan($token,'line-update',array('post_id'=>$line_id,'changes'=>array('excerpt'=>'Planned stale line edit'),'evidence_confirmed'=>true,'evidence_reference'=>'ci:m5-line:stale')); wp_update_post(array('ID'=>$line_id,'post_excerpt'=>'External change after Preview')); $stale_apply=m5l_request('POST','research-lines/plans/'.rawurlencode((string)$stale['plan_id']).'/apply',$token,array('confirm'=>true)); $code=(string)($stale_apply->get_data()['code']??''); if(409!==$stale_apply->get_status()||!in_array($code,array('stale_revision','research_manager_line_editor_stale_preview'),true))m5l_fail('stale line Preview was not rejected',$stale_apply->get_data()); wp_update_post(array('ID'=>$line_id,'post_excerpt'=>'Bounded line fixture.'));

m5l_rollback($token,$update_op); $restored=Eduardo_Research_Manager::line_editor()->inspect($line_id); if(is_wp_error($restored)||'M5 Remote Research Line'!==(string)($restored['title']??'')||'unverified'!==(string)($restored['evidence_status']??''))m5l_fail('update rollback failed',$restored);
m5l_rollback($token,$create_op); if(get_post($line_id) instanceof WP_Post)m5l_fail('creation rollback did not remove line',$line_id);

fwrite(STDOUT,"M5 Research Line remote lifecycle OK\n");
