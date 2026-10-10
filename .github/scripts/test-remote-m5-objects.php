<?php
/** Fresh-WordPress acceptance for M5 remote Research Object foundation. */

wp_set_current_user(1);

function m5o_fail(string $message, mixed $context = null): never {
    fwrite(STDERR, "M5 OBJECTS FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m5o_request(string $method, string $route, string $token, array $body = array(), string $request_id = ''): WP_REST_Response {
    $request = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', '' !== $request_id ? $request_id : 'req-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($body) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($request);
}

function m5o_fixture_data(string $kind): array {
    $base = array(
        'title'=>'M5 ' . ucfirst($kind) . ' Fixture',
        'slug'=>'m5-' . $kind . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 10),
        'excerpt'=>'Bounded M5 Research Object fixture.',
        'content'=>'<p>Research Object content controlled through the Manager.</p>',
        'language'=>'en',
        'status'=>'draft',
        'line_ids'=>array(),
    );
    if ('output' === $kind) { $base['authors'] = array(); }
    if ('project' === $kind) { $base['methods'] = array(); }
    if ('software' === $kind) { $base['programming_languages'] = array(); }
    if ('dataset' === $kind) { $base['formats'] = array(); }
    return $base;
}

function m5o_plan(string $token, string $operation, array $payload, string $request_id = ''): array {
    $response = m5o_request('POST', 'research-objects/plan', $token, array('operation'=>$operation,'payload'=>$payload), $request_id);
    if (200 !== $response->get_status()) { m5o_fail('plan request failed: ' . $operation, $response->get_data()); }
    return (array) ($response->get_data()['data'] ?? array());
}

function m5o_apply(string $token, string $plan_id): array {
    $response = m5o_request('POST', 'research-objects/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
    if (200 !== $response->get_status()) { m5o_fail('Apply failed', $response->get_data()); }
    $operation = (array) ($response->get_data()['data'] ?? array());
    if (empty($operation['operation_id']) || empty($operation['result']['verified'])) { m5o_fail('Apply result invalid', $operation); }
    return $operation;
}

function m5o_verify(string $token, string $operation_id, bool $rendered = true): array {
    $response = m5o_request('POST', 'research-objects/operations/' . rawurlencode($operation_id) . '/verify', $token, array('rendered'=>$rendered));
    if (200 !== $response->get_status()) { m5o_fail('Verify request failed', $response->get_data()); }
    $operation = (array) ($response->get_data()['data'] ?? array());
    if (empty($operation['stored_verification']['verified'])) { m5o_fail('stored Verify failed', $operation); }
    if ($rendered && empty($operation['rendered_verification']['verified'])) { m5o_fail('rendered Verify failed', $operation); }
    return $operation;
}

function m5o_rollback(string $token, string $operation_id): array {
    $response = m5o_request('POST', 'research-objects/operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
    if (200 !== $response->get_status()) { m5o_fail('Rollback failed', $response->get_data()); }
    $operation = (array) ($response->get_data()['data'] ?? array());
    if ('rolled-back' !== (string) ($operation['status'] ?? '')) { m5o_fail('Rollback status invalid', $operation); }
    return $operation;
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { m5o_fail('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { m5o_fail('canonical seed failed', $seed); }
rest_get_server();

// Prove Research-specific write scope is independently enforced.
$read_only = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','research.read','operations.apply','operations.rollback'),
    1,
    'CI M5 read-only Research scope'
);
if (is_wp_error($read_only) || empty($read_only['token'])) { m5o_fail('read-only token issuance failed'); }
$read_only_token = (string) $read_only['token'];
$denied = m5o_request('POST', 'research-objects/plan', $read_only_token, array(
    'operation'=>'object-create',
    'payload'=>array('kind'=>'output','data'=>m5o_fixture_data('output'),'evidence_confirmed'=>true,'evidence_reference'=>'ci:m5-readonly'),
));
if (403 !== $denied->get_status() || 'scope_denied' !== (string) ($denied->get_data()['code'] ?? '')) {
    m5o_fail('Research write scope was not independently enforced', $denied->get_data());
}

$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','research.read','research.write','operations.apply','operations.rollback'),
    1,
    'CI M5 Research Objects'
);
if (is_wp_error($issued) || empty($issued['token'])) { m5o_fail('credential issuance failed'); }
$token = (string) $issued['token'];

$cap_response = m5o_request('GET', 'capabilities', $token);
$control = (array) ($cap_response->get_data()['data']['research_object_control'] ?? array());
if (200 !== $cap_response->get_status()
    || 'M5' !== (string) ($control['milestone'] ?? '')
    || empty($control['foundation'])
    || empty($control['evidence_gate']['required_for_mutation'])
    || ! in_array('object-create', (array) ($control['operations'] ?? array()), true)
    || ! in_array('object-update', (array) ($control['operations'] ?? array()), true)) {
    m5o_fail('M5 capability discovery incomplete', $cap_response->get_data());
}
foreach (array('output','project','software','dataset') as $kind) {
    if (! isset($control['kinds'][$kind])) { m5o_fail('M5 capability missing kind ' . $kind, $control); }
}

$unsupported = m5o_request('POST', 'research-objects/plan', $token, array(
    'operation'=>'object-create','payload'=>array('kind'=>'post','data'=>array('title'=>'Nope')),
));
if (400 !== $unsupported->get_status()) { m5o_fail('unsupported Research Object kind was accepted', $unsupported->get_data()); }

// Evidence-required Preview must remain non-applicable without an explicit source/reference.
$blocked_plan = m5o_plan($token, 'object-create', array('kind'=>'output','data'=>m5o_fixture_data('output')));
if (! empty($blocked_plan['apply_allowed'])
    || 'evidence-required' !== (string) ($blocked_plan['risk'] ?? '')
    || 'evidence-explicit' !== (string) ($blocked_plan['confirmation_class'] ?? '')) {
    m5o_fail('evidence gate did not block unconfirmed Research Output creation', $blocked_plan);
}
$blocked_apply = m5o_request('POST', 'research-objects/plans/' . rawurlencode((string) $blocked_plan['plan_id']) . '/apply', $token, array('confirm'=>true));
if (409 !== $blocked_apply->get_status() || 'evidence_required' !== (string) ($blocked_apply->get_data()['code'] ?? '')) {
    m5o_fail('evidence-blocked plan unexpectedly applied', $blocked_apply->get_data());
}

$created = array();
$updated = array();
foreach (array('output','project','software','dataset') as $kind) {
    $data = m5o_fixture_data($kind);
    $request_id = 'req-m5-' . $kind . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 16);
    $payload = array(
        'kind'=>$kind,'data'=>$data,
        'evidence_confirmed'=>true,
        'evidence_reference'=>'ci:m5-foundation:' . $kind,
    );
    $plan = m5o_plan($token, 'object-create', $payload, $request_id);
    if (empty($plan['apply_allowed']) || 'evidence-required' !== (string) ($plan['risk'] ?? '')) {
        m5o_fail('evidence-confirmed creation plan invalid for ' . $kind, $plan);
    }

    $replay = m5o_request('POST', 'research-objects/plan', $token, array('operation'=>'object-create','payload'=>$payload), $request_id);
    if (200 !== $replay->get_status()
        || empty($replay->get_data()['idempotent_replay'])
        || (string) ($replay->get_data()['data']['plan_id'] ?? '') !== (string) ($plan['plan_id'] ?? '')) {
        m5o_fail('idempotent creation plan replay failed for ' . $kind, $replay->get_data());
    }

    $operation = m5o_apply($token, (string) $plan['plan_id']);
    $post_id = (int) ($operation['target']['post_id'] ?? 0);
    if ($post_id <= 0) { m5o_fail('creation operation missing target ID for ' . $kind, $operation); }
    $verified = m5o_verify($token, (string) $operation['operation_id'], true);
    $rendered = (array) ($verified['rendered_verification']['resources'][0] ?? array());
    if (empty($rendered['skipped']) || 'draft-or-non-public' !== (string) ($rendered['reason'] ?? '')) {
        m5o_fail('draft Research Object was not safely skipped by rendered verification: ' . $kind, $verified);
    }

    $inspect = m5o_request('GET', 'research-objects/' . $kind . '/' . $post_id, $token);
    if (200 !== $inspect->get_status()
        || $post_id !== (int) ($inspect->get_data()['data']['post_id'] ?? 0)
        || 'en' !== (string) ($inspect->get_data()['data']['language'] ?? '')) {
        m5o_fail('remote inspection failed for ' . $kind, $inspect->get_data());
    }
    $created[$kind] = array('operation_id'=>(string) $operation['operation_id'],'post_id'=>$post_id,'title'=>(string) $data['title']);

    $new_title = (string) $data['title'] . ' Updated';
    $update_plan = m5o_plan($token, 'object-update', array(
        'kind'=>$kind,'post_id'=>$post_id,'changes'=>array('title'=>$new_title),
        'evidence_confirmed'=>true,'evidence_reference'=>'ci:m5-foundation:update:' . $kind,
    ));
    if (empty($update_plan['apply_allowed'])) { m5o_fail('update plan blocked for ' . $kind, $update_plan); }
    $update_operation = m5o_apply($token, (string) $update_plan['plan_id']);
    m5o_verify($token, (string) $update_operation['operation_id'], false);
    $after = Eduardo_Research_Manager::object_editor()->inspect($kind, $post_id);
    if (is_wp_error($after) || $new_title !== (string) ($after['title'] ?? '')) {
        m5o_fail('updated title mismatch for ' . $kind, $after);
    }
    $updated[$kind] = array('operation_id'=>(string) $update_operation['operation_id'],'title'=>$new_title);
}

$inventory = m5o_request('GET', 'research-objects', $token);
$inventory_data = (array) ($inventory->get_data()['data'] ?? array());
if (200 !== $inventory->get_status() || (int) ($inventory_data['count'] ?? 0) < 4) {
    m5o_fail('aggregate Research Object inventory incomplete', $inventory->get_data());
}
foreach (array_keys($created) as $kind) {
    if (empty($inventory_data['groups'][$kind]['count'])) { m5o_fail('inventory missing kind ' . $kind, $inventory_data); }
}

// Target-level stale protection: external object mutation after Preview must invalidate Apply.
$dataset_id = (int) $created['dataset']['post_id'];
$stale_plan = m5o_plan($token, 'object-update', array(
    'kind'=>'dataset','post_id'=>$dataset_id,'changes'=>array('excerpt'=>'Planned stale excerpt'),
    'evidence_confirmed'=>true,'evidence_reference'=>'ci:m5-stale',
));
wp_update_post(array('ID'=>$dataset_id,'post_excerpt'=>'External edit after Preview'));
$stale_apply = m5o_request('POST', 'research-objects/plans/' . rawurlencode((string) $stale_plan['plan_id']) . '/apply', $token, array('confirm'=>true));
$stale_code = (string) ($stale_apply->get_data()['code'] ?? '');
if (409 !== $stale_apply->get_status() || ! in_array($stale_code, array('stale_revision','research_manager_object_editor_stale_preview'), true)) {
    m5o_fail('stale Research Object Preview was not rejected', $stale_apply->get_data());
}
wp_update_post(array('ID'=>$dataset_id,'post_excerpt'=>'Bounded M5 Research Object fixture.'));

// Reverse all updates, then all creations, and prove absence baseline.
foreach (array_reverse(array('output','project','software','dataset')) as $kind) {
    m5o_rollback($token, (string) $updated[$kind]['operation_id']);
    $record = Eduardo_Research_Manager::object_editor()->inspect($kind, (int) $created[$kind]['post_id']);
    if (is_wp_error($record) || (string) $created[$kind]['title'] !== (string) ($record['title'] ?? '')) {
        m5o_fail('update rollback did not restore title for ' . $kind, $record);
    }
}
foreach (array_reverse(array('output','project','software','dataset')) as $kind) {
    m5o_rollback($token, (string) $created[$kind]['operation_id']);
    if (get_post((int) $created[$kind]['post_id']) instanceof WP_Post) {
        m5o_fail('creation rollback did not remove ' . $kind, $created[$kind]);
    }
}

fwrite(STDOUT, "M5 Research Object foundation OK\n");
