<?php
/** Fresh-WordPress acceptance for M7b bounded Theme component variants. */

wp_set_current_user(1);

function m7b_fail(string $message, mixed $context = null): never {
    fwrite(STDERR, "M7B FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m7b_request(string $method, string $route, string $token, array $body = array()): WP_REST_Response {
    $request = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', 'req-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($body) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($request);
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { m7b_fail('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { m7b_fail('canonical seed failed', $seed); }
rest_get_server();

if (! function_exists('eduardo_research_design_variant_public_contract')) { m7b_fail('Theme component variant contract unavailable'); }
$contract = eduardo_research_design_variant_public_contract();
$baseline = array('hero'=>'orbit','sections'=>'layered','cards'=>'bordered','collections'=>'editorial-grid');
if (1 !== (int) ($contract['version'] ?? 0) || $baseline !== (array) ($contract['state'] ?? array())) {
    m7b_fail('Unexpected component variant baseline', $contract);
}
foreach (array('hero','sections','cards','collections') as $dimension) {
    if (count((array) ($contract['presets'][$dimension] ?? array())) < 3) { m7b_fail('Variant dimension lacks bounded choices: ' . $dimension); }
}
if (empty($contract['constraints']['semantic_markup_unchanged'])
    || empty($contract['constraints']['content_order_unchanged'])
    || ! empty($contract['constraints']['arbitrary_html'])
    || ! empty($contract['constraints']['arbitrary_class'])
    || ! empty($contract['constraints']['arbitrary_css'])
    || ! empty($contract['constraints']['section_hiding'])
    || empty($contract['constraints']['reduced_motion_respected'])) {
    m7b_fail('Component variant safety contract incomplete', $contract['constraints'] ?? array());
}

$limited = Eduardo_Research_Manager::remote_credentials()->issue_token(array('site.read'), 1, 'CI M7b no design scope');
if (is_wp_error($limited) || empty($limited['token'])) { m7b_fail('limited credential issuance failed'); }
$denied = m7b_request('GET', 'design/variants', (string) $limited['token']);
if (403 !== $denied->get_status() || 'scope_denied' !== (string) ($denied->get_data()['code'] ?? '')) {
    m7b_fail('design.read was not enforced for variants', $denied->get_data());
}

$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','design.read','design.write','operations.apply','operations.rollback'),
    1,
    'CI M7b bounded component variants'
);
if (is_wp_error($issued) || empty($issued['token'])) { m7b_fail('M7b credential issuance failed'); }
$token = (string) $issued['token'];

$routes = rest_get_server()->get_routes();
foreach (array('/research-manager/v1/design/variants','/research-manager/v1/design/variants/plan') as $route) {
    if (! isset($routes[$route])) { m7b_fail('Missing M7b route: ' . $route); }
}

$cap = m7b_request('GET', 'capabilities', $token);
$cap_data = (array) ($cap->get_data()['data'] ?? array());
$variants_cap = (array) ($cap_data['design_control']['section_component_variants'] ?? array());
if (200 !== $cap->get_status()
    || 'design.read' !== (string) ($cap_data['read_endpoints']['design_variants'] ?? '')
    || empty($variants_cap['available'])
    || ! empty($variants_cap['arbitrary_html'])
    || ! empty($variants_cap['arbitrary_class'])
    || ! empty($variants_cap['arbitrary_css'])
    || ! empty($variants_cap['section_hiding'])) {
    m7b_fail('M7b capability discovery incomplete', $cap->get_data());
}

$invalid_dimension = m7b_request('POST', 'design/variants/plan', $token, array('changes'=>array('html'=>'<script>alert(1)</script>')));
if (400 !== $invalid_dimension->get_status() || 'validation_failed' !== (string) ($invalid_dimension->get_data()['code'] ?? '')) {
    m7b_fail('Arbitrary HTML dimension was not rejected', $invalid_dimension->get_data());
}
$invalid_class = m7b_request('POST', 'design/variants/plan', $token, array('changes'=>array('cards'=>'my-custom-class')));
if (400 !== $invalid_class->get_status()) { m7b_fail('Arbitrary class-like variant was not rejected', $invalid_class->get_data()); }

$m7a_baseline = eduardo_research_design_state();
$plan_response = m7b_request('POST', 'design/variants/plan', $token, array(
    'intent'=>'Use editorial hero, tonal bands, lifted cards and list collections',
    'changes'=>array('hero'=>'editorial','sections'=>'bands','cards'=>'lifted','collections'=>'list'),
));
if (200 !== $plan_response->get_status()) { m7b_fail('Variant Preview failed', $plan_response->get_data()); }
$plan = (array) ($plan_response->get_data()['data'] ?? array());
$plan_id = (string) ($plan['plan_id'] ?? '');
if ('' === $plan_id || empty($plan['apply_allowed']) || eduardo_research_design_variant_state() !== $baseline || eduardo_research_design_state() !== $m7a_baseline) {
    m7b_fail('Variant Preview mutated Theme state or is incomplete', $plan);
}
$expected_after = array('hero'=>'editorial','sections'=>'bands','cards'=>'lifted','collections'=>'list');
if ($expected_after !== (array) ($plan['after'] ?? array())
    || empty($plan['preview']['semantic_markup_unchanged'])
    || empty($plan['preview']['content_order_unchanged'])
    || ! empty($plan['preview']['arbitrary_html'])
    || ! empty($plan['preview']['arbitrary_class'])) {
    m7b_fail('Variant Preview safety contract incomplete', $plan);
}

$unconfirmed = m7b_request('POST', 'design/variants/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>false));
if (409 !== $unconfirmed->get_status() || 'confirmation_required' !== (string) ($unconfirmed->get_data()['code'] ?? '')) {
    m7b_fail('Variant Apply confirmation gate failed', $unconfirmed->get_data());
}

$apply = m7b_request('POST', 'design/variants/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
if (200 !== $apply->get_status()) { m7b_fail('Variant Apply failed', $apply->get_data()); }
$operation = (array) ($apply->get_data()['data'] ?? array());
$operation_id = (string) ($operation['operation_id'] ?? '');
if ('' === $operation_id || eduardo_research_design_variant_state() !== $expected_after || eduardo_research_design_state() !== $m7a_baseline) {
    m7b_fail('Variant Apply did not persist exact isolated state', array('operation'=>$operation,'variants'=>eduardo_research_design_variant_state(),'m7a'=>eduardo_research_design_state()));
}

$expected_classes = array(
    'research-variant-hero-editorial',
    'research-variant-sections-bands',
    'research-variant-cards-lifted',
    'research-variant-collections-list',
);
$body_classes = eduardo_research_design_variant_body_classes(array('site-base'));
foreach ($expected_classes as $class) {
    if (! in_array($class, $body_classes, true)) { m7b_fail('Expected bounded body class missing: ' . $class, $body_classes); }
}
foreach ($body_classes as $class) {
    if ('site-base' === $class) { continue; }
    if (1 !== preg_match('/^research-variant-(hero|sections|cards|collections)-[a-z0-9-]+$/', (string) $class)) {
        m7b_fail('Unexpected/unbounded body class emitted', $body_classes);
    }
}

$css = eduardo_research_design_variant_css();
foreach (array(
    '.research-variant-hero-editorial .research-hero',
    '.research-variant-sections-bands main>.research-section:nth-of-type(even)',
    '.research-variant-cards-lifted .research-card',
    '.research-variant-collections-list .research-collection-grid',
    '@media (prefers-reduced-motion:reduce)',
) as $needle) {
    if (false === strpos($css, $needle)) { m7b_fail('Expected fixed variant CSS selector missing: ' . $needle); }
}
if (false !== stripos($css, 'url(') || false !== stripos($css, '@import') || false !== stripos($css, '<script')) {
    m7b_fail('Variant CSS contains a forbidden dynamic resource or script surface');
}

$verify = m7b_request('POST', 'design/variants/operations/' . rawurlencode($operation_id) . '/verify', $token);
$verified = (array) ($verify->get_data()['data'] ?? array());
if (200 !== $verify->get_status()
    || 'verified' !== (string) ($verified['status'] ?? '')
    || empty($verified['verification']['verified'])
    || empty($verified['verification']['classes_safe'])
    || empty($verified['verification']['safe_css'])
    || empty($verified['verification']['semantic_markup_unchanged'])
    || empty($verified['verification']['content_order_unchanged'])) {
    m7b_fail('Variant Verify failed', $verify->get_data());
}

ob_start();
eduardo_research_render_design_variants();
$style_output = (string) ob_get_clean();
if (false === strpos($style_output, 'id="eduardo-research-component-variants"') || false === strpos($style_output, 'prefers-reduced-motion:reduce')) {
    m7b_fail('Theme did not render bounded component variant stylesheet', $style_output);
}

// Stale plan must not overwrite an externally changed variant state.
$stale_plan_response = m7b_request('POST', 'design/variants/plan', $token, array('changes'=>array('hero'=>'compact')));
if (200 !== $stale_plan_response->get_status()) { m7b_fail('Stale-protection Preview failed', $stale_plan_response->get_data()); }
$stale_plan_id = (string) ($stale_plan_response->get_data()['data']['plan_id'] ?? '');
update_option('eduardo_research_design_variants', array_merge($expected_after, array('cards'=>'quiet')), false);
$stale_apply = m7b_request('POST', 'design/variants/plans/' . rawurlencode($stale_plan_id) . '/apply', $token, array('confirm'=>true));
if (409 !== $stale_apply->get_status() || 'stale_revision' !== (string) ($stale_apply->get_data()['code'] ?? '')) {
    m7b_fail('Variant stale revision protection failed', $stale_apply->get_data());
}
update_option('eduardo_research_design_variants', $expected_after, false);

$rollback = m7b_request('POST', 'design/variants/operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $rollback->get_status()
    || 'rolled-back' !== (string) ($rollback->get_data()['data']['status'] ?? '')
    || eduardo_research_design_variant_state() !== $baseline
    || eduardo_research_design_state() !== $m7a_baseline) {
    m7b_fail('Variant Rollback failed', array('response'=>$rollback->get_data(),'variants'=>eduardo_research_design_variant_state(),'m7a'=>eduardo_research_design_state()));
}

fwrite(STDOUT, "M7b bounded Theme component variants lifecycle OK\n");
