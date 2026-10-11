<?php
/** Fresh-WordPress acceptance for P3.3 bounded Remote Media assignment. */

wp_set_current_user(1);

function fail_p3_media_assignment(string $message, mixed $context = null): never {
    fwrite(STDERR, "P3 MEDIA ASSIGNMENT FAIL: {$message}\n");
    if (null !== $context) {
        fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    exit(1);
}

function p3_media_assignment_request(
    string $method,
    string $route,
    string $token,
    array $body = array(),
    ?string $request_id = null
): WP_REST_Response {
    $request = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', $request_id ?: 'req-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($body) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($request);
}

function issue_p3_media_assignment_token(array $scopes, string $label): string {
    $issued = Eduardo_Research_Manager::remote_credentials()->issue_token($scopes, 1, $label);
    if (is_wp_error($issued) || empty($issued['token'])) {
        fail_p3_media_assignment('credential issuance failed: ' . $label, $issued);
    }
    return (string) $issued['token'];
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { fail_p3_media_assignment('canonical blueprint unavailable', $blueprint); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { fail_p3_media_assignment('canonical seed failed', $seed); }

$upload = wp_upload_bits(
    'p3-media-assignment-fixture.png',
    null,
    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
);
if (! empty($upload['error']) || empty($upload['file'])) { fail_p3_media_assignment('fixture upload failed', $upload); }
$attachment_id = wp_insert_attachment(array(
    'post_mime_type'=>'image/png',
    'post_title'=>'P3 Assignment Image',
    'post_excerpt'=>'Assignment caption',
    'post_status'=>'inherit',
), (string) $upload['file']);
if (is_wp_error($attachment_id) || (int) $attachment_id <= 0) { fail_p3_media_assignment('attachment insert failed', $attachment_id); }
$attachment_id = (int) $attachment_id;
update_attached_file($attachment_id, (string) $upload['file']);
wp_update_attachment_metadata($attachment_id, array('width'=>1,'height'=>1,'file'=>wp_basename((string) $upload['file'])));
update_post_meta($attachment_id, '_wp_attachment_image_alt', 'P3 assignment alt');

$insight_id = wp_insert_post(array(
    'post_type'=>'post',
    'post_status'=>'publish',
    'post_title'=>'P3 Media Assignment Insight',
    'post_name'=>'p3-media-assignment-insight',
    'post_excerpt'=>'P3 assignment acceptance',
    'post_content'=>'Original Insight body',
));
if (is_wp_error($insight_id) || (int) $insight_id <= 0) { fail_p3_media_assignment('Insight fixture insert failed', $insight_id); }
$insight_id = (int) $insight_id;
update_post_meta($insight_id, '_research_language', 'en');
update_post_meta($insight_id, '_research_insight_type', 'research_note');

$project_id = wp_insert_post(array(
    'post_type'=>'research_project',
    'post_status'=>'publish',
    'post_title'=>'P3 Media Assignment Project',
    'post_name'=>'p3-media-assignment-project',
    'post_content'=>'Research project fixture',
));
if (is_wp_error($project_id) || (int) $project_id <= 0) { fail_p3_media_assignment('Project fixture insert failed', $project_id); }
$project_id = (int) $project_id;
update_post_meta($project_id, '_research_language', 'en');

register_shutdown_function(static function () use ($attachment_id, $insight_id, $project_id): void {
    if (get_post($insight_id)) { wp_delete_post($insight_id, true); }
    if (get_post($project_id)) { wp_delete_post($project_id, true); }
    if (get_post($attachment_id)) { wp_delete_attachment($attachment_id, true); }
});

$rendered_attachment_id = 0;
add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$rendered_attachment_id) {
    $safe = esc_url((string) $url);
    $marker = $rendered_attachment_id > 0
        ? '<figure data-research-media-slot="primary" data-attachment-id="' . (int) $rendered_attachment_id . '"><img src="https://example.test/media.png" alt="P3 assignment alt"></figure>'
        : '';
    $body = '<!doctype html><html lang="en"><head>'
        . '<link rel="canonical" href="' . $safe . '">'
        . '<link rel="alternate" hreflang="en" href="' . $safe . '">'
        . '<meta property="og:url" content="' . $safe . '">'
        . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article"}</script>'
        . '</head><body>' . $marker . '</body></html>';
    return array('headers'=>array(),'body'=>$body,'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array(),'filename'=>null);
}, 10, 3);

rest_get_server();
$routes = rest_get_server()->get_routes();
foreach (array(
    '/research-manager/v1/media/assignments/plan',
    '/research-manager/v1/media/assignments/plans/(?P<plan_id>[A-Za-z0-9._:-]+)',
    '/research-manager/v1/media/assignments/plans/(?P<plan_id>[A-Za-z0-9._:-]+)/apply',
    '/research-manager/v1/media/assignments/operations/(?P<operation_id>[A-Za-z0-9._:-]+)',
    '/research-manager/v1/media/assignments/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/verify',
    '/research-manager/v1/media/assignments/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/rollback',
) as $route) {
    if (! isset($routes[$route])) { fail_p3_media_assignment('assignment route missing: ' . $route); }
}

$insight_payload = array('payload'=>array(
    'target'=>array('type'=>'insight','post_id'=>$insight_id),
    'attachment_id'=>$attachment_id,
    'slot'=>'primary',
));

$missing_target_write = issue_p3_media_assignment_token(
    array('media.read','media.write','operations.apply'),
    'CI P3 assignment missing insights.write'
);
$denied_target = p3_media_assignment_request('POST', 'media/assignments/plan', $missing_target_write, $insight_payload);
if (403 !== $denied_target->get_status() || 'scope_denied' !== (string) ($denied_target->get_data()['code'] ?? '')) {
    fail_p3_media_assignment('target semantic write scope was not enforced', $denied_target->get_data());
}

$missing_media_write = issue_p3_media_assignment_token(
    array('media.read','insights.write','operations.apply'),
    'CI P3 assignment missing media.write'
);
$denied_media = p3_media_assignment_request('POST', 'media/assignments/plan', $missing_media_write, $insight_payload);
if (403 !== $denied_media->get_status() || 'scope_denied' !== (string) ($denied_media->get_data()['code'] ?? '')) {
    fail_p3_media_assignment('media.write was not independently enforced', $denied_media->get_data());
}

$full_scopes = array(
    'site.read','site.diagnostics','media.read','media.write',
    'pages.read','pages.write','insights.read','insights.write','research.read','research.write',
    'operations.apply','operations.rollback',
);
$token = issue_p3_media_assignment_token($full_scopes, 'CI P3 Media assignment full');

$cap_response = p3_media_assignment_request('GET', 'capabilities', $token);
$cap = $cap_response->get_data()['data']['media_control']['assignment'] ?? array();
if (200 !== $cap_response->get_status()
    || empty($cap['available'])
    || 'primary' !== (string) ($cap['slot'] ?? '')
    || 'pages.write' !== (string) ($cap['targets']['page']['write_scope'] ?? '')
    || 'insights.write' !== (string) ($cap['targets']['insight']['write_scope'] ?? '')
    || 'research.write' !== (string) ($cap['targets']['research']['write_scope'] ?? '')
    || empty($cap['transport']['dual_resource_stale_protection'])
    || empty($cap['transport']['rendered_verification'])
    || ! empty($cap['arbitrary_post_meta'])) {
    fail_p3_media_assignment('capabilities do not expose the bounded assignment contract', $cap);
}

$page_plan = p3_media_assignment_request('POST', 'media/assignments/plan', $token, array('payload'=>array(
    'target'=>array('type'=>'page','key'=>'contact','language'=>'en'),
    'attachment_id'=>$attachment_id,
    'slot'=>'primary',
)));
if (200 !== $page_plan->get_status() || 'pages.write' !== (string) ($page_plan->get_data()['data']['semantic_scope'] ?? '')) {
    fail_p3_media_assignment('Page assignment Preview failed', $page_plan->get_data());
}
if (0 !== absint(get_option('eduardo_research_media_primary_contact_en', 0))) {
    fail_p3_media_assignment('Page assignment Preview mutated the relationship');
}

$research_plan = p3_media_assignment_request('POST', 'media/assignments/plan', $token, array('payload'=>array(
    'target'=>array('type'=>'research','kind'=>'project','post_id'=>$project_id),
    'attachment_id'=>$attachment_id,
    'slot'=>'primary',
)));
if (200 !== $research_plan->get_status() || 'research.write' !== (string) ($research_plan->get_data()['data']['semantic_scope'] ?? '')) {
    fail_p3_media_assignment('Research assignment Preview failed', $research_plan->get_data());
}
if (0 !== absint(get_post_meta($project_id, '_eduardo_research_primary_media_id', true))) {
    fail_p3_media_assignment('Research assignment Preview mutated the relationship');
}

$plan_request_id = 'req-p3-media-assignment-plan-0001';
$plan = p3_media_assignment_request('POST', 'media/assignments/plan', $token, $insight_payload, $plan_request_id);
$plan_id = (string) ($plan->get_data()['data']['plan_id'] ?? '');
if (200 !== $plan->get_status() || '' === $plan_id || 'insights.write' !== (string) ($plan->get_data()['data']['semantic_scope'] ?? '')) {
    fail_p3_media_assignment('Insight assignment Plan failed', $plan->get_data());
}
$plan_replay = p3_media_assignment_request('POST', 'media/assignments/plan', $token, $insight_payload, $plan_request_id);
if (200 !== $plan_replay->get_status() || empty($plan_replay->get_data()['idempotent_replay'])) {
    fail_p3_media_assignment('assignment Plan idempotency failed', $plan_replay->get_data());
}
if (0 !== absint(get_post_meta($insight_id, '_eduardo_research_primary_media_id', true))) {
    fail_p3_media_assignment('assignment Preview mutated Insight relationship');
}

$no_confirm = p3_media_assignment_request('POST', 'media/assignments/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>false));
if (409 !== $no_confirm->get_status() || 'confirmation_required' !== (string) ($no_confirm->get_data()['code'] ?? '')) {
    fail_p3_media_assignment('Apply without confirmation was not blocked', $no_confirm->get_data());
}

$apply = p3_media_assignment_request(
    'POST',
    'media/assignments/plans/' . rawurlencode($plan_id) . '/apply',
    $token,
    array('confirm'=>true),
    'req-p3-media-assignment-apply-0001'
);
$operation = $apply->get_data()['data'] ?? array();
$operation_id = (string) ($operation['operation_id'] ?? '');
if (200 !== $apply->get_status() || '' === $operation_id || empty($operation['snapshot_id'])) {
    fail_p3_media_assignment('assignment Apply failed', $apply->get_data());
}
if ($attachment_id !== absint(get_post_meta($insight_id, '_eduardo_research_primary_media_id', true))) {
    fail_p3_media_assignment('stored Insight Media relationship is incorrect');
}

ob_start();
eduardo_research_render_primary_media($insight_id);
$theme_markup = (string) ob_get_clean();
if (false === strpos($theme_markup, 'data-research-media-slot="primary"')
    || false === strpos($theme_markup, 'data-attachment-id="' . $attachment_id . '"')) {
    fail_p3_media_assignment('Theme did not render the bounded Media relationship', $theme_markup);
}

$rendered_attachment_id = $attachment_id;
$verify = p3_media_assignment_request(
    'POST',
    'media/assignments/operations/' . rawurlencode($operation_id) . '/verify',
    $token,
    array('rendered'=>true)
);
if (200 !== $verify->get_status()
    || 'verified' !== (string) ($verify->get_data()['data']['status'] ?? '')
    || empty($verify->get_data()['data']['stored_verification']['verified'])
    || empty($verify->get_data()['data']['rendered_verification']['checks']['media_assignment'])) {
    fail_p3_media_assignment('rendered assignment verification failed', $verify->get_data());
}

$original_content = (string) get_post_field('post_content', $insight_id);
wp_update_post(array('ID'=>$insight_id,'post_content'=>'Concurrent target change'));
clean_post_cache($insight_id);
$target_stale = p3_media_assignment_request('POST', 'media/assignments/operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
if (409 !== $target_stale->get_status() || 'stale_revision' !== (string) ($target_stale->get_data()['code'] ?? '')) {
    fail_p3_media_assignment('target stale revision did not block Rollback', $target_stale->get_data());
}
wp_update_post(array('ID'=>$insight_id,'post_content'=>$original_content));
clean_post_cache($insight_id);

update_post_meta($attachment_id, '_wp_attachment_image_alt', 'Concurrent Media change');
$media_stale = p3_media_assignment_request('POST', 'media/assignments/operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
if (409 !== $media_stale->get_status() || 'stale_revision' !== (string) ($media_stale->get_data()['code'] ?? '')) {
    fail_p3_media_assignment('attachment stale revision did not block Rollback', $media_stale->get_data());
}
update_post_meta($attachment_id, '_wp_attachment_image_alt', 'P3 assignment alt');
clean_post_cache($attachment_id);

$rollback = p3_media_assignment_request(
    'POST',
    'media/assignments/operations/' . rawurlencode($operation_id) . '/rollback',
    $token,
    array('confirm'=>true),
    'req-p3-media-assignment-rollback-0001'
);
if (200 !== $rollback->get_status()
    || 'rolled-back' !== (string) ($rollback->get_data()['data']['status'] ?? '')
    || empty($rollback->get_data()['data']['rollback_restored_relationship'])) {
    fail_p3_media_assignment('assignment Rollback failed', $rollback->get_data());
}
if (0 !== absint(get_post_meta($insight_id, '_eduardo_research_primary_media_id', true))) {
    fail_p3_media_assignment('Rollback did not restore the exact prior relationship');
}

wp_delete_post($insight_id, true);
wp_delete_post($project_id, true);
wp_delete_attachment($attachment_id, true);
fwrite(STDOUT, "P3 Media assignment acceptance: PASS\n");
