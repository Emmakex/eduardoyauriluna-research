<?php
/** Regression guard for the Theme-owned localized sitemap provider. */

if (! defined('ABSPATH')) {
    fwrite(STDERR, "WordPress must be loaded before this guard runs.\n");
    exit(1);
}

if (! class_exists('Eduardo_Research_Language_Sitemap_Provider')) {
    fwrite(STDERR, "Localized sitemap provider class is unavailable.\n");
    exit(1);
}

$provider_name = Eduardo_Research_Language_Sitemap_Provider::PROVIDER_NAME;
if (1 !== preg_match('/^[a-z]+$/', $provider_name)) {
    fwrite(STDERR, "Provider id is incompatible with WordPress sitemap routing: {$provider_name}\n");
    exit(1);
}
if ('researchlanguages' !== $provider_name) {
    fwrite(STDERR, "Unexpected localized sitemap provider id: {$provider_name}\n");
    exit(1);
}

$server = wp_sitemaps_get_server();
$provider = $server->registry->get_provider($provider_name);
if (! $provider instanceof Eduardo_Research_Language_Sitemap_Provider) {
    fwrite(STDERR, "Localized sitemap provider is not registered under the canonical id.\n");
    exit(1);
}
if (null !== $server->registry->get_provider('research-languages')) {
    fwrite(STDERR, "Legacy hyphenated sitemap provider id is still registered.\n");
    exit(1);
}
if (null !== $server->registry->get_provider('users')) {
    fwrite(STDERR, "Low-value WordPress users sitemap provider is still registered.\n");
    exit(1);
}

$expected_url = home_url('/wp-sitemap-' . $provider_name . '-1.xml');
$found = false;
foreach ($server->index->get_sitemap_list() as $entry) {
    $location = (string) ($entry['loc'] ?? '');
    if ($expected_url === $location) {
        $found = true;
    }
    if (false !== strpos($location, '/wp-sitemap-users-')) {
        fwrite(STDERR, "Low-value users sitemap leaked into the native sitemap index: {$location}\n");
        exit(1);
    }
}
if (! $found) {
    fwrite(STDERR, "Localized sitemap is missing from the native sitemap index: {$expected_url}\n");
    exit(1);
}

flush_rewrite_rules(false);
$rules = get_option('rewrite_rules', array());
if (! is_array($rules) || array() === $rules) {
    fwrite(STDERR, "WordPress rewrite rules are unavailable.\n");
    exit(1);
}

$route = 'wp-sitemap-' . $provider_name . '-1.xml';
$matched = false;
foreach ($rules as $regex => $query) {
    $matches = array();
    if (1 !== preg_match('#^' . $regex . '#', $route, $matches)) {
        continue;
    }
    $resolved = (string) $query;
    foreach ($matches as $index => $value) {
        if (0 === $index) {
            continue;
        }
        $resolved = str_replace('$matches[' . $index . ']', (string) $value, $resolved);
    }
    if (false !== strpos($resolved, 'sitemap=' . $provider_name)) {
        $matched = true;
        break;
    }
}
if (! $matched) {
    fwrite(STDERR, "Native rewrite rules do not resolve {$route} to provider {$provider_name}.\n");
    exit(1);
}

echo "PASS: Theme sitemap provider id, index URL, native rewrite route and author-sitemap exclusion are aligned.\n";
