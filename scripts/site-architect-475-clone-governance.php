<?php
/**
 * Site Architect #475.18: isolated governance acceptance of the *exported* 0.11.1 plugin.
 * Invoke through wp eval-file after installing the independent source candidate ZIP.
 * This test MUST NEVER run on a production WordPress installation.
 */
if (! defined('ABSPATH') || ! defined('WP_CLI') || ! WP_CLI) {
    throw new RuntimeException('Isolated WP-CLI only');
}
if (getenv('CI') !== 'true' || ! str_contains(ABSPATH, '/tmp/sa-wp/')) {
    throw new RuntimeException('Refusing operations outside the disposable CI WordPress clone');
}

function site_architect_47518_assert(bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException('Site Architect #475.18 clone regression: ' . $message);
    }
}
function site_architect_47518_ok(mixed $result, string $action): array {
    if (is_wp_error($result)) {
        throw new RuntimeException($action . ': ' . $result->get_error_code() . ': ' . $result->get_error_message());
    }
    site_architect_47518_assert(is_array($result), $action . ' must return a result array');
    return $result;
}

wp_set_current_user(1);
require_once WP_PLUGIN_DIR . '/eduardo-research-manager/includes/class-research-manager-site-profile.php';
$profile = (new Eduardo_Research_Manager_Site_Profile())->id();
site_architect_47518_assert($profile === getenv('PROFILE'), 'expected WordPress profile');

$design = new Eduardo_Research_Manager_Design();
$navigation = new Eduardo_Research_Manager_Navigation();

if ('generic-wordpress' === $profile) {
    $before = array(
        'design' => get_option('eduardo_research_design', false),
        'navigation' => get_option('eduardo_research_navigation', false),
    );
    $d = $design->inspect();
    $n = $navigation->inspect();
    site_architect_47518_assert(is_wp_error($d)
        && $d->get_error_code() === 'capability_unavailable', 'Design requires matching Research Theme');
    site_architect_47518_assert(is_wp_error($n)
        && $n->get_error_code() === 'capability_unavailable', 'Navigation requires matching Research Theme');
    site_architect_47518_assert(
        get_option('eduardo_research_design', false) === $before['design']
        && get_option('eduardo_research_navigation', false) === $before['navigation'],
        'Generic read-only inspection must not write Research design/navigation options'
    );
    echo "PASS: Site Architect #475.18 generic WordPress denies Research design/navigation, no mutations\n";
    return;
}
site_architect_47518_assert('research-theme' === $profile, 'unknown profile fail closed');

$contract = Eduardo_Research_Manager::contract();
site_architect_47518_assert($contract->compatible(), 'Theme Contract available');
$languages = $contract->languages();
site_architect_47518_assert($languages === array('en', 'es'), 'current Research EN/ES contract preserved');
$pages = $contract->pages();
site_architect_47518_assert(count($pages) === 12, '12 contract-owned pages, no silent loss');
$inspected = 0;
foreach (array_keys($pages) as $key) {
    foreach ($languages as $language) {
        $page = site_architect_47518_ok(Eduardo_Research_Manager::pages()->inspect((string) $key, $language), 'Page inspect');
        $checks = array(
            'key'=>(string) $page['key'] === (string) $key,
            'language'=>(string) $page['language'] === $language,
            'published'=>! empty($page['published']),
            'contract_aligned'=>! empty($page['contract_aligned']),
            'url'=>! empty($page['url']),
            'option_key'=>! empty($page['option_key']),
            'allowed_slots'=>is_array($page['allowed_slots']),
            'effective_slots'=>is_array($page['effective_slots']),
        );
        if (in_array(false, $checks, true)) {
            fwrite(STDERR, 'Page contract probe: '. wp_json_encode(array(
                'key'=>$key,'language'=>$language,'checks'=>$checks,
                'status'=>$page['status'] ?? '',
                'role'=>$page['role'] ?? '', 'expected_role'=>$page['expected_role'] ?? '',
                'model'=>$page['model'] ?? '', 'expected_model'=>$page['expected_model'] ?? '',
            )) . "\n");
        }
        site_architect_47518_assert(
            ! in_array(false, $checks, true),
            'Theme-owned Page state/route/storage parity for ' . $key . '/' . $language
        );
        $inspected++;
    }
}
site_architect_47518_assert($inspected === 24, 'all 24 bilingual Page views inspected');

$seo = (new Eduardo_Research_Manager_SEO_GEO())->site();
site_architect_47518_assert(
    is_array($seo) && isset($seo['resource_counts'])
    && ! empty($seo['rendered_contract']['pages_require_bilingual_alternates'])
    && in_array('canonical', $seo['rendered_contract']['checks'] ?? array(), true)
    && in_array('json_ld', $seo['rendered_contract']['checks'] ?? array(), true),
    'SEO/GEO structural diagnostics and rendered verification policy present'
);

$actor = array(
    'connection_id' => 'site-architect-47518-isolated-only',
    'wordpress_user_id' => get_current_user_id(),
    'request_id' => 'sa-47518-clone-acceptance',
);
site_architect_47518_assert(current_user_can('manage_options'), 'isolated admin authority');

$beforeDesign = site_architect_47518_ok($design->inspect(), 'Design inspect');
site_architect_47518_assert(
    is_array($beforeDesign['state'] ?? null) && is_array($beforeDesign['presets'] ?? null),
    'Theme bounded Design contract inspect'
);

$dimension = '';
$alternate = '';
foreach (array('typography', 'density') as $candidate) {
    $current = (string) ($beforeDesign['state'][$candidate] ?? '');
    foreach (array_keys((array) ($beforeDesign['presets'][$candidate] ?? array())) as $preset) {
        if ((string) $preset !== $current) {
            $dimension = $candidate;
            $alternate = (string) $preset;
            break 2;
        }
    }
}
site_architect_47518_assert('' !== $dimension, 'Theme has an alternate bounded typography or density preset');

$badDesign = $design->create_plan(array('custom-css' => 'body {display:none}'), 'Reject arbitrary CSS', $actor);
site_architect_47518_assert(is_wp_error($badDesign), 'arbitrary CSS denied by Design contract');
$plan = site_architect_47518_ok(
    $design->create_plan(array($dimension => $alternate), 'Isolated clone reversible Design', $actor),
    'Design preview'
);
$refused = $design->apply((string) $plan['plan_id'], false, $actor);
site_architect_47518_assert(
    is_wp_error($refused) && $refused->get_error_code() === 'confirmation_required',
    'Design Apply requires explicit confirmation'
);
site_architect_47518_assert(
    eduardo_research_design_state() === $beforeDesign['state'],
    'Design state unchanged before explicit Apply'
);
$applied = site_architect_47518_ok($design->apply((string) $plan['plan_id'], true, $actor), 'Design Apply');
$operationId = (string) ($applied['operation_id'] ?? '');
site_architect_47518_assert('' !== $operationId, 'Design operation ID recorded');
$verified = site_architect_47518_ok($design->verify($operationId, $actor), 'Design Verify');
site_architect_47518_assert(! empty($verified['verification']['verified']), 'Design verification passes');
$rolled = site_architect_47518_ok($design->rollback($operationId, true, $actor), 'Design Rollback');
site_architect_47518_assert(
    (string) ($rolled['status'] ?? '') === 'rolled-back'
    && eduardo_research_design_state() === $beforeDesign['state'],
    'Design state exactly restored through bounded Rollback'
);

$beforeNav = site_architect_47518_ok($navigation->inspect(), 'Navigation inspect');
$locations = is_array($beforeNav['contract']['locations'] ?? null)
    ? $beforeNav['contract']['locations'] : array();
$location = '';
$reversed = array();
foreach (array_keys($locations) as $candidate) {
    $items = $beforeNav['state'][$candidate] ?? null;
    if (! is_array($items) || count($items) < 2) { continue; }
    $location = (string) $candidate;
    $reversed = array_values(array_reverse($items));
    break;
}
site_architect_47518_assert('' !== $location, 'Need an existing multi-target navigation location');
$badNav = $navigation->create_plan($location, array('external-arbitrary-url'), 'Reject unregistered target', $actor);
site_architect_47518_assert(is_wp_error($badNav), 'Navigation denies arbitrary external targets');
$navPlan = site_architect_47518_ok(
    $navigation->create_plan($location, $reversed, 'Isolated clone reversible Navigation', $actor),
    'Navigation preview'
);
$refusedNav = $navigation->apply((string) $navPlan['plan_id'], false, $actor);
site_architect_47518_assert(
    is_wp_error($refusedNav) && $refusedNav->get_error_code() === 'confirmation_required',
    'Navigation Apply requires explicit confirmation'
);
site_architect_47518_assert(
    eduardo_research_navigation_state() === $beforeNav['state'],
    'Navigation unchanged before confirmation'
);
$navApplied = site_architect_47518_ok(
    $navigation->apply((string) $navPlan['plan_id'], true, $actor), 'Navigation Apply'
);
$navId = (string) ($navApplied['operation_id'] ?? '');
site_architect_47518_assert('' !== $navId, 'Navigation operation ID recorded');
$navVerified = site_architect_47518_ok(
    $navigation->verify($navId, false, $actor), 'Navigation state Verify'
);
site_architect_47518_assert(
    ! empty($navVerified['verification']['verified']),
    'Navigation state and revision Verify'
);
$navRolled = site_architect_47518_ok(
    $navigation->rollback($navId, true, $actor), 'Navigation Rollback'
);
site_architect_47518_assert(
    (string) ($navRolled['status'] ?? '') === 'rolled-back'
    && eduardo_research_navigation_state() === $beforeNav['state'],
    'Navigation state restored exactly through bounded Rollback'
);

// This deliberately does not claim HTTP-rendered SEO parity or media parity.
echo "PASS: Site Architect #475.18 independent clone: 24 Pages, SEO/GEO structural contract, ";
echo "Design and Navigation Preview/Confirm/Apply/Verify/Rollback, restored baseline\n";
