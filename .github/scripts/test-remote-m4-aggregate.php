<?php
/** Aggregate fresh-WordPress acceptance for the complete Remote Manager M4 Insight operating workflow. */

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
        m4a_fail('general plan failed: ' . $operation, $planned->get_data());
    }
    $applied = m4a_request('POST', 'plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
    $result = $applied->get_data()['data'] ?? array();
    if (200 !== $applied->get_status() || empty($result['operation_id']) || empty($result['result']['verified'])) {
        m4a_fail('general Apply failed: ' . $operation, $applied->get_data());
    }
    return $result;
}

function m4a_verify(string $token, string $operation_id, bool $rendered): array {
    $response = m4a_request('POST', 'operations/' . rawurlencode($operation_id) . '/verify', $token, array('rendered'=>$rendered));
    $result = $response->get_data()['data'] ?? array();
    if (200 !== $response->get_status() || empty($result['stored_verification']['verified']) || ($rendered && empty($result['rendered_verification']['verified']))) {
        m4a_fail('general Verify failed', $response->get_data());
    }
    return $result;
}

function m4a_rollback(string $token, string $operation_id): array {
    $response = m4a_request('POST', 'operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
    $result = $response->get_data()['data'] ?? array();
    if (200 !== $response->get_status() || 'rolled-back' !== (string) ($result['status'] ?? '')) {
        m4a_fail('general Rollback failed', $response->get_data());
    }
    return $result;
}

function m4a_pair_apply(string $token, string $operation, array $payload): array {
    $planned = m4a_request('POST', 'insights/pairing/plan', $token, array('operation'=>$operation,'payload'=>$payload));
    $plan = $planned->get_data()['data'] ?? array();
    $plan_id = (string) ($plan['plan_id'] ?? '');
    if (200 !== $planned->get_status() || '' === $plan_id || empty($plan['apply_allowed'])) {
        m4a_fail('pairing plan failed: ' . $operation, $planned->get_data());
    }
    $applied = m4a_request('POST', 'insights/pairing/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
    $result = $applied->get_data()['data'] ?? array();
    if (200 !== $applied->get_status() || empty($result['operation_id']) || empty($result['result']['verified'])) {
        m4a_fail('pairing Apply failed: ' . $operation, $applied->get_data());
    }
    return $result;
}

function m4a_pair_verify(string $token, string $operation_id, bool $rendered): array {
    $response = m4a_request('POST', 'insights/pairing/operations/' . rawurlencode($operation_id) . '/verify', $token, array('rendered'=>$rendered));
    $result = $response->get_data()['data'] ?? array();
    if (200 !== $response->get_status() || empty($result['stored_verification']['verified']) || ($rendered && empty($result['rendered_verification']['verified']))) {
        m4a_fail('pairing Verify failed', $response->get_data());
    }
    return $result;
}

function m4a_pair_rollback(string $token, string $operation_id): array {
    $response = m4a_request('POST', 'insights/pairing/operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
    $result = $response->get_data()['data'] ?? array();
    if (200 !== $response->get_status() || 'rolled-back' !== (string) ($result['status'] ?? '')) {
        m4a_fail('pairing Rollback failed', $response->get_data());
    }
    return $result;
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

function m4a_create_line(string $language): int {
    $id = wp_insert_post(array(
        'post_type'=>'research_line','post_status'=>'publish','post_title'=>'M4 Aggregate Line ' . strtoupper($language),
        'post_name'=>'m4-aggregate-line-' . $language . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
    ), true);
    if (is_wp_error($id)) { m4a_fail('Research Line fixture failed', $id->get_error_message()); }
    update_post_meta((int) $id, '_research_language', $language);
    update_post_meta((int) $id, '_research_evidence_status', 'verified');
    update_post_meta((int) $id, '_research_status', 'active');
    return (int) $id;
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { m4a_fail('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { m4a_fail('canonical Greenfield seed failed', $seed); }

rest_get_server();
$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','operations.apply','operations.rollback'),
    1,
    'CI M4 Aggregate'
);
if (is_wp_error($issued) || empty($issued['token'])) { m4a_fail('credential issuance failed'); }
$token = (string) $issued['token'];

$cap_response = m4a_request('GET', 'capabilities', $token);
$cap = $cap_response->get_data()['data'] ?? array();
$control = (array) ($cap['insight_control'] ?? array());
if (200 !== $cap_response->get_status()
    || 'M4' !== (string) ($control['milestone'] ?? '')
    || empty($control['inventory'])
    || empty($control['inspection'])
    || empty($control['scheduling']['available'])
    || empty($control['research_relations']['available'])
    || empty($control['translation_pairing']['available'])
    || empty($control['status_transition']['available'])
    || empty($control['rendered_seo_geo_inspection'])) {
    m4a_fail('M4 capability discovery is incomplete', $cap);
}

$line_en = m4a_create_line('en');

// 1. Create an English draft exclusively through the remote exact-operation transport.
$en_create = m4a_plan_apply($token, 'insight-create', array('data'=>array(
    'title'=>'M4 Aggregate English Insight',
    'slug'=>'m4-aggregate-en-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
    'excerpt'=>'Initial aggregate draft.',
    'content'=>'<p>Initial aggregate content.</p>',
    'language'=>'en',
    'insight_type'=>'research_note',
    'status'=>'draft',
)));
$en_create_id = (string) $en_create['operation_id'];
$en_id = (int) ($en_create['target']['post_id'] ?? $en_create['result']['post_id'] ?? 0);
if ($en_id <= 0) { m4a_fail('English creation did not return a managed Insight ID', $en_create); }
$en_create_verified = m4a_verify($token, $en_create_id, true);
$draft_render = (array) ($en_create_verified['rendered_verification']['resources'][0] ?? array());
if (empty($draft_render['skipped']) || 'draft-or-non-public' !== (string) ($draft_render['reason'] ?? '')) {
    m4a_fail('draft creation was not safely treated as non-public', $en_create_verified);
}

// 2. Edit content and attach a verified same-language Research Line in one bounded editorial update.
$en_update = m4a_plan_apply($token, 'insight-update', array(
    'post_id'=>$en_id,
    'changes'=>array(
        'title'=>'M4 Aggregate English Insight — Updated',
        'excerpt'=>'Updated aggregate excerpt.',
        'content'=>'<p>Updated aggregate content with structured research context.</p>',
        'line_ids'=>array($line_en),
    ),
    'reason'=>'Aggregate M4 editorial update and structured relation',
));
$en_update_id = (string) $en_update['operation_id'];
m4a_verify($token, $en_update_id, false);
$seo_relation = m4a_request('GET', 'insights/' . $en_id . '/seo-geo', $token)->get_data()['data'] ?? array();
if (1 !== (int) ($seo_relation['relations']['count'] ?? 0) || empty($seo_relation['relations']['verified_same_language'])) {
    m4a_fail('structured Research relation did not surface in diagnostics', $seo_relation);
}
ob_start(); eduardo_research_render_research_context($en_id); $relation_html = (string) ob_get_clean();
if (! str_contains($relation_html, (string) get_permalink($line_en))) { m4a_fail('Theme did not render the Research Line internal link', $relation_html); }

// 3. Schedule the draft, verify native non-public scheduling, then roll it back to the editorial draft state.
$scheduled_at = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
$en_schedule = m4a_plan_apply($token, 'insight-update', array(
    'post_id'=>$en_id,
    'changes'=>array('scheduled_at'=>$scheduled_at),
    'reason'=>'Aggregate M4 scheduled publication rehearsal',
));
$en_schedule_id = (string) $en_schedule['operation_id'];
$scheduled_verify = m4a_verify($token, $en_schedule_id, true);
$scheduled_render = (array) ($scheduled_verify['rendered_verification']['resources'][0] ?? array());
if (empty($scheduled_render['skipped']) || 'draft-or-non-public' !== (string) ($scheduled_render['reason'] ?? '')) {
    m4a_fail('scheduled Insight was not safely treated as non-public', $scheduled_verify);
}
$scheduled_record = Eduardo_Research_Manager::insight_editor()->inspect($en_id);
if (is_wp_error($scheduled_record) || 'future' !== (string) ($scheduled_record['status'] ?? '') || $scheduled_at !== (string) ($scheduled_record['scheduled_at'] ?? '')) {
    m4a_fail('scheduled Insight state mismatch', $scheduled_record);
}
m4a_rollback($token, $en_schedule_id);
$after_schedule_rollback = Eduardo_Research_Manager::insight_editor()->inspect($en_id);
if (is_wp_error($after_schedule_rollback) || 'draft' !== (string) ($after_schedule_rollback['status'] ?? '') || '' !== (string) ($after_schedule_rollback['scheduled_at'] ?? '')) {
    m4a_fail('schedule rollback did not restore draft state', $after_schedule_rollback);
}

// 4. Publish the English Insight and verify its rendered Article contract.
$en_publish = m4a_plan_apply($token, 'insight-update', array(
    'post_id'=>$en_id,
    'changes'=>array('status'=>'publish'),
    'reason'=>'Aggregate M4 publish English Insight',
));
$en_publish_id = (string) $en_publish['operation_id'];
m4a_fake_render(array($en_id));
m4a_verify($token, $en_publish_id, true);
remove_all_filters('pre_http_request');

// 5. Create the Spanish published counterpart through the same remote Manager transport.
$es_create = m4a_plan_apply($token, 'insight-create', array('data'=>array(
    'title'=>'M4 Insight Agregado en Español',
    'slug'=>'m4-aggregate-es-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
    'excerpt'=>'Contraparte española del flujo agregado.',
    'content'=>'<p>Contenido español para validar el control editorial bilingüe.</p>',
    'language'=>'es',
    'insight_type'=>'research_note',
    'status'=>'publish',
)));
$es_create_id = (string) $es_create['operation_id'];
$es_id = (int) ($es_create['target']['post_id'] ?? $es_create['result']['post_id'] ?? 0);
if ($es_id <= 0) { m4a_fail('Spanish creation did not return a managed Insight ID', $es_create); }
m4a_fake_render(array($es_id));
m4a_verify($token, $es_create_id, true);
remove_all_filters('pre_http_request');

// 6. Pair EN/ES through the dedicated bounded pairing gateway and verify both rendered records.
$pair = m4a_pair_apply($token, 'insight-pair', array('first_id'=>$en_id,'second_id'=>$es_id));
$pair_id = (string) $pair['operation_id'];
m4a_fake_render(array($en_id,$es_id));
m4a_pair_verify($token, $pair_id, true);
remove_all_filters('pre_http_request');
$paired = Eduardo_Research_Manager::translations()->verify_pair($en_id, $es_id);
if (is_wp_error($paired) || empty($paired['verified'])) { m4a_fail('EN/ES pair did not verify', $paired); }

// 7. Prove remote discovery sees the final bilingual editorial state.
$en_inventory = m4a_request('GET', 'insights?language=en', $token)->get_data()['data'] ?? array();
$es_inventory = m4a_request('GET', 'insights?language=es', $token)->get_data()['data'] ?? array();
$en_ids = array_map(static fn(array $row): int => (int) ($row['post_id'] ?? 0), (array) ($en_inventory['items'] ?? array()));
$es_ids = array_map(static fn(array $row): int => (int) ($row['post_id'] ?? 0), (array) ($es_inventory['items'] ?? array()));
if (! in_array($en_id, $en_ids, true) || ! in_array($es_id, $es_ids, true)) {
    m4a_fail('language inventories do not expose the managed pair', array('en'=>$en_inventory,'es'=>$es_inventory));
}
$en_seo = m4a_request('GET', 'insights/' . $en_id . '/seo-geo', $token)->get_data()['data'] ?? array();
if (empty($en_seo['stored_ready']) || (int) ($en_seo['translation']['counterpart_id'] ?? 0) !== $es_id || 1 !== (int) ($en_seo['relations']['count'] ?? 0)) {
    m4a_fail('final English SEO/GEO state is incomplete', $en_seo);
}

// 8. Roll the complete workflow back in reverse order and prove the initial absence baseline is restored.
m4a_pair_rollback($token, $pair_id);
m4a_rollback($token, $es_create_id);
m4a_rollback($token, $en_publish_id);
m4a_rollback($token, $en_update_id);
m4a_rollback($token, $en_create_id);

if (get_post($en_id) instanceof WP_Post || get_post($es_id) instanceof WP_Post) {
    m4a_fail('aggregate rollback did not remove created Insights', array('en'=>get_post($en_id),'es'=>get_post($es_id)));
}
wp_delete_post($line_en, true);

fwrite(STDOUT, "M4 aggregate Insight operating workflow OK\n");
