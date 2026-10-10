<?php
/** Fresh-WordPress acceptance for M5 Research relationships and public schema. */
wp_set_current_user(1);

function m5rs_fail(string $message, mixed $context = null): never {
    fwrite(STDERR, "M5 RELATIONSHIP/SCHEMA FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m5rs_request(string $method, string $route, string $token, array $body = array(), string $rid = ''): WP_REST_Response {
    $request = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', '' !== $rid ? $rid : 'req-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($body) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($request);
}

function m5rs_line_plan(string $token, array $data, string $reference): array {
    $response = m5rs_request('POST', 'research-lines/plan', $token, array(
        'operation'=>'line-create',
        'payload'=>array('data'=>$data,'evidence_confirmed'=>true,'evidence_reference'=>$reference),
    ));
    if (200 !== $response->get_status()) { m5rs_fail('Research Line plan failed', $response->get_data()); }
    return (array) ($response->get_data()['data'] ?? array());
}

function m5rs_line_apply(string $token, string $plan_id): array {
    $response = m5rs_request('POST', 'research-lines/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
    if (200 !== $response->get_status()) { m5rs_fail('Research Line Apply failed', $response->get_data()); }
    $operation = (array) ($response->get_data()['data'] ?? array());
    if (empty($operation['operation_id']) || empty($operation['result']['verified'])) { m5rs_fail('Research Line Apply result invalid', $operation); }
    return $operation;
}

function m5rs_object_plan_response(string $token, string $kind, array $data, string $reference): WP_REST_Response {
    return m5rs_request('POST', 'research-objects/plan', $token, array(
        'operation'=>'object-create',
        'payload'=>array('kind'=>$kind,'data'=>$data,'evidence_confirmed'=>true,'evidence_reference'=>$reference),
    ));
}

function m5rs_object_plan(string $token, string $kind, array $data, string $reference): array {
    $response = m5rs_object_plan_response($token, $kind, $data, $reference);
    if (200 !== $response->get_status()) { m5rs_fail('Research Object plan failed: ' . $kind, $response->get_data()); }
    return (array) ($response->get_data()['data'] ?? array());
}

function m5rs_object_apply(string $token, string $plan_id): array {
    $response = m5rs_request('POST', 'research-objects/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
    if (200 !== $response->get_status()) { m5rs_fail('Research Object Apply failed', $response->get_data()); }
    $operation = (array) ($response->get_data()['data'] ?? array());
    if (empty($operation['operation_id']) || empty($operation['result']['verified'])) { m5rs_fail('Research Object Apply result invalid', $operation); }
    return $operation;
}

function m5rs_verify_object_stored(string $token, string $operation_id): array {
    $response = m5rs_request('POST', 'research-objects/operations/' . rawurlencode($operation_id) . '/verify', $token, array('rendered'=>false));
    if (200 !== $response->get_status()) { m5rs_fail('Research Object Verify failed', $response->get_data()); }
    $operation = (array) ($response->get_data()['data'] ?? array());
    if (empty($operation['stored_verification']['verified'])) { m5rs_fail('Research Object stored verification failed', $operation); }
    return $operation;
}

function m5rs_rollback(string $token, string $base, string $operation_id): void {
    $response = m5rs_request('POST', $base . '/operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
    if (200 !== $response->get_status()) { m5rs_fail('Rollback failed for ' . $base, $response->get_data()); }
    $operation = (array) ($response->get_data()['data'] ?? array());
    if ('rolled-back' !== (string) ($operation['status'] ?? '')) { m5rs_fail('Rollback status invalid for ' . $base, $operation); }
}

function m5rs_with_query(int $post_id, callable $callback): mixed {
    global $wp_query, $wp_the_query, $post;
    $old_query = $wp_query;
    $old_the_query = $wp_the_query;
    $old_post = $post;

    $query = new WP_Query(array(
        'p'=>$post_id,
        'post_type'=>(string) get_post_type($post_id),
        'post_status'=>'publish',
        'posts_per_page'=>1,
        'no_found_rows'=>true,
    ));
    if (! $query->have_posts() || ! isset($query->posts[0]) || ! $query->posts[0] instanceof WP_Post) {
        m5rs_fail('Unable to establish singular Theme query', $post_id);
    }
    $wp_query = $query;
    $wp_the_query = $query;
    $post = $query->posts[0];
    setup_postdata($post);

    try {
        return $callback();
    } finally {
        wp_reset_postdata();
        $wp_query = $old_query;
        $wp_the_query = $old_the_query;
        $post = $old_post;
        if ($post instanceof WP_Post) { setup_postdata($post); }
    }
}

function m5rs_capture(int $post_id, callable $callback): string {
    return (string) m5rs_with_query($post_id, static function () use ($callback): string {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    });
}

function m5rs_schema_graph(int $post_id): array {
    $html = m5rs_capture($post_id, static function (): void { eduardo_research_schema_graph(); });
    if (1 !== preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match)) {
        m5rs_fail('Theme schema graph was not emitted', array('post_id'=>$post_id,'html'=>$html));
    }
    $decoded = json_decode((string) $match[1], true);
    if (! is_array($decoded) || ! is_array($decoded['@graph'] ?? null)) { m5rs_fail('Theme schema graph is invalid JSON-LD', $decoded); }
    return $decoded['@graph'];
}

function m5rs_find_schema_node(array $graph, string $id): array {
    foreach ($graph as $node) {
        if (is_array($node) && $id === (string) ($node['@id'] ?? '')) { return $node; }
    }
    m5rs_fail('Expected JSON-LD node missing', array('id'=>$id,'graph'=>$graph));
}

function m5rs_ids(array $nodes): array {
    $ids = array();
    foreach ($nodes as $node) {
        if (is_array($node) && '' !== (string) ($node['@id'] ?? '')) { $ids[] = (string) $node['@id']; }
    }
    return $ids;
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { m5rs_fail('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { m5rs_fail('canonical seed failed', $seed); }
rest_get_server();

$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','research.read','research.write','operations.apply','operations.rollback'),
    1,
    'CI M5 Relationships Schema'
);
if (is_wp_error($issued) || empty($issued['token'])) { m5rs_fail('credential issuance failed'); }
$token = (string) $issued['token'];

$line_data = array(
    'title'=>'M5 Verified Relationship Line',
    'slug'=>'m5-verified-relationship-line-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
    'excerpt'=>'Verified line used to prove public Research relationships.',
    'content'=>'<p>Verified relationship line.</p>',
    'language'=>'en',
    'status'=>'publish',
    'evidence_status'=>'verified',
    'research_status'=>'active',
    'central_question'=>'How should verified research relationships be exposed to humans and machines?',
    'order'=>'95',
    'topics'=>array('research graph','provenance'),
    'methods'=>array('verification'),
);
$line_plan = m5rs_line_plan($token, $line_data, 'ci:m5e:line');
if (empty($line_plan['apply_allowed'])) { m5rs_fail('verified public line plan blocked', $line_plan); }
$line_operation = m5rs_line_apply($token, (string) $line_plan['plan_id']);
$line_operation_id = (string) $line_operation['operation_id'];
$line_id = (int) ($line_operation['target']['post_id'] ?? 0);
if ($line_id <= 0 || ! eduardo_research_line_is_verified_public($line_id, 'en')) { m5rs_fail('created Research Line is not verified/public', $line_operation); }
$line_url = (string) get_permalink($line_id);

// Relationship IDs outside the verified same-language contract must be rejected before Apply.
$invalid_relation = m5rs_object_plan_response($token, 'project', array(
    'title'=>'Invalid Relation Project','slug'=>'invalid-relation-project','excerpt'=>'Should never plan.','content'=>'','language'=>'en','status'=>'publish','line_ids'=>array(999999999),
), 'ci:m5e:invalid-id');
if (400 !== $invalid_relation->get_status() || 'research_manager_unverified_line_relation' !== (string) ($invalid_relation->get_data()['code'] ?? '')) {
    m5rs_fail('unverified/nonexistent line relation was not rejected', $invalid_relation->get_data());
}
$wrong_language = m5rs_object_plan_response($token, 'project', array(
    'title'=>'Wrong Language Relation','slug'=>'wrong-language-relation','excerpt'=>'Should never plan.','content'=>'','language'=>'es','status'=>'publish','line_ids'=>array($line_id),
), 'ci:m5e:language-mismatch');
if (400 !== $wrong_language->get_status() || 'research_manager_unverified_line_relation' !== (string) ($wrong_language->get_data()['code'] ?? '')) {
    m5rs_fail('cross-language line relation was not rejected', $wrong_language->get_data());
}

$fixtures = array(
    'output'=>array(
        'title'=>'M5 Related Scholarly Output','excerpt'=>'Output related to the verified Research Line.','content'=>'<p>Scholarly relationship fixture.</p>',
        'output_type'=>'journal_article','publication_date'=>'2026-10-10',
        'authors'=>array(array('display_name'=>'Eduardo Jose Yauri Luna','is_site_researcher'=>true)),
    ),
    'project'=>array(
        'title'=>'M5 Related Project','excerpt'=>'Project related to the verified Research Line.','content'=>'<p>Project relationship fixture.</p>',
        'question'=>'How can the relationship contract remain evidence-aware?',
    ),
    'software'=>array(
        'title'=>'M5 Related Software','excerpt'=>'Software related to the verified Research Line.','content'=>'<p>Software relationship fixture.</p>',
        'repository_url'=>'https://github.com/Emmakex/eduardoyauriluna-research','programming_languages'=>array('PHP'),
    ),
    'dataset'=>array(
        'title'=>'M5 Related Dataset','excerpt'=>'Dataset related to the verified Research Line.','content'=>'<p>Dataset relationship fixture.</p>',
        'repository'=>'https://example.test/m5-related-dataset','formats'=>array('CSV'),
    ),
);
$expected_schema_types = array('output'=>'ScholarlyArticle','project'=>'CreativeWork','software'=>'SoftwareSourceCode','dataset'=>'Dataset');
$objects = array();

foreach ($fixtures as $kind => $specific) {
    $data = array_merge(array(
        'slug'=>'m5-related-' . $kind . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
        'language'=>'en','status'=>'publish','line_ids'=>array($line_id),
    ), $specific);
    $plan = m5rs_object_plan($token, $kind, $data, 'ci:m5e:relationship:' . $kind);
    if (empty($plan['apply_allowed'])) { m5rs_fail('relationship creation plan blocked for ' . $kind, $plan); }
    $operation = m5rs_object_apply($token, (string) $plan['plan_id']);
    $operation_id = (string) $operation['operation_id'];
    $post_id = (int) ($operation['target']['post_id'] ?? 0);
    if ($post_id <= 0) { m5rs_fail('relationship object missing post ID for ' . $kind, $operation); }
    m5rs_verify_object_stored($token, $operation_id);

    $inspect = m5rs_request('GET', 'research-objects/' . $kind . '/' . $post_id, $token);
    $record = (array) ($inspect->get_data()['data'] ?? array());
    if (200 !== $inspect->get_status() || array($line_id) !== array_values((array) ($record['line_ids'] ?? array()))) {
        m5rs_fail('remote relationship inspection mismatch for ' . $kind, $inspect->get_data());
    }
    $public_line_ids = eduardo_research_post_line_ids($post_id, 'en');
    if (array($line_id) !== $public_line_ids) { m5rs_fail('Theme did not accept stored relation as public for ' . $kind, $public_line_ids); }

    $object_url = (string) get_permalink($post_id);
    $relation_html = m5rs_capture($post_id, static function () use ($post_id): void { eduardo_research_render_research_context($post_id); });
    if (! str_contains($relation_html, 'data-research-line-id="' . $line_id . '"') || ! str_contains($relation_html, esc_url($line_url))) {
        m5rs_fail('public object relationship HTML missing verified line for ' . $kind, $relation_html);
    }

    $head = m5rs_capture($post_id, static function (): void { eduardo_research_head_metadata(); });
    if (! str_contains($head, '<link rel="canonical" href="' . esc_url($object_url) . '">')
        || ! str_contains($head, 'hreflang="en"')
        || ! str_contains($head, '<meta property="og:url" content="' . esc_url($object_url) . '">')) {
        m5rs_fail('public canonical/hreflang metadata incomplete for ' . $kind, $head);
    }

    $graph = m5rs_schema_graph($post_id);
    $node = m5rs_find_schema_node($graph, $object_url . '#research-object');
    if ($expected_schema_types[$kind] !== (string) ($node['@type'] ?? '') || 'en' !== (string) ($node['inLanguage'] ?? '')) {
        m5rs_fail('public schema type/language mismatch for ' . $kind, $node);
    }
    $about_ids = m5rs_ids((array) ($node['about'] ?? array()));
    if (! in_array($line_url . '#research-line', $about_ids, true)) {
        m5rs_fail('public object schema missing Research Line about relationship for ' . $kind, $node);
    }
    if ('software' === $kind && 'https://github.com/Emmakex/eduardoyauriluna-research' !== (string) ($node['codeRepository'] ?? '')) {
        m5rs_fail('SoftwareSourceCode schema missing codeRepository', $node);
    }

    $objects[$kind] = array('post_id'=>$post_id,'operation_id'=>$operation_id,'url'=>$object_url,'title'=>(string) $specific['title']);
}

// Reverse relationship: the verified Research Line must discover and render every related object.
$related = eduardo_research_related_objects_query_for_line($line_id, 24);
$related_ids = array_map(static fn($post): int => $post instanceof WP_Post ? (int) $post->ID : 0, $related->posts);
foreach ($objects as $kind => $state) {
    if (! in_array((int) $state['post_id'], $related_ids, true)) { m5rs_fail('reverse relationship query missing ' . $kind, $related_ids); }
}
wp_reset_postdata();

$line_relations_html = m5rs_capture($line_id, static function () use ($line_id): void { eduardo_research_render_related_objects($line_id); });
foreach ($objects as $kind => $state) {
    if (! str_contains($line_relations_html, esc_url((string) $state['url'])) || ! str_contains($line_relations_html, esc_html((string) $state['title']))) {
        m5rs_fail('Research Line public HTML missing related ' . $kind, $line_relations_html);
    }
}

$line_graph = m5rs_schema_graph($line_id);
$line_node = m5rs_find_schema_node($line_graph, $line_url . '#research-line');
if ('CreativeWork' !== (string) ($line_node['@type'] ?? '') || 'en' !== (string) ($line_node['inLanguage'] ?? '')) {
    m5rs_fail('Research Line public schema type/language invalid', $line_node);
}
$subject_ids = m5rs_ids((array) ($line_node['subjectOf'] ?? array()));
foreach ($objects as $kind => $state) {
    if (! in_array((string) $state['url'] . '#research-object', $subject_ids, true)) {
        m5rs_fail('Research Line schema subjectOf missing ' . $kind, $line_node);
    }
}

// Roll back the remote creations and prove no test relationship survives.
foreach (array_reverse(array_keys($objects)) as $kind) {
    m5rs_rollback($token, 'research-objects', (string) $objects[$kind]['operation_id']);
    if (get_post((int) $objects[$kind]['post_id']) instanceof WP_Post) { m5rs_fail('object rollback did not remove ' . $kind, $objects[$kind]); }
}
m5rs_rollback($token, 'research-lines', $line_operation_id);
if (get_post($line_id) instanceof WP_Post) { m5rs_fail('line rollback did not remove relationship line', $line_id); }

fwrite(STDOUT, "M5 Research relationships/public schema acceptance OK\n");
