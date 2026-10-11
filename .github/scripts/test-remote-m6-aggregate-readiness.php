<?php
/** Fresh-WordPress acceptance for final aggregate M6 SEO/GEO readiness. */

wp_set_current_user(1);

function m6d_fail(string $message, mixed $context = null): never {
    fwrite(STDERR, "M6D FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m6d_request(
    string $method,
    string $route,
    string $token,
    array $body = array(),
    array $query = array()
): WP_REST_Response {
    $request = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', 'req-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($query) { $request->set_query_params($query); }
    if ($body) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($request);
}

function m6d_object_plan(string $token, string $operation, array $payload): array {
    $response = m6d_request('POST', 'research-objects/plan', $token, array('operation'=>$operation,'payload'=>$payload));
    if (200 !== $response->get_status()) { m6d_fail('Research Object plan failed', $response->get_data()); }
    return (array) ($response->get_data()['data'] ?? array());
}

function m6d_object_apply(string $token, string $plan_id): array {
    $response = m6d_request('POST', 'research-objects/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
    if (200 !== $response->get_status()) { m6d_fail('Research Object Apply failed', $response->get_data()); }
    return (array) ($response->get_data()['data'] ?? array());
}

function m6d_object_rollback(string $token, string $operation_id): array {
    $response = m6d_request('POST', 'research-objects/operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
    if (200 !== $response->get_status()) { m6d_fail('Research Object Rollback failed', $response->get_data()); }
    return (array) ($response->get_data()['data'] ?? array());
}

function m6d_has_action(array $report, string $group, string $check_id, int $post_id = 0): bool {
    foreach ((array) ($report['residual_actions'][$group] ?? array()) as $item) {
        if (! is_array($item) || $check_id !== (string) ($item['check_id'] ?? '')) { continue; }
        $target = is_array($item['target'] ?? null) ? $item['target'] : array();
        $action_target = is_array($item['action']['target'] ?? null) ? $item['action']['target'] : array();
        $candidate = absint($target['post_id'] ?? $target['identifier'] ?? $action_target['post_id'] ?? 0);
        if ($post_id <= 0 || $candidate === $post_id) { return true; }
    }
    return false;
}

function m6d_install_rendered_fixture(): void {
    $home_en = function_exists('eduardo_research_page_url') ? (string) eduardo_research_page_url('home', 'en') : home_url('/');
    $home_es = function_exists('eduardo_research_page_url') ? (string) eduardo_research_page_url('home', 'es') : home_url('/es/');

    add_filter('pre_http_request', static function ($preempt, $args, $url) use ($home_en, $home_es) {
        $url = (string) $url;
        $post_id = url_to_postid($url);
        $language = 'en';
        $schema_type = 'WebPage';
        $title = 'Research page';
        $en_url = $url;
        $es_url = $url;

        if (untrailingslashit($url) === untrailingslashit($home_en) || untrailingslashit($url) === untrailingslashit($home_es)) {
            $language = untrailingslashit($url) === untrailingslashit($home_es) ? 'es' : 'en';
            $en_url = $home_en;
            $es_url = $home_es;
        } elseif ($post_id > 0) {
            $language = function_exists('eduardo_research_post_language') ? (string) eduardo_research_post_language($post_id) : 'en';
            $post_type = (string) get_post_type($post_id);
            $title = (string) get_the_title($post_id);
            $schema_type = match ($post_type) {
                'research_line', 'research_project' => 'CreativeWork',
                'research_dataset' => 'Dataset',
                'research_software' => 'SoftwareSourceCode',
                'research_output' => function_exists('eduardo_research_output_schema_type') ? eduardo_research_output_schema_type($post_id) : 'CreativeWork',
                'post' => 'Article',
                default => 'WebPage',
            };
        }

        $safe = esc_url($url);
        $body = '<!doctype html><html lang="' . esc_attr($language) . '"><head>'
            . '<link rel="canonical" href="' . $safe . '">'
            . '<link rel="alternate" hreflang="en" href="' . esc_url($en_url) . '">'
            . '<link rel="alternate" hreflang="es" href="' . esc_url($es_url) . '">'
            . '<link rel="alternate" hreflang="x-default" href="' . esc_url($en_url) . '">'
            . '<meta property="og:url" content="' . $safe . '">'
            . '<meta name="robots" content="index, follow">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"' . esc_attr($schema_type) . '"}</script>'
            . '</head><body><main>' . esc_html($title) . '</main></body></html>';
        return array(
            'headers'=>array(),
            'body'=>$body,
            'response'=>array('code'=>200,'message'=>'OK'),
            'cookies'=>array(),
            'filename'=>null,
        );
    }, 10, 3);
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { m6d_fail('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { m6d_fail('canonical seed failed', $seed); }
rest_get_server();

$limited = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics'),
    1,
    'CI M6 aggregate without SEO scope'
);
if (is_wp_error($limited) || empty($limited['token'])) { m6d_fail('limited credential issuance failed'); }
$denied = m6d_request('GET', 'seo-geo/readiness', (string) $limited['token']);
if (403 !== $denied->get_status() || 'scope_denied' !== (string) ($denied->get_data()['code'] ?? '')) {
    m6d_fail('seo.read was not enforced for aggregate readiness', $denied->get_data());
}

$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array(
        'site.read','site.diagnostics','seo.read','seo.write',
        'research.read','research.write','operations.apply','operations.rollback'
    ),
    1,
    'CI final M6 aggregate readiness'
);
if (is_wp_error($issued) || empty($issued['token'])) { m6d_fail('M6 aggregate credential issuance failed'); }
$token = (string) $issued['token'];

$routes = rest_get_server()->get_routes();
if (! isset($routes['/research-manager/v1/seo-geo/readiness'])) { m6d_fail('aggregate readiness route missing'); }
$cap = m6d_request('GET', 'capabilities', $token);
$cap_data = (array) ($cap->get_data()['data'] ?? array());
$control = (array) ($cap_data['seo_geo_control'] ?? array());
if (200 !== $cap->get_status()
    || 'site.diagnostics + seo.read' !== (string) ($cap_data['read_endpoints']['seo_geo_readiness'] ?? '')
    || empty($control['aggregate_readiness']['available'])
    || empty($control['aggregate_readiness']['read_only'])
    || ! empty($control['aggregate_readiness']['direct_mutation'])
    || 'available' !== (string) ($control['aggregate_geo_readiness'] ?? '')) {
    m6d_fail('aggregate readiness capability discovery incomplete', $cap->get_data());
}

$original_blog_public = (string) get_option('blog_public', '1');
update_option('blog_public', '0', false);

$lines = get_posts(array(
    'post_type'=>'research_line',
    'post_status'=>'publish',
    'posts_per_page'=>1,
    'meta_query'=>array(
        'relation'=>'AND',
        array('key'=>'_research_evidence_status','value'=>'verified','compare'=>'='),
        array('key'=>'_research_language','value'=>'en','compare'=>'='),
    ),
    'suppress_filters'=>true,
));
if (! isset($lines[0]) || ! $lines[0] instanceof WP_Post) { m6d_fail('no verified English Research Line available'); }
$line_id = (int) $lines[0]->ID;

$create_plan = m6d_object_plan($token, 'object-create', array(
    'kind'=>'output',
    'data'=>array(
        'title'=>'M6 Aggregate Research Output',
        'slug'=>'m6-aggregate-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 10),
        'excerpt'=>'Aggregate M6 readiness fixture with a bounded SEO/GEO source contract.',
        'content'=>'<p>Aggregate M6 readiness fixture.</p>',
        'language'=>'en',
        'status'=>'publish',
        'line_ids'=>array(),
        'authors'=>array(),
    ),
    'evidence_confirmed'=>true,
    'evidence_reference'=>'ci:m6:aggregate-output',
));
$create_operation = m6d_object_apply($token, (string) ($create_plan['plan_id'] ?? ''));
$output_id = absint($create_operation['target']['post_id'] ?? 0);
$create_operation_id = (string) ($create_operation['operation_id'] ?? '');
if ($output_id <= 0 || '' === $create_operation_id) { m6d_fail('aggregate fixture creation incomplete', $create_operation); }

m6d_install_rendered_fixture();
$before = m6d_request('GET', 'seo-geo/readiness', $token, array(), array('per_type_limit'=>1));
$before_data = (array) ($before->get_data()['data'] ?? array());
if (200 !== $before->get_status()
    || ! empty($before_data['ready'])
    || (int) ($before_data['blocking_count'] ?? 0) < 1
    || ! m6d_has_action($before_data, 'safe_remediation', 'search-indexability')
    || ! m6d_has_action($before_data, 'evidence_required', 'research-entity-graph', $output_id)
    || empty($before_data['representative']['selection'])) {
    m6d_fail('aggregate readiness did not expose initial blocking/residual actions', $before_data);
}

$index_plan_response = m6d_request('POST', 'seo-geo/remediation/plan', $token, array(
    'check_id'=>'search-indexability',
    'intent'=>'CI restore public Research indexing',
));
if (200 !== $index_plan_response->get_status()) { m6d_fail('indexability plan failed', $index_plan_response->get_data()); }
$index_plan_id = (string) ($index_plan_response->get_data()['data']['plan_id'] ?? '');
$index_apply = m6d_request('POST', 'seo-geo/plans/' . rawurlencode($index_plan_id) . '/apply', $token, array('confirm'=>true));
if (200 !== $index_apply->get_status()) { m6d_fail('indexability apply failed', $index_apply->get_data()); }
$index_operation_id = (string) ($index_apply->get_data()['data']['operation_id'] ?? '');
if ('' === $index_operation_id || '1' !== (string) get_option('blog_public')) { m6d_fail('indexability operation incomplete'); }

$relation_plan = m6d_object_plan($token, 'object-update', array(
    'kind'=>'output',
    'post_id'=>$output_id,
    'changes'=>array('line_ids'=>array($line_id)),
    'evidence_confirmed'=>true,
    'evidence_reference'=>'ci:m6:aggregate-relation',
));
$relation_operation = m6d_object_apply($token, (string) ($relation_plan['plan_id'] ?? ''));
$relation_operation_id = (string) ($relation_operation['operation_id'] ?? '');
if ('' === $relation_operation_id) { m6d_fail('relation operation incomplete', $relation_operation); }

$after = m6d_request('GET', 'seo-geo/readiness', $token, array(), array('per_type_limit'=>1));
$after_data = (array) ($after->get_data()['data'] ?? array());
if (200 !== $after->get_status()
    || empty($after_data['ready'])
    || 0 !== (int) ($after_data['blocking_count'] ?? -1)
    || m6d_has_action($after_data, 'safe_remediation', 'search-indexability')
    || m6d_has_action($after_data, 'evidence_required', 'research-entity-graph', $output_id)
    || empty($after_data['indexability']['ready'])
    || (int) ($after_data['internal_links']['summary']['research_graph_unlinked'] ?? 0) > 0) {
    m6d_fail('aggregate readiness did not converge after bounded corrections', $after_data);
}
if ((int) ($after_data['residual_actions']['summary']['total'] ?? 0) >= (int) ($before_data['residual_actions']['summary']['total'] ?? 0)) {
    m6d_fail('residual action count did not decrease after corrections', array('before'=>$before_data['residual_actions']['summary'],'after'=>$after_data['residual_actions']['summary']));
}

m6d_object_rollback($token, $relation_operation_id);
$index_rollback = m6d_request('POST', 'seo-geo/operations/' . rawurlencode($index_operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $index_rollback->get_status() || '0' !== (string) get_option('blog_public')) {
    m6d_fail('indexability rollback failed', $index_rollback->get_data());
}
m6d_object_rollback($token, $create_operation_id);
if (get_post($output_id) instanceof WP_Post) { m6d_fail('aggregate fixture rollback left a residual Output', $output_id); }
update_option('blog_public', $original_blog_public, false);
remove_all_filters('pre_http_request');

fwrite(STDOUT, "M6 aggregate SEO/GEO readiness and residual-action reporting OK\n");
