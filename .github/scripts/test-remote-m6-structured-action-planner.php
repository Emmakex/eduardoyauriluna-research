<?php
/** Fresh-WordPress acceptance for M6 structured SEO/GEO action planning. */
wp_set_current_user(1);

function m6b_fail(string $message, mixed $context = null): never {
    fwrite(STDERR, "M6 ACTION PLANNER FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m6b_request(string $method, string $route, string $token, array $body = array(), array $query = array(), string $rid = ''): WP_REST_Response {
    $request = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', '' !== $rid ? $rid : 'req-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($query) { $request->set_query_params($query); }
    if ($body) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($request);
}

function m6b_find_check(array $planner, string $check_id): array {
    foreach ((array) ($planner['checks'] ?? array()) as $check) {
        if (is_array($check) && $check_id === (string) ($check['check_id'] ?? '')) { return $check; }
    }
    m6b_fail('Planner check missing: ' . $check_id, $planner);
}

function m6b_page_plan(string $token, string $operation, array $payload): array {
    $response = m6b_request('POST', 'operations/plan', $token, array('operation'=>$operation,'payload'=>$payload));
    if (200 !== $response->get_status()) { m6b_fail('Page gateway plan failed', $response->get_data()); }
    return (array) ($response->get_data()['data'] ?? array());
}

function m6b_page_apply(string $token, string $plan_id): array {
    $response = m6b_request('POST', 'plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
    if (200 !== $response->get_status()) { m6b_fail('Page gateway Apply failed', $response->get_data()); }
    $operation = (array) ($response->get_data()['data'] ?? array());
    if (empty($operation['operation_id']) || empty($operation['result']['verified'])) { m6b_fail('Page gateway operation invalid', $operation); }
    return $operation;
}

function m6b_page_rollback(string $token, string $operation_id): void {
    $response = m6b_request('POST', 'operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
    if (200 !== $response->get_status() || 'rolled-back' !== (string) ($response->get_data()['data']['status'] ?? '')) {
        m6b_fail('Page gateway rollback failed', $response->get_data());
    }
}

function m6b_object_plan(string $token, string $operation, array $payload): array {
    $response = m6b_request('POST', 'research-objects/plan', $token, array('operation'=>$operation,'payload'=>$payload));
    if (200 !== $response->get_status()) { m6b_fail('Research Object plan failed', $response->get_data()); }
    return (array) ($response->get_data()['data'] ?? array());
}

function m6b_object_apply(string $token, string $plan_id): array {
    $response = m6b_request('POST', 'research-objects/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
    if (200 !== $response->get_status()) { m6b_fail('Research Object Apply failed', $response->get_data()); }
    $operation = (array) ($response->get_data()['data'] ?? array());
    if (empty($operation['operation_id']) || empty($operation['result']['verified'])) { m6b_fail('Research Object operation invalid', $operation); }
    return $operation;
}

function m6b_object_rollback(string $token, string $operation_id): void {
    $response = m6b_request('POST', 'research-objects/operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
    if (200 !== $response->get_status() || 'rolled-back' !== (string) ($response->get_data()['data']['status'] ?? '')) {
        m6b_fail('Research Object rollback failed', $response->get_data());
    }
}

function m6b_fake_page_http(string $url, string $language, string $en_url, string $es_url): void {
    add_filter('pre_http_request', static function ($preempt, $args, $requested_url) use ($url, $language, $en_url, $es_url) {
        if (untrailingslashit((string) $requested_url) !== untrailingslashit($url)) { return $preempt; }
        $safe = esc_url($url);
        $body = '<!doctype html><html lang="' . esc_attr($language) . '"><head>'
            . '<link rel="canonical" href="' . $safe . '">'
            . '<link rel="alternate" hreflang="en" href="' . esc_url($en_url) . '">'
            . '<link rel="alternate" hreflang="es" href="' . esc_url($es_url) . '">'
            . '<link rel="alternate" hreflang="x-default" href="' . esc_url($en_url) . '">'
            . '<meta property="og:url" content="' . $safe . '">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebPage"}</script>'
            . '</head><body><main><h1>Contact</h1></main></body></html>';
        return array('headers'=>array(),'body'=>$body,'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array(),'filename'=>null);
    }, 10, 3);
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { m6b_fail('Canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { m6b_fail('Canonical seed failed', $seed); }
rest_get_server();

// Planner read access is independently guarded by seo.read.
$limited = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','operations.apply','operations.rollback','research.read','research.write'),
    1,
    'CI M6b limited'
);
if (is_wp_error($limited) || empty($limited['token'])) { m6b_fail('Limited credential issuance failed'); }
$denied = m6b_request('GET', 'seo-geo/resource/actions', (string) $limited['token'], array(), array('type'=>'page','key'=>'contact','language'=>'en'));
if (403 !== $denied->get_status() || 'scope_denied' !== (string) ($denied->get_data()['code'] ?? '')) {
    m6b_fail('seo.read was not enforced for action planning', $denied->get_data());
}

$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','seo.read','research.read','research.write','operations.apply','operations.rollback'),
    1,
    'CI M6b structured action planner'
);
if (is_wp_error($issued) || empty($issued['token'])) { m6b_fail('M6b credential issuance failed'); }
$token = (string) $issued['token'];

$cap = m6b_request('GET', 'capabilities', $token);
$cap_data = (array) ($cap->get_data()['data'] ?? array());
$control = (array) ($cap_data['seo_geo_control'] ?? array());
$read_endpoints = (array) ($cap_data['read_endpoints'] ?? array());
if (200 !== $cap->get_status()
    || 'M6' !== (string) ($control['milestone'] ?? '')
    || empty($control['structured_action_planner'])
    || 'site.diagnostics + seo.read' !== (string) ($read_endpoints['seo_geo_resource_actions'] ?? '')
    || ! in_array('research-objects', (array) ($control['planner_delegates_to'] ?? array()), true)
    || ! in_array('pages', (array) ($control['planner_delegates_to'] ?? array()), true)
    || ! empty($control['arbitrary_meta_editor'])) {
    m6b_fail('M6b capability discovery incomplete', $cap->get_data());
}

// PAGE: deliberately degrade the structured description source through M3,
// let M6b route the finding back to M3, execute the recommendation, and reanalyse.
$contact = Eduardo_Research_Manager::page_editor()->inspect('contact', 'en');
if (is_wp_error($contact)) { m6b_fail('Contact Page inspection failed', $contact->get_error_message()); }
$original_lead = (string) ($contact['effective_slots']['lead'] ?? '');
if ('' === $original_lead) { m6b_fail('Canonical contact lead unexpectedly empty'); }

$degrade_plan = m6b_page_plan($token, 'page-slots-update', array(
    'key'=>'contact','language'=>'en','slots'=>array('lead'=>''),'reason'=>'CI M6b deliberate Page SEO/GEO gap',
));
$degrade_operation = m6b_page_apply($token, (string) ($degrade_plan['plan_id'] ?? ''));
$degrade_operation_id = (string) ($degrade_operation['operation_id'] ?? '');

$contact_en = function_exists('eduardo_research_page_url') ? (string) eduardo_research_page_url('contact','en') : (string) ($contact['url'] ?? '');
$contact_es = function_exists('eduardo_research_page_url') ? (string) eduardo_research_page_url('contact','es') : $contact_en;
m6b_fake_page_http($contact_en, 'en', $contact_en, $contact_es);
$page_actions_response = m6b_request('GET', 'seo-geo/resource/actions', $token, array(), array('type'=>'page','key'=>'contact','language'=>'en'));
remove_all_filters('pre_http_request');
if (200 !== $page_actions_response->get_status()) { m6b_fail('Page action planning failed', $page_actions_response->get_data()); }
$page_actions = (array) ($page_actions_response->get_data()['data'] ?? array());
$page_description = m6b_find_check($page_actions, 'description-source');
$page_action = (array) ($page_description['action'] ?? array());
if ('fail' !== (string) ($page_description['status'] ?? '')
    || 'pages' !== (string) ($page_action['gateway'] ?? '')
    || 'page-slots-update' !== (string) ($page_action['operation'] ?? '')
    || 'lead' !== (string) ($page_action['target']['slot'] ?? '')
    || ! in_array('value', (array) ($page_action['required_input'] ?? array()), true)
    || ! empty($page_action['auto_apply'])) {
    m6b_fail('Page description finding did not route to the bounded Page gateway', $page_description);
}
if (! empty($page_actions['direct_meta_mutation']) || 'delegate-to-existing-manager-gateways' !== (string) ($page_actions['mutation_model'] ?? '')) {
    m6b_fail('Planner exposed a direct metadata mutation model', $page_actions);
}

$fixed_lead = 'Research contact, academic profiles, collaboration enquiries and verified scholarly identity.';
$fix_page_plan = m6b_page_plan($token, (string) $page_action['operation'], array(
    'key'=>(string) $page_action['target']['key'],
    'language'=>(string) $page_action['target']['language'],
    'slots'=>array((string) $page_action['target']['slot']=>$fixed_lead),
    'reason'=>'CI M6b execute planner-recommended Page correction',
));
$fix_page_operation = m6b_page_apply($token, (string) ($fix_page_plan['plan_id'] ?? ''));
$fix_page_operation_id = (string) ($fix_page_operation['operation_id'] ?? '');

m6b_fake_page_http($contact_en, 'en', $contact_en, $contact_es);
$page_reanalysis_response = m6b_request('GET', 'seo-geo/resource/actions', $token, array(), array('type'=>'page','key'=>'contact','language'=>'en'));
remove_all_filters('pre_http_request');
$page_reanalysis = (array) ($page_reanalysis_response->get_data()['data'] ?? array());
$page_description_after = m6b_find_check($page_reanalysis, 'description-source');
if (200 !== $page_reanalysis_response->get_status() || 'pass' !== (string) ($page_description_after['status'] ?? '')) {
    m6b_fail('Page finding did not clear after executing recommended gateway', $page_reanalysis_response->get_data());
}

// Roll back correction, then deliberate degradation, to restore the canonical seed exactly.
m6b_page_rollback($token, $fix_page_operation_id);
m6b_page_rollback($token, $degrade_operation_id);
$restored_contact = Eduardo_Research_Manager::page_editor()->inspect('contact', 'en');
if (is_wp_error($restored_contact) || $original_lead !== (string) ($restored_contact['effective_slots']['lead'] ?? '')) {
    m6b_fail('Page rollback sequence did not restore canonical lead', $restored_contact);
}

// RESEARCH OBJECT: create a draft Output with an empty excerpt. M6b must route
// the description finding to the evidence-aware M5 Research Object gateway.
$output_data = array(
    'title'=>'M6b Structured Planner Output',
    'slug'=>'m6b-structured-planner-output-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 10),
    'excerpt'=>'',
    'content'=>'<p>Research output used to prove SEO/GEO source routing.</p>',
    'language'=>'en',
    'status'=>'draft',
    'output_type'=>'technical_report',
    'authors'=>array(),
    'line_ids'=>array(),
);
$create_output_plan = m6b_object_plan($token, 'object-create', array(
    'kind'=>'output','data'=>$output_data,'evidence_confirmed'=>true,'evidence_reference'=>'ci:m6b:create-output',
));
$create_output_operation = m6b_object_apply($token, (string) ($create_output_plan['plan_id'] ?? ''));
$create_output_operation_id = (string) ($create_output_operation['operation_id'] ?? '');
$output_id = (int) ($create_output_operation['target']['post_id'] ?? 0);
if ($output_id <= 0) { m6b_fail('Created Output target ID missing', $create_output_operation); }

$output_actions_response = m6b_request('GET', 'seo-geo/resource/actions', $token, array(), array('type'=>'output','id'=>$output_id));
if (200 !== $output_actions_response->get_status()) { m6b_fail('Output action planning failed', $output_actions_response->get_data()); }
$output_actions = (array) ($output_actions_response->get_data()['data'] ?? array());
$output_description = m6b_find_check($output_actions, 'description-source');
$output_action = (array) ($output_description['action'] ?? array());
if ('fail' !== (string) ($output_description['status'] ?? '')
    || 'research-objects' !== (string) ($output_action['gateway'] ?? '')
    || 'object-update' !== (string) ($output_action['operation'] ?? '')
    || 'output' !== (string) ($output_action['target']['kind'] ?? '')
    || $output_id !== (int) ($output_action['target']['post_id'] ?? 0)
    || 'excerpt' !== (string) ($output_action['target']['field'] ?? '')
    || empty($output_action['requires_evidence'])
    || ! in_array('evidence_reference', (array) ($output_action['required_input'] ?? array()), true)) {
    m6b_fail('Output description finding did not route to evidence-aware Research Object gateway', $output_description);
}

$fixed_excerpt = 'Evidence-aware report on structured SEO/GEO planning through bounded Research Manager gateways.';
$fix_output_plan = m6b_object_plan($token, (string) $output_action['operation'], array(
    'kind'=>(string) $output_action['target']['kind'],
    'post_id'=>(int) $output_action['target']['post_id'],
    'changes'=>array((string) $output_action['target']['field']=>$fixed_excerpt),
    'evidence_confirmed'=>true,
    'evidence_reference'=>'ci:m6b:fix-output-description',
));
$fix_output_operation = m6b_object_apply($token, (string) ($fix_output_plan['plan_id'] ?? ''));
$fix_output_operation_id = (string) ($fix_output_operation['operation_id'] ?? '');

$output_reanalysis_response = m6b_request('GET', 'seo-geo/resource/actions', $token, array(), array('type'=>'output','id'=>$output_id));
$output_reanalysis = (array) ($output_reanalysis_response->get_data()['data'] ?? array());
$output_description_after = m6b_find_check($output_reanalysis, 'description-source');
if (200 !== $output_reanalysis_response->get_status() || 'pass' !== (string) ($output_description_after['status'] ?? '')) {
    m6b_fail('Output finding did not clear after executing recommended gateway', $output_reanalysis_response->get_data());
}

// Preserve the evidence/provenance boundary: a draft Output with no Line relation
// may be advisory, but M6b still must route the suggestion through object-update.
$graph_check = m6b_find_check($output_reanalysis, 'research-entity-graph');
$graph_action = (array) ($graph_check['action'] ?? array());
if ('advisory' !== (string) ($graph_check['status'] ?? '')
    || 'research-objects' !== (string) ($graph_action['gateway'] ?? '')
    || 'object-update' !== (string) ($graph_action['operation'] ?? '')
    || 'line_ids' !== (string) ($graph_action['target']['field'] ?? '')) {
    m6b_fail('Research entity graph advisory is not routed through M5', $graph_check);
}

m6b_object_rollback($token, $fix_output_operation_id);
m6b_object_rollback($token, $create_output_operation_id);
if (get_post($output_id) instanceof WP_Post) { m6b_fail('Output creation rollback left a residual post', $output_id); }

fwrite(STDOUT, "M6 structured SEO/GEO action planner OK\n");