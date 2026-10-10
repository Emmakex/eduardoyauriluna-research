<?php
/** Aggregate fresh-WordPress operating acceptance for remote M5 Research control. */
wp_set_current_user(1);

function m5a_fail(string $message, mixed $context = null): never {
    fwrite(STDERR, "M5 AGGREGATE FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m5a_request(string $method, string $route, string $token, array $body = array(), string $rid = ''): WP_REST_Response {
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

function m5a_plan(string $token, string $base, string $operation, array $payload): array {
    $response = m5a_request('POST', $base . '/plan', $token, array('operation'=>$operation,'payload'=>$payload));
    if (200 !== $response->get_status()) { m5a_fail('Plan failed: ' . $base . ' / ' . $operation, $response->get_data()); }
    $plan = (array) ($response->get_data()['data'] ?? array());
    if (empty($plan['apply_allowed'])) { m5a_fail('Aggregate Plan unexpectedly blocked: ' . $operation, $plan); }
    return $plan;
}

function m5a_apply(string $token, string $base, string $plan_id): array {
    $response = m5a_request('POST', $base . '/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
    if (200 !== $response->get_status()) { m5a_fail('Apply failed: ' . $base, $response->get_data()); }
    $operation = (array) ($response->get_data()['data'] ?? array());
    if (empty($operation['operation_id']) || empty($operation['result']['verified'])) { m5a_fail('Apply result invalid: ' . $base, $operation); }
    return $operation;
}

function m5a_verify(string $token, string $base, string $operation_id, array $body = array()): array {
    $response = m5a_request('POST', $base . '/operations/' . rawurlencode($operation_id) . '/verify', $token, $body);
    if (200 !== $response->get_status()) { m5a_fail('Verify failed: ' . $base, $response->get_data()); }
    $operation = (array) ($response->get_data()['data'] ?? array());
    if (empty($operation['stored_verification']['verified'])) { m5a_fail('Stored verification failed: ' . $base, $operation); }
    return $operation;
}

function m5a_rollback(string $token, string $base, string $operation_id): array {
    $response = m5a_request('POST', $base . '/operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
    if (200 !== $response->get_status()) { m5a_fail('Rollback failed: ' . $base, $response->get_data()); }
    $operation = (array) ($response->get_data()['data'] ?? array());
    if ('rolled-back' !== (string) ($operation['status'] ?? '')) { m5a_fail('Rollback status invalid: ' . $base, $operation); }
    return $operation;
}

function m5a_with_query(int $post_id, callable $callback): mixed {
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
        m5a_fail('Unable to establish public singular query', $post_id);
    }
    $wp_query = $query;
    $wp_the_query = $query;
    $post = $query->posts[0];
    setup_postdata($post);
    try { return $callback(); }
    finally {
        wp_reset_postdata();
        $wp_query = $old_query;
        $wp_the_query = $old_the_query;
        $post = $old_post;
        if ($post instanceof WP_Post) { setup_postdata($post); }
    }
}

function m5a_capture(int $post_id, callable $callback): string {
    return (string) m5a_with_query($post_id, static function () use ($callback): string {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    });
}

function m5a_graph(int $post_id): array {
    $html = m5a_capture($post_id, static function (): void { eduardo_research_schema_graph(); });
    if (1 !== preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match)) { m5a_fail('JSON-LD script missing', $html); }
    $decoded = json_decode((string) $match[1], true);
    if (! is_array($decoded) || ! is_array($decoded['@graph'] ?? null)) { m5a_fail('Invalid JSON-LD graph', $decoded); }
    return $decoded['@graph'];
}

function m5a_node(array $graph, string $id): array {
    foreach ($graph as $node) {
        if (is_array($node) && $id === (string) ($node['@id'] ?? '')) { return $node; }
    }
    m5a_fail('Expected JSON-LD node missing', array('id'=>$id,'graph'=>$graph));
}

function m5a_node_ids(array $nodes): array {
    $ids = array();
    foreach ($nodes as $node) { if (is_array($node) && '' !== (string) ($node['@id'] ?? '')) { $ids[] = (string) $node['@id']; } }
    return $ids;
}

function m5a_same(mixed $left, mixed $right): bool { return maybe_serialize($left) === maybe_serialize($right); }

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { m5a_fail('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { m5a_fail('canonical seed failed', $seed); }
rest_get_server();

$baseline_evidence = get_option('eduardo_research_evidence', array());
$baseline_evidence = is_array($baseline_evidence) ? $baseline_evidence : array();

$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(array(
    'site.read','site.diagnostics',
    'research.read','research.write',
    'translations.read','translations.write',
    'evidence.read','evidence.write',
    'operations.apply','operations.rollback',
), 1, 'CI M5 Aggregate');
if (is_wp_error($issued) || empty($issued['token'])) { m5a_fail('aggregate credential issuance failed'); }
$token = (string) $issued['token'];

// All M5 gateways must coexist under one bounded connection.
$cap = m5a_request('GET', 'capabilities', $token);
$cap_data = (array) ($cap->get_data()['data'] ?? array());
foreach (array('research_line_control','research_object_control','research_translation_control','research_evidence_control') as $key) {
    if (empty($cap_data[$key]['available']) || 'M5' !== (string) ($cap_data[$key]['milestone'] ?? '')) { m5a_fail('aggregate capability missing: ' . $key, $cap_data); }
}

// 1. Verified provenance record that must also appear in public Person schema.
$orcid_url = 'https://orcid.org/0000-0002-1825-0097';
$evidence_plan = m5a_plan($token, 'research-evidence', 'evidence-create', array(
    'group'=>'identifiers',
    'data'=>array('label'=>'ORCID','value'=>'0000-0002-1825-0097','url'=>$orcid_url,'status'=>'verified','summary'=>'Aggregate verified identity provenance.'),
    'evidence_confirmed'=>true,
    'evidence_reference'=>'ci:m5-aggregate:evidence',
));
$evidence_record_id = (string) ($evidence_plan['target']['record_id'] ?? '');
$evidence_operation = m5a_apply($token, 'research-evidence', (string) $evidence_plan['plan_id']);
$evidence_operation_id = (string) $evidence_operation['operation_id'];
$evidence_verified = m5a_verify($token, 'research-evidence', $evidence_operation_id);
if ($orcid_url !== (string) ($evidence_verified['provenance']['evidence_reference'] ?? '')
    && 'ci:m5-aggregate:evidence' !== (string) ($evidence_verified['provenance']['evidence_reference'] ?? '')) {
    m5a_fail('aggregate evidence provenance missing', $evidence_verified);
}
if ('' === $evidence_record_id) { m5a_fail('aggregate evidence record ID missing', $evidence_plan); }

// 2. Create verified/public EN and ES Research Lines through the remote Manager.
$line_operations = array();
$line_ids = array();
foreach (array('en','es') as $language) {
    $line_data = array(
        'title'=>'en' === $language ? 'Aggregate Verified Research Line' : 'Línea de investigación verificada agregada',
        'slug'=>('en' === $language ? 'aggregate-verified-line-' : 'linea-verificada-agregada-') . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
        'excerpt'=>'en' === $language ? 'Aggregate M5 verified line.' : 'Línea verificada para la aceptación agregada M5.',
        'content'=>'<p>Aggregate M5 Research Line.</p>',
        'language'=>$language,
        'status'=>'publish',
        'evidence_status'=>'verified',
        'research_status'=>'active',
        'central_question'=>'en' === $language ? 'Can the complete M5 graph operate as one bounded system?' : '¿Puede operar el grafo M5 completo como un sistema acotado?',
        'order'=>'98',
        'topics'=>array('AI','research graph'),
        'methods'=>array('verification'),
    );
    $plan = m5a_plan($token, 'research-lines', 'line-create', array(
        'data'=>$line_data,
        'evidence_confirmed'=>true,
        'evidence_reference'=>'ci:m5-aggregate:line:' . $language,
    ));
    $operation = m5a_apply($token, 'research-lines', (string) $plan['plan_id']);
    $line_id = (int) ($operation['target']['post_id'] ?? 0);
    if ($line_id <= 0 || ! eduardo_research_line_is_verified_public($line_id, $language)) { m5a_fail('aggregate verified line invalid', $operation); }
    $line_operations[$language] = (string) $operation['operation_id'];
    $line_ids[$language] = $line_id;
}

// 3. Create EN+ES Publication pair and representative EN Project/Software/Dataset, all through remote M5.
$object_states = array();
$object_defs = array(
    'output-en'=>array('kind'=>'output','language'=>'en','line_id'=>$line_ids['en'],'title'=>'Aggregate Scholarly Output','specific'=>array(
        'output_type'=>'journal_article','publication_date'=>'2026-10-10',
        'authors'=>array(array('display_name'=>'Eduardo Jose Yauri Luna','is_site_researcher'=>true)),
    )),
    'output-es'=>array('kind'=>'output','language'=>'es','line_id'=>$line_ids['es'],'title'=>'Resultado académico agregado','specific'=>array(
        'output_type'=>'journal_article','publication_date'=>'2026-10-10',
        'authors'=>array(array('display_name'=>'Eduardo Jose Yauri Luna','is_site_researcher'=>true)),
    )),
    'project-en'=>array('kind'=>'project','language'=>'en','line_id'=>$line_ids['en'],'title'=>'Aggregate Research Project','specific'=>array(
        'question'=>'How can the full Manager research graph remain bounded?',
    )),
    'software-en'=>array('kind'=>'software','language'=>'en','line_id'=>$line_ids['en'],'title'=>'Aggregate Research Software','specific'=>array(
        'repository_url'=>'https://github.com/Emmakex/eduardoyauriluna-research','programming_languages'=>array('PHP'),
    )),
    'dataset-en'=>array('kind'=>'dataset','language'=>'en','line_id'=>$line_ids['en'],'title'=>'Aggregate Research Dataset','specific'=>array(
        'repository'=>'https://example.test/aggregate-research-dataset','formats'=>array('CSV'),
    )),
);

foreach ($object_defs as $key => $definition) {
    $kind = (string) $definition['kind'];
    $language = (string) $definition['language'];
    $data = array_merge(array(
        'title'=>(string) $definition['title'],
        'slug'=>'m5-aggregate-' . $key . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8),
        'excerpt'=>'Aggregate M5 ' . $kind . ' fixture.',
        'content'=>'<p>Aggregate M5 Research Object content.</p>',
        'language'=>$language,
        'status'=>'publish',
        'line_ids'=>array((int) $definition['line_id']),
    ), (array) $definition['specific']);
    $plan = m5a_plan($token, 'research-objects', 'object-create', array(
        'kind'=>$kind,
        'data'=>$data,
        'evidence_confirmed'=>true,
        'evidence_reference'=>'ci:m5-aggregate:object:' . $key,
    ));
    $operation = m5a_apply($token, 'research-objects', (string) $plan['plan_id']);
    $post_id = (int) ($operation['target']['post_id'] ?? 0);
    if ($post_id <= 0) { m5a_fail('aggregate object missing post ID: ' . $key, $operation); }
    m5a_verify($token, 'research-objects', (string) $operation['operation_id'], array('rendered'=>false));
    $record = Eduardo_Research_Manager::object_editor()->inspect($kind, $post_id);
    if (is_wp_error($record) || array((int) $definition['line_id']) !== array_values((array) ($record['line_ids'] ?? array()))) {
        m5a_fail('aggregate object relationship mismatch: ' . $key, $record);
    }
    $object_states[$key] = array(
        'kind'=>$kind,
        'language'=>$language,
        'post_id'=>$post_id,
        'operation_id'=>(string) $operation['operation_id'],
        'url'=>(string) get_permalink($post_id),
    );
}

// 4. Pair EN/ES Lines and the bilingual Publication through the structured translation gateway.
$translation_operations = array();
foreach (array(
    'line'=>array($line_ids['en'],$line_ids['es'],'ci:m5-aggregate:translation:line'),
    'output'=>array($object_states['output-en']['post_id'],$object_states['output-es']['post_id'],'ci:m5-aggregate:translation:output'),
) as $name => $pair) {
    $plan = m5a_plan($token, 'research-translations', 'translation-pair', array(
        'first_id'=>(int) $pair[0],
        'second_id'=>(int) $pair[1],
        'evidence_confirmed'=>true,
        'evidence_reference'=>(string) $pair[2],
    ));
    $operation = m5a_apply($token, 'research-translations', (string) $plan['plan_id']);
    m5a_verify($token, 'research-translations', (string) $operation['operation_id'], array('rendered'=>false));
    $translation_operations[$name] = (string) $operation['operation_id'];
}
$line_pair = Eduardo_Research_Manager::translations()->verify_pair($line_ids['en'], $line_ids['es']);
$output_pair = Eduardo_Research_Manager::translations()->verify_pair($object_states['output-en']['post_id'], $object_states['output-es']['post_id']);
if (is_wp_error($line_pair) || empty($line_pair['verified']) || is_wp_error($output_pair) || empty($output_pair['verified'])) {
    m5a_fail('aggregate translation pairing did not verify', array('line'=>$line_pair,'output'=>$output_pair));
}

// 5. Public Theme must expose the combined evidence, translations, relations and schema coherently.
$en_line_id = $line_ids['en'];
$en_line_url = (string) get_permalink($en_line_id);
$es_line_url = (string) get_permalink($line_ids['es']);
$en_output_id = (int) $object_states['output-en']['post_id'];
$en_output_url = (string) $object_states['output-en']['url'];
$es_output_url = (string) $object_states['output-es']['url'];

$output_head = m5a_capture($en_output_id, static function (): void { eduardo_research_head_metadata(); });
if (! str_contains($output_head, '<link rel="canonical" href="' . esc_url($en_output_url) . '">')
    || ! str_contains($output_head, 'hreflang="en" href="' . esc_url($en_output_url) . '"')
    || ! str_contains($output_head, 'hreflang="es" href="' . esc_url($es_output_url) . '"')) {
    m5a_fail('aggregate bilingual output hreflang/canonical invalid', $output_head);
}
$line_head = m5a_capture($en_line_id, static function (): void { eduardo_research_head_metadata(); });
if (! str_contains($line_head, 'hreflang="es" href="' . esc_url($es_line_url) . '"')) { m5a_fail('aggregate bilingual line hreflang missing', $line_head); }

$output_graph = m5a_graph($en_output_id);
$output_node = m5a_node($output_graph, $en_output_url . '#research-object');
if ('ScholarlyArticle' !== (string) ($output_node['@type'] ?? '') || 'en' !== (string) ($output_node['inLanguage'] ?? '')) {
    m5a_fail('aggregate output schema invalid', $output_node);
}
if (! in_array($en_line_url . '#research-line', m5a_node_ids((array) ($output_node['about'] ?? array())), true)) {
    m5a_fail('aggregate output schema missing line relationship', $output_node);
}
$person = m5a_node($output_graph, home_url('/#researcher'));
if (! in_array($orcid_url, (array) ($person['sameAs'] ?? array()), true)) {
    m5a_fail('verified evidence did not reach public Person.sameAs', $person);
}

$line_graph = m5a_graph($en_line_id);
$line_node = m5a_node($line_graph, $en_line_url . '#research-line');
$subject_ids = m5a_node_ids((array) ($line_node['subjectOf'] ?? array()));
foreach (array('output-en','project-en','software-en','dataset-en') as $key) {
    $expected = (string) $object_states[$key]['url'] . '#research-object';
    if (! in_array($expected, $subject_ids, true)) { m5a_fail('aggregate line subjectOf missing ' . $key, $line_node); }
}

$relation_html = m5a_capture($en_output_id, static function () use ($en_output_id): void { eduardo_research_render_research_context($en_output_id); });
if (! str_contains($relation_html, 'data-research-line-id="' . $en_line_id . '"') || ! str_contains($relation_html, esc_url($en_line_url))) {
    m5a_fail('aggregate public object relation HTML invalid', $relation_html);
}
$reverse_html = m5a_capture($en_line_id, static function () use ($en_line_id): void { eduardo_research_render_related_objects($en_line_id); });
foreach (array('output-en','project-en','software-en','dataset-en') as $key) {
    if (! str_contains($reverse_html, esc_url((string) $object_states[$key]['url']))) { m5a_fail('aggregate reverse HTML missing ' . $key, $reverse_html); }
}

// 6. Reverse all aggregate operations in dependency order and prove baseline restoration.
foreach (array_reverse($translation_operations, true) as $operation_id) {
    m5a_rollback($token, 'research-translations', (string) $operation_id);
}
foreach (array_reverse(array_keys($object_states)) as $key) {
    m5a_rollback($token, 'research-objects', (string) $object_states[$key]['operation_id']);
    if (get_post((int) $object_states[$key]['post_id']) instanceof WP_Post) { m5a_fail('aggregate object rollback left post: ' . $key, $object_states[$key]); }
}
foreach (array('es','en') as $language) {
    m5a_rollback($token, 'research-lines', (string) $line_operations[$language]);
    if (get_post((int) $line_ids[$language]) instanceof WP_Post) { m5a_fail('aggregate line rollback left post: ' . $language, $line_ids[$language]); }
}
m5a_rollback($token, 'research-evidence', $evidence_operation_id);
if (! m5a_same(get_option('eduardo_research_evidence', array()), $baseline_evidence)) {
    m5a_fail('aggregate evidence rollback did not restore baseline', get_option('eduardo_research_evidence', array()));
}
if (! is_wp_error(Eduardo_Research_Manager::evidence_editor()->inspect_record('identifiers', $evidence_record_id))) {
    m5a_fail('aggregate evidence record survived rollback', $evidence_record_id);
}

fwrite(STDOUT, "M5 aggregate ChatGPT-governed Research operating acceptance OK\n");
