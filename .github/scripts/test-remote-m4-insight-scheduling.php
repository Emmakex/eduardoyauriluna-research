<?php
/** Fresh-WordPress acceptance for bounded M4 Research Insight scheduling. */
wp_set_current_user(1);

function m4s_fail(string $message, mixed $context = null): never {
    fwrite(STDERR, "M4 SCHEDULING FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m4s_request(string $method, string $route, string $token, array $body = array()): WP_REST_Response {
    $request = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', 'req-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($body) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($request);
}

function m4s_create_insight(string $status): array {
    $preview = Eduardo_Research_Manager::insight_editor()->preview_create(array(
        'title'=>'M4 Scheduling ' . ucfirst($status) . ' Insight',
        'slug'=>'m4-schedule-' . $status . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
        'excerpt'=>'Scheduling acceptance fixture.',
        'content'=>'<p>Scheduling acceptance fixture.</p>',
        'language'=>'en',
        'insight_type'=>'research_note',
        'status'=>$status,
    ));
    if (is_wp_error($preview) || empty($preview['apply_allowed'])) { m4s_fail('Insight fixture Preview failed', $preview); }
    $result = Eduardo_Research_Manager::insight_editor()->apply_preview($preview);
    if (is_wp_error($result) || empty($result['verified']) || empty($result['post_id']) || empty($result['snapshot_id'])) {
        m4s_fail('Insight fixture Apply failed', $result);
    }
    return array('post_id'=>(int) $result['post_id'],'snapshot_id'=>(string) $result['snapshot_id']);
}

function m4s_mysql_dates(int $timestamp): array {
    $utc = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
    $local = $utc->setTimezone(wp_timezone());
    return array('post_date'=>$local->format('Y-m-d H:i:s'),'post_date_gmt'=>$utc->format('Y-m-d H:i:s'));
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { m4s_fail('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { m4s_fail('canonical seed failed', $seed); }

rest_get_server();
$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','operations.apply','operations.rollback'),
    1,
    'CI M4 Insight Scheduling'
);
if (is_wp_error($issued) || empty($issued['token'])) { m4s_fail('credential issuance failed'); }
$token = (string) $issued['token'];

$cap_response = m4s_request('GET', 'capabilities', $token);
$cap = $cap_response->get_data();
$scheduling = (array) ($cap['data']['insight_control']['scheduling'] ?? array());
if (200 !== $cap_response->get_status()
    || empty($scheduling['available'])
    || 'scheduled_at' !== (string) ($scheduling['field'] ?? '')
    || 'insight-update' !== (string) ($scheduling['operation'] ?? '')
    || empty($scheduling['future_only'])
    || ! in_array('scheduled_at', (array) ($cap['data']['insight_control']['update_fields'] ?? array()), true)) {
    m4s_fail('capabilities do not expose bounded scheduling', $cap);
}

$draft = m4s_create_insight('draft');
$draft_id = $draft['post_id'];
$draft_snapshot = $draft['snapshot_id'];
$before_post = get_post($draft_id);
if (! $before_post instanceof WP_Post) { m4s_fail('draft fixture disappeared'); }
$before_dates = array('post_date'=>(string) $before_post->post_date,'post_date_gmt'=>(string) $before_post->post_date_gmt);

$past = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
$past_plan = m4s_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array('post_id'=>$draft_id,'changes'=>array('scheduled_at'=>$past)),
));
if (400 !== $past_plan->get_status() || 'research_manager_insight_schedule_not_future' !== (string) ($past_plan->get_data()['code'] ?? '')) {
    m4s_fail('past scheduling time was not rejected', $past_plan->get_data());
}

$future_at = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
$mixed = m4s_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array('post_id'=>$draft_id,'changes'=>array('scheduled_at'=>$future_at,'title'=>'Mixed scheduling update')),
));
if (400 !== $mixed->get_status() || 'research_manager_insight_schedule_requires_separate_operation' !== (string) ($mixed->get_data()['code'] ?? '')) {
    m4s_fail('mixed scheduling/content Preview was not rejected', $mixed->get_data());
}

$published = m4s_create_insight('publish');
$published_plan = m4s_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array('post_id'=>$published['post_id'],'changes'=>array('scheduled_at'=>$future_at)),
));
if (400 !== $published_plan->get_status() || 'research_manager_insight_schedule_requires_draft' !== (string) ($published_plan->get_data()['code'] ?? '')) {
    m4s_fail('published Insight was accepted for direct scheduling', $published_plan->get_data());
}
$published_cleanup = Eduardo_Research_Manager::insight_editor()->rollback((string) $published['snapshot_id']);
if (is_wp_error($published_cleanup)) { m4s_fail('published fixture cleanup failed', $published_cleanup->get_error_message()); }

$plan_response = m4s_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array(
        'post_id'=>$draft_id,
        'changes'=>array('scheduled_at'=>$future_at),
        'reason'=>'CI bounded Insight scheduling',
    ),
));
$plan = $plan_response->get_data()['data'] ?? array();
$plan_id = (string) ($plan['plan_id'] ?? '');
if (200 !== $plan_response->get_status() || '' === $plan_id || empty($plan['apply_allowed']) || 'editorial-review' !== (string) ($plan['risk'] ?? '')) {
    m4s_fail('scheduling plan contract invalid', $plan_response->get_data());
}
$before = Eduardo_Research_Manager::insight_editor()->inspect($draft_id);
if (is_wp_error($before) || 'draft' !== (string) ($before['status'] ?? '') || '' !== (string) ($before['scheduled_at'] ?? '')) {
    m4s_fail('scheduling Preview mutated Insight', $before);
}

$blocked = m4s_request('POST', 'plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>false));
if (409 !== $blocked->get_status() || 'confirmation_required' !== (string) ($blocked->get_data()['code'] ?? '')) {
    m4s_fail('scheduling Apply did not require confirmation', $blocked->get_data());
}

$apply = m4s_request('POST', 'plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
$operation = $apply->get_data()['data'] ?? array();
$operation_id = (string) ($operation['operation_id'] ?? '');
if (200 !== $apply->get_status() || '' === $operation_id || empty($operation['snapshot_id']) || empty($operation['result']['verified']) || 'schedule' !== (string) ($operation['result']['mode'] ?? '')) {
    m4s_fail('scheduling Apply failed', $apply->get_data());
}
$scheduled = Eduardo_Research_Manager::insight_editor()->inspect($draft_id);
if (is_wp_error($scheduled) || 'future' !== (string) ($scheduled['status'] ?? '') || $future_at !== (string) ($scheduled['scheduled_at'] ?? '')) {
    m4s_fail('stored scheduling state mismatch', $scheduled);
}

$seo = m4s_request('GET', 'insights/' . $draft_id . '/seo-geo', $token)->get_data()['data'] ?? array();
if (empty($seo['scheduling']['scheduled']) || empty($seo['scheduling']['verified']) || $future_at !== (string) ($seo['scheduling']['scheduled_at'] ?? '')) {
    m4s_fail('scheduling diagnostics mismatch', $seo);
}

$verify = m4s_request('POST', 'operations/' . rawurlencode($operation_id) . '/verify', $token, array('rendered'=>true));
$verified = $verify->get_data()['data'] ?? array();
$rendered_resources = (array) ($verified['rendered_verification']['resources'] ?? array());
if (200 !== $verify->get_status()
    || empty($verified['stored_verification']['verified'])
    || empty($verified['rendered_verification']['verified'])
    || empty($rendered_resources[0]['skipped'])
    || 'draft-or-non-public' !== (string) ($rendered_resources[0]['reason'] ?? '')) {
    m4s_fail('scheduled Insight Verify failed', $verify->get_data());
}

$reschedule_at = gmdate('Y-m-d\TH:i:s\Z', time() + 7200);
$stale_plan_response = m4s_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array('post_id'=>$draft_id,'changes'=>array('scheduled_at'=>$reschedule_at)),
));
$stale_plan_id = (string) ($stale_plan_response->get_data()['data']['plan_id'] ?? '');
if (200 !== $stale_plan_response->get_status() || '' === $stale_plan_id) { m4s_fail('reschedule stale fixture plan failed', $stale_plan_response->get_data()); }

$external_timestamp = time() + 5400;
$external_dates = m4s_mysql_dates($external_timestamp);
$external = wp_update_post(array(
    'ID'=>$draft_id,
    'post_status'=>'future',
    'post_date'=>$external_dates['post_date'],
    'post_date_gmt'=>$external_dates['post_date_gmt'],
), true);
if (is_wp_error($external)) { m4s_fail('external reschedule fixture failed', $external->get_error_message()); }
clean_post_cache($draft_id);
$stale_apply = m4s_request('POST', 'plans/' . rawurlencode($stale_plan_id) . '/apply', $token, array('confirm'=>true));
if (409 !== $stale_apply->get_status() || 'research_manager_insight_editor_stale_preview' !== (string) ($stale_apply->get_data()['code'] ?? '')) {
    m4s_fail('stale scheduling Preview was not rejected', $stale_apply->get_data());
}

$original_timestamp = strtotime($future_at);
$original_dates = m4s_mysql_dates((int) $original_timestamp);
$restore_schedule = wp_update_post(array(
    'ID'=>$draft_id,
    'post_status'=>'future',
    'post_date'=>$original_dates['post_date'],
    'post_date_gmt'=>$original_dates['post_date_gmt'],
), true);
if (is_wp_error($restore_schedule)) { m4s_fail('could not restore scheduled fixture before rollback', $restore_schedule->get_error_message()); }
clean_post_cache($draft_id);

$rollback = m4s_request('POST', 'operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $rollback->get_status()) { m4s_fail('scheduling rollback failed', $rollback->get_data()); }
$restored = Eduardo_Research_Manager::insight_editor()->inspect($draft_id);
$restored_post = get_post($draft_id);
if (is_wp_error($restored)
    || ! $restored_post instanceof WP_Post
    || 'draft' !== (string) ($restored['status'] ?? '')
    || '' !== (string) ($restored['scheduled_at'] ?? '')
    || $before_dates['post_date'] !== (string) $restored_post->post_date
    || $before_dates['post_date_gmt'] !== (string) $restored_post->post_date_gmt) {
    m4s_fail('rollback did not restore exact draft calendar baseline', array('record'=>$restored,'post'=>$restored_post));
}

$cleanup = Eduardo_Research_Manager::insight_editor()->rollback($draft_snapshot);
if (is_wp_error($cleanup)) { m4s_fail('draft fixture cleanup failed', $cleanup->get_error_message()); }
fwrite(STDOUT, "M4 Insight scheduling lifecycle OK\n");
