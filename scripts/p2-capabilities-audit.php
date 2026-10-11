<?php
/**
 * Phase 2 production capability audit for eduardoyauriluna.com.
 *
 * Reads the authenticated Research Manager /capabilities endpoint and checks:
 * - server scope registry drift;
 * - minimum scopes for a named operating profile;
 * - dangerous scope exposure;
 * - core read/mutation lifecycle declarations.
 *
 * The bearer token is read only from ERM_TOKEN and is never printed or written.
 *
 * Usage:
 *   ERM_BASE_URL='https://eduardoyauriluna.com' ERM_TOKEN='...' \
 *     php scripts/p2-capabilities-audit.php --profile=phase2 \
 *     --evidence=tmp/p2-capabilities-audit.json
 */
declare(strict_types=1);

const API_PREFIX = '/wp-json/research-manager/v1';

$expectedSupportedScopes = array(
    'site.read',
    'site.diagnostics',
    'pages.read',
    'pages.write',
    'insights.read',
    'insights.write',
    'research.read',
    'research.write',
    'evidence.read',
    'evidence.write',
    'translations.read',
    'translations.write',
    'seo.read',
    'seo.write',
    'media.read',
    'media.write',
    'connections.read',
    'connections.write',
    'design.read',
    'design.write',
    'operations.apply',
    'operations.rollback',
    'identity.write',
    'credentials.manage',
    'external_publish.execute',
    'software_update.execute',
    'destructive_delete.execute',
);

$profiles = array(
    'content' => array(
        'site.read', 'site.diagnostics',
        'pages.read', 'pages.write',
        'operations.apply', 'operations.rollback',
    ),
    'editorial' => array(
        'site.read', 'site.diagnostics',
        'insights.read', 'insights.write',
        'translations.read', 'translations.write',
        'operations.apply', 'operations.rollback',
    ),
    'research' => array(
        'site.read', 'site.diagnostics',
        'research.read', 'research.write',
        'evidence.read', 'evidence.write',
        'translations.read', 'translations.write',
        'operations.apply', 'operations.rollback',
    ),
    'design' => array(
        'site.read', 'site.diagnostics',
        'design.read', 'design.write',
        'operations.apply', 'operations.rollback',
    ),
    'phase2' => array(
        'site.read', 'site.diagnostics',
        'pages.read', 'pages.write',
        'insights.read', 'insights.write',
        'research.read', 'research.write',
        'evidence.read', 'evidence.write',
        'translations.read', 'translations.write',
        'seo.read', 'seo.write',
        'design.read',
        'operations.apply', 'operations.rollback',
    ),
);

$dangerousByDefault = array(
    'identity.write',
    'credentials.manage',
    'external_publish.execute',
    'software_update.execute',
    'destructive_delete.execute',
    'connections.write',
    'media.write',
    'design.write',
);

$options = getopt('', array('profile::', 'evidence::', 'help'));
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php scripts/p2-capabilities-audit.php [--profile=phase2] [--evidence=tmp/p2-capabilities-audit.json]\n");
    exit(0);
}

$profile = strtolower(trim((string) ($options['profile'] ?? 'phase2')));
if (! isset($profiles[$profile])) {
    fwrite(STDERR, 'Unknown profile: ' . $profile . PHP_EOL);
    fwrite(STDERR, 'Allowed profiles: ' . implode(', ', array_keys($profiles)) . PHP_EOL);
    exit(2);
}

$baseUrl = rtrim(trim((string) getenv('ERM_BASE_URL')), '/');
$token = trim((string) getenv('ERM_TOKEN'));
if ('' === $baseUrl || '' === $token) {
    fwrite(STDERR, "ERM_BASE_URL and ERM_TOKEN must be set in the local environment.\n");
    exit(2);
}
if (! preg_match('#^https://#i', $baseUrl)) {
    fwrite(STDERR, "ERM_BASE_URL must use HTTPS.\n");
    exit(2);
}

$requestId = 'p2-capabilities-' . time() . '-' . bin2hex(random_bytes(4));
$nonce = bin2hex(random_bytes(16));
$timestamp = (string) time();
$url = $baseUrl . API_PREFIX . '/capabilities';

$ch = curl_init($url);
if (false === $ch) {
    fwrite(STDERR, "Unable to initialise cURL.\n");
    exit(2);
}

curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
        'X-Research-Manager-Request-Id: ' . $requestId,
        'X-Research-Manager-Nonce: ' . $nonce,
        'X-Research-Manager-Timestamp: ' . $timestamp,
    ),
));

$raw = curl_exec($ch);
$curlError = curl_error($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (false === $raw || '' !== $curlError) {
    fwrite(STDERR, 'Capabilities request failed: ' . $curlError . PHP_EOL);
    exit(1);
}

$decoded = json_decode((string) $raw, true);
if (! is_array($decoded)) {
    fwrite(STDERR, 'Capabilities endpoint returned invalid JSON (HTTP ' . $status . ').' . PHP_EOL);
    exit(1);
}
if ($status < 200 || $status >= 300 || empty($decoded['ok']) || ! is_array($decoded['data'] ?? null)) {
    $code = (string) ($decoded['code'] ?? $decoded['error'] ?? 'request_failed');
    $message = (string) ($decoded['message'] ?? 'Capabilities request did not succeed.');
    fwrite(STDERR, sprintf("Capabilities request failed: HTTP %d %s — %s\n", $status, $code, $message));
    exit(1);
}

$data = $decoded['data'];
$granted = array_values(array_unique(array_map('strval', (array) ($data['granted_scopes'] ?? array()))));
$supported = array_values(array_unique(array_map('strval', (array) ($data['supported_scopes'] ?? array()))));
sort($granted);
sort($supported);

$expectedSorted = $expectedSupportedScopes;
sort($expectedSorted);
$required = $profiles[$profile];
$missingRequired = array_values(array_diff($required, $granted));
$extraGranted = array_values(array_diff($granted, $required));
$unexpectedDangerous = array_values(array_intersect($granted, $dangerousByDefault));
$scopeRegistryMissing = array_values(array_diff($expectedSorted, $supported));
$scopeRegistryUnexpected = array_values(array_diff($supported, $expectedSorted));

$readEndpoints = is_array($data['read_endpoints'] ?? null) ? $data['read_endpoints'] : array();
$transport = is_array($data['mutation_transport'] ?? null) ? $data['mutation_transport'] : array();
$lifecycle = array_values(array_map('strval', (array) ($transport['lifecycle'] ?? array())));

$coreChecks = array(
    'namespace_v1' => 'research-manager/v1' === (string) ($data['namespace'] ?? ''),
    'site_read_capabilities' => 'site.read' === (string) ($readEndpoints['capabilities'] ?? ''),
    'site_diagnostics_readiness' => 'site.diagnostics' === (string) ($readEndpoints['readiness'] ?? ''),
    'site_diagnostics_diagnostics' => 'site.diagnostics' === (string) ($readEndpoints['diagnostics'] ?? ''),
    'mutation_transport_available' => ! empty($transport['available']),
    'exact_plan_required' => ! empty($transport['exact_plan_required']),
    'stale_revision_protection' => ! empty($transport['stale_revision_protection']),
    'idempotency' => ! empty($transport['idempotency']),
    'lifecycle_plan' => in_array('plan', $lifecycle, true),
    'lifecycle_apply' => in_array('apply', $lifecycle, true),
    'lifecycle_status' => in_array('status', $lifecycle, true),
    'lifecycle_verify' => in_array('verify', $lifecycle, true),
    'lifecycle_rollback' => in_array('rollback', $lifecycle, true),
);

$pass = empty($missingRequired)
    && empty($unexpectedDangerous)
    && empty($scopeRegistryMissing)
    && empty($scopeRegistryUnexpected)
    && ! in_array(false, $coreChecks, true);

$result = array(
    'audit' => 'p2-production-capabilities',
    'profile' => $profile,
    'pass' => $pass,
    'checked_at' => gmdate(DATE_W3C),
    'http_status' => $status,
    'request_id' => $requestId,
    'connection_id' => (string) ($data['connection_id'] ?? ''),
    'writes_enabled' => ! empty($data['writes_enabled']),
    'required_scopes' => $required,
    'granted_scopes' => $granted,
    'missing_required_scopes' => $missingRequired,
    'extra_granted_scopes' => $extraGranted,
    'dangerous_scopes_granted' => $unexpectedDangerous,
    'supported_scope_registry' => array(
        'matches_source_contract' => empty($scopeRegistryMissing) && empty($scopeRegistryUnexpected),
        'missing' => $scopeRegistryMissing,
        'unexpected' => $scopeRegistryUnexpected,
    ),
    'core_checks' => $coreChecks,
    'mutation_transport' => array(
        'milestone' => (string) ($transport['milestone'] ?? ''),
        'supported_operations' => array_values(array_map('strval', (array) ($transport['supported_operations'] ?? array()))),
        'lifecycle' => $lifecycle,
    ),
);

$evidencePath = trim((string) ($options['evidence'] ?? ''));
if ('' !== $evidencePath) {
    $dir = dirname($evidencePath);
    if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
        fwrite(STDERR, 'Unable to create evidence directory: ' . $dir . PHP_EOL);
        exit(2);
    }
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (false === file_put_contents($evidencePath, $json, LOCK_EX)) {
        fwrite(STDERR, 'Unable to write evidence file: ' . $evidencePath . PHP_EOL);
        exit(2);
    }
    @chmod($evidencePath, 0600);
}

fwrite(STDOUT, 'P2 capabilities audit: ' . ($pass ? 'PASS' : 'FAIL') . PHP_EOL);
fwrite(STDOUT, 'Profile: ' . $profile . PHP_EOL);
fwrite(STDOUT, 'Granted scopes: ' . count($granted) . PHP_EOL);
fwrite(STDOUT, 'Missing required: ' . (empty($missingRequired) ? 'none' : implode(', ', $missingRequired)) . PHP_EOL);
fwrite(STDOUT, 'Dangerous granted: ' . (empty($unexpectedDangerous) ? 'none' : implode(', ', $unexpectedDangerous)) . PHP_EOL);
fwrite(STDOUT, 'Scope registry drift: ' . ((empty($scopeRegistryMissing) && empty($scopeRegistryUnexpected)) ? 'none' : 'detected') . PHP_EOL);
if ('' !== $evidencePath) {
    fwrite(STDOUT, 'Evidence: ' . $evidencePath . PHP_EOL);
}

exit($pass ? 0 : 1);
