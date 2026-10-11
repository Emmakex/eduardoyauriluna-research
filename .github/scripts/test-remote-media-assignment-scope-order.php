<?php
/** Security acceptance: assignment semantic scopes are checked before target lookup. */

wp_set_current_user(1);

function fail_p3_media_assignment_scope_order(string $message, mixed $context = null): never {
    fwrite(STDERR, "P3 MEDIA ASSIGNMENT SCOPE ORDER FAIL: {$message}\n");
    if (null !== $context) {
        fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    exit(1);
}

function issue_p3_media_assignment_scope_token(array $scopes, string $label): string {
    $issued = Eduardo_Research_Manager::remote_credentials()->issue_token($scopes, 1, $label);
    if (is_wp_error($issued) || empty($issued['token'])) {
        fail_p3_media_assignment_scope_order('credential issuance failed', $issued);
    }
    return (string) $issued['token'];
}

function p3_media_assignment_scope_request(string $method, string $route, string $token, array $body = array(), array $query = array()): WP_REST_Response {
    $request = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', 'req-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($query) { $request->set_query_params($query); }
    if ($body) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($request);
}

rest_get_server();
$missing_id = 999999999;

$inspect_token = issue_p3_media_assignment_scope_token(array('media.read'), 'CI P3 assignment scope order read');
$inspect = p3_media_assignment_scope_request('GET', 'media/assignments', $inspect_token, array(), array(
    'type'=>'insight',
    'post_id'=>$missing_id,
    'slot'=>'primary',
));
if (403 !== $inspect->get_status() || 'scope_denied' !== (string) ($inspect->get_data()['code'] ?? '')) {
    fail_p3_media_assignment_scope_order('inspection resolved target before enforcing insights.read', $inspect->get_data());
}

$plan_token = issue_p3_media_assignment_scope_token(
    array('operations.apply','media.write'),
    'CI P3 assignment scope order write'
);
$plan = p3_media_assignment_scope_request('POST', 'media/assignments/plan', $plan_token, array(
    'payload'=>array(
        'target'=>array('type'=>'insight','post_id'=>$missing_id),
        'attachment_id'=>$missing_id,
        'slot'=>'primary',
    ),
));
if (403 !== $plan->get_status() || 'scope_denied' !== (string) ($plan->get_data()['code'] ?? '')) {
    fail_p3_media_assignment_scope_order('Plan resolved target or Media before enforcing insights.write', $plan->get_data());
}

fwrite(STDOUT, "P3 Media assignment scope ordering: PASS\n");
