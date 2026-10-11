<?php
/** Fresh-WordPress acceptance for P3.4 bounded Media diagnostics and safe remediation delegation. */

wp_set_current_user(1);

function fail_p34_media(string $message, mixed $context = null): never {
    fwrite(STDERR, "P3.4 MEDIA DIAGNOSTICS FAIL: {$message}\n");
    if (null !== $context) {
        fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    exit(1);
}

function p34_request(
    string $method,
    string $route,
    string $token,
    array $body = array(),
    array $query = array(),
    ?string $request_id = null
): WP_REST_Response {
    $request = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', $request_id ?: 'req-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($query) { $request->set_query_params($query); }
    if ($body) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($request);
}

function p34_token(array $scopes, string $label): string {
    $issued = Eduardo_Research_Manager::remote_credentials()->issue_token($scopes, 1, $label);
    if (is_wp_error($issued) || empty($issued['token'])) { fail_p34_media('credential issuance failed', $issued); }
    return (string) $issued['token'];
}

function p34_attachment(string $name, string $title, int $width, int $height, int $filesize, string $alt, int $parent = 0): int {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $upload = wp_upload_bits($name, null, $png);
    if (! empty($upload['error']) || empty($upload['file'])) { fail_p34_media('fixture upload failed', $upload); }
    $id = wp_insert_attachment(array(
        'post_mime_type'=>'image/png',
        'post_title'=>$title,
        'post_status'=>'inherit',
        'post_parent'=>$parent,
    ), (string) $upload['file']);
    if (is_wp_error($id) || (int) $id <= 0) { fail_p34_media('fixture attachment insert failed', $id); }
    $id = (int) $id;
    update_attached_file($id, (string) $upload['file']);
    $relative = _wp_relative_upload_path((string) $upload['file']);
    wp_update_attachment_metadata($id, array(
        'width'=>$width,
        'height'=>$height,
        'file'=>$relative,
        'filesize'=>$filesize,
    ));
    update_post_meta($id, '_wp_attachment_image_alt', $alt);
    clean_post_cache($id);
    return $id;
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { fail_p34_media('canonical blueprint unavailable', $blueprint); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { fail_p34_media('canonical seed failed', $seed); }

$parent_id = wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'P3.4 Parent'));
if (is_wp_error($parent_id) || (int) $parent_id <= 0) { fail_p34_media('parent fixture failed', $parent_id); }
$parent_id = (int) $parent_id;

$insight_id = wp_insert_post(array(
    'post_type'=>'post','post_status'=>'publish','post_title'=>'P3.4 Managed Insight','post_name'=>'p34-managed-insight',
));
if (is_wp_error($insight_id) || (int) $insight_id <= 0) { fail_p34_media('Insight fixture failed', $insight_id); }
$insight_id = (int) $insight_id;
update_post_meta($insight_id, '_research_language', 'en');
update_post_meta($insight_id, '_research_insight_type', 'research_note');

$missing_id = p34_attachment('p34-large-missing.png', 'Large missing alt', 3000, 2200, 123456, '');
$weak_id = p34_attachment('p34-duplicate-a.png', 'Duplicate candidate A', 1200, 800, 77777, 'IMG_1234');
$good_id = p34_attachment('p34-duplicate-b.png', 'Duplicate candidate B', 1200, 800, 77777, 'Diagram of human and AI review workflow', $parent_id);
update_post_meta($insight_id, Eduardo_Research_Manager_Media_Assignment_Editor::POST_META, $weak_id);

register_shutdown_function(static function () use ($missing_id, $weak_id, $good_id, $parent_id, $insight_id): void {
    foreach (array($missing_id, $weak_id, $good_id) as $id) { if (get_post($id)) { wp_delete_attachment($id, true); } }
    foreach (array($parent_id, $insight_id) as $id) { if (get_post($id)) { wp_delete_post($id, true); } }
});

rest_get_server();
$routes = rest_get_server()->get_routes();
foreach (array(
    '/research-manager/v1/media/diagnostics',
    '/research-manager/v1/media/diagnostics/(?P<id>\\d+)',
    '/research-manager/v1/media/diagnostics/remediation/plan',
) as $route) {
    if (! isset($routes[$route])) { fail_p34_media('P3.4 route missing: ' . $route); }
}

$read_token = p34_token(array('site.read','media.read'), 'CI P3.4 diagnostics read');
$report_response = p34_request('GET', 'media/diagnostics', $read_token);
if (200 !== $report_response->get_status()) { fail_p34_media('diagnostics report failed', $report_response->get_data()); }
$report = $report_response->get_data()['data'] ?? array();
$summary = (array) ($report['summary'] ?? array());
if ((int) ($summary['missing_alt'] ?? 0) < 1
    || (int) ($summary['weak_alt'] ?? 0) < 1
    || (int) ($summary['unused'] ?? 0) < 1
    || (int) ($summary['duplicate_candidates'] ?? 0) < 2
    || (int) ($summary['duplicate_groups'] ?? 0) < 1
    || (int) ($summary['performance_advisories'] ?? 0) < 1
    || ! empty($report['ready'])
    || empty($report['read_only'])) {
    fail_p34_media('aggregate diagnostics contract incomplete', $report);
}
if (! empty($report['remediation']['automatic_delete']) || ! empty($report['remediation']['binary_replacement'])) {
    fail_p34_media('destructive or binary remediation was exposed', $report['remediation']);
}
foreach (array($missing_id, $weak_id, $good_id) as $id) {
    if (! get_post($id)) { fail_p34_media('diagnostic report deleted attachment #' . $id); }
}

$weak_response = p34_request('GET', 'media/diagnostics/' . $weak_id, $read_token);
$weak_item = $weak_response->get_data()['data'] ?? array();
if (200 !== $weak_response->get_status()
    || empty($weak_item['flags']['weak_alt'])
    || ! empty($weak_item['flags']['unused'])
    || empty($weak_item['usage']['bounded_theme_assignments'])) {
    fail_p34_media('bounded assignment usage or weak-alt classification failed', $weak_item);
}

$cap_response = p34_request('GET', 'capabilities', $read_token);
$diagnostic_cap = $cap_response->get_data()['data']['media_control']['diagnostics'] ?? array();
$remediation_cap = $cap_response->get_data()['data']['media_control']['diagnostic_remediation'] ?? array();
if (200 !== $cap_response->get_status()
    || empty($diagnostic_cap['available'])
    || 'media.read' !== (string) ($diagnostic_cap['read_scope'] ?? '')
    || ! empty($diagnostic_cap['destructive_delete'])
    || ! empty($diagnostic_cap['content_hashing'])
    || (array) ($remediation_cap['supported_fields'] ?? array()) !== array('alt_text')
    || empty($remediation_cap['operator_supplied_alt_required'])
    || ! empty($remediation_cap['automatic_alt_generation'])
    || ! empty($remediation_cap['auto_apply'])) {
    fail_p34_media('capabilities do not expose safe P3.4 boundaries', array($diagnostic_cap, $remediation_cap));
}

$no_transport = p34_token(array('media.write'), 'CI P3.4 no operations.apply');
$transport_denied = p34_request('POST', 'media/diagnostics/remediation/plan', $no_transport, array(
    'attachment_id'=>$missing_id,
    'changes'=>array('alt_text'=>'Diagram showing connected AI process stages'),
));
if (403 !== $transport_denied->get_status() || 'scope_denied' !== (string) ($transport_denied->get_data()['code'] ?? '')) {
    fail_p34_media('operations.apply was not enforced independently', $transport_denied->get_data());
}

$no_media_write = p34_token(array('operations.apply'), 'CI P3.4 no media.write');
$denied = p34_request('POST', 'media/diagnostics/remediation/plan', $no_media_write, array(
    'attachment_id'=>$missing_id,
    'changes'=>array('alt_text'=>'Diagram showing connected AI process stages'),
));
if (403 !== $denied->get_status() || 'scope_denied' !== (string) ($denied->get_data()['code'] ?? '')) {
    fail_p34_media('media.write was not independently enforced', $denied->get_data());
}

$full_token = p34_token(array(
    'site.read','site.diagnostics','media.read','media.write','operations.apply','operations.rollback',
), 'CI P3.4 remediation full');

$weak_replacement = p34_request('POST', 'media/diagnostics/remediation/plan', $full_token, array(
    'attachment_id'=>$missing_id,
    'changes'=>array('alt_text'=>'image'),
));
if (400 !== $weak_replacement->get_status() || 'validation_failed' !== (string) ($weak_replacement->get_data()['code'] ?? '')) {
    fail_p34_media('weak replacement alt was not rejected', $weak_replacement->get_data());
}

$unsupported = p34_request('POST', 'media/diagnostics/remediation/plan', $full_token, array(
    'attachment_id'=>$missing_id,
    'changes'=>array('title'=>'Unsafe diagnostic shortcut'),
));
if (400 !== $unsupported->get_status() || 'validation_failed' !== (string) ($unsupported->get_data()['code'] ?? '')) {
    fail_p34_media('unsupported remediation field was not rejected', $unsupported->get_data());
}

$not_needed = p34_request('POST', 'media/diagnostics/remediation/plan', $full_token, array(
    'attachment_id'=>$good_id,
    'changes'=>array('alt_text'=>'Replacement text that should not be needed'),
));
if (409 !== $not_needed->get_status() || 'validation_failed' !== (string) ($not_needed->get_data()['code'] ?? '')) {
    fail_p34_media('ready attachment incorrectly received deterministic remediation', $not_needed->get_data());
}

$plan_request_id = 'req-p34-remediation-plan-0001';
$replacement_alt = 'Abstract system diagram showing connected AI process stages';
$plan_body = array(
    'attachment_id'=>$missing_id,
    'changes'=>array('alt_text'=>$replacement_alt),
);
$plan = p34_request('POST', 'media/diagnostics/remediation/plan', $full_token, $plan_body, array(), $plan_request_id);
$plan_data = $plan->get_data()['data'] ?? array();
$plan_id = (string) ($plan_data['plan_id'] ?? '');
if (200 !== $plan->get_status() || '' === $plan_id || 'media-metadata-update' !== (string) ($plan_data['operation'] ?? '')) {
    fail_p34_media('safe diagnostic remediation did not delegate to P3.2 plan lifecycle', $plan->get_data());
}
$plan_replay = p34_request('POST', 'media/diagnostics/remediation/plan', $full_token, $plan_body, array(), $plan_request_id);
if (200 !== $plan_replay->get_status()
    || empty($plan_replay->get_data()['idempotent_replay'])
    || $plan_id !== (string) ($plan_replay->get_data()['data']['plan_id'] ?? '')) {
    fail_p34_media('diagnostic remediation planning is not idempotent', $plan_replay->get_data());
}
if ('' !== (string) get_post_meta($missing_id, '_wp_attachment_image_alt', true)) {
    fail_p34_media('diagnostic remediation Preview mutated Media');
}

$audit_match = false;
foreach (Eduardo_Research_Manager::remote_audit()->recent(50) as $entry) {
    if ('remote-media-plan-created' === (string) ($entry['event'] ?? '')
        && $plan_request_id === (string) ($entry['request_id'] ?? '')
        && $plan_id === (string) ($entry['plan_id'] ?? '')
        && 'media.write' === (string) ($entry['scope'] ?? '')) {
        $audit_match = true;
        break;
    }
}
if (! $audit_match) {
    fail_p34_media('diagnostic remediation plan lost audit/request correlation', Eduardo_Research_Manager::remote_audit()->recent(20));
}

$apply = p34_request(
    'POST',
    'media/metadata/plans/' . rawurlencode($plan_id) . '/apply',
    $full_token,
    array('confirm'=>true),
    array(),
    'req-p34-remediation-apply-0001'
);
$operation = $apply->get_data()['data'] ?? array();
$operation_id = (string) ($operation['operation_id'] ?? '');
if (200 !== $apply->get_status() || '' === $operation_id || 'applied' !== (string) ($operation['status'] ?? '')) {
    fail_p34_media('delegated P3.2 Apply failed', $apply->get_data());
}

$after = p34_request('GET', 'media/diagnostics/' . $missing_id, $full_token);
$after_item = $after->get_data()['data'] ?? array();
if (200 !== $after->get_status()
    || ! empty($after_item['flags']['missing_alt'])
    || ! empty($after_item['flags']['weak_alt'])
    || $replacement_alt !== (string) ($after_item['alt_text'] ?? '')) {
    fail_p34_media('diagnostic did not clear after bounded metadata Apply', $after_item);
}

$verify = p34_request(
    'POST',
    'media/metadata/operations/' . rawurlencode($operation_id) . '/verify',
    $full_token,
    array(),
    array(),
    'req-p34-remediation-verify-0001'
);
if (200 !== $verify->get_status() || 'verified' !== (string) ($verify->get_data()['data']['status'] ?? '')) {
    fail_p34_media('delegated P3.2 Verify failed', $verify->get_data());
}

$rollback = p34_request(
    'POST',
    'media/metadata/operations/' . rawurlencode($operation_id) . '/rollback',
    $full_token,
    array('confirm'=>true),
    array(),
    'req-p34-remediation-rollback-0001'
);
if (200 !== $rollback->get_status() || 'rolled-back' !== (string) ($rollback->get_data()['data']['status'] ?? '')) {
    fail_p34_media('delegated P3.2 Rollback failed', $rollback->get_data());
}
$restored = p34_request('GET', 'media/diagnostics/' . $missing_id, $full_token);
if (empty($restored->get_data()['data']['flags']['missing_alt'])) {
    fail_p34_media('Rollback did not restore original diagnostic state', $restored->get_data());
}

foreach (array($missing_id, $weak_id, $good_id) as $id) {
    if (! get_post($id)) { fail_p34_media('P3.4 diagnostic/remediation lifecycle deleted attachment #' . $id); }
}

fwrite(STDOUT, "P3.4 Media diagnostics OK\n");
