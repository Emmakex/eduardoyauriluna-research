<?php
/**
 * #475.22: bounded private, aggregate WP-CLI inventory. Never included in the plugin ZIP.
 * Clone: SA47522_ORIGIN=clone, CI=true, installation /tmp/sa-wp.
 * Production (opt in): SA47522_ORIGIN=production,
 * SA47522_AUTHORIZE_PRODUCTION_READONLY=1, exact HTTPS Eduardo WordPress home.
 * No posts/titles/URLs/media paths/user IDs/tokens or raw option payloads are exported.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Site Architect inventory requires WP-CLI');
}
function sa22_require(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException('Site Architect #475.22: ' . $message); }
}
$origin = (string) getenv('SA47522_ORIGIN');
$home = wp_parse_url((string) get_option('home', ''));
sa22_require(is_array($home), 'WordPress home URL missing');
if ($origin === 'clone') {
    sa22_require(getenv('CI') === 'true'
        && str_contains(ABSPATH, '/tmp/sa-wp/')
        && ($home['scheme'] ?? '') === 'http'
        && ($home['host'] ?? '') === '127.0.0.1'
        && (int) ($home['port'] ?? 0) === 8080,
        'clone guard failed');
} elseif ($origin === 'production') {
    sa22_require(getenv('SA47522_AUTHORIZE_PRODUCTION_READONLY') === '1'
        && ($home['scheme'] ?? '') === 'https'
        && ($home['host'] ?? '') === 'eduardoyauriluna.com'
        && !isset($home['port']), 'production guard failed');
} else {
    throw new RuntimeException('Explicit SA47522_ORIGIN is required');
}
$output = (string) getenv('SA47522_OUTPUT');
sa22_require(preg_match('#^/tmp/sa-47522-[a-z0-9-]+\.json$#', $output) === 1,
    'report must remain in controlled /tmp/sa-47522-*.json');
require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
sa22_require($wpdb instanceof wpdb, 'WordPress database unavailable');
sa22_require($wpdb->query('START TRANSACTION READ ONLY') !== false,
    'MySQL read-only transaction unavailable; refusing inventory');

try {
    $path = WP_PLUGIN_DIR . '/eduardo-research-manager/eduardo-research-manager.php';
    $plugin_active = is_plugin_active('eduardo-research-manager/eduardo-research-manager.php');
    $plugin_sha = is_file($path) ? hash_file('sha256', $path) : false;
    $theme = wp_get_theme();
    $preset = function_exists('eduardo_research_preset') ? eduardo_research_preset() : array();
    $pages = is_array($preset['pages'] ?? null) ? $preset['pages'] : array();
    $languages = array_values(array_filter(array_map(
        'sanitize_key', (array) ($preset['languages'] ?? array())
    )));
    $contract = class_exists('Eduardo_Research_Manager_Contract')
        ? new Eduardo_Research_Manager_Contract() : null;
    $page_service = class_exists('Eduardo_Research_Manager_Page_Resource')
        ? new Eduardo_Research_Manager_Page_Resource() : null;

    $page_inventory = array();
    foreach ($pages as $key => $definition) {
        $key = sanitize_key((string) $key);
        $id = $contract ? $contract->page_id($key) : 0;
        $post = $id > 0 ? get_post($id) : null;
        foreach ($languages as $language) {
            $item = ($post instanceof WP_Post && $page_service)
                ? $page_service->inspect($key, $language) : null;
            $page_inventory[] = array(
                'key'=>$key,
                'language'=>$language,
                'exists'=>$post instanceof WP_Post,
                'status'=>$post instanceof WP_Post ? (string) $post->post_status : 'missing',
                'contract_aligned'=>is_array($item) && !empty($item['contract_aligned']),
                'stored_slot_count'=>is_array($item) ? count((array) ($item['stored_slots'] ?? array())) : 0,
                'allowed_slot_count'=>is_array($item) ? count((array) ($item['allowed_slots'] ?? array())) : 0,
            );
        }
    }

    $types = array('page','post','attachment');
    if (class_exists('Eduardo_Research_Manager_Resource_Registry')) {
        $types = array_values(array_unique(array_merge(
            $types, Eduardo_Research_Manager_Resource_Registry::post_types()
        )));
    }
    $types = array_values(array_filter($types, static fn($v) =>
        is_string($v) && preg_match('/^[a-z0-9_]+$/', $v)));
    $sql = $wpdb->prepare(
        "SELECT post_type, post_status, COUNT(*) AS total FROM {$wpdb->posts} "
        . "WHERE post_type IN (" . implode(',', array_fill(0, count($types), '%s'))
        . ") GROUP BY post_type, post_status", ...$types
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);
    sa22_require(is_array($rows), 'aggregate post read failed');
    $status_counts = array();
    foreach ($rows as $row) {
        $status_counts[(string) $row['post_type']][(string) $row['post_status']] = (int) $row['total'];
    }
    $media_rows = $wpdb->get_results(
        "SELECT post_mime_type AS mime, COUNT(*) AS total FROM {$wpdb->posts} "
        . "WHERE post_type='attachment' GROUP BY post_mime_type", ARRAY_A
    );
    sa22_require(is_array($media_rows), 'aggregate Media read failed');
    $media = array('image'=>0, 'application'=>0, 'other'=>0);
    foreach ($media_rows as $row) {
        $mime = (string) $row['mime'];
        $type = str_starts_with($mime, 'image/') ? 'image'
            : (str_starts_with($mime, 'application/') ? 'application' : 'other');
        $media[$type] += (int) $row['total'];
    }
    $credential = get_option('eduardo_research_manager_remote_connection', array());
    $credential = is_array($credential) ? $credential : array();
    $temporary = get_option('eduardo_research_manager_remote_temporary_connections', array());
    $temporary = is_array($temporary) ? $temporary : array();
    $scopes = is_array($credential['scopes'] ?? null) ? $credential['scopes'] : array();
    $write_count = count(array_filter($scopes, static fn($scope) => is_string($scope)
        && (str_ends_with($scope, '.write')
        || in_array($scope, array('operations.apply','operations.rollback',
            'credentials.manage','external_publish.execute',
            'software_update.execute','destructive_delete.execute'), true))));
    $menu = function_exists('eduardo_research_navigation_state')
        ? eduardo_research_navigation_state() : array();
    $report = array(
        'schema_version'=>1,
        'origin'=>$origin,
        'read_only_transaction'=>true,
        'site_host_verified'=>true,
        'observed_at_utc'=>gmdate(DATE_W3C),
        'plugin'=>array(
            'active'=>$plugin_active,
            'product'=>defined('EDUARDO_RESEARCH_MANAGER_PRODUCT_NAME')
                ? (string) EDUARDO_RESEARCH_MANAGER_PRODUCT_NAME : '',
            'version'=>defined('EDUARDO_RESEARCH_MANAGER_VERSION')
                ? (string) EDUARDO_RESEARCH_MANAGER_VERSION : '',
            'entrypoint_sha256'=>is_string($plugin_sha) ? $plugin_sha : null,
        ),
        'theme'=>array(
            'stylesheet'=>(string) $theme->get_stylesheet(),
            'version'=>(string) $theme->get('Version'),
            'preset_id'=>(string) ($preset['id'] ?? ''),
            'preset_version'=>(int) ($preset['version'] ?? 0),
            'compatible'=>$contract ? $contract->compatible() : false,
            'languages'=>$languages,
            'page_contract_count'=>count($pages),
        ),
        'owned_pages'=>$page_inventory,
        'page_language_count'=>count($page_inventory),
        'aligned_page_language_count'=>count(array_filter(
            $page_inventory, static fn($p) => $p['contract_aligned']
        )),
        'post_status_counts'=>$status_counts,
        'media_type_counts'=>$media,
        'menu_item_counts'=>array(
            'primary'=>count((array) ($menu['primary'] ?? array())),
            'footer'=>count((array) ($menu['footer'] ?? array())),
        ),
        'remote'=>array(
            'primary_configured'=>!empty($credential['connection_id']),
            'primary_enabled'=>!empty($credential['enabled']),
            'credential_present'=>!empty($credential['token_hash']),
            'scope_count'=>count($scopes),
            'write_scope_count'=>$write_count,
            'temporary_active_count'=>count(array_filter($temporary, static fn($v) =>
                is_array($v) && !empty($v['enabled']) && !empty($v['token_hash']))),
        ),
        'excluded_sensitive_fields'=>array(
            'titles','bodies','media_paths','identifiers','users','raw_options',
            'credentials','tokens','token_hashes','connection_ids','scope_names',
        ),
        'certification'=>'PRIVATE_READBACK_ONLY_NOT_CUTOVER',
    );
} finally {
    // Ending the read-only transaction leaves no data modifications.
    $wpdb->query('ROLLBACK');
}
$json = wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
sa22_require(is_string($json) && $json !== '', 'cannot encode redacted report');
sa22_require(file_put_contents($output, $json . "\n", LOCK_EX) !== false,
    'unable to write private temp report');
@chmod($output, 0600);
echo 'PASS: Site Architect #475.22 aggregate readback; origin=' . $origin
    . ' product=' . ($report['plugin']['product'] ?? '')
    . ' version=' . ($report['plugin']['version'] ?? '')
    . ' page-language-count=' . count($report['owned_pages'])
    . ' report=' . $output . "\n";
