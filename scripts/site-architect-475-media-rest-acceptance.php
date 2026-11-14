<?php
/**
 * Site Architect #475.20: real Media binary/metadata/assignment lifecycle +
 * WordPress REST credential/scope/replay guard. Run only in disposable CI clone.
 *
 * The test file is NOT part of the externally distributed WordPress plugin.
 * A localhost PHP HTTP server must be serving the exact installed clone.
 */
if (! defined('ABSPATH') || ! defined('WP_CLI') || ! WP_CLI
    || getenv('CI') !== 'true' || ! str_contains(ABSPATH, '/tmp/sa-wp/')) {
    throw new RuntimeException('Media/REST acceptance forbidden outside disposable isolated CI WordPress');
}

function sa20_check(bool $condition, string $reason): void {
    if (! $condition) { throw new RuntimeException('Site Architect #475.20: ' . $reason); }
}
function sa20_result(mixed $result, string $action): array {
    if (is_wp_error($result)) {
        throw new RuntimeException($action . ' failed: ' . $result->get_error_code()
            . ' ' . $result->get_error_message());
    }
    sa20_check(is_array($result), $action . ' must produce structured result');
    return $result;
}
function sa20_rejected(mixed $result, string $code, string $action): void {
    sa20_check(is_wp_error($result) && $result->get_error_code() === $code,
        $action . ' must fail closed with ' . $code
        . (is_wp_error($result) ? ' instead of ' . $result->get_error_code() : ''));
}
function sa20_rest(string $method, string $endpoint, string $token = '',
    string $request_id = '', string $nonce = ''): WP_REST_Response {
    $request = new WP_REST_Request($method, '/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE . $endpoint);
    $request->set_header('x-research-manager-request-id', $request_id ?: 'sa20.' . bin2hex(random_bytes(8)));
    $request->set_header('x-research-manager-nonce', $nonce ?: bin2hex(random_bytes(12)));
    $request->set_header('x-research-manager-timestamp', (string) time());
    if ('' !== $token) { $request->set_header('authorization', 'Bearer ' . $token); }
    $result = rest_do_request($request);
    sa20_check($result instanceof WP_REST_Response,
        'REST dispatch must return a REST Response for ' . $method . ' ' . $endpoint);
    return $result;
}

require_once WP_PLUGIN_DIR . '/eduardo-research-manager/includes/class-research-manager-site-profile.php';
wp_set_current_user(1);
sa20_check(current_user_can('manage_options') && current_user_can('upload_files'), 'isolated admin identity required');
sa20_check((new Eduardo_Research_Manager_Site_Profile())->id() === 'research-theme', 'must run only with Research Theme');
sa20_check(defined('EDUARDO_RESEARCH_MANAGER_VERSION')
    && EDUARDO_RESEARCH_MANAGER_VERSION === '0.11.1'
    && EDUARDO_RESEARCH_MANAGER_PRODUCT_NAME === 'Site Architect', 'complete plugin 0.11.1 required');
$host = wp_parse_url(home_url('/'));
sa20_check(is_array($host) && ($host['host'] ?? '') === '127.0.0.1'
    && (int) ($host['port'] ?? 0) === 8080 && ($host['scheme'] ?? '') === 'http',
    'test may contact only the 127.0.0.1:8080 clone');

$report = array(
    'product'=>'Site Architect 0.11.1', 'site'=>'CI Research Theme clone',
    'media'=>array(), 'rest'=>array(), 'complete'=>false,
);

$routes = rest_get_server()->get_routes();
$ns = '/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE;
foreach (array('/status', '/media/ingest/plan',
    '/media/metadata/plan', '/media/assignments/plan') as $route) {
    sa20_check(isset($routes[$ns . $route]), 'actual REST route missing: ' . $route);
}
$report['rest']['route_presence'] = true;

// Prove that the live REST permission callbacks reject requests without a
// remote token, even when running inside an authenticated WordPress admin CLI.
$old_https = $_SERVER['HTTPS'] ?? null;
$_SERVER['HTTPS'] = 'on'; // HTTPS flag is limited to this local, in-process REST dispatch.
$credentials = Eduardo_Research_Manager::remote_credentials();
$connection_id = '';
try {
    foreach (array(
        array('GET','/status'),
        array('POST','/media/ingest/plan'),
        array('POST','/media/metadata/plan'),
        array('POST','/media/assignments/plan'),
    ) as $route) {
        $result = sa20_rest($route[0], $route[1]);
        sa20_check((int) $result->get_status() === 401,
            'anonymous REST access must require a bearer credential: ' . $route[1]
            . ', received=' . $result->get_status());
    }
    $report['rest']['anonymous_denied'] = true;

    $limited = sa20_result(
        $credentials->issue_temporary_token(array('site.read', 'media.read'), 1, 'sa20-disposable-read-only'),
        'Issue isolated read-only temporary token'
    );
    $token = (string) ($limited['token'] ?? '');
    $connection_id = (string) ($limited['connection']['connection_id'] ?? '');
    sa20_check('' !== $token && '' !== $connection_id, 'temporary token creation must yield real identity');
    sa20_check(
        $credentials->has_scope((array) $limited['connection'], 'media.read')
        && ! $credentials->has_scope((array) $limited['connection'], 'media.write')
        && ! $credentials->has_scope((array) $limited['connection'], 'operations.apply'),
        'read-only token may not inherit Media write/Apply'
    );
    $read = sa20_rest('GET', '/status', $token);
    sa20_check((int) $read->get_status() === 200, 'read-only token must access status');
    foreach (array('/media/ingest/plan', '/media/metadata/plan', '/media/assignments/plan') as $route) {
        $denied = sa20_rest('POST', $route, $token);
        sa20_check((int) $denied->get_status() === 403,
            'read-only token must not acquire Media mutation: ' . $route
            . ' got=' . $denied->get_status());
    }
    $report['rest']['read_only_scopes_denied_write'] = true;

    $nonce = bin2hex(random_bytes(12));
    $fresh = sa20_rest('GET', '/status', $token, 'sa20-replay-first', $nonce);
    sa20_check((int) $fresh->get_status() === 200, 'first use of nonce must be allowed');
    $replay = sa20_rest('GET', '/status', $token, 'sa20-replay-second', $nonce);
    sa20_check((int) $replay->get_status() === 409, 'reused authenticated nonce must be rejected');
    $report['rest']['nonce_replay_denied'] = true;

    sa20_result($credentials->revoke_temporary($connection_id), 'Revoke temporary read-only connection');
    $revoked = sa20_rest('GET', '/status', $token);
    sa20_check((int) $revoked->get_status() === 401,
        'revoked bearer token must be rejected by REST permission callback');
    $report['rest']['revocation_enforced'] = true;
    $token = '';
} finally {
    if ($connection_id !== '') {
        $credentials->revoke_temporary($connection_id);
    }
    if ($old_https === null) { unset($_SERVER['HTTPS']); }
    else { $_SERVER['HTTPS'] = $old_https; }
    wp_set_current_user(1);
}

// The only fixture binary created here is a synthetic 1x1 PNG, never user media.
// It is not shipped in the plugin and never touches a production Media Library.
$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2JM5sAAAAASUVORK5CYII=',
    true
);
sa20_check(is_string($png) && strlen($png) > 30, 'synthetic PNG fixture decode');
$tmp = '/tmp/sa-47520-unique-pixel.png';
file_put_contents($tmp, $png);
sa20_check(getimagesize($tmp) !== false, 'synthetic PNG must be a real image');
$file = array(
    'name'=>'sa-47520-unique-pixel.png',
    'type'=>'image/png',
    'tmp_name'=>$tmp,
    'error'=>UPLOAD_ERR_OK,
    'size'=>filesize($tmp),
);
$meta = array(
    'title'=>'Isolated reversible media probe',
    'alt_text'=>'Synthetic 1 pixel proof for isolated Site Architect CI',
    'caption'=>'Synthetic CI only',
    'description'=>'Not actual client Media',
);
$actor = array(
    'connection_id'=>'sa20-disposable-operations',
    'request_id'=>'sa20-media-primary',
    'wordpress_user_id'=>1,
);
$foreign = $actor;
$foreign['connection_id'] = 'sa20-other-connection';
$ingest = new Eduardo_Research_Manager_Remote_Media_Ingest_Operations();
$metadata = new Eduardo_Research_Manager_Remote_Media_Metadata_Operations();
$assignment = new Eduardo_Research_Manager_Remote_Media_Assignment_Operations();

sa20_rejected($ingest->create_plan($file, array_merge($meta, array('alt_text'=>'')), $actor),
    'validation_failed', 'Image ingest without operator-reviewed alt');
$fake = $file;
$fake['name'] = 'sa-47520-not-an-image.png';
$fake['tmp_name'] = '/tmp/sa-47520-not-an-image.png';
file_put_contents($fake['tmp_name'], 'not-an-image');
$fake['size'] = filesize($fake['tmp_name']);
sa20_rejected($ingest->create_plan($fake, $meta, $actor),
    'validation_failed', 'Content/extension spoofed image');
$report['media']['invalid_binary_and_alt_denied'] = true;

$plan_id = '';
$ingest_op_id = '';
$metadata_op_id = '';
$assignment_op_id = '';
$attachment_id = 0;
try {
    $plan = sa20_result($ingest->create_plan($file, $meta, $actor), 'Media ingest Preview');
    $plan_id = (string) ($plan['plan_id'] ?? '');
    sa20_check($plan_id !== '' && ! empty($plan['apply_allowed'])
        && ! array_key_exists('staged_path', $plan), 'private staged file path cannot leak from Preview');
    sa20_check(($plan['file']['sha256'] ?? '') === hash_file('sha256', $tmp),
        'Preview must bind exact file SHA256');
    sa20_rejected($ingest->apply($plan_id, true, $foreign), 'scope_denied', 'Cross-connection ingest Apply');
    sa20_rejected($ingest->apply($plan_id, false, $actor), 'confirmation_required', 'Unconfirmed ingest Apply');
    $created = sa20_result($ingest->apply($plan_id, true, $actor), 'Confirmed Media ingest Apply');
    $ingest_op_id = (string) ($created['operation_id'] ?? '');
    $attachment_id = (int) ($created['target']['attachment_id'] ?? 0);
    sa20_check($ingest_op_id !== '' && $attachment_id > 0, 'Managed Media operation created');
    $verified = sa20_result($ingest->verify($ingest_op_id, $actor), 'Media checksum/provenance Verify');
    sa20_check(! empty($verified['verification']['verified'])
        && ! empty($verified['verification']['checksum_matches'])
        && ! empty($verified['verification']['provenance_matches'])
        && ! empty($verified['verification']['alt_matches']),
        'Ingest Verify must prove checksum, provenance and reviewed alt');
    $report['media']['ingest_checksum_provenance_verified'] = true;

    $before_title = get_the_title($attachment_id);
    $before_alt = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
    $media_plan = sa20_result($metadata->create_plan('media-metadata-update', array(
        'attachment_id'=>$attachment_id,
        'changes'=>array('title'=>'CI metadata temporary title','alt_text'=>'CI temporary alt text'),
    ), $actor), 'Metadata Preview');
    $media_plan_id = (string) $media_plan['plan_id'];
    sa20_rejected($metadata->apply($media_plan_id, true, $foreign), 'scope_denied', 'Cross-connection metadata Apply');
    sa20_rejected($metadata->apply($media_plan_id, false, $actor), 'confirmation_required', 'Unconfirmed metadata Apply');
    $metadata_op = sa20_result($metadata->apply($media_plan_id, true, $actor), 'Metadata Apply');
    $metadata_op_id = (string) ($metadata_op['operation_id'] ?? '');
    sa20_check($metadata_op_id !== '', 'Metadata operation id recorded');
    $metadata_verify = sa20_result($metadata->verify($metadata_op_id, false, $actor), 'Metadata Verify');
    sa20_check(($metadata_verify['status'] ?? '') === 'verified'
        && ! empty($metadata_verify['stored_verification']['verified']), 'Metadata stored Verify');
    $metadata_rollback = sa20_result($metadata->rollback($metadata_op_id, true, $actor), 'Metadata Rollback');
    sa20_check(($metadata_rollback['status'] ?? '') === 'rolled-back'
        && get_the_title($attachment_id) === $before_title
        && (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true) === $before_alt,
        'Metadata Rollback must restore exact title and alt');
    $metadata_op_id = '';
    $report['media']['metadata_reversible'] = true;

    $target = array('type'=>'page','key'=>'home','language'=>'en');
    sa20_rejected($assignment->create_plan(array(
        'target'=>$target,'attachment_id'=>$attachment_id,'slot'=>'arbitrary-slot'
    ), $actor), 'validation_failed', 'Arbitrary Media slot');
    $assign_plan = sa20_result($assignment->create_plan(array(
        'target'=>$target,'attachment_id'=>$attachment_id,'slot'=>'primary'
    ), $actor), 'Media assignment Preview');
    $assign_id = (string) $assign_plan['plan_id'];
    sa20_rejected($assignment->apply($assign_id, true, $foreign), 'scope_denied', 'Cross-connection assignment');
    sa20_rejected($assignment->apply($assign_id, false, $actor), 'confirmation_required', 'Unconfirmed assignment');
    $assigned = sa20_result($assignment->apply($assign_id, true, $actor), 'Media assignment Apply');
    $assignment_op_id = (string) ($assigned['operation_id'] ?? '');
    sa20_check($assignment_op_id !== ''
        && (int) get_option('eduardo_research_media_primary_home_en', 0) === $attachment_id,
        'Theme Page stored Media assignment must bind actual attachment');
    $in_use_rollback = $ingest->rollback($ingest_op_id, true, $actor);
    sa20_rejected($in_use_rollback, 'rollback_unavailable',
        'Ingest rollback must refuse while image is still assigned');
    $render_verify = sa20_result($assignment->verify($assignment_op_id, true, $actor),
        'Real HTTP-rendered Media assignment Verify');
    sa20_check(($render_verify['status'] ?? '') === 'verified'
        && ! empty($render_verify['stored_verification']['verified'])
        && ! empty($render_verify['rendered_verification']['verified']),
        'Media figure/attachment HTML must appear at real HTTP Home');
    $report['media']['assignment_rendered_http_verified'] = true;
    $assign_rollback = sa20_result($assignment->rollback($assignment_op_id, true, $actor), 'Assignment Rollback');
    sa20_check(($assign_rollback['status'] ?? '') === 'rolled-back'
        && (int) get_option('eduardo_research_media_primary_home_en', 0) === 0,
        'Assignment Rollback must remove precise Theme relationship');
    $assignment_op_id = '';
    $report['media']['assignment_reversible'] = true;

    $ingest_rollback = sa20_result($ingest->rollback($ingest_op_id, true, $actor), 'Ingest Rollback');
    sa20_check(($ingest_rollback['status'] ?? '') === 'rolled-back'
        && get_post($attachment_id) === null, 'Ingest rollback must delete unused owned Media');
    $ingest_op_id = '';
    $report['media']['ingest_reversible'] = true;
    $report['complete'] = true;
} finally {
    // CI is disposable, but best-effort cleanup ensures test code is not itself
    // masking stale relationships or residue when an assertion fails.
    if ($assignment_op_id !== '') { $assignment->rollback($assignment_op_id, true, $actor); }
    if ($metadata_op_id !== '') { $metadata->rollback($metadata_op_id, true, $actor); }
    if ($ingest_op_id !== '') { $ingest->rollback($ingest_op_id, true, $actor); }
    if ($plan_id !== '' && $ingest_op_id === '' && $attachment_id === 0) { $ingest->cancel($plan_id, $actor); }
    @unlink($tmp);
    @unlink('/tmp/sa-47520-not-an-image.png');
    file_put_contents('/tmp/sa-47520-media-rest-report.json',
        (string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
sa20_check($report['complete'] === true, 'all Media + REST gates must succeed');
echo 'PASS: Site Architect #475.20 isolated exported 0.11.1 Media ingest/metadata/assignment '
    . 'checksum+provenance, HTTP render, scoped REST auth/replay/revoke, complete rollback; '
    . 'report=/tmp/sa-47520-media-rest-report.json' . "\n";
