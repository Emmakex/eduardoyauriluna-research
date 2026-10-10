<?php
/** Fresh-WordPress acceptance for bounded Remote Manager Page remediation. */

wp_set_current_user(1);

function fail_m3_remediation(string $message, mixed $context = null): never {
    fwrite(STDERR, "M3 REMEDIATION FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m3_remediation_request(string $method, string $route, string $token, array $body = array(), ?string $request_id = null): WP_REST_Response {
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

function fake_m3_rendered_page(): void {
    add_filter('pre_http_request', static function ($preempt, $args, $url) {
        $lang = false !== strpos((string) $url, '/es') ? 'es' : 'en';
        $safe = esc_url((string) $url);
        $body = '<!doctype html><html lang="' . esc_attr($lang) . '"><head>'
            . '<link rel="canonical" href="' . $safe . '">'
            . '<link rel="alternate" hreflang="en" href="' . $safe . '">'
            . '<link rel="alternate" hreflang="es" href="' . $safe . '">'
            . '<link rel="alternate" hreflang="x-default" href="' . $safe . '">'
            . '<meta property="og:url" content="' . $safe . '">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebPage"}</script>'
            . '</head><body>Research Page</body></html>';
        return array('headers'=>array(),'body'=>$body,'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array(),'filename'=>null);
    }, 10, 3);
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { fail_m3_remediation('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { fail_m3_remediation('canonical seed failed', $seed); }

rest_get_server();
$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','operations.apply','operations.rollback'),
    1,
    'CI M3 Page Remediation'
);
if (is_wp_error($issued) || empty($issued['token'])) { fail_m3_remediation('credential issuance failed'); }
$token = (string) $issued['token'];

$capabilities = m3_remediation_request('GET', 'capabilities', $token)->get_data();
$supported = (array) ($capabilities['data']['mutation_transport']['supported_operations'] ?? array());
if (! in_array('page-remediate', $supported, true)
    || empty($capabilities['data']['page_control']['bounded_readiness_remediation'])
    || 'M6' !== (string) ($capabilities['data']['page_control']['advanced_seo_geo_remediation_milestone'] ?? '')) {
    fail_m3_remediation('capabilities do not expose the bounded remediation boundary', $capabilities);
}

$contact_id = Eduardo_Research_Manager::contract()->page_id('contact');
if ($contact_id <= 0) { fail_m3_remediation('contact Page unavailable'); }
$expected_role = (string) (Eduardo_Research_Manager::contract()->pages()['contact']['role'] ?? '');
if ('' === $expected_role) { fail_m3_remediation('contact expected role unavailable'); }
$broken_role = 'broken-ci-role';
update_post_meta($contact_id, '_eduardo_research_role', $broken_role);
if ($broken_role !== (string) get_post_meta($contact_id, '_eduardo_research_role', true)) {
    fail_m3_remediation('could not establish broken Page state');
}

$source_revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
if (is_wp_error($source_revision) || '' === $source_revision) { fail_m3_remediation('could not fingerprint broken source state', $source_revision); }

fake_m3_rendered_page();
$inspection = m3_remediation_request('GET', 'pages/contact/seo-geo', $token);
remove_all_filters('pre_http_request');
if (200 !== $inspection->get_status()) { fail_m3_remediation('Page SEO/GEO inspection failed', $inspection->get_data()); }
$inspection_data = $inspection->get_data()['data'] ?? array();
$candidates = (array) ($inspection_data['remediation']['candidates'] ?? array());
$candidate_ids = array_map(static fn(array $item): string => (string) ($item['check_id'] ?? ''), $candidates);
if (! in_array('page-contact', $candidate_ids, true)) {
    fail_m3_remediation('broken contact Page was not exposed as an auto-remediable candidate', $inspection_data);
}

$invalid = m3_remediation_request('POST', 'operations/plan', $token, array(
    'operation'=>'page-remediate',
    'payload'=>array('key'=>'contact','check_id'=>'language-contract'),
));
if (400 !== $invalid->get_status() || 'validation_failed' !== (string) ($invalid->get_data()['code'] ?? '')) {
    fail_m3_remediation('cross-resource remediation was not rejected', $invalid->get_data());
}

$plan_request_id = 'req-m3-remediation-plan-0001';
$plan_body = array(
    'operation'=>'page-remediate',
    'payload'=>array('key'=>'contact','check_id'=>'page-contact','reason'=>'CI bounded contact Page remediation'),
);
$plan_response = m3_remediation_request('POST', 'operations/plan', $token, $plan_body, $plan_request_id);
if (200 !== $plan_response->get_status()) { fail_m3_remediation('remediation plan failed', $plan_response->get_data()); }
$plan = $plan_response->get_data()['data'] ?? array();
$plan_id = (string) ($plan['plan_id'] ?? '');
if ('' === $plan_id || 'page-remediate' !== (string) ($plan['operation'] ?? '') || empty($plan['apply_allowed'])) {
    fail_m3_remediation('remediation plan contract invalid', $plan);
}
if ($broken_role !== (string) get_post_meta($contact_id, '_eduardo_research_role', true)) {
    fail_m3_remediation('remediation Preview mutated Page state');
}

$retry = m3_remediation_request('POST', 'operations/plan', $token, $plan_body, $plan_request_id);
if (200 !== $retry->get_status() || empty($retry->get_data()['idempotent_replay'])) {
    fail_m3_remediation('remediation plan idempotency failed', $retry->get_data());
}

$no_confirm = m3_remediation_request('POST', 'plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>false));
if (409 !== $no_confirm->get_status() || 'confirmation_required' !== (string) ($no_confirm->get_data()['code'] ?? '')) {
    fail_m3_remediation('remediation Apply without confirmation was not blocked', $no_confirm->get_data());
}

$apply_request_id = 'req-m3-remediation-apply-0001';
$apply = m3_remediation_request('POST', 'plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true), $apply_request_id);
if (200 !== $apply->get_status()) { fail_m3_remediation('remediation Apply failed', $apply->get_data()); }
$operation = $apply->get_data()['data'] ?? array();
$operation_id = (string) ($operation['operation_id'] ?? '');
if ('' === $operation_id || empty($operation['snapshot_id']) || empty($operation['result']['verified'])) {
    fail_m3_remediation('remediation operation contract invalid', $operation);
}
if ($expected_role !== (string) get_post_meta($contact_id, '_eduardo_research_role', true)) {
    fail_m3_remediation('remediation did not restore Theme Page role');
}

$stored_verify = m3_remediation_request('POST', 'operations/' . rawurlencode($operation_id) . '/verify', $token, array('rendered'=>false));
if (200 !== $stored_verify->get_status() || empty($stored_verify->get_data()['data']['stored_verification']['verified'])) {
    fail_m3_remediation('stored remediation verification failed', $stored_verify->get_data());
}

fake_m3_rendered_page();
$rendered_verify = m3_remediation_request('POST', 'operations/' . rawurlencode($operation_id) . '/verify', $token, array('rendered'=>true));
remove_all_filters('pre_http_request');
if (200 !== $rendered_verify->get_status() || empty($rendered_verify->get_data()['data']['rendered_verification']['verified'])) {
    fail_m3_remediation('rendered remediation verification failed', $rendered_verify->get_data());
}
if (false !== strpos((string) wp_json_encode($rendered_verify->get_data()), '<html')) {
    fail_m3_remediation('rendered remediation verification leaked HTML');
}

$rollback_request_id = 'req-m3-remediation-rollback-0001';
$rollback = m3_remediation_request('POST', 'operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true), $rollback_request_id);
if (200 !== $rollback->get_status()) { fail_m3_remediation('remediation rollback failed', $rollback->get_data()); }
$rolled = $rollback->get_data()['data'] ?? array();
if ('rolled-back' !== (string) ($rolled['status'] ?? '') || empty($rolled['rollback_restored_source_revision'])) {
    fail_m3_remediation('remediation rollback did not restore exact source revision', $rolled);
}
if ($broken_role !== (string) get_post_meta($contact_id, '_eduardo_research_role', true)) {
    fail_m3_remediation('rollback did not restore deliberately broken source state');
}
if (! hash_equals((string) $source_revision, (string) ($rolled['post_rollback_revision'] ?? ''))) {
    fail_m3_remediation('rollback revision differs from the exact source revision', $rolled);
}

// Repair again with a fresh exact plan so the acceptance finishes in a healthy state.
$fresh = m3_remediation_request('POST', 'operations/plan', $token, $plan_body);
$fresh_plan_id = (string) ($fresh->get_data()['data']['plan_id'] ?? '');
if (200 !== $fresh->get_status() || '' === $fresh_plan_id) { fail_m3_remediation('fresh remediation plan failed', $fresh->get_data()); }
$fresh_apply = m3_remediation_request('POST', 'plans/' . rawurlencode($fresh_plan_id) . '/apply', $token, array('confirm'=>true));
$fresh_operation_id = (string) ($fresh_apply->get_data()['data']['operation_id'] ?? '');
if (200 !== $fresh_apply->get_status() || '' === $fresh_operation_id) { fail_m3_remediation('fresh remediation Apply failed', $fresh_apply->get_data()); }
$fresh_verify = m3_remediation_request('POST', 'operations/' . rawurlencode($fresh_operation_id) . '/verify', $token, array('rendered'=>false));
if (200 !== $fresh_verify->get_status() || empty($fresh_verify->get_data()['data']['stored_verification']['verified'])) {
    fail_m3_remediation('fresh remediation verification failed', $fresh_verify->get_data());
}
if ($expected_role !== (string) get_post_meta($contact_id, '_eduardo_research_role', true)) {
    fail_m3_remediation('acceptance did not finish in healthy Page state');
}

$audit_json = (string) wp_json_encode(get_option('eduardo_research_manager_remote_audit', array()));
foreach (array($plan_id, $operation_id, $apply_request_id, $rollback_request_id) as $needle) {
    if (false === strpos($audit_json, $needle)) { fail_m3_remediation('audit correlation missing: ' . $needle); }
}
if (false !== strpos($audit_json, $token)) { fail_m3_remediation('audit leaked bearer token'); }

echo "M3 bounded Page remediation acceptance OK\n";
