<?php
/** Aggregate fresh-WordPress acceptance for the complete Remote Manager M4 Insight workflow. */

wp_set_current_user(1);

function m4a_fail(string $message, mixed $context = null): never {
    fwrite(STDERR, "M4 AGGREGATE FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m4a_request(string $method, string $route, string $token, array $body = array()): WP_REST_Response {
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

function m4a_plan_apply(string $token, string $operation, array $payload): array {
    $planned = m4a_request('POST', 'operations/plan', $token, array('operation'=>$operation,'payload'=>$payload));
    $plan = $planned->get_data()['data'] ?? array();
    $plan_id = (string) ($plan['plan_id'] ?? '');
    if (200 !== $planned->get_status() || '' === $plan_id || empty($plan['apply_allowed'])) {
        m4a_fail('plan failed: ' . $operation, $planned->get_data());
    }
    $applied = m4a_request('POST', 'plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
    $result = $applied->get_data()['data'] ?? array();
    if (200 !== $applied->get_status() || empty($result['operation_id']) || empty($result['result']['verified'])) {
        m4a_fail('Apply failed: ' . $operation, $applied->get_data());
    }
    return $result;
}

function m4a_verify(string $token, string $operation_id, bool $rendered): array {
    $response = m4a_request('POST', 'operations/' . rawurlencode($operation_id) . '/verify', $token, array('rendered'=>$rendered));
    $result = $response->get_data()['data'] ?? array();
    if (200 !== $response->get_status() || empty($result['stored_verification']['verified']) || ($rendered && empty($result['rendered_verification']['verified']))) {
        m4a_fail('Verify failed', $response->get_data());
    }
    return $result;
}

function m4a_rollback(string $token, string $operation_id): void {
    $response = m4a_request('POST', 'operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
    if (200 !== $response->get_status() || 'rolled-back' !== (string) ($response->get_data()['data']['status'] ?? '')) {
        m4a_fail('Rollback failed', $response->get_data());
    }
}

function m4a_pair_apply(string $token, int $first_id, int $second_id): array {
    $planned = m4a_request('POST', 'insights/pairing/plan', $token, array(
        'operation'=>'insight-pair',
        'payload'=>array('first_id'=>$first_id,'second_id'=>$second_id),
    ));
    $plan = $planned->get_data()['data'] ?? array();
    $plan_id = (string) ($plan['plan_id'] ?? '');
    if (200 !== $planned->get_status() || '' === $plan_id || empty($plan['apply_allowed'])) {
        m4a_fail('pairing plan failed', $planned->get_data());
    }
    $applied = m4a_request('POST', 'insights/pairing/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
    $result = $applied->get_data()['data'] ?? array();
    if (200 !== $applied->get_status() || empty($result['operation_id']) || empty($result['result']['verified'])) {
        m4a_fail('pairing Apply failed', $applied->get_data());
    }
    return $result;
}

function m4a_pair_verify(string $token, string $operation_id): void {
    $response = m4a_request('POST', 'insights/pairing/operations/' . rawurlencode($operation_id) . '/verify', $token, array('rendered'=>true));
    $data = $response->get_data()['data'] ?? array();
    if (200 !== $response->get_status() || empty($data['stored_verification']['verified']) || empty($data['rendered_verification']['verified'])) {
        m4a_fail('pairing Verify failed', $response->get_data());
    }
}

function m4a_pair_rollback(string $token, string $operation_id): void {
    $response = m4a_request('POST', 'insights/pairing/operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
    if (200 !== $response->get_status() || 'rolled-back' !== (string) ($response->get_data()['data']['status'] ?? '')) {
        m4a_fail('pairing Rollback failed', $response->get_data());
    }
}

function m4a_fake_render(array $ids): void {
    add_filter('pre_http_request', static function ($preempt, $args, $url) use ($ids) {
        foreach ($ids as $post_id) {
            $post = get_post((int) $post_id);
            if (! $post instanceof WP_Post) { continue; }
            $permalink = (string) get_permalink((int) $post_id);
            if (untrailingslashit((string) $url) !== untrailingslashit($permalink)) { continue; }
            $language = function_exists('eduardo_research_post_language') ? eduardo_research_post_language((int) $post_id) : 'en';
            $safe = esc_url($permalink);
            $title = esc_html((string) $post->post_title);
            $body = '<!doctype html><html lang="' . esc_attr($language) . '"><head>'
                . '<link rel="canonical" href="' . $safe . '">'
                . '<link rel="alternate" hreflang="' . esc_attr($language) . '" href="' . $safe . '">'
                . '<meta property="og:url" content="' . $safe . '">'
                . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article"}</script>'
                . '</head><body><article><h1>' . $title . '</h1></article></body></html>';
            return array('headers'=>array(),'body'=>$body,'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array(),'filename'=>null);
        }
        return $preempt;
    }, 10, 3);
}

function m4a_create_line(): int {
    $id = wp_insert_post(array(
        'post_type'=>'research_line','post_status'=>'publish','post_title'=>'M4 Aggregate Line EN',
        'post_name'=>'m4-aggregate-line-en-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
    ), true);
    if (is_wp_error($id)) { m4a_fail('Research Line fixture failed', $id->get_error_message()); }
    update_post_meta((int) $id, '_research_language', 'en');
    update_post_meta((int) $id, '_research_evidence_status', 'verified');
    update_post_meta((int) $id, '_research_status', 'active');
    return (int) $id;
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { m4a_fail('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { m4a_fail('canonical seed failed', $seed); }

rest_get_server();
$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','operations.apply','operations.rollback'), 1, 'CI M4 Aggregate'
);
if (is_wp_error($issued) || empty($issued['token'])) { m4a_fail('credential issuance failed'); }
$token = (string) $issued['token'];

$cap_response = m4a_request('GET', 'capabilities', $token);
$control = (array) ($cap_response->get_data()['data']['insight_control'] ?? array());
if (200 !== $cap_response->get_status()
    || 'M4' !== (string) ($control['milestone'] ?? '')
    || empty($control['inventory'])
    || empty($control['inspection'])
    || empty($control['scheduling']['available'])
    || empty($control['research_relations']['available'])
    || empty($control['translation_pairing']['available'])
    || empty($control['status_transition']['available'])
    || empty($control['rendered_seo_geo_inspection'])) {
    m4a_fail('M4 capability discovery incomplete', $cap_response->get_data());
}

$line_en = m4a_create_line();

// Create EN draft remotely.
$en_create = m4a_plan_apply($token, 'insight-create', array('data'=>array(
    'title'=>'M4 Aggregate English Insight',
    'slug'=>'m4-aggregate-en-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
    'excerpt'=>'Initial aggregate draft.',
    'content'=>'<p>Initial aggregate content.</p>',
    'language'=>'en','insight_type'=>'research_note','status'=>'draft',
)));
$en_create_op = (string) $en_create['operation_id'];
$en_id = (int) ($en_create['target']['post_id'] ?? $en_create['result']['post_id'] ?? 0);
if ($en_id <= 0) { m4a_fail('EN create did not expose post ID', $en_create); }
$draft_verify = m4a_verify($token, $en_create_op, true);
$draft_render = (array) ($draft_verify['rendered_verification']['resources'][0] ?? array());
if (empty($draft_render['skipped'])) { m4a_fail('draft was not treated as non-public', $draft_verify); }

// Edit + structured same-language relation.
$en_update = m4a_plan_apply($token, 'insight-update', array(
    'post_id'=>$en_id,
    'changes'=>array(
        'title'=>'M4 Aggregate English Insight — Updated',
        'excerpt'=>'Updated aggregate excerpt.',
        'content'=>'<p>Updated aggregate content with structured research context.</p>',
        'line_ids'=>array($line_en),
    ),
    'reason'=>'Aggregate editorial update and relation',
));
$en_update_op = (string) $en_update['operation_id'];
m4a_verify($token, $en_update_op, false);
$seo_relation = m4a_request('GET', 'insights/' . $en_id . '/seo-geo', $token)->get_data()['data'] ?? array();
if (1 !== (int) ($seo_relation['relations']['count'] ?? 0) || empty($seo_relation['relations']['verified_same_language'])) {
    m4a_fail('relation diagnostics incomplete', $seo_relation);
}
ob_start(); eduardo_research_render_research_context($en_id); $relation_html = (string) ob_get_clean();
if (! str_contains($relation_html, (string) get_permalink($line_en))) { m4a_fail('Theme relation link missing', $relation_html); }

// Schedule, verify non-public state, rollback to draft.
$scheduled_at = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
$en_schedule = m4a_plan_apply($token, 'insight-update', array(
    'post_id'=>$en_id,'changes'=>array('scheduled_at'=>$scheduled_at),'reason'=>'Aggregate schedule rehearsal',
));
$en_schedule_op = (string) $en_schedule['operation_id'];
$schedule_verify = m4a_verify($token, $en_schedule_op, true);
$schedule_render = (array) ($schedule_verify['rendered_verification']['resources'][0] ?? array());
if (empty($schedule_render['skipped'])) { m4a_fail('scheduled Insight was not treated as non-public', $schedule_verify); }
$scheduled = Eduardo_Research_Manager::insight_editor()->inspect($en_id);
if (is_wp_error($scheduled) || 'future' !== (string) ($scheduled['status'] ?? '') || $scheduled_at !== (string) ($scheduled['scheduled_at'] ?? '')) {
    m4a_fail('scheduled state mismatch', $scheduled);
}
m4a_rollback($token, $en_schedule_op);
$draft_again = Eduardo_Research_Manager::insight_editor()->inspect($en_id);
if (is_wp_error($draft_again) || 'draft' !== (string) ($draft_again['status'] ?? '') || '' !== (string) ($draft_again['scheduled_at'] ?? '')) {
    m4a_fail('schedule rollback did not restore draft', $draft_again);
}

// Publish EN and verify rendered Article.
$en_publish = m4a_plan_apply($token, 'insight-update', array(
    'post_id'=>$en_id,'changes'=>array('status'=>'publish'),'reason'=>'Aggregate publish EN',
));
$en_publish_op = (string) $en_publish['operation_id'];
m4a_fake_render(array($en_id));
m4a_verify($token, $en_publish_op, true);
remove_all_filters('pre_http_request');

// Create published ES counterpart remotely.
$es_create = m4a_plan_apply($token, 'insight-create', array('data'=>array(
    'title'=>'M4 Insight Agregado en Español',
    'slug'=>'m4-aggregate-es-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
    'excerpt'=>'Contraparte española del flujo agregado.',
    'content'=>'<p>Contenido español para validar el control editorial bilingüe.</p>',
    'language'=>'es','insight_type'=>'research_note','status'=>'publish',
)));
$es_create_op = (string) $es_create['operation_id'];
$es_id = (int) ($es_create['target']['post_id'] ?? $es_create['result']['post_id'] ?? 0);
if ($es_id <= 0) { m4a_fail('ES create did not expose post ID', $es_create); }
m4a_fake_render(array($es_id));
m4a_verify($token, $es_create_op, true);
remove_all_filters('pre_http_request');

// Pair EN/ES and verify both stored + rendered state.
$pair = m4a_pair_apply($token, $en_id, $es_id);
$pair_op = (string) $pair['operation_id'];
m4a_fake_render(array($en_id,$es_id));
m4a_pair_verify($token, $pair_op);
remove_all_filters('pre_http_request');
$paired = Eduardo_Research_Manager::translations()->verify_pair($en_id, $es_id);
if (is_wp_error($paired) || empty($paired['verified'])) { m4a_fail('translation pair did not verify', $paired); }

// Inventory + inspection + SEO/GEO expose the final managed state.
$inventory_response = m4a_request('GET', 'insights', $token);
$inventory = $inventory_response->get_data()['data'] ?? array();
$rows = (array) ($inventory['items'] ?? array());
$found_en = false; $found_es = false;
foreach ($rows as $row) {
    if ((int) ($row['post_id'] ?? 0) === $en_id && 'en' === (string) ($row['language'] ?? '')) { $found_en = true; }
    if ((int) ($row['post_id'] ?? 0) === $es_id && 'es' === (string) ($row['language'] ?? '')) { $found_es = true; }
}
if (200 !== $inventory_response->get_status() || ! $found_en || ! $found_es) {
    m4a_fail('inventory does not expose bilingual managed Insights', $inventory);
}
$en_inspect = m4a_request('GET', 'insights/' . $en_id, $token)->get_data()['data'] ?? array();
if ('publish' !== (string) ($en_inspect['status'] ?? '') || array($line_en) !== (array) ($en_inspect['line_ids'] ?? array())) {
    m4a_fail('final EN inspection state mismatch', $en_inspect);
}
$en_seo = m4a_request('GET', 'insights/' . $en_id . '/seo-geo', $token)->get_data()['data'] ?? array();
if (empty($en_seo['stored_ready']) || (int) ($en_seo['translation']['counterpart_id'] ?? 0) !== $es_id || 1 !== (int) ($en_seo['relations']['count'] ?? 0)) {
    m4a_fail('final EN SEO/GEO state incomplete', $en_seo);
}

// Reverse the complete chain to prove end-to-end reversibility.
m4a_pair_rollback($token, $pair_op);
m4a_rollback($token, $es_create_op);
m4a_rollback($token, $en_publish_op);
m4a_rollback($token, $en_update_op);
m4a_rollback($token, $en_create_op);
if (get_post($en_id) instanceof WP_Post || get_post($es_id) instanceof WP_Post) {
    m4a_fail('aggregate rollback did not restore absence baseline', array('en'=>get_post($en_id),'es'=>get_post($es_id)));
}
wp_delete_post($line_en, true);

fwrite(STDOUT, "M4 aggregate Insight operating workflow OK\n");
