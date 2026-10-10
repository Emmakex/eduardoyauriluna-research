<?php
/** Fresh-WordPress acceptance for M4 Insight Research Line relations and internal links. */

wp_set_current_user(1);

function fail_m4_relations(string $message, mixed $context = null): never {
    fwrite(STDERR, "M4 RELATIONS FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m4_relations_request(string $method, string $route, string $token, array $body = array()): WP_REST_Response {
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

function create_relation_line(string $language, string $evidence = 'verified'): int {
    $id = wp_insert_post(array(
        'post_type'=>'research_line',
        'post_status'=>'publish',
        'post_title'=>'M4 Relation Line ' . strtoupper($language) . ' ' . wp_generate_password(5, false),
        'post_name'=>'m4-relation-line-' . $language . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
        'post_excerpt'=>'Verified line for M4 relation acceptance.',
    ), true);
    if (is_wp_error($id)) { fail_m4_relations('line fixture creation failed', $id->get_error_message()); }
    update_post_meta((int) $id, '_research_language', $language);
    update_post_meta((int) $id, '_research_evidence_status', $evidence);
    update_post_meta((int) $id, '_research_status', 'active');
    return (int) $id;
}

function create_related_project(int $line_id, string $language): int {
    $id = wp_insert_post(array(
        'post_type'=>'research_project',
        'post_status'=>'publish',
        'post_title'=>'M4 Related Project',
        'post_name'=>'m4-related-project-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
        'post_excerpt'=>'Project sharing the Insight Research Line.',
        'post_content'=>'<p>Related project content.</p>',
    ), true);
    if (is_wp_error($id)) { fail_m4_relations('related project fixture creation failed', $id->get_error_message()); }
    update_post_meta((int) $id, '_research_language', $language);
    update_post_meta((int) $id, '_research_line_ids', array($line_id));
    return (int) $id;
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { fail_m4_relations('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { fail_m4_relations('canonical seed failed', $seed); }

rest_get_server();
$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','operations.apply','operations.rollback'),
    1,
    'CI M4 Insight Relations'
);
if (is_wp_error($issued) || empty($issued['token'])) { fail_m4_relations('credential issuance failed'); }
$token = (string) $issued['token'];

$cap_response = m4_relations_request('GET', 'capabilities', $token);
$cap = $cap_response->get_data();
$relation_cap = (array) ($cap['data']['insight_control']['research_relations'] ?? array());
if (200 !== $cap_response->get_status()
    || empty($relation_cap['available'])
    || 'line_ids' !== (string) ($relation_cap['field'] ?? '')
    || empty($relation_cap['theme_renders_line_links'])
    || empty($relation_cap['theme_derives_related_object_links'])
    || ! in_array('line_ids', (array) ($cap['data']['insight_control']['update_fields'] ?? array()), true)) {
    fail_m4_relations('capabilities do not expose structured Insight relations', $cap);
}

$line_en = create_relation_line('en', 'verified');
$line_es = create_relation_line('es', 'verified');
$line_unverified = create_relation_line('en', 'unverified');
$project_id = create_related_project($line_en, 'en');

$preview = Eduardo_Research_Manager::insight_editor()->preview_create(array(
    'title'=>'M4 Insight Relations',
    'slug'=>'m4-insight-relations-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
    'excerpt'=>'Insight relation acceptance.',
    'content'=>'<p>Insight relation acceptance body.</p>',
    'language'=>'en',
    'insight_type'=>'research_note',
    'status'=>'publish',
));
if (is_wp_error($preview) || empty($preview['apply_allowed'])) { fail_m4_relations('Insight fixture Preview failed', $preview); }
$created = Eduardo_Research_Manager::insight_editor()->apply_preview($preview);
if (is_wp_error($created) || empty($created['verified']) || empty($created['post_id']) || empty($created['snapshot_id'])) {
    fail_m4_relations('Insight fixture Apply failed', $created);
}
$insight_id = (int) $created['post_id'];
$insight_snapshot = (string) $created['snapshot_id'];

// Wrong-language and unverified lines must never enter the managed relation set.
foreach (array($line_es, $line_unverified) as $invalid_line_id) {
    $invalid = m4_relations_request('POST', 'operations/plan', $token, array(
        'operation'=>'insight-update',
        'payload'=>array('post_id'=>$insight_id,'changes'=>array('line_ids'=>array($invalid_line_id))),
    ));
    if (400 !== $invalid->get_status() || 'research_manager_unverified_insight_line_relation' !== (string) ($invalid->get_data()['code'] ?? '')) {
        fail_m4_relations('invalid Research Line relation was not blocked', $invalid->get_data());
    }
}

$plan_response = m4_relations_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array(
        'post_id'=>$insight_id,
        'changes'=>array('line_ids'=>array($line_en)),
        'reason'=>'CI M4 structured Insight Research Line relation',
    ),
));
if (200 !== $plan_response->get_status()) { fail_m4_relations('relation plan failed', $plan_response->get_data()); }
$plan = $plan_response->get_data()['data'] ?? array();
$plan_id = (string) ($plan['plan_id'] ?? '');
if ('' === $plan_id || empty($plan['apply_allowed']) || 'insight-update' !== (string) ($plan['operation'] ?? '')) {
    fail_m4_relations('relation plan contract invalid', $plan);
}
$before = Eduardo_Research_Manager::insight_editor()->inspect($insight_id);
if (is_wp_error($before) || ! empty($before['line_ids'])) { fail_m4_relations('relation Preview mutated Insight', $before); }

$apply = m4_relations_request('POST', 'plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
if (200 !== $apply->get_status()) { fail_m4_relations('relation Apply failed', $apply->get_data()); }
$operation = $apply->get_data()['data'] ?? array();
$operation_id = (string) ($operation['operation_id'] ?? '');
if ('' === $operation_id || empty($operation['snapshot_id']) || empty($operation['result']['verified'])) {
    fail_m4_relations('relation operation invalid', $operation);
}
$related = Eduardo_Research_Manager::insight_editor()->inspect($insight_id);
if (is_wp_error($related) || array($line_en) !== (array) ($related['line_ids'] ?? array())) {
    fail_m4_relations('stored Insight relation did not verify', $related);
}

// The Theme must turn the structured relation into deterministic internal links.
ob_start();
eduardo_research_render_research_context($insight_id);
$line_html = (string) ob_get_clean();
if (! str_contains($line_html, (string) get_permalink($line_en))
    || ! str_contains($line_html, 'data-research-line-id="' . $line_en . '"')) {
    fail_m4_relations('Theme did not render verified Research Line internal link', $line_html);
}

ob_start();
eduardo_research_render_related_objects($insight_id);
$object_html = (string) ob_get_clean();
if (! str_contains($object_html, (string) get_permalink($project_id)) || ! str_contains($object_html, 'M4 Related Project')) {
    fail_m4_relations('Theme did not derive related Research Object internal link', $object_html);
}

$seo_response = m4_relations_request('GET', 'insights/' . $insight_id . '/seo-geo', $token);
$seo = $seo_response->get_data()['data'] ?? array();
if (200 !== $seo_response->get_status()
    || 1 !== (int) ($seo['relations']['count'] ?? 0)
    || empty($seo['relations']['verified_same_language'])
    || empty($seo['relations']['theme_internal_links'])) {
    fail_m4_relations('SEO/GEO inspection did not expose relation readiness', $seo_response->get_data());
}

$verify = m4_relations_request('POST', 'operations/' . rawurlencode($operation_id) . '/verify', $token, array('rendered'=>false));
if (200 !== $verify->get_status() || empty($verify->get_data()['data']['stored_verification']['verified'])) {
    fail_m4_relations('remote relation Verify failed', $verify->get_data());
}

// Relation meta participates in target baseline and must invalidate a stale Preview.
$stale_response = m4_relations_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array('post_id'=>$insight_id,'changes'=>array('line_ids'=>array())),
));
$stale_plan_id = (string) ($stale_response->get_data()['data']['plan_id'] ?? '');
if (200 !== $stale_response->get_status() || '' === $stale_plan_id) { fail_m4_relations('stale relation fixture plan failed', $stale_response->get_data()); }
update_post_meta($insight_id, '_research_insight_line_relations', array());
$stale_apply = m4_relations_request('POST', 'plans/' . rawurlencode($stale_plan_id) . '/apply', $token, array('confirm'=>true));
if (409 !== $stale_apply->get_status() || 'research_manager_insight_editor_stale_preview' !== (string) ($stale_apply->get_data()['code'] ?? '')) {
    fail_m4_relations('stale relation Preview was not rejected', $stale_apply->get_data());
}
update_post_meta($insight_id, '_research_insight_line_relations', array($line_en));

$rollback = m4_relations_request('POST', 'operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $rollback->get_status()) { fail_m4_relations('relation rollback failed', $rollback->get_data()); }
$restored = Eduardo_Research_Manager::insight_editor()->inspect($insight_id);
if (is_wp_error($restored) || ! empty($restored['line_ids'])) { fail_m4_relations('relation rollback did not restore empty baseline', $restored); }

ob_start();
eduardo_research_render_research_context($insight_id);
$after_line_html = (string) ob_get_clean();
ob_start();
eduardo_research_render_related_objects($insight_id);
$after_object_html = (string) ob_get_clean();
if ('' !== trim($after_line_html) || '' !== trim($after_object_html)) {
    fail_m4_relations('rollback did not remove derived internal relation sections', array('lines'=>$after_line_html,'objects'=>$after_object_html));
}

$cleanup = Eduardo_Research_Manager::insight_editor()->rollback($insight_snapshot);
if (is_wp_error($cleanup)) { fail_m4_relations('Insight fixture cleanup failed', $cleanup->get_error_message()); }
wp_delete_post($project_id, true);
wp_delete_post($line_en, true);
wp_delete_post($line_es, true);
wp_delete_post($line_unverified, true);

fwrite(STDOUT, "M4 Insight relations and internal links OK\n");
