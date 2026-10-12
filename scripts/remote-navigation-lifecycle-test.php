#!/usr/bin/env php
<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

final class WP_Error {
    public function __construct(private string $code, private string $message, private array $data = array()) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): array { return $this->data; }
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?? ''; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function wp_json_encode(mixed $value): string|false { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
function wp_generate_uuid4(): string { static $i = 0; ++$i; return sprintf('00000000-0000-4000-8000-%012d', $i); }
function current_user_can(string $capability): bool { return 'manage_options' === $capability; }

$GLOBALS['erm_options'] = array();
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['erm_options'][$key] ?? $default; }
function update_option(string $key, mixed $value, bool $autoload = false): bool { $GLOBALS['erm_options'][$key] = $value; return true; }

final class Eduardo_Research_Manager_Remote_Audit {
    public array $events = array();
    public function record(array $entry): array { $this->events[] = $entry; return $entry; }
}

function eduardo_research_navigation_contract(): array {
    return array(
        'version'=>1,
        'locations'=>array(
            'primary'=>array('default'=>array('about','research','publications','contact'),'min_items'=>1),
            'footer'=>array('default'=>array('privacy-policy','legal-notice'),'min_items'=>1),
        ),
        'allowed_targets'=>array('home','about','research','publications','contact','privacy-policy','legal-notice'),
        'arbitrary_urls'=>false,
    );
}
function eduardo_research_navigation_sanitize_state(array $candidate): array {
    $contract = eduardo_research_navigation_contract();
    $allowed = array_fill_keys($contract['allowed_targets'], true);
    $state = array();
    foreach ($contract['locations'] as $location => $spec) {
        $items = isset($candidate[$location]) && is_array($candidate[$location]) ? $candidate[$location] : $spec['default'];
        $clean = array();
        foreach ($items as $item) {
            $key = sanitize_key((string) $item);
            if (isset($allowed[$key]) && ! in_array($key, $clean, true)) { $clean[] = $key; }
        }
        $state[$location] = $clean ?: $spec['default'];
    }
    return $state;
}
function eduardo_research_navigation_state(): array {
    $stored = get_option('eduardo_research_navigation', array());
    return eduardo_research_navigation_sanitize_state(is_array($stored) ? $stored : array());
}

require_once __DIR__ . '/../wordpress/plugins/eduardo-research-manager/includes/class-research-manager-navigation.php';

function check(bool $condition, string $message): void {
    if (! $condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$audit = new Eduardo_Research_Manager_Remote_Audit();
$navigation = new Eduardo_Research_Manager_Navigation($audit);
$actor = array('connection_id'=>'rmt_navigation_test','wordpress_user_id'=>1,'request_id'=>'req-nav-1');
$baseline = eduardo_research_navigation_state();

$invalid = $navigation->create_plan('primary', array('about','https://evil.example/'), 'invalid external target', $actor);
check(is_wp_error($invalid) && 'validation_failed' === $invalid->get_error_code(), 'arbitrary URL-like targets must be rejected');

$desired = array('research','about','publications','contact');
$plan = $navigation->create_plan('primary', $desired, 'reorder primary navigation', $actor);
check(is_array($plan), 'valid navigation Preview must succeed');
check('navigation-update' === $plan['operation'], 'wrong operation name');
check(true === $plan['preview']['reordered'], 'reorder must be reported in Preview');
check(false === $plan['preview']['arbitrary_urls'], 'Preview must preserve arbitrary URL boundary');

$operation = $navigation->apply($plan['plan_id'], true, $actor);
check(is_array($operation) && 'applied' === $operation['status'], 'Apply must succeed');
check($desired === eduardo_research_navigation_state()['primary'], 'Apply must persist exact bounded order');

$verified = $navigation->verify($operation['operation_id'], false, $actor);
check(is_array($verified) && 'verified' === $verified['status'], 'stored-state Verify must succeed');
check(true === $verified['verification']['state_matches'], 'Verify must match exact state');

$rolled = $navigation->rollback($operation['operation_id'], true, $actor);
check(is_array($rolled) && 'rolled-back' === $rolled['status'], 'Rollback must succeed');
check($baseline === eduardo_research_navigation_state(), 'Rollback must restore exact baseline');

$stalePlan = $navigation->create_plan('footer', array('legal-notice','privacy-policy'), 'stale proof', array_merge($actor, array('request_id'=>'req-nav-2')));
check(is_array($stalePlan), 'stale Preview setup must succeed');
update_option('eduardo_research_navigation', array('primary'=>array('about','contact'),'footer'=>$baseline['footer']), false);
$staleApply = $navigation->apply($stalePlan['plan_id'], true, $actor);
check(is_wp_error($staleApply) && 'stale_revision' === $staleApply->get_error_code(), 'Apply must reject stale navigation Preview');

check(count($audit->events) >= 4, 'navigation lifecycle must emit audit events');
foreach ($audit->events as $event) { check('pages.write' === ($event['scope'] ?? ''), 'navigation audit must use established pages.write scope'); }

fwrite(STDOUT, "Bounded navigation lifecycle: PASS\n");
