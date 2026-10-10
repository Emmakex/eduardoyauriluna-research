<?php
/** Guard against accidentally coupling Greenfield to migration capabilities. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

$capabilities = Eduardo_Research_Manager_Mode::capabilities();
$expected = array(
    'direct_contract_creation',
    'legacy_discovery',
    'legacy_mapping',
    'legacy_url_preservation',
    'migration_preview',
);
if ($expected !== array_keys($capabilities)) { exit(1); }

if (Eduardo_Research_Manager_Mode::is_greenfield()) {
    if (true !== $capabilities['direct_contract_creation']) { exit(1); }
    foreach (array('legacy_discovery','legacy_mapping','legacy_url_preservation','migration_preview') as $key) {
        if (false !== $capabilities[$key]) { exit(1); }
    }
}
