<?php
/** Fresh-WordPress acceptance for exact M4 EN/ES Research Insight pairing. */

wp_set_current_user(1);

function fail_m4_pairing(string $message, mixed $context = null): never {
    fwrite(STDERR, "M4 PAIRING FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m4_pairing_request(string $method, string $route, string $token, array $body = array(), string $request_id = ''): WP_REST_Response {
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

function create_m4_pairing_insight(string $language, string $title): array {
    $slug = 'm4-pair-' . $language . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 10);
    $preview = Eduardo_Research_Manager::insight_editor()->preview_create(array(
        'title'=>$title,
        'slug'=>$slug,
        'excerpt'=>'Pairing acceptance excerpt for ' . strtoupper($language) . '.',
        'content'=>'<p>Pairing acceptance content for ' . strtoupper($language) . '.</p>',
        'language'=>$language,
        'insight_type'=>'research_note',
        'status'=>'publish',
    ));
    if (is_wp_error($preview) || empty($preview['apply_allowed'])) { fail_m4_pairing('fixture Preview failed', $preview); }
    $result = Eduardo_Research_Manager::insight_editor()->apply_preview($preview);
    if (is_wp_error($result) || empty($result['verified']) || empty($result['post_id']) || empty($result['snapshot_id'])) {
        fail_m4_pairing('fixture Apply failed', $result);
    }
    return array('post_id'=>(int) $result['post_id'],'snapshot_id'=>(string) $result['snapshot_id']);
}

function fake_m4_pairing_render(array $ids): void {
    add_filter('pre_http_request', static function ($preempt, $args, $url) use ($ids) {
        foreach ($ids as $post_id) {
            $post = get_post((int) $post_id);
            if (! $post instanceof WP_Post) { continue; }
            $permalink = (string) get_permalink((int) $post_id);
            if (untrailingslashit((string) $url) !== untrailingslashit($permalink)) { continue; }
            $lang = function_exists('eduardo_research_post_language') ? eduardo_research_post_language((int) $post_id) : 'en';
            $safe = esc_url($permalink);
            $title = esc_html((string) $post->post_title);
            $body = '<!doctype html><html lang="' . esc_attr($lang) . '"><head>'
                . '<link rel="canonical" href="' . $safe . '">'
                . '<link rel="alternate" hreflang="' . esc_attr($lang) . '" href="' . $safe . '">'
                . '<meta property="og:url" content="' . $safe . '">'
                . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article"}</script>'
                . '</head><body><article><h1>' . $title . '</h1></article></body></html>';
            return array('headers'=>array(),'body'=>$body,'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array(),'filename'=>null);
        }
        return $preempt;
    }, 10, 3);
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { fail_m4_pairing('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { fail_m4_pairing('canonical seed failed', $seed); }

rest_get_server();
$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','operations.apply','operations.rollback'),
    1,
    'CI M4 Insight Pairing'
);
if (is_wp_error($issued) || empty($issued['token'])) { fail_m4_pairing('credential issuance failed'); }
$token = (string) $issued['token'];

$cap_response = m4_pairing_request('GET', 'capabilities', $token);
$cap = $cap_response->get_data();
$pairing_cap = (array) ($cap['data']['insight_control']['translation_pairing'] ?? array());
if (200 !== $cap_response->get_status()
    || empty($pairing_cap['available'])
    || empty($pairing_cap['managed_insights_only'])
    || empty($pairing_cap['collision_protection'])
    || ! in_array('insight-pair', (array) ($pairing_cap['operations'] ?? array()), true)
    || ! in_array('insight-unpair', (array) ($pairing_cap['operations'] ?? array()), true)) {
    fail_m4_pairing('capabilities do not expose bounded Insight pairing', $cap);
}

$en = create_m4_pairing_insight('en', 'M4 Pairing English Insight');
$es = create_m4_pairing_insight('es', 'M4 Pairing Spanish Insight');
$es2 = create_m4_pairing_insight('es', 'M4 Pairing Collision Insight');
$en_id = $en['post_id'];
$es_id = $es['post_id'];
$es2_id = $es2['post_id'];

$ordinary_id = wp_insert_post(array(
    'post_type'=>'post',
    'post_status'=>'publish',
    'post_title'=>'Ordinary WordPress Post',
    'post_name'=>'ordinary-m4-pair-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
), true);
if (is_wp_error($ordinary_id)) { fail_m4_pairing('ordinary post fixture failed', $ordinary_id->get_error_message()); }

$ordinary = m4_pairing_request('POST', 'insights/pairing/plan', $token, array(
    'operation'=>'insight-pair',
    'payload'=>array('first_id'=>(int) $ordinary_id,'second_id'=>$es_id),
));
if (400 !== $ordinary->get_status()) { fail_m4_pairing('ordinary WordPress post was accepted by pairing gateway', $ordinary->get_data()); }

$plan_body = array(
    'operation'=>'insight-pair',
    'payload'=>array('first_id'=>$en_id,'second_id'=>$es_id),
);
$fixed_request_id = 'req-m4-pair-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 20);
$plan_response = m4_pairing_request('POST', 'insights/pairing/plan', $token, $plan_body, $fixed_request_id);
if (200 !== $plan_response->get_status()) { fail_m4_pairing('pair plan failed', $plan_response->get_data()); }
$plan = $plan_response->get_data()['data'] ?? array();
$plan_id = (string) ($plan['plan_id'] ?? '');
if ('' === $plan_id || empty($plan['apply_allowed']) || 'insight-pair' !== (string) ($plan['operation'] ?? '')) {
    fail_m4_pairing('pair plan contract invalid', $plan);
}
$preview_state = Eduardo_Research_Manager::translations()->verify_pair($en_id, $es_id);
if (! is_wp_error($preview_state) && ! empty($preview_state['verified'])) { fail_m4_pairing('pair Preview mutated translation state', $preview_state); }

$replay = m4_pairing_request('POST', 'insights/pairing/plan', $token, $plan_body, $fixed_request_id);
if (200 !== $replay->get_status()
    || empty($replay->get_data()['idempotent_replay'])
    || $plan_id !== (string) ($replay->get_data()['data']['plan_id'] ?? '')) {
    fail_m4_pairing('pair plan idempotency failed', $replay->get_data());
}

$blocked_apply = m4_pairing_request('POST', 'insights/pairing/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>false));
if (409 !== $blocked_apply->get_status() || 'confirmation_required' !== (string) ($blocked_apply->get_data()['code'] ?? '')) {
    fail_m4_pairing('pair Apply did not require confirmation', $blocked_apply->get_data());
}

$apply = m4_pairing_request('POST', 'insights/pairing/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
if (200 !== $apply->get_status()) { fail_m4_pairing('pair Apply failed', $apply->get_data()); }
$operation = $apply->get_data()['data'] ?? array();
$operation_id = (string) ($operation['operation_id'] ?? '');
if ('' === $operation_id || empty($operation['snapshot_id']) || empty($operation['result']['verified'])) {
    fail_m4_pairing('pair operation contract invalid', $operation);
}
$paired = Eduardo_Research_Manager::translations()->verify_pair($en_id, $es_id);
if (is_wp_error($paired) || empty($paired['verified'])) { fail_m4_pairing('pair did not verify semantically', $paired); }

$collision = m4_pairing_request('POST', 'insights/pairing/plan', $token, array(
    'operation'=>'insight-pair',
    'payload'=>array('first_id'=>$en_id,'second_id'=>$es2_id),
));
if (400 !== $collision->get_status() || 'research_manager_translation_collision' !== (string) ($collision->get_data()['code'] ?? '')) {
    fail_m4_pairing('translation collision was not blocked', $collision->get_data());
}

fake_m4_pairing_render(array($en_id,$es_id));
$verify = m4_pairing_request('POST', 'insights/pairing/operations/' . rawurlencode($operation_id) . '/verify', $token, array('rendered'=>true));
remove_all_filters('pre_http_request');
if (200 !== $verify->get_status()
    || empty($verify->get_data()['data']['stored_verification']['verified'])
    || empty($verify->get_data()['data']['rendered_verification']['verified'])) {
    fail_m4_pairing('paired Insights verification failed', $verify->get_data());
}

// Prove stale protection on unpair Preview by simulating an external relationship edit.
$stale_unpair = m4_pairing_request('POST', 'insights/pairing/plan', $token, array(
    'operation'=>'insight-unpair',
    'payload'=>array('post_id'=>$en_id),
));
$stale_plan_id = (string) ($stale_unpair->get_data()['data']['plan_id'] ?? '');
if (200 !== $stale_unpair->get_status() || '' === $stale_plan_id) { fail_m4_pairing('stale unpair fixture plan failed', $stale_unpair->get_data()); }
update_post_meta($en_id, '_research_translation_es', '0');
$stale_apply = m4_pairing_request('POST', 'insights/pairing/plans/' . rawurlencode($stale_plan_id) . '/apply', $token, array('confirm'=>true));
if (409 !== $stale_apply->get_status() || 'research_manager_translation_editor_stale_preview' !== (string) ($stale_apply->get_data()['code'] ?? '')) {
    fail_m4_pairing('stale unpair Preview was not rejected', $stale_apply->get_data());
}
update_post_meta($en_id, '_research_translation_es', (string) $es_id);

$unpair_plan_response = m4_pairing_request('POST', 'insights/pairing/plan', $token, array(
    'operation'=>'insight-unpair',
    'payload'=>array('post_id'=>$en_id),
));
$unpair_plan = $unpair_plan_response->get_data()['data'] ?? array();
$unpair_plan_id = (string) ($unpair_plan['plan_id'] ?? '');
if (200 !== $unpair_plan_response->get_status() || '' === $unpair_plan_id || empty($unpair_plan['apply_allowed'])) {
    fail_m4_pairing('unpair plan failed', $unpair_plan_response->get_data());
}
$unpair_apply = m4_pairing_request('POST', 'insights/pairing/plans/' . rawurlencode($unpair_plan_id) . '/apply', $token, array('confirm'=>true));
if (200 !== $unpair_apply->get_status()) { fail_m4_pairing('unpair Apply failed', $unpair_apply->get_data()); }
$unpair_operation = $unpair_apply->get_data()['data'] ?? array();
$unpair_operation_id = (string) ($unpair_operation['operation_id'] ?? '');
if ('' === $unpair_operation_id || empty($unpair_operation['snapshot_id'])) { fail_m4_pairing('unpair operation contract invalid', $unpair_operation); }

$unpaired_en = Eduardo_Research_Manager::translations()->verify_unpaired($en_id);
$unpaired_es = Eduardo_Research_Manager::translations()->verify_unpaired($es_id);
if (is_wp_error($unpaired_en) || is_wp_error($unpaired_es) || empty($unpaired_en['verified']) || empty($unpaired_es['verified'])) {
    fail_m4_pairing('unpair did not clear both directions', array($unpaired_en,$unpaired_es));
}

$unpair_verify = m4_pairing_request('POST', 'insights/pairing/operations/' . rawurlencode($unpair_operation_id) . '/verify', $token, array('rendered'=>false));
if (200 !== $unpair_verify->get_status() || empty($unpair_verify->get_data()['data']['stored_verification']['verified'])) {
    fail_m4_pairing('unpair verification failed', $unpair_verify->get_data());
}

$unpair_rollback = m4_pairing_request('POST', 'insights/pairing/operations/' . rawurlencode($unpair_operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $unpair_rollback->get_status()) { fail_m4_pairing('unpair rollback failed', $unpair_rollback->get_data()); }
$restored_pair = Eduardo_Research_Manager::translations()->verify_pair($en_id, $es_id);
if (is_wp_error($restored_pair) || empty($restored_pair['verified'])) { fail_m4_pairing('unpair rollback did not restore pair', $restored_pair); }

$pair_rollback = m4_pairing_request('POST', 'insights/pairing/operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $pair_rollback->get_status()) { fail_m4_pairing('pair rollback failed', $pair_rollback->get_data()); }
$final_en = Eduardo_Research_Manager::translations()->verify_unpaired($en_id);
$final_es = Eduardo_Research_Manager::translations()->verify_unpaired($es_id);
if (is_wp_error($final_en) || is_wp_error($final_es) || empty($final_en['verified']) || empty($final_es['verified'])) {
    fail_m4_pairing('pair rollback did not restore unpaired baseline', array($final_en,$final_es));
}

foreach (array($es2,$es,$en) as $fixture) {
    $cleanup = Eduardo_Research_Manager::insight_editor()->rollback((string) $fixture['snapshot_id']);
    if (is_wp_error($cleanup)) { fail_m4_pairing('fixture cleanup rollback failed', $cleanup->get_error_message()); }
}
wp_delete_post((int) $ordinary_id, true);

fwrite(STDOUT, "M4 Insight EN/ES pairing lifecycle OK\n");
