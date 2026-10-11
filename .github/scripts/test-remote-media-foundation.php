<?php
/** Fresh-WordPress acceptance for Phase 3 read-only Remote Media control. */

wp_set_current_user(1);

function fail_p3_media(string $message, mixed $context = null): never {
    fwrite(STDERR, "P3 MEDIA FAIL: {$message}\n");
    if (null !== $context) {
        fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    exit(1);
}

function p3_media_request(
    string $method,
    string $route,
    string $token,
    array $query = array()
): WP_REST_Response {
    $request = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', 'req-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($query) {
        $request->set_query_params($query);
    }
    return rest_do_request($request);
}

$upload = wp_upload_bits(
    'p3-media-fixture.png',
    null,
    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
);
if (! empty($upload['error']) || empty($upload['file']) || empty($upload['url'])) {
    fail_p3_media('fixture upload failed', $upload);
}

$attachment_id = wp_insert_attachment(array(
    'post_mime_type' => 'image/png',
    'post_title' => 'P3 Media Fixture',
    'post_excerpt' => 'Fixture caption',
    'post_content' => 'Fixture description',
    'post_status' => 'inherit',
), (string) $upload['file']);
if (is_wp_error($attachment_id) || (int) $attachment_id <= 0) {
    fail_p3_media('fixture attachment insert failed', $attachment_id);
}
$attachment_id = (int) $attachment_id;
update_attached_file($attachment_id, (string) $upload['file']);
wp_update_attachment_metadata($attachment_id, array(
    'width' => 1,
    'height' => 1,
    'file' => wp_basename((string) $upload['file']),
));
update_post_meta($attachment_id, '_wp_attachment_image_alt', '');

$cleanup = static function () use ($attachment_id): void {
    if ($attachment_id > 0) {
        wp_delete_attachment($attachment_id, true);
    }
};
register_shutdown_function($cleanup);

rest_get_server();
$routes = rest_get_server()->get_routes();
foreach (array(
    '/research-manager/v1/media',
    '/research-manager/v1/media/(?P<id>\d+)',
) as $route) {
    if (! isset($routes[$route])) {
        fail_p3_media('Remote Media route missing: ' . $route);
    }
}

$denied_issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read'),
    1,
    'CI P3 Media denied'
);
if (is_wp_error($denied_issued) || empty($denied_issued['token'])) {
    fail_p3_media('denied credential issuance failed', $denied_issued);
}
$denied = p3_media_request('GET', 'media', (string) $denied_issued['token']);
if (403 !== $denied->get_status() || 'scope_denied' !== (string) ($denied->get_data()['code'] ?? '')) {
    fail_p3_media('media.read was not independently enforced', $denied->get_data());
}

$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read', 'media.read'),
    1,
    'CI P3 Media read'
);
if (is_wp_error($issued) || empty($issued['token'])) {
    fail_p3_media('media.read credential issuance failed', $issued);
}
$token = (string) $issued['token'];

$cap_response = p3_media_request('GET', 'capabilities', $token);
if (200 !== $cap_response->get_status()) {
    fail_p3_media('capabilities request failed', $cap_response->get_data());
}
$cap = $cap_response->get_data()['data'] ?? array();
if ('media.read' !== (string) ($cap['read_endpoints']['media'] ?? '')
    || 'media.read' !== (string) ($cap['read_endpoints']['media_item'] ?? '')
    || empty($cap['media_control']['inventory'])
    || empty($cap['media_control']['inspection'])
    || false !== ($cap['media_control']['write_available'] ?? null)
    || ! empty($cap['media_control']['filesystem_paths_exposed'])
    || ! empty($cap['media_control']['arbitrary_upload_proxy'])) {
    fail_p3_media('capabilities do not expose the bounded read-only Media contract', $cap['media_control'] ?? $cap);
}

$inventory_response = p3_media_request('GET', 'media', $token, array(
    'search' => 'P3 Media Fixture',
    'mime_type' => 'image',
    'per_page' => 10,
));
if (200 !== $inventory_response->get_status()) {
    fail_p3_media('Media inventory failed', $inventory_response->get_data());
}
$inventory = $inventory_response->get_data()['data'] ?? array();
$items = (array) ($inventory['items'] ?? array());
$fixture = null;
foreach ($items as $item) {
    if ((int) ($item['id'] ?? 0) === $attachment_id) {
        $fixture = $item;
        break;
    }
}
if (! is_array($fixture)) {
    fail_p3_media('fixture missing from filtered Media inventory', $inventory);
}
if ('image/png' !== (string) ($fixture['mime_type'] ?? '')
    || 'image' !== (string) ($fixture['media_type'] ?? '')
    || empty($fixture['source_url'])
    || empty($fixture['readiness']['missing_alt'])
    || empty($fixture['readiness']['dimensions_known'])) {
    fail_p3_media('Media inventory item contract invalid', $fixture);
}

$inspect_response = p3_media_request('GET', 'media/' . $attachment_id, $token);
if (200 !== $inspect_response->get_status()) {
    fail_p3_media('Media inspection failed', $inspect_response->get_data());
}
$inspection = $inspect_response->get_data()['data'] ?? array();
if ($attachment_id !== (int) ($inspection['id'] ?? 0)
    || 'P3 Media Fixture' !== (string) ($inspection['title'] ?? '')
    || 1 !== (int) ($inspection['width'] ?? 0)
    || 1 !== (int) ($inspection['height'] ?? 0)) {
    fail_p3_media('Media inspection contract invalid', $inspection);
}

$serialized = (string) wp_json_encode(array($inventory, $inspection), JSON_UNESCAPED_SLASHES);
$upload_dir = wp_upload_dir();
$basedir = (string) ($upload_dir['basedir'] ?? '');
if ('' !== $basedir && str_contains($serialized, $basedir)) {
    fail_p3_media('Media API leaked an absolute upload filesystem path');
}
if (str_contains($serialized, '_wp_attached_file')) {
    fail_p3_media('Media API leaked private attachment storage metadata');
}

$post_id = wp_insert_post(array(
    'post_title' => 'Not media',
    'post_status' => 'publish',
    'post_type' => 'post',
));
if (is_wp_error($post_id) || (int) $post_id <= 0) {
    fail_p3_media('non-media fixture insert failed', $post_id);
}
$not_media = p3_media_request('GET', 'media/' . (int) $post_id, $token);
wp_delete_post((int) $post_id, true);
if (404 !== $not_media->get_status() || 'media_not_found' !== (string) ($not_media->get_data()['code'] ?? '')) {
    fail_p3_media('non-attachment resource was not rejected', $not_media->get_data());
}

$invalid_mime = p3_media_request('GET', 'media', $token, array('mime_type' => 'text/plain'));
if (400 !== $invalid_mime->get_status() || 'validation_failed' !== (string) ($invalid_mime->get_data()['code'] ?? '')) {
    fail_p3_media('unbounded MIME filter was not rejected', $invalid_mime->get_data());
}

$cleanup();
$attachment_id = 0;
fwrite(STDOUT, "P3 media foundation acceptance: PASS\n");
