#!/usr/bin/env php
<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$GLOBALS['erm_test_options'] = array();
function add_action(...$args): void {}
function add_filter(...$args): void {}
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?? ''; }
function wp_generate_uuid4(): string { static $i = 0; ++$i; return sprintf('00000000-0000-4000-8000-%012d', $i); }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['erm_test_options'][$key] ?? $default; }
function add_option(string $key, mixed $value, string $deprecated = '', bool $autoload = false): bool { $GLOBALS['erm_test_options'][$key] = $value; return true; }
function update_option(string $key, mixed $value, bool $autoload = false): bool { $GLOBALS['erm_test_options'][$key] = $value; return true; }
function delete_option(string $key): bool { unset($GLOBALS['erm_test_options'][$key]); return true; }

require_once __DIR__ . '/../wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-audit.php';

function check(bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$audit = new Eduardo_Research_Manager_Remote_Audit();

$first = $audit->record(array(
    'request_id'=>'req-a',
    'connection_id'=>'conn-1',
    'wordpress_user_id'=>1,
    'method'=>'GET',
    'route'=>'/research-manager/v1/status',
    'scope'=>'site.read',
    'event'=>'remote-read',
    'outcome'=>'success',
    'operation_id'=>'',
    'plan_id'=>'',
    'reason'=>'read status',
    'token'=>'must-never-be-stored',
    'authorization'=>'Bearer must-never-be-stored',
));
$audit->record(array(
    'request_id'=>'req-b',
    'connection_id'=>'conn-2',
    'wordpress_user_id'=>1,
    'method'=>'POST',
    'route'=>'/research-manager/v1/operations/plan',
    'scope'=>'operations.apply',
    'event'=>'remote-request-rejected',
    'outcome'=>'blocked',
    'error_code'=>'scope_denied',
    'operation_id'=>'op-b',
    'plan_id'=>'plan-b',
    'reason'=>'negative permission proof',
));
$audit->record(array(
    'request_id'=>'req-c',
    'connection_id'=>'conn-1',
    'wordpress_user_id'=>1,
    'method'=>'GET',
    'route'=>'/research-manager/v1/diagnostics',
    'scope'=>'site.diagnostics',
    'event'=>'remote-read',
    'outcome'=>'success',
    'operation_id'=>'op-c',
));

foreach (array('token','token_hash','authorization','password','secret','cookie') as $forbidden) {
    check(! array_key_exists($forbidden, $first), "audit entry exposed forbidden key {$forbidden}");
}

$by_request = $audit->query(array('request_id'=>'req-b'));
check(1 === $by_request['total'], 'request_id filter must return one record');
check('req-b' === $by_request['items'][0]['request_id'], 'request_id filter returned wrong record');
check('scope_denied' === $by_request['items'][0]['error_code'], 'correlated denial lost error code');

$by_operation = $audit->query(array('operation_id'=>'op-c'));
check(1 === $by_operation['total'], 'operation_id filter must return one record');
check('req-c' === $by_operation['items'][0]['request_id'], 'operation_id filter returned wrong record');

$by_connection = $audit->query(array('connection_id'=>'conn-1'));
check(2 === $by_connection['total'], 'connection_id filter must return two records');

$blocked = $audit->query(array('outcome'=>'blocked'));
check(1 === $blocked['total'], 'outcome filter must return one blocked record');
check('remote-request-rejected' === $blocked['items'][0]['event'], 'blocked filter lost event');

$page1 = $audit->query(array('page'=>1, 'per_page'=>2));
$page2 = $audit->query(array('page'=>2, 'per_page'=>2));
check(3 === $page1['total'] && 2 === count($page1['items']), 'page 1 pagination mismatch');
check(2 === $page1['total_pages'], 'total_pages mismatch');
check(1 === count($page2['items']), 'page 2 pagination mismatch');

$bounded = $audit->query(array('per_page'=>1000));
check(100 === $bounded['per_page'], 'per_page must be capped at 100');

$empty = $audit->query(array('request_id'=>'missing'));
check(0 === $empty['total'] && 0 === $empty['total_pages'] && array() === $empty['items'], 'empty result contract mismatch');

fwrite(STDOUT, "Remote audit read surface contract: PASS\n");
