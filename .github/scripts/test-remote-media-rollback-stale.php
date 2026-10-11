<?php
/** Focused acceptance: Media rollback must not overwrite changes made after Apply. */
wp_set_current_user(1);

function fail_p3_media_rollback_stale(string $message,mixed $context=null): never {
    fwrite(STDERR,"P3 MEDIA ROLLBACK STALE FAIL: {$message}\n");
    if(null!==$context) fwrite(STDERR,wp_json_encode($context,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    exit(1);
}

function p3_media_rollback_request(string $method,string $route,string $token,array $body=array()): WP_REST_Response {
    $request=new WP_REST_Request($method,'/research-manager/v1/'.ltrim($route,'/'));
    $request->set_header('Authorization','Bearer '.$token);
    $request->set_header('X-Research-Manager-Request-Id','req-'.wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce','nonce-'.str_replace('-','',wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp',(string)time());
    if($body){ $request->set_header('Content-Type','application/json'); $request->set_body((string)wp_json_encode($body)); }
    return rest_do_request($request);
}

$upload=wp_upload_bits('p3-media-rollback-stale.png',null,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
if(!empty($upload['error'])||empty($upload['file'])) fail_p3_media_rollback_stale('fixture upload failed',$upload);
$attachment_id=wp_insert_attachment(array('post_mime_type'=>'image/png','post_title'=>'Rollback source','post_status'=>'inherit'),(string)$upload['file']);
if(is_wp_error($attachment_id)||(int)$attachment_id<=0) fail_p3_media_rollback_stale('attachment insert failed',$attachment_id);
$attachment_id=(int)$attachment_id; update_attached_file($attachment_id,(string)$upload['file']); update_post_meta($attachment_id,'_wp_attachment_image_alt','Source alt');
register_shutdown_function(static function()use($attachment_id):void{ if(get_post($attachment_id)) wp_delete_attachment($attachment_id,true); });
rest_get_server();

$issued=Eduardo_Research_Manager::remote_credentials()->issue_token(array('site.read','site.diagnostics','media.read','media.write','operations.apply','operations.rollback'),1,'CI P3 Media rollback stale');
if(is_wp_error($issued)||empty($issued['token'])) fail_p3_media_rollback_stale('credential issuance failed',$issued);
$token=(string)$issued['token'];

$plan=p3_media_rollback_request('POST','media/metadata/plan',$token,array('operation'=>'media-metadata-update','payload'=>array('attachment_id'=>$attachment_id,'changes'=>array('title'=>'Applied title'))));
$plan_id=(string)($plan->get_data()['data']['plan_id']??'');
if(200!==$plan->get_status()||''===$plan_id) fail_p3_media_rollback_stale('plan failed',$plan->get_data());
$apply=p3_media_rollback_request('POST','media/metadata/plans/'.rawurlencode($plan_id).'/apply',$token,array('confirm'=>true));
$operation_id=(string)($apply->get_data()['data']['operation_id']??'');
if(200!==$apply->get_status()||''===$operation_id) fail_p3_media_rollback_stale('apply failed',$apply->get_data());

wp_update_post(array('ID'=>$attachment_id,'post_title'=>'Newer concurrent title')); clean_post_cache($attachment_id);
$blocked=p3_media_rollback_request('POST','media/metadata/operations/'.rawurlencode($operation_id).'/rollback',$token,array('confirm'=>true));
if(409!==$blocked->get_status()||'stale_revision'!==(string)($blocked->get_data()['code']??'')) fail_p3_media_rollback_stale('rollback overwrote or accepted newer state',$blocked->get_data());
$current=get_post($attachment_id);
if(!$current instanceof WP_Post||'Newer concurrent title'!==$current->post_title) fail_p3_media_rollback_stale('blocked rollback changed concurrent state',$current);

wp_update_post(array('ID'=>$attachment_id,'post_title'=>'Applied title')); clean_post_cache($attachment_id);
$rollback=p3_media_rollback_request('POST','media/metadata/operations/'.rawurlencode($operation_id).'/rollback',$token,array('confirm'=>true));
if(200!==$rollback->get_status()||'rolled-back'!==(string)($rollback->get_data()['data']['status']??'')) fail_p3_media_rollback_stale('rollback after exact post-Apply restoration failed',$rollback->get_data());
$restored=get_post($attachment_id);
if(!$restored instanceof WP_Post||'Rollback source'!==$restored->post_title) fail_p3_media_rollback_stale('source title was not restored',$restored);

wp_delete_attachment($attachment_id,true);
fwrite(STDOUT,"P3 Media rollback stale protection: PASS\n");
