<?php
/**
 * Site Architect #475.19: full HTML/HTTP evidence from the independently exported ZIP.
 * Run only via WP-CLI eval-file in the disposable /tmp/sa-wp clone with a real HTTP server.
 * No production/domain access; no mutations beyond reversible clone-only hydration/navigation.
 */
if (! defined('ABSPATH') || ! defined('WP_CLI') || ! WP_CLI
    || getenv('CI') !== 'true' || ! str_contains(ABSPATH, '/tmp/sa-wp/')) {
    throw new RuntimeException('Refusing HTTP governance acceptance outside disposable CI WP-CLI');
}
function sa_47519_assert(bool $ok, string $message): void {
    if (! $ok) { throw new RuntimeException('Site Architect #475.19 HTTP regression: ' . $message); }
}
function sa_47519_result(mixed $result, string $action): array {
    if (is_wp_error($result)) {
        throw new RuntimeException($action . ': ' . $result->get_error_code() . ': ' . $result->get_error_message());
    }
    sa_47519_assert(is_array($result), $action . ' must return an array');
    return $result;
}
function sa_47519_local(string $url): void {
    $parts = wp_parse_url($url);
    sa_47519_assert(
        is_array($parts) && ($parts['scheme'] ?? '') === 'http'
        && ($parts['host'] ?? '') === '127.0.0.1'
        && (int) ($parts['port'] ?? 0) === 8080,
        'Network target must be the isolated localhost:8080 WordPress, never a public host'
    );
}

wp_set_current_user(1);
require_once WP_PLUGIN_DIR . '/eduardo-research-manager/includes/class-research-manager-site-profile.php';
sa_47519_assert((new Eduardo_Research_Manager_Site_Profile())->id() === 'research-theme',
    'Research Theme is the explicit active profile');
sa_47519_local(home_url('/'));
$contract = Eduardo_Research_Manager::contract();
sa_47519_assert($contract->compatible(), 'Research Theme Contract compatible');
sa_47519_assert(EDUARDO_RESEARCH_MANAGER_VERSION === '0.11.1'
    && EDUARDO_RESEARCH_MANAGER_PRODUCT_NAME === 'Site Architect', 'full real plugin loaded');

$blueprint_path = (string) getenv('GITHUB_WORKSPACE') . '/config/research-site-blueprint.json';
$blueprint = json_decode((string) file_get_contents($blueprint_path), true);
sa_47519_assert(is_array($blueprint), 'canonical Research Blueprint available');
$preview = sa_47519_result(Eduardo_Research_Manager::hydrator()->preview($blueprint), 'Hydration Preview');
sa_47519_assert(! empty($preview['apply_allowed']), 'hydration Preview authorized by Theme');
$hydrate = sa_47519_result(Eduardo_Research_Manager::hydrator()->apply($blueprint), 'Hydration Apply');
sa_47519_assert(! empty($hydrate['verified']) && count((array) ($hydrate['snapshot_ids'] ?? array())) === 25,
    'identity + 24 language slots applied in isolated clone');

$report = array('theme'=>'research','product'=>'Site Architect 0.11.1',
    'pages_verified'=>array(), 'pages_not_public'=>array(), 'navigation'=>array(),
    'rendered_checks'=>array(), 'pass'=>false);
try {
    $verifier = Eduardo_Research_Manager::rendered();
    $languages = $contract->languages();
    $pages = $contract->pages();
    sa_47519_assert(count($pages) === 12 && $languages === array('en', 'es'),
        '12 Research Theme pages × EN/ES contract');
    $mandatory = array('home','about','cv','contact');
    foreach (array_keys($pages) as $key) {
        foreach ($languages as $language) {
            $key = (string) $key;
            $page = $contract->page_state($key);
            $available = function_exists('eduardo_research_page_is_publicly_available')
                ? eduardo_research_page_is_publicly_available($key, $language) : true;
            $published = (string) ($page['status'] ?? '') === 'publish';
            $url = (string) eduardo_research_page_url($key, $language);
            sa_47519_local($url);
            if (! $published || ! $available) {
                // Suppressed empty Research collections and draft legal Pages
                // are not falsely represented as HTTP-200 ready.
                $reason = ! $published ? 'draft' : 'collection_without_public_records';
                $report['pages_not_public'][$key . '/' . $language] = $reason;
                if (in_array($key, $mandatory, true)) {
                    throw new RuntimeException('Mandatory Page unavailable: ' . $key . '/' . $language . ' reason=' . $reason);
                }
                continue;
            }

            $rendered = sa_47519_result($verifier->verify_page($key, $language), 'HTTP render ' . $key . '/' . $language);
            $checks = is_array($rendered['checks'] ?? null) ? $rendered['checks'] : array();
            $failed = array_keys(array_filter($checks, static fn($v): bool => $v !== true));
            $html = (string) ($rendered['body'] ?? '');
            $extra = array(
                'html5_doctype'=>stripos($html, '<!doctype html>') !== false,
                'research_header'=>strpos($html, 'class="research-site-header"') !== false,
                'primary_navigation'=>strpos($html, 'id="research-primary-nav"') !== false,
                'footer_navigation'=>strpos($html, 'class="research-footer-nav"') !== false,
                'stylesheet'=>strpos($html, '/wp-content/themes/eduardo-research/style.css') !== false,
            );
            foreach ($extra as $check => $ok) {
                if (! $ok) { $failed[] = $check; }
            }
            if ($failed) {
                throw new RuntimeException(
                    'Rendered Page ' . $key . '/' . $language . ' URL=' . $url
                    . ' HTTP=' . ($rendered['status'] ?? '?')
                    . ' failed_checks=' . implode(',', $failed)
                );
            }
            $report['pages_verified'][$key . '/' . $language] = 200;
            foreach (array_keys($checks + $extra) as $check) { $report['rendered_checks'][$check] = true; }
        }
    }
    sa_47519_assert(count($report['pages_verified']) >= 8,
        'at least eight rendered language/page URLs must pass real HTTP assertions');
    sa_47519_assert(isset($report['pages_not_public']['privacy-policy/en']),
        'WordPress draft Privacy page must not be mislabeled as published');

    // Verify a REAL rendered navigation update, not only an option-state update.
    // The footer is the bounded Theme contract location with two stable legal
    // targets. Reorder it in the disposable clone, inspect both rendered
    // languages, then restore the exact state using the plugin rollback.
    $nav = new Eduardo_Research_Manager_Navigation();
    $baseline = sa_47519_result($nav->inspect(), 'navigation inspect');
    $footer = (array) ($baseline['state']['footer'] ?? array());
    sa_47519_assert(count($footer) >= 2, 'footer has at least two Theme-owned legal targets');
    $actor = array(
        'connection_id'=>'sa-47519-ci-local-only',
        'wordpress_user_id'=>get_current_user_id(),
        'request_id'=>'sa-47519-rendered-acceptance',
    );
    $plan = sa_47519_result(
        $nav->create_plan('footer', array_reverse($footer), 'Reversible rendered HTTP footer navigation', $actor),
        'Navigation Preview'
    );
    $op = sa_47519_result($nav->apply((string) $plan['plan_id'], true, $actor), 'Navigation Apply');
    $op_id = (string) ($op['operation_id'] ?? '');
    try {
        sa_47519_assert($op_id !== '', 'Navigation operation ID recorded');
        $verified = sa_47519_result($nav->verify($op_id, true, $actor), 'Navigation rendered Verify');
        if (empty($verified['verification']['verified'])) {
            throw new RuntimeException(
                'Navigation rendered Verify mismatch: '
                . wp_json_encode((array) ($verified['verification'] ?? array()))
            );
        }
        $report['navigation']['rendered_en_es'] = true;
    } finally {
        if ($op_id !== '') {
            $rolled = sa_47519_result($nav->rollback($op_id, true, $actor), 'Navigation Rollback');
            sa_47519_assert(
                ($rolled['status'] ?? '') === 'rolled-back'
                && eduardo_research_navigation_state() === $baseline['state'],
                'navigation state restored exactly after rendered HTTP Verify'
            );
            $report['navigation']['rollback_verified'] = true;
        }
    }
    $report['pass'] = true;
} finally {
    $rollback = sa_47519_result(Eduardo_Research_Manager::hydrator()->rollback((array) $hydrate['snapshot_ids']),
        'Hydration Rollback');
    sa_47519_assert((int) ($rollback['snapshot_count'] ?? 0) === 25,
        'hydration snapshot rollback restores original clone');
    sa_47519_assert(get_option('eduardo_research_identity', false) === false,
        'isolated identity does not survive rollback');
    $report['hydrate_rollback_verified'] = true;
    $path = '/tmp/sa-47519-http-report.json';
    file_put_contents($path, (string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
sa_47519_assert($report['pass'] === true, 'all HTTP verification gates passed');
echo 'PASS: Site Architect #475.19 HTTP rendered Research Theme clone: '
    . count($report['pages_verified']) . ' 200/SEO/HTML pages, '
    . count($report['pages_not_public']) . ' correctly unready surfaces, '
    . 'real EN/ES footer navigation rendered Verify + Rollback, hydration Rollback; '
    . 'report=/tmp/sa-47519-http-report.json' . "\n";
