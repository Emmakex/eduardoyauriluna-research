<?php
/**
 * Disposable PHP built-in server router for Site Architect #475.19 HTTP acceptance.
 * This file is copied ONLY into /tmp/sa-wp; never deployed to production.
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(500);
    exit;
}
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$file = realpath(__DIR__ . $path);
$root = realpath(__DIR__);
if ($file !== false && $root !== false
    && str_starts_with($file, $root . DIRECTORY_SEPARATOR)
    && is_file($file) && ! str_ends_with(strtolower($file), '.php')) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require __DIR__ . '/index.php';
