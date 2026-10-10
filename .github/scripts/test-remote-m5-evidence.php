<?php
/** Fresh-WordPress acceptance for remote M5 academic evidence/provenance. */
wp_set_current_user(1);

function m5e_fail(string $message, mixed $context = null): never {
    fwrite(STDERR, "M5 EVIDENCE FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}
function m5e_request(string $method, string $route, string $token, array $body = array(), string $rid = ''): WP_REST_Response {
    $r = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $r->set_header('Authorization', 'Bearer ' . $token);
    $r->set_header('X-Research-Manager-Request-Id', '' !== $rid ? $rid : 'req-' . wp_generate_uuid4());
    $r->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $r->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($body) {
        $r->set_header('Content-Type', 'application/json');
        $r->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($r);
}
function m5e_plan(string $token, string $operation, array $payload, string $rid = ''): array {
    $r = m5e_request('POST', 'research-evidence/plan', $token, array('operation'=>$operation,'payload'=>$payload), $rid);
    if (200 !== $r->get_status()) { m5e_fail('plan failed: ' . $operation, $r->get_data()); }
    return (array) ($r->get_data()['data'] ?? array());
}
function m5e_apply(string $token, string $plan_id, bool $confirm = true): array {
    $r = m5e_request('POST', 'research-evidence/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>$confirm));
    if (200 !== $r->get_status()) { m5e_fail('Apply failed', $r->get_data()); }
    $d = (array) ($r->get_data()['data'] ?? array());
    if (empty($d['operation_id']) || empty($d['result']['verified'])) { m5e_fail('Apply result invalid', $d); }
    return $d;
}
function m5e_verify(string $token, string $operation_id): array {
    $r = m5e_request('POST', 'research-evidence/operations/' . rawurlencode($operation_id) . '/verify', $token);
    if (200 !== $r->get_status()) { m5e_fail('Verify request failed', $r->get_data()); }
    $d = (array) ($r->get_data()['data'] ?? array());
    if (empty($d['stored_verification']['verified'])) { m5e_fail('stored Verify failed', $d); }
    return $d;
}
function m5e_rollback(string $token, string $operation_id): array {
    $r = m5e_request('POST', 'research-evidence/operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
    if (200 !== $r->get_status()) { m5e_fail('Rollback failed', $r->get_data()); }
    $d = (array) ($r->get_data()['data'] ?? array());
    if ('rolled-back' !== (string) ($d['status'] ?? '') || empty($d['rollback_restored_baseline'])) { m5e_fail('Rollback baseline invalid', $d); }
    return $d;
}
function m5e_same(mixed $left, mixed $right): bool { return maybe_serialize($left) === maybe_serialize($right); }

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { m5e_fail('blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { m5e_fail('seed failed', $seed); }
rest_get_server();

$baseline_identity = get_option('eduardo_research_identity', array());
$baseline_store = get_option('eduardo_research_evidence', array());
$baseline_identity = is_array($baseline_identity) ? $baseline_identity : array();
$baseline_store = is_array($baseline_store) ? $baseline_store : array();

// evidence.write must be independently granted.
$read_only = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','evidence.read','operations.apply','operations.rollback'),
    1,
    'CI M5 evidence read-only'
);
if (is_wp_error($read_only) || empty($read_only['token'])) { m5e_fail('read-only token failed'); }
$read_token = (string) $read_only['token'];
$denied = m5e_request('POST', 'research-evidence/plan', $read_token, array(
    'operation'=>'evidence-create',
    'payload'=>array(
        'group'=>'identifiers',
        'data'=>array('label'=>'ORCID','value'=>'0000-0000-0000-0000','status'=>'verified'),
        'evidence_confirmed'=>true,
        'evidence_reference'=>'ci:denied',
    ),
));
if (403 !== $denied->get_status() || 'scope_denied' !== (string) ($denied->get_data()['code'] ?? '')) {
    m5e_fail('evidence.write scope not enforced', $denied->get_data());
}

// evidence.write must not imply identity.write.
$evidence_writer = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','evidence.read','evidence.write','operations.apply','operations.rollback'),
    1,
    'CI M5 evidence writer without identity'
);
if (is_wp_error($evidence_writer) || empty($evidence_writer['token'])) { m5e_fail('evidence writer token failed'); }
$evidence_writer_token = (string) $evidence_writer['token'];
$identity_denied = m5e_request('POST', 'research-evidence/plan', $evidence_writer_token, array(
    'operation'=>'identity-update',
    'payload'=>array(
        'name'=>'CI Forbidden Identity',
        'evidence_confirmed'=>true,
        'evidence_reference'=>'ci:identity-denied',
    ),
));
if (403 !== $identity_denied->get_status() || 'scope_denied' !== (string) ($identity_denied->get_data()['code'] ?? '')) {
    m5e_fail('identity.write scope not enforced', $identity_denied->get_data());
}

$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','evidence.read','evidence.write','operations.apply','operations.rollback','identity.write'),
    1,
    'CI M5 evidence full'
);
if (is_wp_error($issued) || empty($issued['token'])) { m5e_fail('full evidence token failed'); }
$token = (string) $issued['token'];

$cap = m5e_request('GET', 'capabilities', $token);
$control = (array) ($cap->get_data()['data']['research_evidence_control'] ?? array());
$supported_scopes = (array) ($cap->get_data()['data']['supported_scopes'] ?? array());
if (
    200 !== $cap->get_status()
    || 'M5' !== (string) ($control['milestone'] ?? '')
    || empty($control['available'])
    || empty($control['provenance_readback'])
    || ! empty($control['arbitrary_wordpress_proxy'])
    || 'identity.write' !== (string) ($control['identity_extra_scope'] ?? '')
    || ! in_array('evidence.read', $supported_scopes, true)
    || ! in_array('evidence.write', $supported_scopes, true)
    || ! isset($control['groups']['identifiers'])
) {
    m5e_fail('evidence capabilities incomplete', $cap->get_data());
}

$inventory = m5e_request('GET', 'research-evidence', $token);
if (200 !== $inventory->get_status() || ! isset($inventory->get_data()['data']['groups']['identifiers'])) {
    m5e_fail('evidence inventory failed', $inventory->get_data());
}
$identity_read = m5e_request('GET', 'research-evidence/identity', $token);
if (200 !== $identity_read->get_status()) { m5e_fail('identity read failed', $identity_read->get_data()); }

$reference = 'ci:m5-evidence:orcid';
$create_payload = array(
    'group'=>'identifiers',
    'data'=>array(
        'label'=>'ORCID',
        'value'=>'0000-0002-1825-0097',
        'status'=>'verified',
        'url'=>'https://orcid.org/0000-0002-1825-0097',
        'summary'=>'CI verified identifier provenance record.',
        'translations'=>array('es'=>array('label'=>'ORCID','summary'=>'Registro de procedencia verificado en CI.')),
    ),
    'evidence_confirmed'=>true,
    'evidence_reference'=>$reference,
);
$rid = 'req-m5e-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 20);
$create_plan = m5e_plan($token, 'evidence-create', $create_payload, $rid);
if (empty($create_plan['apply_allowed']) || 'evidence-explicit' !== (string) ($create_plan['confirmation_class'] ?? '')) {
    m5e_fail('create plan blocked', $create_plan);
}
$record_id = (string) ($create_plan['target']['record_id'] ?? '');
if ('' === $record_id) { m5e_fail('create plan missing record_id', $create_plan); }
$before_preview = Eduardo_Research_Manager::evidence_editor()->inspect_record('identifiers', $record_id);
if (! is_wp_error($before_preview)) { m5e_fail('Preview mutated evidence store', $before_preview); }
$replay = m5e_request('POST', 'research-evidence/plan', $token, array('operation'=>'evidence-create','payload'=>$create_payload), $rid);
if (200 !== $replay->get_status() || empty($replay->get_data()['idempotent_replay']) || (string) ($replay->get_data()['data']['plan_id'] ?? '') !== (string) $create_plan['plan_id']) {
    m5e_fail('idempotent evidence plan failed', $replay->get_data());
}
$no_confirm = m5e_request('POST', 'research-evidence/plans/' . rawurlencode((string) $create_plan['plan_id']) . '/apply', $token, array('confirm'=>false));
if (409 !== $no_confirm->get_status() || 'confirmation_required' !== (string) ($no_confirm->get_data()['code'] ?? '')) {
    m5e_fail('confirmation gate not enforced', $no_confirm->get_data());
}
$created = m5e_apply($token, (string) $create_plan['plan_id']);
$create_operation = (string) $created['operation_id'];
$created_verify = m5e_verify($token, $create_operation);
if (
    'identifiers' !== (string) ($created_verify['provenance']['group'] ?? '')
    || $reference !== (string) ($created_verify['provenance']['evidence_reference'] ?? '')
    || 'verified' !== (string) ($created_verify['provenance']['status'] ?? '')
    || ! in_array('about', (array) ($created_verify['provenance']['surfaces'] ?? array()), true)
    || ! in_array('contact', (array) ($created_verify['provenance']['surfaces'] ?? array()), true)
) {
    m5e_fail('provenance readback incomplete after create', $created_verify);
}
$inspect = m5e_request('GET', 'research-evidence/identifiers/' . rawurlencode($record_id), $token);
if (
    200 !== $inspect->get_status()
    || $reference !== (string) ($inspect->get_data()['data']['evidence_reference'] ?? '')
    || '' === (string) ($inspect->get_data()['data']['verified_at'] ?? '')
) {
    m5e_fail('evidence inspect missing provenance', $inspect->get_data());
}

// A target-specific evidence change after Preview must invalidate Apply.
$update_payload = array(
    'group'=>'identifiers',
    'record_id'=>$record_id,
    'data'=>array('summary'=>'Updated provenance record.','status'=>'verified'),
    'evidence_confirmed'=>true,
    'evidence_reference'=>'ci:m5-evidence:update',
);
$stale_plan = m5e_plan($token, 'evidence-update', $update_payload);
$store_after_create = get_option('eduardo_research_evidence', array());
$drifted_store = is_array($store_after_create) ? $store_after_create : array();
$drifted_store['__ci_drift'] = array('changed'=>true);
update_option('eduardo_research_evidence', $drifted_store, false);
$stale = m5e_request('POST', 'research-evidence/plans/' . rawurlencode((string) $stale_plan['plan_id']) . '/apply', $token, array('confirm'=>true));
if (409 !== $stale->get_status() || 'research_manager_evidence_stale_preview' !== (string) ($stale->get_data()['code'] ?? '')) {
    m5e_fail('stale evidence Preview was not rejected', $stale->get_data());
}
update_option('eduardo_research_evidence', $store_after_create, false);

$update_plan = m5e_plan($token, 'evidence-update', $update_payload);
$updated = m5e_apply($token, (string) $update_plan['plan_id']);
$update_operation = (string) $updated['operation_id'];
$updated_verify = m5e_verify($token, $update_operation);
if ('ci:m5-evidence:update' !== (string) ($updated_verify['provenance']['evidence_reference'] ?? '')) {
    m5e_fail('updated provenance reference missing', $updated_verify);
}

$delete_plan = m5e_plan($token, 'evidence-delete', array(
    'group'=>'identifiers',
    'record_id'=>$record_id,
    'evidence_confirmed'=>true,
    'evidence_reference'=>'ci:m5-evidence:delete',
));
$deleted = m5e_apply($token, (string) $delete_plan['plan_id']);
$delete_operation = (string) $deleted['operation_id'];
$delete_verify = m5e_verify($token, $delete_operation);
if ('absent' !== (string) ($delete_verify['provenance']['status'] ?? '')) { m5e_fail('delete provenance does not report absence', $delete_verify); }
m5e_rollback($token, $delete_operation);
$restored_record = Eduardo_Research_Manager::evidence_editor()->inspect_record('identifiers', $record_id);
if (is_wp_error($restored_record) || 'Updated provenance record.' !== (string) ($restored_record['summary'] ?? '')) {
    m5e_fail('delete rollback did not restore updated record', $restored_record);
}
m5e_rollback($token, $update_operation);
$created_state = Eduardo_Research_Manager::evidence_editor()->inspect_record('identifiers', $record_id);
if (is_wp_error($created_state) || 'CI verified identifier provenance record.' !== (string) ($created_state['summary'] ?? '')) {
    m5e_fail('update rollback did not restore created record', $created_state);
}
m5e_rollback($token, $create_operation);
if (! is_wp_error(Eduardo_Research_Manager::evidence_editor()->inspect_record('identifiers', $record_id))) {
    m5e_fail('create rollback did not remove created record');
}
if (! m5e_same(get_option('eduardo_research_evidence', array()), $baseline_store)) {
    m5e_fail('evidence store did not return to baseline');
}

// Identity has a separate high-impact scope and the same evidence-required lifecycle.
$identity_payload = array(
    'name'=>'Eduardo Jose Yauri Luna — CI Provenance',
    'evidence_confirmed'=>true,
    'evidence_reference'=>'ci:m5-identity:verified',
);
$identity_plan = m5e_plan($token, 'identity-update', $identity_payload);
if (empty($identity_plan['apply_allowed'])) { m5e_fail('identity plan blocked', $identity_plan); }
$identity_apply = m5e_apply($token, (string) $identity_plan['plan_id']);
$identity_operation = (string) $identity_apply['operation_id'];
$identity_verify = m5e_verify($token, $identity_operation);
if (
    'identity' !== (string) ($identity_verify['provenance']['resource'] ?? '')
    || 'ci:m5-identity:verified' !== (string) ($identity_verify['provenance']['evidence_reference'] ?? '')
    || '' === (string) ($identity_verify['provenance']['verified_at'] ?? '')
) {
    m5e_fail('identity provenance readback incomplete', $identity_verify);
}
m5e_rollback($token, $identity_operation);
if (! m5e_same(get_option('eduardo_research_identity', array()), $baseline_identity) || ! m5e_same(get_option('eduardo_research_evidence', array()), $baseline_store)) {
    m5e_fail('identity rollback did not restore full baseline');
}

fwrite(STDOUT, "M5 Research evidence/provenance lifecycle OK\n");
