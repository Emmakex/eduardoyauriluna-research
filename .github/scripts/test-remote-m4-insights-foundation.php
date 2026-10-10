<?php
/** Fresh-WordPress acceptance for M4 Insight read/editorial foundation. */

wp_set_current_user(1);

function fail_m4_foundation(string $message, mixed $context = null): never {
    fwrite(STDERR, "M4 FOUNDATION FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}

function m4_foundation_request(string $method, string $route, string $token, array $query = array()): WP_REST_Response {
    $request = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $request->set_header('Authorization', 'Bearer ' . $token);
    $request->set_header('X-Research-Manager-Request-Id', 'req-' . wp_generate_uuid4());
    $request->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $request->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($query) { $request->set_query_params($query); }
    return rest_do_request($request);
}

function fake_rendered_insight(int $post_id): void {
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
if (is_wp_error($blueprint)) { fail_m4_foundation('canonical blueprint unavailable', $blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { fail_m4_foundation('canonical seed failed', $seed); }

$create_preview = Eduardo_Research_Manager::insight_editor()->preview_create(array(
    'title'=>'Remote M4 Foundation Insight',
    'slug'=>'remote-m4-foundation-insight',
    'excerpt'=>'Structured excerpt for M4.',
    'content'=>'<p>Structured editorial body for the M4 remote Manager foundation.</p>',
    'language'=>'en',
    'insight_type'=>'research_note',
    'status'=>'draft',
));
if (is_wp_error($create_preview) || empty($create_preview['apply_allowed'])) { fail_m4_foundation('Insight create preview failed', $create_preview); }
$created = Eduardo_Research_Manager::insight_editor()->apply_preview($create_preview);
if (is_wp_error($created) || empty($created['verified']) || empty($created['post_id'])) { fail_m4_foundation('Insight create apply failed', $created); }
$post_id = (int) $created['post_id'];
$create_snapshot = (string) ($created['snapshot_id'] ?? '');

$record = Eduardo_Research_Manager::insight_editor()->inspect($post_id);
if (is_wp_error($record) || 'draft' !== (string) ($record['status'] ?? '')) { fail_m4_foundation('created Insight is not draft', $record); }

rest_get_server();
$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(
    array('site.read','site.diagnostics','operations.apply','operations.rollback'),
    1,
    'CI M4 Insights Foundation'
);
if (is_wp_error($issued) || empty($issued['token'])) { fail_m4_foundation('credential issuance failed'); }
$token = (string) $issued['token'];

$routes = rest_get_server()->get_routes();
foreach (array(
    '/research-manager/v1/insights',
    '/research-manager/v1/insights/(?P<post_id>\d+)',
    '/research-manager/v1/insights/(?P<post_id>\d+)/seo-geo',
) as $route) {
    if (! isset($routes[$route])) { fail_m4_foundation('M4 Insight route missing: ' . $route); }
}

$cap = m4_foundation_request('GET', 'capabilities', $token)->get_data();
if (empty($cap['data']['insight_control']['inventory'])
    || ! in_array('draft', (array) ($cap['data']['insight_control']['statuses'] ?? array()), true)
    || ! in_array('publish', (array) ($cap['data']['insight_control']['statuses'] ?? array()), true)) {
    fail_m4_foundation('capabilities do not advertise M4 Insight foundation', $cap);
}

$inventory = m4_foundation_request('GET', 'insights', $token, array('language'=>'en'));
if (200 !== $inventory->get_status()) { fail_m4_foundation('Insight inventory failed', $inventory->get_data()); }
$items = (array) ($inventory->get_data()['data']['items'] ?? array());
if (! array_filter($items, static fn(array $row): bool => $post_id === (int) ($row['post_id'] ?? 0))) {
    fail_m4_foundation('created Insight missing from remote inventory', $inventory->get_data());
}

$inspect = m4_foundation_request('GET', 'insights/' . $post_id, $token);
if (200 !== $inspect->get_status() || 'draft' !== (string) ($inspect->get_data()['data']['status'] ?? '')) {
    fail_m4_foundation('remote Insight inspection failed', $inspect->get_data());
}

$draft_seo = m4_foundation_request('GET', 'insights/' . $post_id . '/seo-geo', $token);
if (200 !== $draft_seo->get_status()
    || ! empty($draft_seo->get_data()['data']['rendered']['available'])
    || empty($draft_seo->get_data()['data']['stored_ready'])) {
    fail_m4_foundation('draft Insight SEO/GEO inspection contract invalid', $draft_seo->get_data());
}

$publish_preview = Eduardo_Research_Manager::insight_editor()->preview_update($post_id, array('status'=>'publish'));
if (is_wp_error($publish_preview) || empty($publish_preview['apply_allowed'])) { fail_m4_foundation('publish preview failed', $publish_preview); }
$publish = Eduardo_Research_Manager::insight_editor()->apply_preview($publish_preview);
if (is_wp_error($publish) || empty($publish['verified'])) { fail_m4_foundation('publish apply failed', $publish); }
$publish_snapshot = (string) ($publish['snapshot_id'] ?? '');
$published = Eduardo_Research_Manager::insight_editor()->inspect($post_id);
if (is_wp_error($published) || 'publish' !== (string) ($published['status'] ?? '')) { fail_m4_foundation('Insight did not publish', $published); }

fake_rendered_insight($post_id);
$published_seo = m4_foundation_request('GET', 'insights/' . $post_id . '/seo-geo', $token);
remove_all_filters('pre_http_request');
if (200 !== $published_seo->get_status()
    || empty($published_seo->get_data()['data']['rendered']['available'])
    || empty($published_seo->get_data()['data']['rendered']['verified'])) {
    fail_m4_foundation('published Insight rendered verification failed', $published_seo->get_data());
}

if ('' === $publish_snapshot) { fail_m4_foundation('publish transition produced no rollback snapshot'); }
$rollback_publish = Eduardo_Research_Manager::insight_editor()->rollback($publish_snapshot);
if (is_wp_error($rollback_publish)) { fail_m4_foundation('publish rollback failed', $rollback_publish->get_error_message()); }
$restored = Eduardo_Research_Manager::insight_editor()->inspect($post_id);
if (is_wp_error($restored) || 'draft' !== (string) ($restored['status'] ?? '')) { fail_m4_foundation('publish rollback did not restore draft', $restored); }

if ('' === $create_snapshot) { fail_m4_foundation('Insight creation produced no rollback snapshot'); }
$rollback_create = Eduardo_Research_Manager::insight_editor()->rollback($create_snapshot);
if (is_wp_error($rollback_create)) { fail_m4_foundation('creation rollback failed', $rollback_create->get_error_message()); }
if (get_post($post_id) instanceof WP_Post) { fail_m4_foundation('creation rollback did not remove Manager-owned Insight'); }

fwrite(STDOUT, "M4 Insight foundation OK\n");
