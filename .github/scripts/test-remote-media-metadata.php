<?php
/** Fresh-WordPress acceptance for Phase 3 bounded Remote Media metadata mutation. */

wp_set_current_user(1);

function fail_p3_media_metadata(string $message, mixed $context = null): never {
    fwrite(STDERR, "P3 MEDIA METADATA FAIL: {$message}\n");
    if (null !== $context) {
        fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    exit(1);
}

function p3_media_metadata_request(
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

function issue_p3_media_metadata_token(array $scopes, string $label): string {
    $issued = Eduardo_Research_Manager::remote_credentials()->issue_token($scopes, 1, $label);
    if (is_wp_error($issued) || empty($issued['token'])) {
        fail_p3_media_metadata('credential issuance failed: ' . $label, $issued);
    }
    return (string) $issued['token'];
}

$upload = wp_upload_bits(
    'p3-media-metadata-fixture.png',
    null,
    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
);
if (! empty($upload['error']) || empty($upload['file'])) {
    fail_p3_media_metadata('fixture upload failed', $upload);
}

$attachment_id = wp_insert_attachment(array(
    'post_mime_type'=>'image/png',
    'post_title'=>'P3 Media Mutation Fixture',
    'post_excerpt'=>'Original caption',
    'post_content'=>'Original description',
    'post_status'=>'inherit',
), (string) $upload['file']);
if (is_wp_error($attachment_id) || (int) $attachment_id <= 0) {
    fail_p3_media_metadata('fixture attachment insert failed', $attachment_id);
}
$attachment_id = (int) $attachment_id;
update_attached_file($attachment_id, (string) $upload['file']);
wp_update_attachment_metadata($attachment_id, array(
    'width'=>1,
    'height'=>1,
    'file'=>wp_basename((string) $upload['file']),
));
update_post_meta($attachment_id, '_wp_attachment_image_alt', 'Original alt');

register_shutdown_function(static function () use ($attachment_id): void {
    if ($attachment_id > 0 && get_post($attachment_id)) {
        wp_delete_attachment($attachment_id, true);
    }
});

rest_get_server();
$routes = rest_get_server()->get_routes();
foreach (array(
    '/research-manager/v1/media/metadata/plan',
    '/research-manager/v1/media/metadata/plans/(?P<plan_id>[A-Za-z0-9._:-]+)',
    '/research-manager/v1/media/metadata/plans/(?P<plan_id>[A-Za-z0-9._:-]+)/apply',
    '/research-manager/v1/media/metadata/operations/(?P<operation_id>[A-Za-z0-9._:-]+)',
    '/research-manager/v1/media/metadata/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/verify',
    '/research-manager/v1/media/metadata/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/rollback',
) as $route) {
    if (! isset($routes[$route])) {
        fail_p3_media_metadata('Media metadata route missing: ' . $route);
    }
}

$basic_body = array(
    'operation'=>'media-metadata-update',
    'payload'=>array(
        'attachment_id'=>$attachment_id,
        'changes'=>array('alt_text'=>'Scoped alt'),
    ),
);

$missing_transport = issue_p3_media_metadata_token(
    array('media.read','media.write'),
    'CI P3 Media missing operations.apply'
);
$denied_transport = p3_media_metadata_request('POST', 'media/metadata/plan', $missing_transport, $basic_body);
if (403 !== $denied_transport->get_status() || 'scope_denied' !== (string) ($denied_transport->get_data()['code'] ?? '')) {
    fail_p3_media_metadata('operations.apply was not enforced', $denied_transport->get_data());
}

$missing_semantic = issue_p3_media_metadata_token(
    array('media.read','operations.apply'),
    'CI P3 Media missing media.write'
);
$denied_semantic = p3_media_metadata_request('POST', 'media/metadata/plan', $missing_semantic, $basic_body);
if (403 !== $denied_semantic->get_status() || 'scope_denied' !== (string) ($denied_semantic->get_data()['code'] ?? '')) {
    fail_p3_media_metadata('media.write was not independently enforced', $denied_semantic->get_data());
}

$full_scopes = array(
    'site.read','site.diagnostics','media.read','media.write','operations.apply','operations.rollback',
);
$token = issue_p3_media_metadata_token($full_scopes, 'CI P3 Media metadata full');

$cap = p3_media_metadata_request('GET', 'capabilities', $token)->get_data()['data'] ?? array();
$media_control = is_array($cap['media_control'] ?? null) ? $cap['media_control'] : array();
if (empty($media_control['write_available'])
    || 'media.write' !== (string) ($media_control['write_scope'] ?? '')
    || ! in_array('media-metadata-update', (array) ($media_control['operations'] ?? array()), true)
    || ! empty(array_diff(array('title','alt_text','caption','description'), (array) ($media_control['metadata_fields'] ?? array())))
    || empty($media_control['metadata_transport']['stale_attachment_revision_protection'])
    || ! empty($media_control['arbitrary_upload_proxy'])
    || ! empty($media_control['destructive_delete'])
    || ! empty($media_control['arbitrary_wordpress_proxy'])) {
    fail_p3_media_metadata('capabilities do not expose the bounded Media metadata contract', $media_control);
}

$original = (new Eduardo_Research_Manager_Media_Resource())->inspect($attachment_id);
if (is_wp_error($original)) {
    fail_p3_media_metadata('original Media inspection failed', $original->get_error_message());
}

$changes = array(
    'title'=>'P3 Media Mutation Updated',
    'alt_text'=>'Updated descriptive alt',
    'caption'=>'Updated caption',
    'description'=>'Updated description',
);
$plan_body = array(
    'operation'=>'media-metadata-update',
    'payload'=>array(
        'attachment_id'=>$attachment_id,
        'changes'=>$changes,
    ),
);
$plan_request_id = 'req-p3-media-plan-0001';
$plan_response = p3_media_metadata_request('POST', 'media/metadata/plan', $token, $plan_body, $plan_request_id);
if (200 !== $plan_response->get_status()) {
    fail_p3_media_metadata('Media metadata plan failed', $plan_response->get_data());
}
$plan = $plan_response->get_data()['data'] ?? array();
$plan_id = (string) ($plan['plan_id'] ?? '');
if ('' === $plan_id
    || 'media-metadata-update' !== (string) ($plan['operation'] ?? '')
    || empty($plan['apply_allowed'])
    || empty($plan['source_revision'])) {
    fail_p3_media_metadata('Media metadata plan contract invalid', $plan);
}

$plan_retry = p3_media_metadata_request('POST', 'media/metadata/plan', $token, $plan_body, $plan_request_id);
if (200 !== $plan_retry->get_status() || empty($plan_retry->get_data()['idempotent_replay'])) {
    fail_p3_media_metadata('Media metadata plan idempotency failed', $plan_retry->get_data());
}
$after_preview = (new Eduardo_Research_Manager_Media_Resource())->inspect($attachment_id);
if (is_wp_error($after_preview)
    || (string) ($after_preview['title'] ?? '') !== (string) ($original['title'] ?? '')
    || (string) ($after_preview['alt_text'] ?? '') !== (string) ($original['alt_text'] ?? '')
    || (string) ($after_preview['caption'] ?? '') !== (string) ($original['caption'] ?? '')
    || (string) ($after_preview['description'] ?? '') !== (string) ($original['description'] ?? '')) {
    fail_p3_media_metadata('Preview mutated Media metadata', $after_preview);
}

// Per-attachment stale revision protection.
$stale_plan = p3_media_metadata_request('POST', 'media/metadata/plan', $token, array(
    'operation'=>'media-metadata-update',
    'payload'=>array(
        'attachment_id'=>$attachment_id,
        'changes'=>array('title'=>'Stale candidate'),
    ),
));
$stale_plan_id = (string) ($stale_plan->get_data()['data']['plan_id'] ?? '');
if (200 !== $stale_plan->get_status() || '' === $stale_plan_id) {
    fail_p3_media_metadata('stale protection plan failed', $stale_plan->get_data());
}
wp_update_post(array('ID'=>$attachment_id, 'post_title'=>'Concurrent direct change'));
clean_post_cache($attachment_id);
$stale_apply = p3_media_metadata_request('POST', 'media/metadata/plans/' . rawurlencode($stale_plan_id) . '/apply', $token, array('confirm'=>true));
if (409 !== $stale_apply->get_status() || 'stale_revision' !== (string) ($stale_apply->get_data()['code'] ?? '')) {
    fail_p3_media_metadata('stale Media Preview was not blocked', $stale_apply->get_data());
}
wp_update_post(array('ID'=>$attachment_id, 'post_title'=>(string) $original['title']));
clean_post_cache($attachment_id);

// The original plan is valid again because the managed state was restored exactly.
$no_confirm = p3_media_metadata_request('POST', 'media/metadata/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>false));
if (409 !== $no_confirm->get_status() || 'confirmation_required' !== (string) ($no_confirm->get_data()['code'] ?? '')) {
    fail_p3_media_metadata('Media Apply without confirmation was not blocked', $no_confirm->get_data());
}

$apply = p3_media_metadata_request(
    'POST',
    'media/metadata/plans/' . rawurlencode($plan_id) . '/apply',
    $token,
    array('confirm'=>true),
    'req-p3-media-apply-0001'
);
if (200 !== $apply->get_status()) {
    fail_p3_media_metadata('Media metadata Apply failed', $apply->get_data());
}
$operation = $apply->get_data()['data'] ?? array();
$operation_id = (string) ($operation['operation_id'] ?? '');
if ('' === $operation_id || empty($operation['snapshot_id']) || empty($operation['result']['verified'])) {
    fail_p3_media_metadata('Media metadata operation contract invalid', $operation);
}

$changed = (new Eduardo_Research_Manager_Media_Resource())->inspect($attachment_id);
if (is_wp_error($changed)) {
    fail_p3_media_metadata('changed Media inspection failed', $changed->get_error_message());
}
foreach ($changes as $field => $expected) {
    if ((string) ($changed[$field] ?? '') !== $expected) {
        fail_p3_media_metadata('Media metadata field did not change: ' . $field, $changed);
    }
}

$verify = p3_media_metadata_request(
    'POST',
    'media/metadata/operations/' . rawurlencode($operation_id) . '/verify',
    $token,
    array('rendered'=>false)
);
if (200 !== $verify->get_status()
    || empty($verify->get_data()['data']['stored_verification']['verified'])
    || empty($verify->get_data()['data']['stored_verification']['revision_matches'])) {
    fail_p3_media_metadata('stored Media metadata verification failed', $verify->get_data());
}

$rollback_without_write = issue_p3_media_metadata_token(
    array('site.read','site.diagnostics','media.read','operations.rollback'),
    'CI P3 Media rollback missing media.write'
);
$denied_rollback = p3_media_metadata_request(
    'POST',
    'media/metadata/operations/' . rawurlencode($operation_id) . '/rollback',
    $rollback_without_write,
    array('confirm'=>true)
);
if (403 !== $denied_rollback->get_status() || 'scope_denied' !== (string) ($denied_rollback->get_data()['code'] ?? '')) {
    fail_p3_media_metadata('media.write was not enforced for rollback', $denied_rollback->get_data());
}

$token = issue_p3_media_metadata_token($full_scopes, 'CI P3 Media metadata rollback');
$rollback = p3_media_metadata_request(
    'POST',
    'media/metadata/operations/' . rawurlencode($operation_id) . '/rollback',
    $token,
    array('confirm'=>true),
    'req-p3-media-rollback-0001'
);
if (200 !== $rollback->get_status()) {
    fail_p3_media_metadata('Media metadata rollback failed', $rollback->get_data());
}
$rolled = $rollback->get_data()['data'] ?? array();
if ('rolled-back' !== (string) ($rolled['status'] ?? '') || empty($rolled['rollback_restored_source_revision'])) {
    fail_p3_media_metadata('Media rollback did not restore exact source revision', $rolled);
}
$restored = (new Eduardo_Research_Manager_Media_Resource())->inspect($attachment_id);
if (is_wp_error($restored)) {
    fail_p3_media_metadata('restored Media inspection failed', $restored->get_error_message());
}
foreach (array('title','alt_text','caption','description') as $field) {
    if ((string) ($restored[$field] ?? '') !== (string) ($original[$field] ?? '')) {
        fail_p3_media_metadata('Media rollback failed to restore: ' . $field, $restored);
    }
}

$unsupported = p3_media_metadata_request('POST', 'media/metadata/plan', $token, array(
    'operation'=>'media-metadata-update',
    'payload'=>array(
        'attachment_id'=>$attachment_id,
        'changes'=>array('file'=>'/tmp/not-allowed.png'),
    ),
));
if (400 !== $unsupported->get_status() || 'validation_failed' !== (string) ($unsupported->get_data()['code'] ?? '')) {
    fail_p3_media_metadata('unsupported Media field was not rejected', $unsupported->get_data());
}

$post_id = wp_insert_post(array('post_title'=>'Not an attachment', 'post_status'=>'publish', 'post_type'=>'post'));
if (is_wp_error($post_id) || (int) $post_id <= 0) {
    fail_p3_media_metadata('non-attachment fixture insert failed', $post_id);
}
$non_attachment = p3_media_metadata_request('POST', 'media/metadata/plan', $token, array(
    'operation'=>'media-metadata-update',
    'payload'=>array(
        'attachment_id'=>(int) $post_id,
        'changes'=>array('title'=>'Should fail'),
    ),
));
wp_delete_post((int) $post_id, true);
if (404 !== $non_attachment->get_status() || 'media_not_found' !== (string) ($non_attachment->get_data()['code'] ?? '')) {
    fail_p3_media_metadata('non-attachment mutation target was not rejected', $non_attachment->get_data());
}

wp_delete_attachment($attachment_id, true);
$attachment_id = 0;
fwrite(STDOUT, "P3 Media metadata lifecycle acceptance: PASS\n");
