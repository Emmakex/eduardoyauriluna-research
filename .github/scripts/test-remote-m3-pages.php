<?php
/** Fresh-WordPress acceptance for bounded Remote Manager M3 Page control. */

wp_set_current_user(1);

function fail_m3(string $message, mixed $context = null): never {
    fwrite(STDERR, "M3 FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m3_request(
    string $method,
    string $route,
    string $token,
    array $body = array(),
    ?string $request_id = null,
    array $query = array()
): WP_REST_Response {
    $request = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', $request_id ?: 'req-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($query) { $request->set_query_params($query); }
    if ($body) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($request);
}

function fake_rendered_pages(): void {
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
if (is_wp_error($blueprint)) { fail_m3('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { fail_m3('canonical seed failed', $seed); }

rest_get_server();
$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','operations.apply','operations.rollback'),
    1,
    'CI M3 Pages'
);
if (is_wp_error($issued) || empty($issued['token'])) { fail_m3('credential issuance failed'); }
$token = (string) $issued['token'];

$routes = rest_get_server()->get_routes();
foreach (array(
    '/research-manager/v1/pages',
    '/research-manager/v1/pages/(?P<key>[a-z0-9-]+)',
    '/research-manager/v1/pages/(?P<key>[a-z0-9-]+)/seo-geo',
) as $route) {
    if (! isset($routes[$route])) { fail_m3('M3 Page route missing: ' . $route); }
}

$cap = m3_request('GET', 'capabilities', $token)->get_data();
$supported = (array) ($cap['data']['mutation_transport']['supported_operations'] ?? array());
if (! in_array('page-slots-update', $supported, true)
    || ! in_array('page-create', $supported, true)
    || empty($cap['data']['page_control']['structured_slots_only'])) {
    fail_m3('capabilities do not preserve bounded M3 Page control', $cap);
}

$inventory_response = m3_request('GET', 'pages', $token);
if (200 !== $inventory_response->get_status()) { fail_m3('Page inventory failed', $inventory_response->get_data()); }
$inventory = $inventory_response->get_data()['data'] ?? array();
if (empty($inventory['count'])
    || ! in_array('en', (array) ($inventory['languages'] ?? array()), true)
    || ! in_array('es', (array) ($inventory['languages'] ?? array()), true)) {
    fail_m3('Page inventory contract incomplete', $inventory);
}

$inspection_response = m3_request('GET', 'pages/contact', $token, array(), null, array('language'=>'en'));
if (200 !== $inspection_response->get_status()) { fail_m3('Page inspection failed', $inspection_response->get_data()); }
$inspection = $inspection_response->get_data()['data'] ?? array();
if ('contact' !== (string) ($inspection['key'] ?? '')
    || ! in_array('lead', (array) ($inspection['allowed_slots'] ?? array()), true)) {
    fail_m3('contact Page inspection missing structured slot contract', $inspection);
}
$original_en = (string) ($inspection['effective_slots']['lead'] ?? '');

// EN: Preview → Apply → stored/rendered Verify → Rollback.
$new_en = 'Remote M3 English contact lead ' . wp_generate_uuid4();
$plan_request_id = 'req-m3-page-plan-0001';
$plan_body = array(
    'operation'=>'page-slots-update',
    'payload'=>array('key'=>'contact','language'=>'en','slots'=>array('lead'=>$new_en),'reason'=>'CI M3 EN Page update'),
);
$plan_response = m3_request('POST', 'operations/plan', $token, $plan_body, $plan_request_id);
if (200 !== $plan_response->get_status()) { fail_m3('EN Page plan failed', $plan_response->get_data()); }
$plan = $plan_response->get_data()['data'] ?? array();
$plan_id = (string) ($plan['plan_id'] ?? '');
if ('' === $plan_id || 'page-slots-update' !== (string) ($plan['operation'] ?? '') || empty($plan['apply_allowed'])) {
    fail_m3('EN Page plan contract invalid', $plan);
}
$plan_retry = m3_request('POST', 'operations/plan', $token, $plan_body, $plan_request_id);
if (200 !== $plan_retry->get_status() || empty($plan_retry->get_data()['idempotent_replay'])) {
    fail_m3('Page plan idempotency failed', $plan_retry->get_data());
}
$after_preview = Eduardo_Research_Manager::page_editor()->inspect('contact', 'en');
if (is_wp_error($after_preview) || $original_en !== (string) ($after_preview['effective_slots']['lead'] ?? '')) {
    fail_m3('Preview mutated EN Page state', $after_preview);
}

$no_confirm = m3_request('POST', 'plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>false));
if (409 !== $no_confirm->get_status() || 'confirmation_required' !== (string) ($no_confirm->get_data()['code'] ?? '')) {
    fail_m3('Page Apply without confirmation was not blocked', $no_confirm->get_data());
}

$apply_request_id = 'req-m3-page-apply-0001';
$apply = m3_request('POST', 'plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true), $apply_request_id);
if (200 !== $apply->get_status()) { fail_m3('EN Page Apply failed', $apply->get_data()); }
$operation = $apply->get_data()['data'] ?? array();
$operation_id = (string) ($operation['operation_id'] ?? '');
if ('' === $operation_id || empty($operation['snapshot_id']) || empty($operation['result']['verified'])) {
    fail_m3('EN Page operation contract invalid', $operation);
}
$changed_en = Eduardo_Research_Manager::page_editor()->inspect('contact', 'en');
if (is_wp_error($changed_en) || $new_en !== (string) ($changed_en['effective_slots']['lead'] ?? '')) {
    fail_m3('EN Page slot did not change', $changed_en);
}

$stored_verify = m3_request('POST', 'operations/' . rawurlencode($operation_id) . '/verify', $token, array('rendered'=>false));
if (200 !== $stored_verify->get_status() || empty($stored_verify->get_data()['data']['stored_verification']['verified'])) {
    fail_m3('EN Page stored verification failed', $stored_verify->get_data());
}

fake_rendered_pages();
$rendered_verify = m3_request('POST', 'operations/' . rawurlencode($operation_id) . '/verify', $token, array('rendered'=>true));
remove_all_filters('pre_http_request');
if (200 !== $rendered_verify->get_status() || empty($rendered_verify->get_data()['data']['rendered_verification']['verified'])) {
    fail_m3('EN Page rendered verification failed', $rendered_verify->get_data());
}
if (false !== strpos((string) wp_json_encode($rendered_verify->get_data()), '<html')) {
    fail_m3('rendered verification leaked HTML body');
}

$rollback_request_id = 'req-m3-page-rollback-0001';
$rollback = m3_request('POST', 'operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true), $rollback_request_id);
if (200 !== $rollback->get_status()) { fail_m3('EN Page rollback failed', $rollback->get_data()); }
$rolled = $rollback->get_data()['data'] ?? array();
if ('rolled-back' !== (string) ($rolled['status'] ?? '') || empty($rolled['rollback_restored_source_revision'])) {
    fail_m3('EN Page rollback did not restore exact source revision', $rolled);
}
$restored_en = Eduardo_Research_Manager::page_editor()->inspect('contact', 'en');
if (is_wp_error($restored_en) || $original_en !== (string) ($restored_en['effective_slots']['lead'] ?? '')) {
    fail_m3('EN Page rollback did not restore lead', $restored_en);
}

// ES is independent structured state under the same contractual Page surface.
$original_es_state = Eduardo_Research_Manager::page_editor()->inspect('contact', 'es');
if (is_wp_error($original_es_state)) { fail_m3('ES Page inspection failed', $original_es_state->get_error_message()); }
$original_es = (string) ($original_es_state['effective_slots']['lead'] ?? '');
$new_es = 'Lead remoto M3 ' . wp_generate_uuid4();
$es_plan = m3_request('POST', 'operations/plan', $token, array(
    'operation'=>'page-slots-update',
    'payload'=>array('key'=>'contact','language'=>'es','slots'=>array('lead'=>$new_es),'reason'=>'CI M3 ES Page update'),
));
$es_plan_id = (string) ($es_plan->get_data()['data']['plan_id'] ?? '');
if (200 !== $es_plan->get_status() || '' === $es_plan_id) { fail_m3('ES Page plan failed', $es_plan->get_data()); }
$es_apply = m3_request('POST', 'plans/' . rawurlencode($es_plan_id) . '/apply', $token, array('confirm'=>true));
$es_operation_id = (string) ($es_apply->get_data()['data']['operation_id'] ?? '');
if (200 !== $es_apply->get_status() || '' === $es_operation_id) { fail_m3('ES Page Apply failed', $es_apply->get_data()); }
$changed_es = Eduardo_Research_Manager::page_editor()->inspect('contact', 'es');
$still_en = Eduardo_Research_Manager::page_editor()->inspect('contact', 'en');
if (is_wp_error($changed_es) || $new_es !== (string) ($changed_es['effective_slots']['lead'] ?? '')) { fail_m3('ES Page slot did not change', $changed_es); }
if (is_wp_error($still_en) || $original_en !== (string) ($still_en['effective_slots']['lead'] ?? '')) { fail_m3('ES change contaminated EN state', $still_en); }
$es_rollback = m3_request('POST', 'operations/' . rawurlencode($es_operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $es_rollback->get_status()) { fail_m3('ES Page rollback failed', $es_rollback->get_data()); }
$restored_es = Eduardo_Research_Manager::page_editor()->inspect('contact', 'es');
if (is_wp_error($restored_es) || $original_es !== (string) ($restored_es['effective_slots']['lead'] ?? '')) { fail_m3('ES Page rollback did not restore lead', $restored_es); }

// Contract-bounded missing Page creation and provenance-safe rollback.
$missing_key = 'legal-notice';
$missing_id = Eduardo_Research_Manager::contract()->page_id($missing_key);
if ($missing_id <= 0 || ! wp_delete_post($missing_id, true)) { fail_m3('could not prepare missing Page scenario', $missing_id); }
if (! empty(Eduardo_Research_Manager::contract()->page_state($missing_key)['exists'])) { fail_m3('Page deletion did not produce missing state'); }

$create_plan = m3_request('POST', 'operations/plan', $token, array(
    'operation'=>'page-create',
    'payload'=>array('key'=>$missing_key,'reason'=>'CI M3 missing Page recovery'),
));
if (200 !== $create_plan->get_status()) { fail_m3('Page create plan failed', $create_plan->get_data()); }
$create_plan_data = $create_plan->get_data()['data'] ?? array();
$create_plan_id = (string) ($create_plan_data['plan_id'] ?? '');
if ('' === $create_plan_id || 'page-create' !== (string) ($create_plan_data['operation'] ?? '')) { fail_m3('Page create plan contract invalid', $create_plan_data); }
if (! empty(Eduardo_Research_Manager::contract()->page_state($missing_key)['exists'])) { fail_m3('Page create Preview mutated WordPress'); }

$create_apply = m3_request('POST', 'plans/' . rawurlencode($create_plan_id) . '/apply', $token, array('confirm'=>true));
if (200 !== $create_apply->get_status()) { fail_m3('Page create Apply failed', $create_apply->get_data()); }
$create_operation = $create_apply->get_data()['data'] ?? array();
$create_operation_id = (string) ($create_operation['operation_id'] ?? '');
if ('' === $create_operation_id || empty($create_operation['result']['verified']) || empty($create_operation['snapshot_id'])) {
    fail_m3('Page create operation invalid', $create_operation);
}
if (empty(Eduardo_Research_Manager::contract()->page_state($missing_key)['exists'])) { fail_m3('Page was not created'); }

$create_verify = m3_request('POST', 'operations/' . rawurlencode($create_operation_id) . '/verify', $token, array('rendered'=>false));
if (200 !== $create_verify->get_status() || empty($create_verify->get_data()['data']['stored_verification']['verified'])) {
    fail_m3('created Page stored verification failed', $create_verify->get_data());
}

fake_rendered_pages();
$seo_geo = m3_request('GET', 'pages/' . $missing_key . '/seo-geo', $token);
remove_all_filters('pre_http_request');
if (200 !== $seo_geo->get_status() || empty($seo_geo->get_data()['data']['ready'])) {
    fail_m3('Page SEO/GEO inspection failed', $seo_geo->get_data());
}

$create_rollback = m3_request('POST', 'operations/' . rawurlencode($create_operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $create_rollback->get_status()) { fail_m3('Page create rollback failed', $create_rollback->get_data()); }
$create_rolled = $create_rollback->get_data()['data'] ?? array();
if ('rolled-back' !== (string) ($create_rolled['status'] ?? '') || empty($create_rolled['rollback_restored_source_revision'])) {
    fail_m3('Page creation rollback did not restore missing source state', $create_rolled);
}
if (! empty(Eduardo_Research_Manager::contract()->page_state($missing_key)['exists'])) { fail_m3('Page creation rollback did not delete Manager-owned Page'); }

$invalid = m3_request('POST', 'operations/plan', $token, array(
    'operation'=>'page-slots-update',
    'payload'=>array('key'=>'contact','language'=>'en','slots'=>array('not-a-theme-slot'=>'x')),
));
if (400 !== $invalid->get_status()) { fail_m3('unknown Theme slot was not rejected as validation error', $invalid->get_data()); }
$audit = get_option('eduardo_research_manager_remote_audit', array());
$audit_json = (string) wp_json_encode($audit);
foreach (array($plan_id, $operation_id, $create_operation_id, $apply_request_id, $rollback_request_id) as $needle) {
    if (false === strpos($audit_json, $needle)) { fail_m3('M3 audit correlation missing: ' . $needle, $audit); }
}
if (false !== strpos($audit_json, $token)) { fail_m3('M3 audit leaked bearer token'); }

echo "M3 bounded remote Page acceptance OK\n";