<?php
/** Fresh-WordPress acceptance for bounded M4 draft/publish transitions. */

wp_set_current_user(1);

function fail_m4_status(string $message, mixed $context = null): never {
    fwrite(STDERR, "M4 STATUS FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m4_status_request(string $method, string $route, string $token, array $body = array()): WP_REST_Response {
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

function fake_m4_status_render(int $post_id): void {
    add_filter('pre_http_request', static function ($preempt, $args, $url) use ($post_id) {
        $post = get_post($post_id);
        $lang = function_exists('eduardo_research_post_language') ? eduardo_research_post_language($post_id) : 'en';
        $safe = esc_url((string) $url);
        $title = $post instanceof WP_Post ? esc_html($post->post_title) : 'Insight';
        $body = '<!doctype html><html lang="' . esc_attr($lang) . '"><head>'
            . '<link rel="canonical" href="' . $safe . '">'
            . '<link rel="alternate" hreflang="' . esc_attr($lang) . '" href="' . $safe . '">'
            . '<meta property="og:url" content="' . $safe . '">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article"}</script>'
            . '</head><body><article><h1>' . $title . '</h1></article></body></html>';
        return array('headers'=>array(),'body'=>$body,'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array(),'filename'=>null);
    }, 10, 3);
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { fail_m4_status('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { fail_m4_status('canonical seed failed', $seed); }

rest_get_server();
$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','operations.apply','operations.rollback'),
    1,
    'CI M4 Insight Status'
);
if (is_wp_error($issued) || empty($issued['token'])) { fail_m4_status('credential issuance failed'); }
$token = (string) $issued['token'];

$cap = m4_status_request('GET', 'capabilities', $token)->get_data();
$status_cap = (array) ($cap['data']['insight_control']['status_transition'] ?? array());
if (empty($status_cap['available'])
    || ! in_array('draft', (array) ($status_cap['allowed'] ?? array()), true)
    || ! in_array('publish', (array) ($status_cap['allowed'] ?? array()), true)
    || empty($status_cap['separate_preview_required'])
    || empty($status_cap['generic_post_status_contract_unchanged'])) {
    fail_m4_status('capabilities do not expose bounded status transition', $cap);
}

$create = Eduardo_Research_Manager::insight_editor()->preview_create(array(
    'title'=>'M4 Status Draft Insight',
    'slug'=>'m4-status-draft-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 10),
    'excerpt'=>'Draft status acceptance.',
    'content'=>'<p>Draft content for bounded status transition.</p>',
    'language'=>'en',
    'insight_type'=>'research_note',
    'status'=>'draft',
));
if (is_wp_error($create) || empty($create['apply_allowed'])) { fail_m4_status('draft fixture preview failed', $create); }
$created = Eduardo_Research_Manager::insight_editor()->apply_preview($create);
if (is_wp_error($created) || empty($created['verified']) || empty($created['post_id'])) { fail_m4_status('draft fixture apply failed', $created); }
$post_id = (int) $created['post_id'];
$creation_snapshot = (string) ($created['snapshot_id'] ?? '');

// Status must be its own operation.
$mixed = m4_status_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array('post_id'=>$post_id,'changes'=>array('status'=>'publish','title'=>'Mixed status edit')),
));
if (400 !== $mixed->get_status() || 'research_manager_insight_status_requires_separate_operation' !== (string) ($mixed->get_data()['code'] ?? '')) {
    fail_m4_status('mixed status/content update was not blocked', $mixed->get_data());
}

$invalid = m4_status_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array('post_id'=>$post_id,'changes'=>array('status'=>'private')),
));
if (400 !== $invalid->get_status() || 'research_manager_invalid_insight_status' !== (string) ($invalid->get_data()['code'] ?? '')) {
    fail_m4_status('unsupported Insight status was not blocked', $invalid->get_data());
}

// Draft -> publish exact lifecycle.
$publish_plan_response = m4_status_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array('post_id'=>$post_id,'changes'=>array('status'=>'publish'),'reason'=>'CI M4 publish transition'),
));
if (200 !== $publish_plan_response->get_status()) { fail_m4_status('publish plan failed', $publish_plan_response->get_data()); }
$publish_plan = $publish_plan_response->get_data()['data'] ?? array();
$publish_plan_id = (string) ($publish_plan['plan_id'] ?? '');
if ('' === $publish_plan_id || empty($publish_plan['apply_allowed']) || 'editorial-review' !== (string) ($publish_plan['risk'] ?? '')) {
    fail_m4_status('publish plan contract invalid', $publish_plan);
}
$still_draft = Eduardo_Research_Manager::insight_editor()->inspect($post_id);
if (is_wp_error($still_draft) || 'draft' !== (string) ($still_draft['status'] ?? '')) { fail_m4_status('publish Preview mutated status', $still_draft); }

$publish_apply = m4_status_request('POST', 'plans/' . rawurlencode($publish_plan_id) . '/apply', $token, array('confirm'=>true));
if (200 !== $publish_apply->get_status()) { fail_m4_status('publish Apply failed', $publish_apply->get_data()); }
$publish_operation = $publish_apply->get_data()['data'] ?? array();
$publish_operation_id = (string) ($publish_operation['operation_id'] ?? '');
if ('' === $publish_operation_id || empty($publish_operation['snapshot_id']) || empty($publish_operation['result']['verified'])) {
    fail_m4_status('publish operation contract invalid', $publish_operation);
}
$published = Eduardo_Research_Manager::insight_editor()->inspect($post_id);
if (is_wp_error($published) || 'publish' !== (string) ($published['status'] ?? '')) { fail_m4_status('Insight did not publish', $published); }

fake_m4_status_render($post_id);
$publish_verify = m4_status_request('POST', 'operations/' . rawurlencode($publish_operation_id) . '/verify', $token, array('rendered'=>true));
remove_all_filters('pre_http_request');
if (200 !== $publish_verify->get_status()
    || empty($publish_verify->get_data()['data']['stored_verification']['verified'])
    || empty($publish_verify->get_data()['data']['rendered_verification']['verified'])) {
    fail_m4_status('published Insight verification failed', $publish_verify->get_data());
}

$publish_rollback = m4_status_request('POST', 'operations/' . rawurlencode($publish_operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $publish_rollback->get_status()) { fail_m4_status('publish rollback failed', $publish_rollback->get_data()); }
$draft_again = Eduardo_Research_Manager::insight_editor()->inspect($post_id);
if (is_wp_error($draft_again) || 'draft' !== (string) ($draft_again['status'] ?? '')) { fail_m4_status('publish rollback did not restore draft', $draft_again); }

// Republish, then prove stale target protection on a later unpublish Preview.
$republish_plan = m4_status_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array('post_id'=>$post_id,'changes'=>array('status'=>'publish')),
));
$republish_plan_id = (string) ($republish_plan->get_data()['data']['plan_id'] ?? '');
if (200 !== $republish_plan->get_status() || '' === $republish_plan_id) { fail_m4_status('republish plan failed', $republish_plan->get_data()); }
$republish_apply = m4_status_request('POST', 'plans/' . rawurlencode($republish_plan_id) . '/apply', $token, array('confirm'=>true));
$republish_operation_id = (string) ($republish_apply->get_data()['data']['operation_id'] ?? '');
if (200 !== $republish_apply->get_status() || '' === $republish_operation_id) { fail_m4_status('republish Apply failed', $republish_apply->get_data()); }

$stale_plan = m4_status_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array('post_id'=>$post_id,'changes'=>array('status'=>'draft')),
));
$stale_plan_id = (string) ($stale_plan->get_data()['data']['plan_id'] ?? '');
if (200 !== $stale_plan->get_status() || '' === $stale_plan_id) { fail_m4_status('stale status fixture plan failed', $stale_plan->get_data()); }
wp_update_post(array('ID'=>$post_id,'post_excerpt'=>'External edit after status Preview'));
$stale_apply = m4_status_request('POST', 'plans/' . rawurlencode($stale_plan_id) . '/apply', $token, array('confirm'=>true));
if (409 !== $stale_apply->get_status() || 'research_manager_insight_editor_stale_preview' !== (string) ($stale_apply->get_data()['code'] ?? '')) {
    fail_m4_status('stale status Preview was not rejected', $stale_apply->get_data());
}
wp_update_post(array('ID'=>$post_id,'post_excerpt'=>'Draft status acceptance.'));

// Published -> draft; rendered verification is safely skipped because the resource is no longer public.
$draft_plan_response = m4_status_request('POST', 'operations/plan', $token, array(
    'operation'=>'insight-update',
    'payload'=>array('post_id'=>$post_id,'changes'=>array('status'=>'draft'),'reason'=>'CI M4 unpublish transition'),
));
$draft_plan_id = (string) ($draft_plan_response->get_data()['data']['plan_id'] ?? '');
if (200 !== $draft_plan_response->get_status() || '' === $draft_plan_id) { fail_m4_status('unpublish plan failed', $draft_plan_response->get_data()); }
$draft_apply = m4_status_request('POST', 'plans/' . rawurlencode($draft_plan_id) . '/apply', $token, array('confirm'=>true));
if (200 !== $draft_apply->get_status()) { fail_m4_status('unpublish Apply failed', $draft_apply->get_data()); }
$draft_operation = $draft_apply->get_data()['data'] ?? array();
$draft_operation_id = (string) ($draft_operation['operation_id'] ?? '');
if ('' === $draft_operation_id || empty($draft_operation['snapshot_id'])) { fail_m4_status('unpublish operation invalid', $draft_operation); }

$draft_verify = m4_status_request('POST', 'operations/' . rawurlencode($draft_operation_id) . '/verify', $token, array('rendered'=>true));
$render_resources = (array) ($draft_verify->get_data()['data']['rendered_verification']['resources'] ?? array());
$render_first = is_array($render_resources[0] ?? null) ? $render_resources[0] : array();
if (200 !== $draft_verify->get_status()
    || empty($draft_verify->get_data()['data']['stored_verification']['verified'])
    || empty($draft_verify->get_data()['data']['rendered_verification']['verified'])
    || empty($render_first['skipped'])
    || 'draft-or-non-public' !== (string) ($render_first['reason'] ?? '')) {
    fail_m4_status('draft rendered verification was not safely skipped', $draft_verify->get_data());
}

$draft_rollback = m4_status_request('POST', 'operations/' . rawurlencode($draft_operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $draft_rollback->get_status()) { fail_m4_status('unpublish rollback failed', $draft_rollback->get_data()); }
$published_again = Eduardo_Research_Manager::insight_editor()->inspect($post_id);
if (is_wp_error($published_again) || 'publish' !== (string) ($published_again['status'] ?? '')) { fail_m4_status('unpublish rollback did not restore published status', $published_again); }

// Cleanup in reverse order. Roll back republish to draft, then creation to absence.
$republish_rollback = m4_status_request('POST', 'operations/' . rawurlencode($republish_operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $republish_rollback->get_status()) { fail_m4_status('republish rollback failed', $republish_rollback->get_data()); }
if ('' === $creation_snapshot) { fail_m4_status('creation fixture missing snapshot'); }
$cleanup = Eduardo_Research_Manager::insight_editor()->rollback($creation_snapshot);
if (is_wp_error($cleanup)) { fail_m4_status('creation cleanup rollback failed', $cleanup->get_error_message()); }
if (get_post($post_id) instanceof WP_Post) { fail_m4_status('creation cleanup did not remove Insight'); }

fwrite(STDOUT, "M4 Insight status transitions OK\n");
