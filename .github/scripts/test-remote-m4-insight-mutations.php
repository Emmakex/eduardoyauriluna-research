<?php
/** Fresh-WordPress acceptance for bounded M4 remote Insight create/update operations. */

wp_set_current_user(1);

function fail_m4_mutation(string $message, mixed $context = null): never {
    fwrite(STDERR, "M4 MUTATION FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m4_mutation_request(
    string $method,
    string $route,
    string $token,
    array $body = array(),
    ?string $request_id = null
): WP_REST_Response {
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

function fake_m4_rendered_insight(int $post_id): void {
    add_filter('pre_http_request', static function ($preempt, $args, $url) use ($post_id) {
        $post = get_post($post_id);
        $language = function_exists('eduardo_research_post_language') ? eduardo_research_post_language($post_id) : 'en';
        $safe = esc_url((string) $url);
        $title = $post instanceof WP_Post ? esc_html($post->post_title) : 'Insight';
        $body = '<!doctype html><html lang="' . esc_attr($language) . '"><head>'
            . '<link rel="canonical" href="' . $safe . '">'
            . '<link rel="alternate" hreflang="' . esc_attr($language) . '" href="' . $safe . '">'
            . '<meta property="og:url" content="' . $safe . '">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article"}</script>'
            . '</head><body><article><h1>' . $title . '</h1><p>Rendered Insight</p></article></body></html>';
        return array('headers'=>array(),'body'=>$body,'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array(),'filename'=>null);
    }, 10, 3);
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { fail_m4_mutation('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { fail_m4_mutation('canonical seed failed', $seed); }

rest_get_server();
$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','operations.apply','operations.rollback'),
    1,
    'CI M4 Insight Mutations'
);
if (is_wp_error($issued) || empty($issued['token'])) { fail_m4_mutation('credential issuance failed'); }
$token = (string) $issued['token'];

$cap = m4_mutation_request('GET', 'capabilities', $token)->get_data();
$supported = (array) ($cap['data']['mutation_transport']['supported_operations'] ?? array());
foreach (array('insight-create','insight-update') as $operation) {
    if (! in_array($operation, $supported, true)) { fail_m4_mutation('capabilities missing ' . $operation, $cap); }
}
if ('M4' !== (string) ($cap['data']['mutation_transport']['milestone'] ?? '')) {
    fail_m4_mutation('mutation transport did not advance to M4', $cap);
}

// Arbitrary WordPress posts must not become managed Insights implicitly.
$ordinary_id = wp_insert_post(array('post_type'=>'post','post_status'=>'draft','post_title'=>'Ordinary WordPress Post','post_content'=>'Not managed by Research Manager'), true);
if (is_wp_error($ordinary_id)) { fail_m4_mutation('ordinary post fixture failed', $ordinary_id->get_error_message()); }
$ordinary_plan = m4_mutation_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array('post_id'=>(int) $ordinary_id,'changes'=>array('title'=>'Should be blocked')),
));
if (400 !== $ordinary_plan->get_status() || 'research_manager_insight_unmanaged' !== (string) ($ordinary_plan->get_data()['code'] ?? '')) {
    fail_m4_mutation('arbitrary WordPress post was not blocked', $ordinary_plan->get_data());
}

// Create a published Research Insight through the exact remote lifecycle.
$slug = 'remote-m4-created-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 10);
$create_body = array(
    'operation'=>'insight-create',
    'payload'=>array(
        'data'=>array(
            'title'=>'Remote M4 Created Insight',
            'slug'=>$slug,
            'excerpt'=>'Remote M4 excerpt',
            'content'=>'<p>Remote M4 body created through the Manager bridge.</p>',
            'language'=>'en',
            'insight_type'=>'research_note',
            'status'=>'publish',
        ),
        'reason'=>'CI M4 create published Insight',
    ),
);
$create_request_id = 'req-m4-insight-create-plan-0001';
$create_plan_response = m4_mutation_request('POST', 'operations/plan', $token, $create_body, $create_request_id);
if (200 !== $create_plan_response->get_status()) { fail_m4_mutation('Insight create plan failed', $create_plan_response->get_data()); }
$create_plan = $create_plan_response->get_data()['data'] ?? array();
$create_plan_id = (string) ($create_plan['plan_id'] ?? '');
if ('' === $create_plan_id || 'insight-create' !== (string) ($create_plan['operation'] ?? '') || empty($create_plan['apply_allowed']) || 'editorial-review' !== (string) ($create_plan['risk'] ?? '')) {
    fail_m4_mutation('Insight create plan contract invalid', $create_plan);
}
if (get_page_by_path($slug, OBJECT, 'post') instanceof WP_Post) { fail_m4_mutation('Insight create Preview mutated WordPress'); }

$create_retry = m4_mutation_request('POST', 'operations/plan', $token, $create_body, $create_request_id);
if (200 !== $create_retry->get_status() || empty($create_retry->get_data()['idempotent_replay'])) {
    fail_m4_mutation('Insight create plan idempotency failed', $create_retry->get_data());
}

$blocked_apply = m4_mutation_request('POST', 'plans/' . rawurlencode($create_plan_id) . '/apply', $token, array('confirm'=>false));
if (409 !== $blocked_apply->get_status() || 'confirmation_required' !== (string) ($blocked_apply->get_data()['code'] ?? '')) {
    fail_m4_mutation('Insight create Apply without confirmation was not blocked', $blocked_apply->get_data());
}

$create_apply = m4_mutation_request('POST', 'plans/' . rawurlencode($create_plan_id) . '/apply', $token, array('confirm'=>true), 'req-m4-insight-create-apply-0001');
if (200 !== $create_apply->get_status()) { fail_m4_mutation('Insight create Apply failed', $create_apply->get_data()); }
$create_operation = $create_apply->get_data()['data'] ?? array();
$create_operation_id = (string) ($create_operation['operation_id'] ?? '');
$post_id = absint($create_operation['target']['post_id'] ?? $create_operation['result']['post_id'] ?? 0);
if ('' === $create_operation_id || $post_id <= 0 || empty($create_operation['snapshot_id']) || empty($create_operation['result']['verified'])) {
    fail_m4_mutation('Insight create operation contract invalid', $create_operation);
}
$created = Eduardo_Research_Manager::insight_editor()->inspect($post_id);
if (is_wp_error($created) || 'publish' !== (string) ($created['status'] ?? '') || $slug !== (string) ($created['slug'] ?? '')) {
    fail_m4_mutation('created Insight state invalid', $created);
}

fake_m4_rendered_insight($post_id);
$create_verify = m4_mutation_request('POST', 'operations/' . rawurlencode($create_operation_id) . '/verify', $token, array('rendered'=>true));
remove_all_filters('pre_http_request');
if (200 !== $create_verify->get_status()
    || empty($create_verify->get_data()['data']['stored_verification']['verified'])
    || empty($create_verify->get_data()['data']['rendered_verification']['verified'])) {
    fail_m4_mutation('created Insight verification failed', $create_verify->get_data());
}

// Update only approved editorial fields.
$original_title = (string) $created['title'];
$new_title = 'Remote M4 Updated Insight';
$new_excerpt = 'Updated excerpt through exact remote M4 operation.';
$update_plan_response = m4_mutation_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array(
        'post_id'=>$post_id,
        'changes'=>array('title'=>$new_title,'excerpt'=>$new_excerpt,'insight_type'=>'explainer'),
        'reason'=>'CI M4 bounded Insight update',
    ),
));
if (200 !== $update_plan_response->get_status()) { fail_m4_mutation('Insight update plan failed', $update_plan_response->get_data()); }
$update_plan = $update_plan_response->get_data()['data'] ?? array();
$update_plan_id = (string) ($update_plan['plan_id'] ?? '');
if ('' === $update_plan_id || 'insight-update' !== (string) ($update_plan['operation'] ?? '') || empty($update_plan['apply_allowed'])) {
    fail_m4_mutation('Insight update plan invalid', $update_plan);
}
$after_update_preview = Eduardo_Research_Manager::insight_editor()->inspect($post_id);
if (is_wp_error($after_update_preview) || $original_title !== (string) ($after_update_preview['title'] ?? '')) {
    fail_m4_mutation('Insight update Preview mutated stored state', $after_update_preview);
}

$update_apply = m4_mutation_request('POST', 'plans/' . rawurlencode($update_plan_id) . '/apply', $token, array('confirm'=>true));
if (200 !== $update_apply->get_status()) { fail_m4_mutation('Insight update Apply failed', $update_apply->get_data()); }
$update_operation = $update_apply->get_data()['data'] ?? array();
$update_operation_id = (string) ($update_operation['operation_id'] ?? '');
if ('' === $update_operation_id || empty($update_operation['snapshot_id']) || empty($update_operation['result']['verified'])) {
    fail_m4_mutation('Insight update operation invalid', $update_operation);
}
$updated = Eduardo_Research_Manager::insight_editor()->inspect($post_id);
if (is_wp_error($updated) || $new_title !== (string) ($updated['title'] ?? '') || $new_excerpt !== (string) ($updated['excerpt'] ?? '') || 'explainer' !== (string) ($updated['insight_type'] ?? '')) {
    fail_m4_mutation('Insight update did not persist expected state', $updated);
}

fake_m4_rendered_insight($post_id);
$update_verify = m4_mutation_request('POST', 'operations/' . rawurlencode($update_operation_id) . '/verify', $token, array('rendered'=>true));
remove_all_filters('pre_http_request');
if (200 !== $update_verify->get_status()
    || empty($update_verify->get_data()['data']['stored_verification']['verified'])
    || empty($update_verify->get_data()['data']['rendered_verification']['verified'])) {
    fail_m4_mutation('updated Insight verification failed', $update_verify->get_data());
}

$update_rollback = m4_mutation_request('POST', 'operations/' . rawurlencode($update_operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $update_rollback->get_status()) { fail_m4_mutation('Insight update rollback failed', $update_rollback->get_data()); }
$restored = Eduardo_Research_Manager::insight_editor()->inspect($post_id);
if (is_wp_error($restored) || $original_title !== (string) ($restored['title'] ?? '') || 'research_note' !== (string) ($restored['insight_type'] ?? '')) {
    fail_m4_mutation('Insight update rollback did not restore original state', $restored);
}

// Target-specific stale Preview must block Apply even when site-level revision is unchanged.
$stale_plan_response = m4_mutation_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array('post_id'=>$post_id,'changes'=>array('title'=>'Stale title must never apply')),
));
$stale_plan_id = (string) ($stale_plan_response->get_data()['data']['plan_id'] ?? '');
if (200 !== $stale_plan_response->get_status() || '' === $stale_plan_id) { fail_m4_mutation('stale fixture plan failed', $stale_plan_response->get_data()); }
wp_update_post(array('ID'=>$post_id,'post_title'=>'External edit after Preview'));
$stale_apply = m4_mutation_request('POST', 'plans/' . rawurlencode($stale_plan_id) . '/apply', $token, array('confirm'=>true));
if (409 !== $stale_apply->get_status() || 'research_manager_insight_editor_stale_preview' !== (string) ($stale_apply->get_data()['code'] ?? '')) {
    fail_m4_mutation('target-specific stale Insight Preview was not rejected', $stale_apply->get_data());
}
wp_update_post(array('ID'=>$post_id,'post_title'=>$original_title));

$create_rollback = m4_mutation_request('POST', 'operations/' . rawurlencode($create_operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $create_rollback->get_status()) { fail_m4_mutation('Insight create rollback failed', $create_rollback->get_data()); }
if (get_post($post_id) instanceof WP_Post) { fail_m4_mutation('Insight create rollback did not remove Manager-owned post'); }

fwrite(STDOUT, "M4 Insight mutations OK\n");
