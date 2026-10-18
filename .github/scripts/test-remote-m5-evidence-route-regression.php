<?php
/** Regression for the Evidence plan-status route used by the MCP Apply preflight. */
wp_set_current_user(1);

function m5er_fail(string $message, mixed $context = null): never {
    fwrite(STDERR, "M5 EVIDENCE ROUTE FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m5er_request(string $method, string $route, string $token, array $body = array()): WP_REST_Response {
    $request = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', 'route-regression-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($body) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($request);
}

rest_get_server();

$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','evidence.read','evidence.write','operations.apply','operations.rollback'),
    1,
    'CI M5 Evidence route regression'
);
if (is_wp_error($issued) || empty($issued['token'])) { m5er_fail('credential issuance failed', $issued); }
$token = (string) $issued['token'];

$plan_response = m5er_request('POST', 'research-evidence/plan', $token, array(
    'operation'=>'evidence-create',
    'payload'=>array(
        'group'=>'methods',
        'data'=>array(
            'title'=>'Evidence route regression fixture',
            'summary'=>'Preview-only fixture proving that plans is not interpreted as an evidence group.',
            'status'=>'unverified',
        ),
        'evidence_confirmed'=>true,
        'evidence_reference'=>'ci:m5-evidence-route-regression',
    ),
));
if (200 !== $plan_response->get_status()) { m5er_fail('plan creation failed', $plan_response->get_data()); }
$plan = (array) ($plan_response->get_data()['data'] ?? array());
$plan_id = (string) ($plan['plan_id'] ?? '');
if ('' === $plan_id) { m5er_fail('plan_id missing', $plan); }

// This exact GET is performed by ResearchManagerA3WriteClient before Evidence Apply.
$preflight = m5er_request('GET', 'research-evidence/plans/' . rawurlencode($plan_id), $token);
if (200 !== $preflight->get_status()) {
    m5er_fail('plan preflight route collided with the generic evidence record route', $preflight->get_data());
}
$read_plan = (array) ($preflight->get_data()['data'] ?? array());
if ($plan_id !== (string) ($read_plan['plan_id'] ?? '') || 'evidence-create' !== (string) ($read_plan['operation'] ?? '')) {
    m5er_fail('plan preflight returned the wrong resource', $read_plan);
}

$record_route = m5er_request('GET', 'research-evidence/methods/' . rawurlencode((string) ($plan['target']['record_id'] ?? 'missing')), $token);
if (200 === $record_route->get_status()) {
    m5er_fail('Preview unexpectedly mutated the evidence store', $record_route->get_data());
}

fwrite(STDOUT, "M5 Evidence plan preflight route regression OK\n");
