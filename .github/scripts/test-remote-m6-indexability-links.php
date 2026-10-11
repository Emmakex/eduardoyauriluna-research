<?php
/** Fresh-WordPress acceptance for M6c indexability, robots, sitemap and internal-link diagnostics. */

wp_set_current_user(1);

function m6c_fail(string $message, mixed $context = null): never {
    fwrite(STDERR, "M6C FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m6c_request(
    string $method,
    string $route,
    string $token,
    array $body = array(),
    array $query = array(),
    string $request_id = ''
): WP_REST_Response {
    $request = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', '' !== $request_id ? $request_id : 'req-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($query) { $request->set_query_params($query); }
    if ($body) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    return rest_do_request($request);
}

function m6c_fake_page_http(string $url, string $language = 'en', string $robots = 'index, follow'): void {
    add_filter('pre_http_request', static function ($preempt, $args, $request_url) use ($url, $language, $robots) {
        if (untrailingslashit((string) $request_url) !== untrailingslashit($url)) { return $preempt; }
        $safe = esc_url($url);
        $body = '<!doctype html><html lang="' . esc_attr($language) . '"><head>'
            . '<link rel="canonical" href="' . $safe . '">'
            . '<link rel="alternate" hreflang="en" href="' . $safe . '">'
            . '<link rel="alternate" hreflang="es" href="' . $safe . '">'
            . '<link rel="alternate" hreflang="x-default" href="' . $safe . '">'
            . '<meta property="og:url" content="' . $safe . '">'
            . '<meta name="robots" content="' . esc_attr($robots) . '">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebPage"}</script>'
            . '</head><body><main>Research page</main></body></html>';
        return array(
            'headers'=>array(),
            'body'=>$body,
            'response'=>array('code'=>200,'message'=>'OK'),
            'cookies'=>array(),
            'filename'=>null,
        );
    }, 10, 3);
}

function m6c_object_plan(string $token, string $operation, array $payload): array {
    $response = m6c_request('POST', 'research-objects/plan', $token, array('operation'=>$operation,'payload'=>$payload));
    if (200 !== $response->get_status()) { m6c_fail('Research Object plan failed', $response->get_data()); }
    return (array) ($response->get_data()['data'] ?? array());
}

function m6c_object_apply(string $token, string $plan_id): array {
    $response = m6c_request('POST', 'research-objects/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
    if (200 !== $response->get_status()) { m6c_fail('Research Object Apply failed', $response->get_data()); }
    return (array) ($response->get_data()['data'] ?? array());
}

function m6c_object_rollback(string $token, string $operation_id): array {
    $response = m6c_request('POST', 'research-objects/operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
    if (200 !== $response->get_status()) { m6c_fail('Research Object Rollback failed', $response->get_data()); }
    return (array) ($response->get_data()['data'] ?? array());
}

function m6c_find_record(array $rows, int $post_id): array {
    foreach ($rows as $row) {
        if (is_array($row) && $post_id === (int) ($row['post_id'] ?? 0)) { return $row; }
    }
    return array();
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { m6c_fail('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { m6c_fail('canonical seed failed', $seed); }
rest_get_server();

// SEO scope remains independent from generic diagnostics.
$limited = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','operations.apply','operations.rollback'),
    1,
    'CI M6c without SEO scope'
);
if (is_wp_error($limited) || empty($limited['token'])) { m6c_fail('limited credential issuance failed'); }
$denied = m6c_request('GET', 'seo-geo/indexability', (string) $limited['token']);
if (403 !== $denied->get_status() || 'scope_denied' !== (string) ($denied->get_data()['code'] ?? '')) {
    m6c_fail('seo.read was not enforced for indexability', $denied->get_data());
}

$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array(
        'site.read','site.diagnostics','seo.read','seo.write',
        'research.read','research.write','operations.apply','operations.rollback'
    ),
    1,
    'CI M6c indexability and links'
);
if (is_wp_error($issued) || empty($issued['token'])) { m6c_fail('M6c credential issuance failed'); }
$token = (string) $issued['token'];

$routes = rest_get_server()->get_routes();
foreach (array(
    '/research-manager/v1/seo-geo/indexability',
    '/research-manager/v1/seo-geo/indexability/resource',
    '/research-manager/v1/seo-geo/internal-links',
) as $route) {
    if (! isset($routes[$route])) { m6c_fail('M6c route missing: ' . $route); }
}

$cap = m6c_request('GET', 'capabilities', $token);
$control = (array) ($cap->get_data()['data']['seo_geo_control'] ?? array());
if (200 !== $cap->get_status()
    || 'M6' !== (string) ($control['milestone'] ?? '')
    || empty($control['indexability']['global_search_visibility'])
    || empty($control['indexability']['robots_rendered_inspection'])
    || empty($control['indexability']['exploration_noindex_follow_policy'])
    || empty($control['internal_links']['research_graph_diagnostics'])
    || ! empty($control['internal_links']['direct_html_mutation'])
    || ! empty($control['arbitrary_meta_editor'])) {
    m6c_fail('M6c capability discovery incomplete', $cap->get_data());
}

// Global WordPress search visibility is an exact auto-remediable SEO readiness finding.
$original_blog_public = (string) get_option('blog_public', '1');
update_option('blog_public', '0', false);

$indexability = m6c_request('GET', 'seo-geo/indexability', $token);
$indexability_data = (array) ($indexability->get_data()['data'] ?? array());
if (200 !== $indexability->get_status()
    || ! empty($indexability_data['search_engine_visibility'])
    || ! empty($indexability_data['ready'])
    || 'search-indexability' !== (string) ($indexability_data['diagnostic_check'] ?? '')
    || empty($indexability_data['exploration_policy']['registered'])) {
    m6c_fail('global indexability inspection failed in blocked state', $indexability->get_data());
}

$site = m6c_request('GET', 'seo-geo/site', $token);
$site_data = (array) ($site->get_data()['data'] ?? array());
$indexability_candidate = false;
foreach ((array) ($site_data['remediation']['auto_remediable'] ?? array()) as $row) {
    if (is_array($row) && 'search-indexability' === (string) ($row['check_id'] ?? '')) { $indexability_candidate = true; }
}
if (! $indexability_candidate) { m6c_fail('search-indexability was not exposed as auto-remediable', $site_data); }

$plan_response = m6c_request('POST', 'seo-geo/remediation/plan', $token, array(
    'check_id'=>'search-indexability',
    'intent'=>'CI enable public Research indexing',
));
if (200 !== $plan_response->get_status()) { m6c_fail('search-indexability Plan failed', $plan_response->get_data()); }
$plan = (array) ($plan_response->get_data()['data'] ?? array());
$plan_id = (string) ($plan['plan_id'] ?? '');
if ('' === $plan_id || empty($plan['apply_allowed']) || '0' !== (string) get_option('blog_public')) {
    m6c_fail('search-indexability Preview contract invalid or mutated state', $plan);
}

$apply = m6c_request('POST', 'seo-geo/plans/' . rawurlencode($plan_id) . '/apply', $token, array('confirm'=>true));
if (200 !== $apply->get_status()) { m6c_fail('search-indexability Apply failed', $apply->get_data()); }
$operation = (array) ($apply->get_data()['data'] ?? array());
$operation_id = (string) ($operation['operation_id'] ?? '');
if ('' === $operation_id || '1' !== (string) get_option('blog_public')) {
    m6c_fail('search-indexability Apply did not enable indexing', $operation);
}

$verify = m6c_request('POST', 'seo-geo/operations/' . rawurlencode($operation_id) . '/verify', $token, array('rendered'=>false));
if (200 !== $verify->get_status() || empty($verify->get_data()['data']['stored_verification']['verified'])) {
    m6c_fail('search-indexability stored Verify failed', $verify->get_data());
}

$after = m6c_request('GET', 'seo-geo/indexability', $token);
$after_data = (array) ($after->get_data()['data'] ?? array());
if (200 !== $after->get_status()
    || empty($after_data['search_engine_visibility'])
    || empty($after_data['core_sitemaps_enabled'])
    || empty($after_data['localized_page_sitemap_provider'])
    || empty($after_data['ready'])) {
    m6c_fail('indexability did not recover after remediation', $after->get_data());
}

// Rendered resource inspection distinguishes canonical indexable content from a noindex response.
$home_url = function_exists('eduardo_research_page_url') ? (string) eduardo_research_page_url('home', 'en') : home_url('/');
m6c_fake_page_http($home_url, 'en', 'index, follow');
$resource_index = m6c_request('GET', 'seo-geo/indexability/resource', $token, array(), array('type'=>'page','key'=>'home','language'=>'en'));
remove_all_filters('pre_http_request');
$resource_data = (array) ($resource_index->get_data()['data'] ?? array());
if (200 !== $resource_index->get_status()
    || empty($resource_data['rendered_checked'])
    || empty($resource_data['indexable'])
    || ! empty($resource_data['noindex_detected'])) {
    m6c_fail('canonical Page indexability inspection failed', $resource_index->get_data());
}

m6c_fake_page_http($home_url, 'en', 'noindex, follow');
$resource_noindex = m6c_request('GET', 'seo-geo/indexability/resource', $token, array(), array('type'=>'page','key'=>'home','language'=>'en'));
remove_all_filters('pre_http_request');
$resource_noindex_data = (array) ($resource_noindex->get_data()['data'] ?? array());
if (200 !== $resource_noindex->get_status()
    || empty($resource_noindex_data['noindex_detected'])
    || ! empty($resource_noindex_data['indexable'])) {
    m6c_fail('rendered noindex was not detected', $resource_noindex->get_data());
}

$rollback = m6c_request('POST', 'seo-geo/operations/' . rawurlencode($operation_id) . '/rollback', $token, array('confirm'=>true));
if (200 !== $rollback->get_status()
    || 'rolled-back' !== (string) ($rollback->get_data()['data']['status'] ?? '')
    || '0' !== (string) get_option('blog_public')) {
    m6c_fail('search-indexability Rollback did not restore blocked baseline', $rollback->get_data());
}
update_option('blog_public', $original_blog_public, false);

// Internal-link diagnostics route an unlinked public Research Object to the existing M5 gateway.
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
if (! isset($lines[0]) || ! $lines[0] instanceof WP_Post) { m6c_fail('no verified English Research Line available'); }
$line_id = (int) $lines[0]->ID;

$create_plan = m6c_object_plan($token, 'object-create', array(
    'kind'=>'output',
    'data'=>array(
        'title'=>'M6c Unlinked Research Output',
        'slug'=>'m6c-unlinked-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 10),
        'excerpt'=>'M6c internal-link diagnostic fixture.',
        'content'=>'<p>Research output used to verify bounded internal-link remediation.</p>',
        'language'=>'en',
        'status'=>'publish',
        'line_ids'=>array(),
        'authors'=>array(),
    ),
    'evidence_confirmed'=>true,
    'evidence_reference'=>'ci:m6c:output-create',
));
if (empty($create_plan['apply_allowed'])) { m6c_fail('fixture creation plan unexpectedly blocked', $create_plan); }
$create_operation = m6c_object_apply($token, (string) $create_plan['plan_id']);
$output_id = (int) ($create_operation['target']['post_id'] ?? 0);
$create_operation_id = (string) ($create_operation['operation_id'] ?? '');
if ($output_id <= 0 || '' === $create_operation_id) { m6c_fail('fixture creation result incomplete', $create_operation); }

$links_before = m6c_request('GET', 'seo-geo/internal-links', $token);
$before_data = (array) ($links_before->get_data()['data'] ?? array());
$before_row = m6c_find_record((array) ($before_data['records'] ?? array()), $output_id);
if (200 !== $links_before->get_status()
    || 'advisory' !== (string) ($before_row['status'] ?? '')
    || ! empty($before_row['research_graph_connected'])
    || 'research-objects' !== (string) ($before_row['action']['gateway'] ?? '')
    || 'object-update' !== (string) ($before_row['action']['operation'] ?? '')
    || 'line_ids' !== (string) ($before_row['action']['target']['field'] ?? '')
    || empty($before_row['action']['requires_evidence'])
    || ! empty($before_data['direct_html_link_mutation'])) {
    m6c_fail('unlinked Research Object diagnostic/action contract invalid', $links_before->get_data());
}

$update_plan = m6c_object_plan($token, 'object-update', array(
    'kind'=>'output',
    'post_id'=>$output_id,
    'changes'=>array('line_ids'=>array($line_id)),
    'evidence_confirmed'=>true,
    'evidence_reference'=>'ci:m6c:line-relation',
));
if (empty($update_plan['apply_allowed'])) { m6c_fail('relation update plan blocked', $update_plan); }
$update_operation = m6c_object_apply($token, (string) $update_plan['plan_id']);
$update_operation_id = (string) ($update_operation['operation_id'] ?? '');
if ('' === $update_operation_id) { m6c_fail('relation update missing operation ID', $update_operation); }

$links_after = m6c_request('GET', 'seo-geo/internal-links', $token);
$after_links_data = (array) ($links_after->get_data()['data'] ?? array());
$after_row = m6c_find_record((array) ($after_links_data['records'] ?? array()), $output_id);
if (200 !== $links_after->get_status()
    || 'pass' !== (string) ($after_row['status'] ?? '')
    || empty($after_row['research_graph_connected'])
    || ! in_array($line_id, array_map('absint', (array) ($after_row['verified_line_ids'] ?? array())), true)) {
    m6c_fail('internal-link diagnostic did not pass after bounded relation update', $links_after->get_data());
}
$line_seen = false;
foreach ((array) ($after_links_data['lines'] ?? array()) as $line_row) {
    if (is_array($line_row) && $line_id === (int) ($line_row['post_id'] ?? 0) && (int) ($line_row['related_object_count'] ?? 0) >= 1) {
        $line_seen = true;
    }
}
if (! $line_seen) { m6c_fail('reverse Research Line related-object graph was not observed', $after_links_data); }

m6c_object_rollback($token, $update_operation_id);
$links_rolled = m6c_request('GET', 'seo-geo/internal-links', $token);
$rolled_row = m6c_find_record((array) ($links_rolled->get_data()['data']['records'] ?? array()), $output_id);
if ('advisory' !== (string) ($rolled_row['status'] ?? '') || ! empty($rolled_row['research_graph_connected'])) {
    m6c_fail('relation rollback did not restore unlinked baseline', $links_rolled->get_data());
}

m6c_object_rollback($token, $create_operation_id);
if (get_post($output_id) instanceof WP_Post) { m6c_fail('fixture creation rollback did not remove Research Output', $output_id); }

fwrite(STDOUT, "M6c indexability, robots, sitemap and internal-link diagnostics OK\n");
