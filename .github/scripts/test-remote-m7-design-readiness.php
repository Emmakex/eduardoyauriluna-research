<?php
/** Fresh-WordPress acceptance for final M7 aggregate design readiness. */

wp_set_current_user(1);

function m7c_fail(string $message, mixed $context = null): never {
    fwrite(STDERR, "M7C FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m7c_request(string $method, string $route, string $token, array $body = array(), array $query = array()): WP_REST_Response {
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

function m7c_semantic_signature(string $body): string {
    $tags = array();
    if (preg_match_all('/<(h[1-6]|main|section|article|nav)\b/i', $body, $matches)) {
        $tags = array_map('strtolower', $matches[1]);
    }
    $headings = array();
    if (preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $body, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags((string) $match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $headings[] = array('level'=>(int) $match[1], 'text'=>$text);
        }
    }
    $links = array();
    if (preg_match_all('/<a\b[^>]*\bhref=["\']([^"\']+)["\']/i', $body, $matches)) {
        foreach ($matches[1] as $href) { $links[] = html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
    }
    return hash('sha256', (string) wp_json_encode(array('tags'=>$tags,'headings'=>$headings,'links'=>$links)));
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { m7c_fail('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { m7c_fail('canonical seed failed', $seed); }
rest_get_server();

foreach (array(
    'eduardo_research_design_public_contract',
    'eduardo_research_design_variant_public_contract',
    'eduardo_research_design_css',
    'eduardo_research_design_variant_css',
) as $fn) {
    if (! function_exists($fn)) { m7c_fail('Required Theme design function unavailable: ' . $fn); }
}

$limited = Eduardo_Research_Manager::remote_credentials()->issue_token(array('site.read'), 1, 'CI M7c no design scope');
if (is_wp_error($limited) || empty($limited['token'])) { m7c_fail('limited credential issuance failed'); }
$denied = m7c_request('GET', 'design/readiness', (string) $limited['token'], array(), array('rendered'=>'0'));
if (403 !== $denied->get_status() || 'scope_denied' !== (string) ($denied->get_data()['code'] ?? '')) {
    m7c_fail('design.read was not enforced for aggregate readiness', $denied->get_data());
}

$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','design.read','design.write','operations.apply','operations.rollback'),
    1,
    'CI M7c final aggregate design readiness'
);
if (is_wp_error($issued) || empty($issued['token'])) { m7c_fail('M7c credential issuance failed'); }
$token = (string) $issued['token'];

$routes = rest_get_server()->get_routes();
if (! isset($routes['/research-manager/v1/design/readiness'])) { m7c_fail('Missing M7c readiness route'); }

$cap = m7c_request('GET', 'capabilities', $token);
$cap_data = (array) ($cap->get_data()['data'] ?? array());
$aggregate_cap = (array) ($cap_data['design_control']['aggregate_readiness'] ?? array());
if (200 !== $cap->get_status()
    || 'design.read' !== (string) ($cap_data['read_endpoints']['design_readiness'] ?? '')
    || empty($aggregate_cap['available'])
    || empty($aggregate_cap['read_only'])
    || ! empty($aggregate_cap['direct_mutation'])
    || empty($aggregate_cap['rendered_home_en_es'])) {
    m7c_fail('M7c capability discovery incomplete', $cap->get_data());
}

$static = m7c_request('GET', 'design/readiness', $token, array(), array('rendered'=>'0'));
$static_data = (array) ($static->get_data()['data'] ?? array());
if (200 !== $static->get_status()
    || empty($static_data['ready'])
    || ! empty($static_data['repository_ci_complete'])
    || empty($static_data['design']['palette_accessibility']['accessible'])
    || empty($static_data['design']['safe_css'])
    || empty($static_data['variants']['classes_safe'])
    || empty($static_data['variants']['semantic_boundary'])
    || empty($static_data['variants']['safe_css'])
    || empty($static_data['responsive_accessibility']['ready'])
    || ! empty($static_data['blocking_count'])
    || ! empty($static_data['residual_actions']['summary']['total'])) {
    m7c_fail('Static aggregate readiness is not clean', $static_data);
}
foreach (array('fluid_spacing','mobile_variant_guard','mobile_hover_lift_disabled','reduced_motion','reduced_motion_contract') as $check) {
    if (empty($static_data['responsive_accessibility'][$check])) { m7c_fail('Responsive/accessibility check failed: ' . $check, $static_data); }
}
if (empty($static_data['mutation_model']['readiness_read_only'])
    || ! empty($static_data['mutation_model']['auto_apply'])
    || ! empty($static_data['mutation_model']['arbitrary_css'])
    || ! empty($static_data['mutation_model']['arbitrary_html'])
    || ! empty($static_data['mutation_model']['arbitrary_classes'])) {
    m7c_fail('Aggregate readiness mutation boundary is unsafe', $static_data['mutation_model'] ?? array());
}

$verifier = new Eduardo_Research_Manager_Rendered_Verifier();
$baseline_en = $verifier->verify_page('home', 'en');
$baseline_es = $verifier->verify_page('home', 'es');
if (is_wp_error($baseline_en) || is_wp_error($baseline_es) || empty($baseline_en['verified']) || empty($baseline_es['verified'])) {
    m7c_fail('Baseline rendered Home verification failed', array(
        'en'=>is_wp_error($baseline_en) ? $baseline_en->get_error_message() : $baseline_en,
        'es'=>is_wp_error($baseline_es) ? $baseline_es->get_error_message() : $baseline_es,
    ));
}
$baseline_signatures = array(
    'en'=>m7c_semantic_signature((string) $baseline_en['body']),
    'es'=>m7c_semantic_signature((string) $baseline_es['body']),
);

$baseline_readiness = m7c_request('GET', 'design/readiness', $token);
$baseline_readiness_data = (array) ($baseline_readiness->get_data()['data'] ?? array());
if (200 !== $baseline_readiness->get_status()
    || empty($baseline_readiness_data['ready'])
    || empty($baseline_readiness_data['repository_ci_complete'])
    || empty($baseline_readiness_data['rendered']['ready'])
    || empty($baseline_readiness_data['rendered']['surfaces']['en']['ready'])
    || empty($baseline_readiness_data['rendered']['surfaces']['es']['ready'])) {
    m7c_fail('Rendered aggregate baseline readiness failed', $baseline_readiness_data);
}

$m7a_baseline = eduardo_research_design_state();
$m7b_baseline = eduardo_research_design_variant_state();

$design_plan_response = m7c_request('POST', 'design/plan', $token, array(
    'intent'=>'M7c representative accessible design state',
    'changes'=>array('palette'=>'ocean-lab','typography'=>'academic-mixed','density'=>'compact'),
));
if (200 !== $design_plan_response->get_status()) { m7c_fail('M7a representative Preview failed', $design_plan_response->get_data()); }
$design_plan_id = (string) ($design_plan_response->get_data()['data']['plan_id'] ?? '');
$design_apply = m7c_request('POST', 'design/plans/' . rawurlencode($design_plan_id) . '/apply', $token, array('confirm'=>true));
if (200 !== $design_apply->get_status()) { m7c_fail('M7a representative Apply failed', $design_apply->get_data()); }
$design_operation_id = (string) ($design_apply->get_data()['data']['operation_id'] ?? '');

$variant_plan_response = m7c_request('POST', 'design/variants/plan', $token, array(
    'intent'=>'M7c representative component state',
    'changes'=>array('hero'=>'editorial','sections'=>'bands','cards'=>'lifted','collections'=>'list'),
));
if (200 !== $variant_plan_response->get_status()) { m7c_fail('M7b representative Preview failed', $variant_plan_response->get_data()); }
$variant_plan_id = (string) ($variant_plan_response->get_data()['data']['plan_id'] ?? '');
$variant_apply = m7c_request('POST', 'design/variants/plans/' . rawurlencode($variant_plan_id) . '/apply', $token, array('confirm'=>true));
if (200 !== $variant_apply->get_status()) { m7c_fail('M7b representative Apply failed', $variant_apply->get_data()); }
$variant_operation_id = (string) ($variant_apply->get_data()['data']['operation_id'] ?? '');

$design_verify = m7c_request('POST', 'design/operations/' . rawurlencode($design_operation_id) . '/verify', $token);
$variant_verify = m7c_request('POST', 'design/variants/operations/' . rawurlencode($variant_operation_id) . '/verify', $token);
if (200 !== $design_verify->get_status() || empty($design_verify->get_data()['data']['verification']['verified'])) {
    m7c_fail('M7a representative Verify failed', $design_verify->get_data());
}
if (200 !== $variant_verify->get_status() || empty($variant_verify->get_data()['data']['verification']['verified'])) {
    m7c_fail('M7b representative Verify failed', $variant_verify->get_data());
}

$changed_readiness = m7c_request('GET', 'design/readiness', $token);
$changed = (array) ($changed_readiness->get_data()['data'] ?? array());
$expected_design = array('palette'=>'ocean-lab','typography'=>'academic-mixed','density'=>'compact');
$expected_variants = array('hero'=>'editorial','sections'=>'bands','cards'=>'lifted','collections'=>'list');
if (200 !== $changed_readiness->get_status()
    || empty($changed['ready'])
    || empty($changed['repository_ci_complete'])
    || $expected_design !== (array) ($changed['design']['state'] ?? array())
    || $expected_variants !== (array) ($changed['variants']['state'] ?? array())
    || empty($changed['rendered']['ready'])
    || ! empty($changed['blocking_count'])
    || ! empty($changed['residual_actions']['summary']['total'])) {
    m7c_fail('Changed aggregate design readiness failed', $changed);
}
foreach (array(
    'research-variant-hero-editorial',
    'research-variant-sections-bands',
    'research-variant-cards-lifted',
    'research-variant-collections-list',
) as $class) {
    if (empty($changed['rendered']['surfaces']['en']['body_classes'][$class]) || empty($changed['rendered']['surfaces']['es']['body_classes'][$class])) {
        m7c_fail('Rendered bounded body class missing: ' . $class, $changed['rendered']);
    }
}

$changed_en = $verifier->verify_page('home', 'en');
$changed_es = $verifier->verify_page('home', 'es');
if (is_wp_error($changed_en) || is_wp_error($changed_es) || empty($changed_en['verified']) || empty($changed_es['verified'])) {
    m7c_fail('Changed rendered Home verification failed');
}
if ($baseline_signatures['en'] !== m7c_semantic_signature((string) $changed_en['body'])
    || $baseline_signatures['es'] !== m7c_semantic_signature((string) $changed_es['body'])) {
    m7c_fail('Bounded design operation changed semantic structure, heading/link order or content order');
}

$variant_rollback = m7c_request('POST', 'design/variants/operations/' . rawurlencode($variant_operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $variant_rollback->get_status() || 'rolled-back' !== (string) ($variant_rollback->get_data()['data']['status'] ?? '')) {
    m7c_fail('M7b representative Rollback failed', $variant_rollback->get_data());
}
$design_rollback = m7c_request('POST', 'design/operations/' . rawurlencode($design_operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $design_rollback->get_status() || 'rolled-back' !== (string) ($design_rollback->get_data()['data']['status'] ?? '')) {
    m7c_fail('M7a representative Rollback failed', $design_rollback->get_data());
}
if ($m7a_baseline !== eduardo_research_design_state() || $m7b_baseline !== eduardo_research_design_variant_state()) {
    m7c_fail('Aggregate rollback did not restore exact M7a/M7b baselines', array('m7a'=>eduardo_research_design_state(),'m7b'=>eduardo_research_design_variant_state()));
}

$final = m7c_request('GET', 'design/readiness', $token);
$final_data = (array) ($final->get_data()['data'] ?? array());
if (200 !== $final->get_status()
    || empty($final_data['ready'])
    || empty($final_data['repository_ci_complete'])
    || ! empty($final_data['blocking_count'])
    || ! empty($final_data['residual_actions']['summary']['total'])
    || 'M8 real eduardoyauriluna.com operating acceptance' !== (string) ($final_data['next_stage'] ?? '')) {
    m7c_fail('Final M7 readiness did not return clean baseline', $final_data);
}

fwrite(STDOUT, "M7c aggregate Theme design readiness, responsive/accessibility rendering and rollback OK\n");
