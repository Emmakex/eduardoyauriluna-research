<?php
/** Fresh-WordPress acceptance for semantic least-privilege write scopes. */

wp_set_current_user(1);

function fail_semantic_scope(string $message, mixed $context = null): never {
    fwrite(STDERR, "SEMANTIC SCOPE FAIL: {$message}\n");
    if (null !== $context) {
        fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    exit(1);
}

function semantic_scope_request(string $method, string $route, string $token, array $body = array()): WP_REST_Response {
    $request = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', 'scope-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($body) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($request);
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { fail_semantic_scope('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { fail_semantic_scope('canonical seed failed', $seed); }

rest_get_server();

$limited = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','operations.apply','operations.rollback'),
    1,
    'CI semantic scope negative probes'
);
if (is_wp_error($limited) || empty($limited['token'])) { fail_semantic_scope('limited credential issuance failed', $limited); }
$limited_token = (string) $limited['token'];

$cap = semantic_scope_request('GET', 'capabilities', $limited_token);
$cap_data = $cap->get_data();
$transport = (array) ($cap_data['data']['mutation_transport'] ?? array());
$scope_contract = (array) ($transport['operation_scope_contract'] ?? array());
if (200 !== $cap->get_status()
    || empty($transport['semantic_scope_enforcement'])
    || array('operations.apply','pages.write') !== (array) ($scope_contract['page-slots-update'] ?? array())
    || array('operations.apply','insights.write') !== (array) ($scope_contract['insight-create'] ?? array())
    || array('operations.apply','insights.write','translations.write') !== (array) ($scope_contract['insight-pair'] ?? array())) {
    fail_semantic_scope('capabilities missing semantic scope contract', $cap_data);
}

$page_before = Eduardo_Research_Manager::page_editor()->inspect('contact', 'en');
if (is_wp_error($page_before)) { fail_semantic_scope('contact Page fixture unavailable', $page_before); }
$original_lead = (string) ($page_before['effective_slots']['lead'] ?? '');
$page_block = semantic_scope_request('POST', 'operations/plan', $limited_token, array(
    'operation'=>'page-slots-update',
    'payload'=>array(
        'key'=>'contact',
        'language'=>'en',
        'slots'=>array('lead'=>'This must never reach Preview.'),
        'reason'=>'CI semantic page denial',
    ),
));
if (403 !== $page_block->get_status() || 'scope_denied' !== (string) ($page_block->get_data()['code'] ?? '')) {
    fail_semantic_scope('operations.apply alone was not blocked for Page mutation', $page_block->get_data());
}
$page_after = Eduardo_Research_Manager::page_editor()->inspect('contact', 'en');
if (is_wp_error($page_after) || $original_lead !== (string) ($page_after['effective_slots']['lead'] ?? '')) {
    fail_semantic_scope('blocked Page request mutated stored state', $page_after);
}

$insight_slug = 'semantic-scope-blocked-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 10);
$insight_block = semantic_scope_request('POST', 'operations/plan', $limited_token, array(
    'operation'=>'insight-create',
    'payload'=>array(
        'data'=>array(
            'title'=>'Blocked semantic scope Insight',
            'slug'=>$insight_slug,
            'excerpt'=>'Must never reach Preview.',
            'content'=>'<p>Must never reach Preview.</p>',
            'language'=>'en',
            'insight_type'=>'research_note',
            'status'=>'draft',
        ),
        'reason'=>'CI semantic Insight denial',
    ),
));
if (403 !== $insight_block->get_status() || 'scope_denied' !== (string) ($insight_block->get_data()['code'] ?? '')) {
    fail_semantic_scope('operations.apply alone was not blocked for Insight mutation', $insight_block->get_data());
}
if (get_page_by_path($insight_slug, OBJECT, 'post') instanceof WP_Post) {
    fail_semantic_scope('blocked Insight request created a post');
}

$pair_block = semantic_scope_request('POST', 'insights/pairing/plan', $limited_token, array(
    'operation'=>'insight-pair',
    'payload'=>array('first_id'=>1,'second_id'=>2),
));
if (403 !== $pair_block->get_status() || 'scope_denied' !== (string) ($pair_block->get_data()['code'] ?? '')) {
    fail_semantic_scope('operations.apply alone was not blocked for Insight pairing', $pair_block->get_data());
}

$full_scopes = array(
    'site.read','site.diagnostics',
    'pages.write','insights.write','translations.write',
    'operations.apply','operations.rollback',
);
$full = Eduardo_Research_Manager::remote_credentials()->issue_token(
    $full_scopes,
    1,
    'CI semantic scope positive probes'
);
if (is_wp_error($full) || empty($full['token'])) { fail_semantic_scope('full credential issuance failed', $full); }
$full_token = (string) $full['token'];

$new_lead = 'Semantic scope authorised ' . wp_generate_uuid4();
$page_plan = semantic_scope_request('POST', 'operations/plan', $full_token, array(
    'operation'=>'page-slots-update',
    'payload'=>array(
        'key'=>'contact',
        'language'=>'en',
        'slots'=>array('lead'=>$new_lead),
        'reason'=>'CI semantic Page positive probe',
    ),
));
$page_plan_id = (string) ($page_plan->get_data()['data']['plan_id'] ?? '');
if (200 !== $page_plan->get_status() || '' === $page_plan_id) {
    fail_semantic_scope('Page mutation with pages.write did not reach Preview', $page_plan->get_data());
}
$page_after_positive_preview = Eduardo_Research_Manager::page_editor()->inspect('contact', 'en');
if (is_wp_error($page_after_positive_preview) || $original_lead !== (string) ($page_after_positive_preview['effective_slots']['lead'] ?? '')) {
    fail_semantic_scope('authorised Page Preview mutated stored state', $page_after_positive_preview);
}

// Scope revocation after Preview must stop Apply without consuming or changing the plan.
$without_pages = array_values(array_filter($full_scopes, static fn(string $scope): bool => 'pages.write' !== $scope));
$scopes_changed = Eduardo_Research_Manager::remote_credentials()->set_scopes($without_pages);
if (is_wp_error($scopes_changed)) { fail_semantic_scope('could not revoke pages.write for Apply probe', $scopes_changed); }
$blocked_page_apply = semantic_scope_request('POST', 'plans/' . rawurlencode($page_plan_id) . '/apply', $full_token, array('confirm'=>true));
if (403 !== $blocked_page_apply->get_status() || 'scope_denied' !== (string) ($blocked_page_apply->get_data()['code'] ?? '')) {
    fail_semantic_scope('revoked pages.write did not block an already-planned Apply', $blocked_page_apply->get_data());
}
$after_blocked_apply = Eduardo_Research_Manager::page_editor()->inspect('contact', 'en');
if (is_wp_error($after_blocked_apply) || $original_lead !== (string) ($after_blocked_apply['effective_slots']['lead'] ?? '')) {
    fail_semantic_scope('blocked Apply mutated Page state', $after_blocked_apply);
}

$scopes_restored = Eduardo_Research_Manager::remote_credentials()->set_scopes($full_scopes);
if (is_wp_error($scopes_restored)) { fail_semantic_scope('could not restore pages.write for Apply probe', $scopes_restored); }
$page_apply = semantic_scope_request('POST', 'plans/' . rawurlencode($page_plan_id) . '/apply', $full_token, array('confirm'=>true));
$page_operation_id = (string) ($page_apply->get_data()['data']['operation_id'] ?? '');
if (200 !== $page_apply->get_status() || '' === $page_operation_id) {
    fail_semantic_scope('restored pages.write did not permit the original plan Apply', $page_apply->get_data());
}
$after_page_apply = Eduardo_Research_Manager::page_editor()->inspect('contact', 'en');
if (is_wp_error($after_page_apply) || $new_lead !== (string) ($after_page_apply['effective_slots']['lead'] ?? '')) {
    fail_semantic_scope('authorised Page Apply did not persist expected state', $after_page_apply);
}

// Scope revocation after Apply must likewise stop Rollback and leave the operation reversible.
$scopes_changed = Eduardo_Research_Manager::remote_credentials()->set_scopes($without_pages);
if (is_wp_error($scopes_changed)) { fail_semantic_scope('could not revoke pages.write for Rollback probe', $scopes_changed); }
$blocked_page_rollback = semantic_scope_request('POST', 'operations/' . rawurlencode($page_operation_id) . '/rollback', $full_token, array('confirm'=>true));
if (403 !== $blocked_page_rollback->get_status() || 'scope_denied' !== (string) ($blocked_page_rollback->get_data()['code'] ?? '')) {
    fail_semantic_scope('revoked pages.write did not block Rollback', $blocked_page_rollback->get_data());
}
$after_blocked_rollback = Eduardo_Research_Manager::page_editor()->inspect('contact', 'en');
if (is_wp_error($after_blocked_rollback) || $new_lead !== (string) ($after_blocked_rollback['effective_slots']['lead'] ?? '')) {
    fail_semantic_scope('blocked Rollback changed Page state', $after_blocked_rollback);
}

$scopes_restored = Eduardo_Research_Manager::remote_credentials()->set_scopes($full_scopes);
if (is_wp_error($scopes_restored)) { fail_semantic_scope('could not restore pages.write for Rollback probe', $scopes_restored); }
$page_rollback = semantic_scope_request('POST', 'operations/' . rawurlencode($page_operation_id) . '/rollback', $full_token, array('confirm'=>true));
if (200 !== $page_rollback->get_status() || 'rolled-back' !== (string) ($page_rollback->get_data()['data']['status'] ?? '')) {
    fail_semantic_scope('restored pages.write did not permit Rollback', $page_rollback->get_data());
}
$page_restored = Eduardo_Research_Manager::page_editor()->inspect('contact', 'en');
if (is_wp_error($page_restored) || $original_lead !== (string) ($page_restored['effective_slots']['lead'] ?? '')) {
    fail_semantic_scope('Page Rollback did not restore the original state', $page_restored);
}

$allowed_slug = 'semantic-scope-allowed-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 10);
$insight_plan = semantic_scope_request('POST', 'operations/plan', $full_token, array(
    'operation'=>'insight-create',
    'payload'=>array(
        'data'=>array(
            'title'=>'Authorised semantic scope Insight',
            'slug'=>$allowed_slug,
            'excerpt'=>'Positive semantic scope Preview.',
            'content'=>'<p>Positive semantic scope Preview.</p>',
            'language'=>'en',
            'insight_type'=>'research_note',
            'status'=>'draft',
        ),
        'reason'=>'CI semantic Insight positive probe',
    ),
));
if (200 !== $insight_plan->get_status() || empty($insight_plan->get_data()['data']['plan_id'])) {
    fail_semantic_scope('Insight mutation with insights.write did not reach Preview', $insight_plan->get_data());
}
if (get_page_by_path($allowed_slug, OBJECT, 'post') instanceof WP_Post) {
    fail_semantic_scope('authorised Insight Preview mutated stored state');
}

fwrite(STDOUT, "Remote semantic scope guard OK\n");
