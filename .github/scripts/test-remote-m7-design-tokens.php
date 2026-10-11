<?php
/** Fresh-WordPress acceptance for M7a bounded Theme design controls. */

wp_set_current_user(1);

function m7a_fail(string $message, mixed $context = null): never {
    fwrite(STDERR, "M7A FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m7a_request(string $method, string $route, string $token, array $body = array(), ?string $request_id = null): WP_REST_Response {
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

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { m7a_fail('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { m7a_fail('canonical seed failed', $seed); }
rest_get_server();

if (! function_exists('eduardo_research_design_public_contract')) { m7a_fail('Theme design contract unavailable'); }
$theme_contract = eduardo_research_design_public_contract();
if (1 !== (int) ($theme_contract['version'] ?? 0)
    || empty($theme_contract['constraints']['palette_wcag_contract'])
    || ! empty($theme_contract['constraints']['arbitrary_css'])
    || ! empty($theme_contract['constraints']['arbitrary_font_url'])) {
    m7a_fail('Theme design contract boundaries are incomplete', $theme_contract);
}
foreach ((array) ($theme_contract['presets']['palette'] ?? array()) as $key => $palette) {
    if (empty($palette['accessibility']['accessible'])) { m7a_fail('Palette fails accessibility contract: ' . $key, $palette); }
}

$limited = Eduardo_Research_Manager::remote_credentials()->issue_token(array('site.read'), 1, 'CI M7 no design scope');
if (is_wp_error($limited) || empty($limited['token'])) { m7a_fail('limited credential issuance failed'); }
$denied = m7a_request('GET', 'design', (string) $limited['token']);
if (403 !== $denied->get_status() || 'scope_denied' !== (string) ($denied->get_data()['code'] ?? '')) {
    m7a_fail('design.read scope was not enforced', $denied->get_data());
}

$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','design.read','design.write','operations.apply','operations.rollback'),
    1,
    'CI M7 bounded design'
);
if (is_wp_error($issued) || empty($issued['token'])) { m7a_fail('M7 credential issuance failed'); }
$token = (string) $issued['token'];

$routes = rest_get_server()->get_routes();
foreach (array('/research-manager/v1/design','/research-manager/v1/design/plan') as $route) {
    if (! isset($routes[$route])) { m7a_fail('Missing design route: ' . $route); }
}

$inspect = m7a_request('GET', 'design', $token);
$inspect_data = (array) ($inspect->get_data()['data'] ?? array());
$baseline = (array) ($inspect_data['state'] ?? array());
if (200 !== $inspect->get_status() || array('palette'=>'researcher','typography'=>'modern-sans','density'=>'balanced') !== $baseline) {
    m7a_fail('Unexpected baseline design state', $inspect->get_data());
}

$cap = m7a_request('GET', 'capabilities', $token);
$cap_data = (array) ($cap->get_data()['data'] ?? array());
$control = (array) ($cap_data['design_control'] ?? array());
if (200 !== $cap->get_status()
    || 'design.read' !== (string) ($cap_data['read_endpoints']['design'] ?? '')
    || empty($control['available'])
    || ! empty($control['arbitrary_css'])
    || ! empty($control['arbitrary_font_url'])
    || ! in_array('palette', (array) ($control['dimensions'] ?? array()), true)) {
    m7a_fail('Design capability discovery incomplete', $cap->get_data());
}

$invalid = m7a_request('POST', 'design/plan', $token, array('changes'=>array('css'=>'body{display:none}')));
if (400 !== $invalid->get_status() || 'validation_failed' !== (string) ($invalid->get_data()['code'] ?? '')) {
    m7a_fail('Arbitrary CSS dimension was not rejected', $invalid->get_data());
}
$unknown = m7a_request('POST', 'design/plan', $token, array('changes'=>array('palette'=>'javascript-url')));
if (400 !== $unknown->get_status()) { m7a_fail('Unknown palette was not rejected', $unknown->get_data()); }

$plan_response = m7a_request('POST', 'design/plan', $token, array(
    'intent'=>'Switch Research site to bounded Ocean Lab academic presentation',
    'changes'=>array('palette'=>'ocean-lab','typography'=>'academic-mixed','density'=>'airy'),
));
if (200 !== $plan_response->get_status()) { m7a_fail('Design Preview failed', $plan_response->get_data()); }
$plan = (array) ($plan_response->get_data()['data'] ?? array());
$plan_id = (string) ($plan['plan_id'] ?? '');
if ('' === $plan_id || empty($plan['apply_allowed']) || eduardo_research_design_state() !== $baseline) {
    m7a_fail('Design Preview mutated state or is incomplete', $plan);
}
if ('ocean-lab' !== (string) ($plan['after']['palette'] ?? '')
    || 'academic-mixed' !== (string) ($plan['after']['typography'] ?? '')
    || 'airy' !== (string) ($plan['after']['density'] ?? '')
    || empty($plan['preview']['palette_accessibility']['accessible'])
    || ! empty($plan['preview']['arbitrary_css'])) {
    m7a_fail('Design Preview contract incomplete', $plan);
}

$unconfirmed = m7a_request('POST', 'design/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>false));
if (409 !== $unconfirmed->get_status() || 'confirmation_required' !== (string) ($unconfirmed->get_data()['code'] ?? '')) {
    m7a_fail('Design Apply confirmation gate failed', $unconfirmed->get_data());
}

$apply = m7a_request('POST', 'design/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
if (200 !== $apply->get_status()) { m7a_fail('Design Apply failed', $apply->get_data()); }
$operation = (array) ($apply->get_data()['data'] ?? array());
$operation_id = (string) ($operation['operation_id'] ?? '');
$active = eduardo_research_design_state();
if ('' === $operation_id || 'ocean-lab' !== $active['palette'] || 'academic-mixed' !== $active['typography'] || 'airy' !== $active['density']) {
    m7a_fail('Design Apply did not persist exact preset state', array('operation'=>$operation,'state'=>$active));
}

$verify = m7a_request('POST', 'design/operations/' . rawurlencode($operation_id) . '/verify', $token);
$verified = (array) ($verify->get_data()['data'] ?? array());
if (200 !== $verify->get_status()
    || 'verified' !== (string) ($verified['status'] ?? '')
    || empty($verified['verification']['verified'])
    || empty($verified['verification']['safe_css'])
    || empty($verified['verification']['palette_accessibility']['accessible'])) {
    m7a_fail('Design Verify failed', $verify->get_data());
}

ob_start();
eduardo_research_render_design_tokens();
$rendered_css = (string) ob_get_clean();
if (false === strpos($rendered_css, 'id="eduardo-research-design-tokens"')
    || false === strpos($rendered_css, '--research-accent:#0A6575')
    || false === strpos($rendered_css, '--research-font-display:Georgia,"Times New Roman",serif')
    || false === strpos($rendered_css, '--research-section-padding:clamp(5.5rem,10vw,10rem)')
    || false !== stripos($rendered_css, 'url(')
    || false !== stripos($rendered_css, '@import')) {
    m7a_fail('Rendered Theme design tokens are unsafe or incomplete', $rendered_css);
}

// A second Preview becomes stale if the Theme design state changes outside its exact plan.
$stale_plan_response = m7a_request('POST', 'design/plan', $token, array('changes'=>array('density'=>'compact')));
if (200 !== $stale_plan_response->get_status()) { m7a_fail('Stale-protection Preview failed', $stale_plan_response->get_data()); }
$stale_plan_id = (string) ($stale_plan_response->get_data()['data']['plan_id'] ?? '');
update_option('eduardo_research_design', array_merge($active, array('typography'=>'humanist-sans')), false);
$stale_apply = m7a_request('POST', 'design/plans/' . rawurlencode($stale_plan_id) . '/apply', $token, array('confirm'=>true));
if (409 !== $stale_apply->get_status() || 'stale_revision' !== (string) ($stale_apply->get_data()['code'] ?? '')) {
    m7a_fail('Design stale revision protection failed', $stale_apply->get_data());
}
update_option('eduardo_research_design', $active, false);

$rollback = m7a_request('POST', 'design/operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $rollback->get_status() || 'rolled-back' !== (string) ($rollback->get_data()['data']['status'] ?? '') || eduardo_research_design_state() !== $baseline) {
    m7a_fail('Design Rollback failed', array('response'=>$rollback->get_data(),'state'=>eduardo_research_design_state()));
}

fwrite(STDOUT, "M7a bounded Theme design tokens lifecycle OK\n");
