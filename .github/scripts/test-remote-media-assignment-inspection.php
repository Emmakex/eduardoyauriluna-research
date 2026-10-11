<?php
/** Focused acceptance for read-only P3.3 Media assignment inspection. */

wp_set_current_user(1);

function fail_p3_media_assignment_inspection(string $message, mixed $context = null): never {
    fwrite(STDERR, "P3 MEDIA ASSIGNMENT INSPECTION FAIL: {$message}\n");
    if (null !== $context) {
        fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    exit(1);
}

function p3_media_assignment_inspection_request(string $token, array $query): WP_REST_Response {
    $request = new WP_REST_Request('GET', '/research-manager/v1/media/assignments');
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', 'req-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    $request->set_query_params($query);
    return rest_do_request($request);
}

function issue_p3_media_assignment_inspection_token(array $scopes, string $label): string {
    $issued = Eduardo_Research_Manager::remote_credentials()->issue_token($scopes, 1, $label);
    if (is_wp_error($issued) || empty($issued['token'])) {
        fail_p3_media_assignment_inspection('credential issuance failed: ' . $label, $issued);
    }
    return (string) $issued['token'];
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { fail_p3_media_assignment_inspection('canonical blueprint unavailable', $blueprint); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { fail_p3_media_assignment_inspection('canonical seed failed', $seed); }

$upload = wp_upload_bits(
    'p3-media-assignment-inspection.png',
    null,
    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
);
if (! empty($upload['error']) || empty($upload['file'])) {
    fail_p3_media_assignment_inspection('fixture upload failed', $upload);
}
$attachment_id = wp_insert_attachment(array(
    'post_mime_type'=>'image/png',
    'post_title'=>'P3 Inspection Image',
    'post_status'=>'inherit',
), (string) $upload['file']);
if (is_wp_error($attachment_id) || (int) $attachment_id <= 0) {
    fail_p3_media_assignment_inspection('attachment insert failed', $attachment_id);
}
$attachment_id = (int) $attachment_id;
update_attached_file($attachment_id, (string) $upload['file']);
wp_update_attachment_metadata($attachment_id, array('width'=>1,'height'=>1,'file'=>wp_basename((string) $upload['file'])));
update_post_meta($attachment_id, '_wp_attachment_image_alt', 'Inspection alt');

$insight_id = wp_insert_post(array(
    'post_type'=>'post',
    'post_status'=>'publish',
    'post_title'=>'P3 Assignment Inspection Insight',
    'post_name'=>'p3-assignment-inspection-insight',
    'post_content'=>'Inspection fixture',
));
if (is_wp_error($insight_id) || (int) $insight_id <= 0) {
    fail_p3_media_assignment_inspection('Insight fixture insert failed', $insight_id);
}
$insight_id = (int) $insight_id;
update_post_meta($insight_id, '_research_language', 'en');
update_post_meta($insight_id, '_research_insight_type', 'research_note');
update_post_meta($insight_id, '_eduardo_research_primary_media_id', $attachment_id);

register_shutdown_function(static function () use ($attachment_id, $insight_id): void {
    if (get_post($insight_id)) { wp_delete_post($insight_id, true); }
    if (get_post($attachment_id)) { wp_delete_attachment($attachment_id, true); }
});

rest_get_server();
$routes = rest_get_server()->get_routes();
if (! isset($routes['/research-manager/v1/media/assignments'])) {
    fail_p3_media_assignment_inspection('read-only assignment inspection route is missing');
}

$query = array('type'=>'insight','post_id'=>$insight_id,'slot'=>'primary');
$media_only = issue_p3_media_assignment_inspection_token(array('media.read'), 'CI P3 assignment inspection missing insights.read');
$denied = p3_media_assignment_inspection_request($media_only, $query);
if (403 !== $denied->get_status() || 'scope_denied' !== (string) ($denied->get_data()['code'] ?? '')) {
    fail_p3_media_assignment_inspection('target-specific read scope was not enforced', $denied->get_data());
}

$token = issue_p3_media_assignment_inspection_token(array('media.read','insights.read','pages.read'), 'CI P3 assignment inspection');
$response = p3_media_assignment_inspection_request($token, $query);
$data = $response->get_data()['data'] ?? array();
if (200 !== $response->get_status()
    || empty($data['read_only'])
    || 'insight:' . $insight_id !== (string) ($data['resource_id'] ?? '')
    || $attachment_id !== (int) ($data['attachment_id'] ?? 0)
    || 'primary' !== (string) ($data['slot'] ?? '')
    || $attachment_id !== (int) ($data['attachment']['id'] ?? 0)
    || 'Inspection alt' !== (string) ($data['attachment']['alt_text'] ?? '')
    || '' === (string) ($data['target_revision'] ?? '')) {
    fail_p3_media_assignment_inspection('bounded Insight assignment inspection contract failed', $response->get_data());
}
foreach (array('storage','storage_key','record','semantic_scope','read_scope') as $forbidden) {
    if (array_key_exists($forbidden, $data)) {
        fail_p3_media_assignment_inspection('inspection leaked internal relationship implementation: ' . $forbidden, $data);
    }
}

$page = p3_media_assignment_inspection_request($token, array(
    'type'=>'page','key'=>'contact','language'=>'en','slot'=>'primary',
));
$page_data = $page->get_data()['data'] ?? array();
if (200 !== $page->get_status()
    || 'page:contact:en' !== (string) ($page_data['resource_id'] ?? '')
    || 0 !== (int) ($page_data['attachment_id'] ?? -1)
    || null !== ($page_data['attachment'] ?? null)
    || empty($page_data['read_only'])) {
    fail_p3_media_assignment_inspection('bounded Page assignment inspection contract failed', $page->get_data());
}

wp_delete_post($insight_id, true);
wp_delete_attachment($attachment_id, true);
fwrite(STDOUT, "P3 Media assignment inspection acceptance: PASS\n");
