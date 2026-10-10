<?php
/** Fresh-WordPress acceptance for M4 Insight Research Line relations and internal links. */
wp_set_current_user(1);

function m4r_fail(string $message, mixed $context = null): never {
    fwrite(STDERR, "M4 RELATIONS FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}
function m4r_request(string $method, string $route, string $token, array $body = array()): WP_REST_Response {
    $r = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $r->set_header('Authorization', 'Bearer ' . $token);
    $r->set_header('X-Research-Manager-Request-Id', 'req-' . wp_generate_uuid4());
    $r->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $r->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($body) { $r->set_header('Content-Type', 'application/json'); $r->set_body((string) wp_json_encode($body)); }
    return rest_do_request($r);
}
function m4r_line(string $language, string $evidence = 'verified'): int {
    $id = wp_insert_post(array(
        'post_type'=>'research_line','post_status'=>'publish','post_title'=>'M4 Relation Line ' . strtoupper($language),
        'post_name'=>'m4-line-' . $language . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
    ), true);
    if (is_wp_error($id)) { m4r_fail('line fixture failed', $id->get_error_message()); }
    update_post_meta((int) $id, '_research_language', $language);
    update_post_meta((int) $id, '_research_evidence_status', $evidence);
    update_post_meta((int) $id, '_research_status', 'active');
    return (int) $id;
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { m4r_fail('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { m4r_fail('canonical seed failed', $seed); }
rest_get_server();
$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(array('site.read','site.diagnostics','operations.apply','operations.rollback'), 1, 'CI M4 Relations');
if (is_wp_error($issued) || empty($issued['token'])) { m4r_fail('credential issuance failed'); }
$token = (string) $issued['token'];

$cap = m4r_request('GET', 'capabilities', $token)->get_data();
$rc = (array) ($cap['data']['insight_control']['research_relations'] ?? array());
if (empty($rc['available']) || 'line_ids' !== (string) ($rc['field'] ?? '') || empty($rc['theme_renders_line_links']) || empty($rc['theme_derives_related_object_links'])) {
    m4r_fail('capabilities missing structured Insight relations', $cap);
}

$line_en = m4r_line('en');
$line_es = m4r_line('es');
$line_bad = m4r_line('en', 'unverified');
$project_id = wp_insert_post(array(
    'post_type'=>'research_project','post_status'=>'publish','post_title'=>'M4 Related Project',
    'post_name'=>'m4-project-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
    'post_excerpt'=>'Related project','post_content'=>'<p>Related project content.</p>',
), true);
if (is_wp_error($project_id)) { m4r_fail('project fixture failed', $project_id->get_error_message()); }
update_post_meta((int) $project_id, '_research_language', 'en');
update_post_meta((int) $project_id, '_research_line_ids', array($line_en));

$create = Eduardo_Research_Manager::insight_editor()->preview_create(array(
    'title'=>'M4 Insight Relations','slug'=>'m4-insight-rel-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
    'excerpt'=>'Relations acceptance','content'=>'<p>Relations acceptance.</p>','language'=>'en','insight_type'=>'research_note','status'=>'publish',
));
if (is_wp_error($create) || empty($create['apply_allowed'])) { m4r_fail('Insight Preview failed', $create); }
$created = Eduardo_Research_Manager::insight_editor()->apply_preview($create);
if (is_wp_error($created) || empty($created['verified']) || empty($created['post_id']) || empty($created['snapshot_id'])) { m4r_fail('Insight Apply failed', $created); }
$insight_id = (int) $created['post_id'];
$insight_snapshot = (string) $created['snapshot_id'];

foreach (array($line_es,$line_bad) as $invalid_line) {
    $invalid = m4r_request('POST', 'operations/plan', $token, array('operation'=>'insight-update','payload'=>array('post_id'=>$insight_id,'changes'=>array('line_ids'=>array($invalid_line)))));
    if (400 !== $invalid->get_status() || 'research_manager_unverified_insight_line_relation' !== (string) ($invalid->get_data()['code'] ?? '')) {
        m4r_fail('wrong-language/unverified line was accepted', $invalid->get_data());
    }
}

$plan_response = m4r_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array('post_id'=>$insight_id,'changes'=>array('line_ids'=>array($line_en)),'reason'=>'CI structured Insight relation'),
));
$plan = $plan_response->get_data()['data'] ?? array();
$plan_id = (string) ($plan['plan_id'] ?? '');
if (200 !== $plan_response->get_status() || '' === $plan_id || empty($plan['apply_allowed'])) { m4r_fail('relation plan failed', $plan_response->get_data()); }
$before = Eduardo_Research_Manager::insight_editor()->inspect($insight_id);
if (is_wp_error($before) || ! empty($before['line_ids'])) { m4r_fail('Preview mutated relation state', $before); }

$apply = m4r_request('POST', 'plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
$op = $apply->get_data()['data'] ?? array();
$operation_id = (string) ($op['operation_id'] ?? '');
if (200 !== $apply->get_status() || '' === $operation_id || empty($op['snapshot_id']) || empty($op['result']['verified'])) { m4r_fail('relation Apply failed', $apply->get_data()); }
$stored = Eduardo_Research_Manager::insight_editor()->inspect($insight_id);
if (is_wp_error($stored) || array($line_en) !== (array) ($stored['line_ids'] ?? array())) { m4r_fail('stored relation mismatch', $stored); }

ob_start(); eduardo_research_render_research_context($insight_id); $line_html = (string) ob_get_clean();
$line_attr = 'data-research-line-id=' . chr(34) . $line_en . chr(34);
if (! str_contains($line_html, (string) get_permalink($line_en)) || ! str_contains($line_html, $line_attr)) { m4r_fail('Theme line internal link missing', $line_html); }
ob_start(); eduardo_research_render_related_objects($insight_id); $object_html = (string) ob_get_clean();
if (! str_contains($object_html, (string) get_permalink((int) $project_id)) || ! str_contains($object_html, 'M4 Related Project')) { m4r_fail('Theme derived object link missing', $object_html); }

$seo = m4r_request('GET', 'insights/' . $insight_id . '/seo-geo', $token)->get_data()['data'] ?? array();
if (1 !== (int) ($seo['relations']['count'] ?? 0) || empty($seo['relations']['verified_same_language']) || empty($seo['relations']['theme_internal_links'])) { m4r_fail('relation diagnostics missing', $seo); }
$verify = m4r_request('POST', 'operations/' . rawurlencode($operation_id) . '/verify', $token, array('rendered'=>false));
if (200 !== $verify->get_status() || empty($verify->get_data()['data']['stored_verification']['verified'])) { m4r_fail('remote relation Verify failed', $verify->get_data()); }

$stale_plan_response = m4r_request('POST', 'operations/plan', $token, array('operation'=>'insight-update','payload'=>array('post_id'=>$insight_id,'changes'=>array('line_ids'=>array()))));
$stale_plan_id = (string) ($stale_plan_response->get_data()['data']['plan_id'] ?? '');
if (200 !== $stale_plan_response->get_status() || '' === $stale_plan_id) { m4r_fail('stale fixture plan failed', $stale_plan_response->get_data()); }
update_post_meta($insight_id, '_research_insight_line_relations', array());
$stale_apply = m4r_request('POST', 'plans/' . rawurlencode($stale_plan_id) . '/apply', $token, array('confirm'=>true));
if (409 !== $stale_apply->get_status() || 'research_manager_insight_editor_stale_preview' !== (string) ($stale_apply->get_data()['code'] ?? '')) { m4r_fail('relation stale Preview was not blocked', $stale_apply->get_data()); }
update_post_meta($insight_id, '_research_insight_line_relations', array($line_en));

$rollback = m4r_request('POST', 'operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $rollback->get_status()) { m4r_fail('relation rollback failed', $rollback->get_data()); }
$restored = Eduardo_Research_Manager::insight_editor()->inspect($insight_id);
if (is_wp_error($restored) || ! empty($restored['line_ids'])) { m4r_fail('rollback did not restore relation baseline', $restored); }
ob_start(); eduardo_research_render_research_context($insight_id); $after_lines = (string) ob_get_clean();
ob_start(); eduardo_research_render_related_objects($insight_id); $after_objects = (string) ob_get_clean();
if ('' !== trim($after_lines) || '' !== trim($after_objects)) { m4r_fail('rollback left derived relation sections', array($after_lines,$after_objects)); }

$cleanup = Eduardo_Research_Manager::insight_editor()->rollback($insight_snapshot);
if (is_wp_error($cleanup)) { m4r_fail('Insight cleanup failed', $cleanup->get_error_message()); }
wp_delete_post((int) $project_id, true);
wp_delete_post($line_en, true); wp_delete_post($line_es, true); wp_delete_post($line_bad, true);
fwrite(STDOUT, "M4 Insight relations and internal links OK\n");
