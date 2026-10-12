#!/usr/bin/env php
<?php
/**
 * Phase 4.5 read-only production closeout audit for Remote Media acceptance.
 *
 * This script performs no mutation and cannot revoke credentials. It is intended to run
 * immediately after P4.3/P4.4 and before the temporary Media credential is revoked locally.
 * It proves:
 * - the exact temporary 8-scope profile is still in use;
 * - Media capabilities remain available;
 * - Media diagnostics succeed after rollback;
 * - no filesystem/secret-like fields are exposed by the audited responses;
 * - supplied P4.3/P4.4 evidence files report PASS and contain no bearer token material.
 *
 * Usage:
 *   ERM_BASE_URL='https://eduardoyauriluna.com' ERM_TOKEN='...' \
 *     php scripts/p4-media-production-closeout.php \
 *     --metadata-evidence=tmp/p4-media-metadata-production-acceptance.json \
 *     --assignment-evidence=tmp/p4-media-assignment-production-acceptance.json \
 *     --evidence=tmp/p4-media-production-closeout.json
 *
 * After PASS, revoke the temporary credential/session locally and prove the old token is
 * rejected. Never put the credential itself in GitHub evidence.
 */
declare(strict_types=1);

const P4_CLOSEOUT_API_PREFIX = '/wp-json/research-manager/v1';

$options = getopt('', array(
    'metadata-evidence:',
    'assignment-evidence:',
    'evidence::',
    'help',
));

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php scripts/p4-media-production-closeout.php --metadata-evidence=FILE --assignment-evidence=FILE [--evidence=FILE]\n");
    exit(0);
}

$baseUrl = rtrim(trim((string) getenv('ERM_BASE_URL')), '/');
$token = trim((string) getenv('ERM_TOKEN'));
$metadataEvidencePath = trim((string) ($options['metadata-evidence'] ?? ''));
$assignmentEvidencePath = trim((string) ($options['assignment-evidence'] ?? ''));
$outputPath = trim((string) ($options['evidence'] ?? ''));

if ('' === $baseUrl || '' === $token) {
    fwrite(STDERR, "ERM_BASE_URL and ERM_TOKEN must be set in the local environment.\n");
    exit(2);
}
if (! preg_match('#^https://#i', $baseUrl)) {
    fwrite(STDERR, "ERM_BASE_URL must use HTTPS.\n");
    exit(2);
}
foreach (array('metadata-evidence' => $metadataEvidencePath, 'assignment-evidence' => $assignmentEvidencePath) as $label => $path) {
    if ('' === $path || ! is_file($path) || ! is_readable($path)) {
        fwrite(STDERR, "--{$label} must point to a readable local acceptance evidence file.\n");
        exit(2);
    }
}

$normaliseSet = static function (array $values): array {
    $values = array_values(array_unique(array_map('strval', $values)));
    sort($values);
    return $values;
};
$expectedScopes = $normaliseSet(array(
    'site.read',
    'site.diagnostics',
    'media.read',
    'media.write',
    'insights.read',
    'insights.write',
    'operations.apply',
    'operations.rollback',
));

$sanitize = null;
$sanitize = static function (mixed $input) use (&$sanitize): mixed {
    if (is_array($input)) {
        $clean = array();
        foreach ($input as $key => $item) {
            $name = strtolower((string) $key);
            if (in_array($name, array('token', 'token_hash', 'authorization', 'password', 'secret'), true)) {
                $clean[$key] = '[redacted]';
                continue;
            }
            $clean[$key] = $sanitize($item);
        }
        return $clean;
    }
    if (is_string($input) && str_contains($input, 'erm1_')) {
        return preg_replace('/erm1_[A-Za-z0-9._:-]+/', 'erm1_[redacted]', $input);
    }
    return $input;
};

$containsUnsafeKey = null;
$containsUnsafeKey = static function (mixed $input, string $path = '') use (&$containsUnsafeKey): ?string {
    if (! is_array($input)) {
        return null;
    }
    foreach ($input as $key => $value) {
        $name = strtolower((string) $key);
        $next = '' === $path ? (string) $key : $path . '.' . $key;
        if (in_array($name, array(
            'token', 'token_hash', 'authorization', 'password', 'secret',
            'filesystem_path', 'file_path', 'absolute_path', 'server_path',
        ), true)) {
            return $next;
        }
        $nested = $containsUnsafeKey($value, $next);
        if (null !== $nested) {
            return $nested;
        }
    }
    return null;
};

$loadEvidence = static function (string $path, string $expectedSchemaPrefix) use ($containsUnsafeKey): array {
    $raw = file_get_contents($path);
    if (false === $raw || '' === trim($raw)) {
        throw new RuntimeException("Acceptance evidence is empty: {$path}");
    }
    if (str_contains($raw, 'erm1_') || preg_match('/Authorization\s*:\s*Bearer/i', $raw)) {
        throw new RuntimeException("Acceptance evidence appears to contain credential material: {$path}");
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        throw new RuntimeException("Acceptance evidence is invalid JSON: {$path}");
    }
    if (! is_array($decoded)) {
        throw new RuntimeException("Acceptance evidence is not an object: {$path}");
    }
    if (! str_starts_with((string) ($decoded['schema'] ?? ''), $expectedSchemaPrefix)) {
        throw new RuntimeException("Unexpected acceptance evidence schema: {$path}");
    }
    if ('pass' !== (string) ($decoded['result'] ?? '')) {
        throw new RuntimeException("Acceptance evidence is not PASS: {$path}");
    }
    $unsafe = $containsUnsafeKey($decoded);
    if (null !== $unsafe) {
        $value = $decoded;
        foreach (explode('.', $unsafe) as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) { break; }
            $value = $value[$part];
        }
        if ('[redacted]' !== $value) {
            throw new RuntimeException("Acceptance evidence contains unsafe unredacted field {$unsafe}: {$path}");
        }
    }
    return $decoded;
};

$requests = array();
$request = static function (string $path, string $label) use ($baseUrl, $token, &$requests, $sanitize, $containsUnsafeKey): array {
    $route = (string) (parse_url($path, PHP_URL_PATH) ?: '');
    if (! in_array($route, array('/capabilities', '/media/diagnostics'), true)) {
        throw new RuntimeException('Route outside Phase 4 closeout read-only allowlist: ' . $route);
    }
    $requestId = 'p4-media-closeout-' . preg_replace('/[^A-Za-z0-9._:-]/', '-', $label) . '-' . time() . '-' . bin2hex(random_bytes(4));
    $ch = curl_init($baseUrl . P4_CLOSEOUT_API_PREFIX . $path);
    if (false === $ch) {
        throw new RuntimeException('Unable to initialise cURL.');
    }
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'X-Research-Manager-Request-Id: ' . $requestId,
            'X-Research-Manager-Nonce: ' . bin2hex(random_bytes(16)),
            'X-Research-Manager-Timestamp: ' . time(),
        ),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Eduardo-Research-P4-Media-Closeout/1.0',
    ));
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (false === $raw || '' !== $curlError) {
        throw new RuntimeException($label . ' request failed: ' . $curlError);
    }
    try {
        $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        throw new RuntimeException(sprintf('%s returned invalid JSON (HTTP %d).', $label, $status));
    }
    if (! is_array($decoded)) {
        throw new RuntimeException($label . ' returned an invalid payload.');
    }
    $unsafe = $containsUnsafeKey($decoded);
    if (null !== $unsafe) {
        throw new RuntimeException($label . ' exposed unsafe field: ' . $unsafe);
    }
    $requests[] = array(
        'label' => $label,
        'method' => 'GET',
        'route' => P4_CLOSEOUT_API_PREFIX . $path,
        'request_id' => $requestId,
        'http_status' => $status,
        'response' => $sanitize($decoded),
    );
    if ($status < 200 || $status >= 300 || empty($decoded['ok']) || ! is_array($decoded['data'] ?? null)) {
        throw new RuntimeException(sprintf(
            '%s failed: HTTP %d %s — %s',
            $label,
            $status,
            (string) ($decoded['code'] ?? 'request_failed'),
            (string) ($decoded['message'] ?? 'Request did not succeed.')
        ));
    }
    return (array) $decoded['data'];
};

$evidence = array(
    'schema' => 'p4-media-production-closeout-v1',
    'generated_at' => gmdate(DATE_W3C),
    'base_url' => $baseUrl,
    'checks' => array(),
    'requests' => array(),
    'result' => 'running',
    'next_required_action' => 'revoke-temporary-media-credential-locally-and-prove-old-token-denied',
);

try {
    $metadataEvidence = $loadEvidence($metadataEvidencePath, 'p4-media-metadata-production-acceptance-');
    $assignmentEvidence = $loadEvidence($assignmentEvidencePath, 'p4-media-assignment-production-acceptance-');
    $evidence['checks']['metadata_acceptance_pass'] = true;
    $evidence['checks']['assignment_acceptance_pass'] = true;
    $evidence['acceptance_refs'] = array(
        'metadata_operation_id' => (string) ($metadataEvidence['operation_id'] ?? ''),
        'assignment_operation_id' => (string) ($assignmentEvidence['operation_id'] ?? ''),
        'assignment_target_url' => (string) ($assignmentEvidence['source']['target_url'] ?? ''),
    );
    if ('' === $evidence['acceptance_refs']['metadata_operation_id'] || '' === $evidence['acceptance_refs']['assignment_operation_id']) {
        throw new RuntimeException('Acceptance evidence is missing operation IDs.');
    }

    $capabilities = $request('/capabilities', 'capabilities');
    if ($normaliseSet((array) ($capabilities['granted_scopes'] ?? array())) !== $expectedScopes) {
        throw new RuntimeException('Closeout credential does not match the exact temporary Phase 4 8-scope profile.');
    }

    $mediaControl = is_array($capabilities['media_control'] ?? null) ? $capabilities['media_control'] : array();
    $diagnosticsContract = is_array($mediaControl['diagnostics'] ?? null) ? $mediaControl['diagnostics'] : array();
    $metadataTransport = is_array($mediaControl['metadata_transport'] ?? null) ? $mediaControl['metadata_transport'] : array();
    $assignmentContract = is_array($mediaControl['assignment'] ?? null) ? $mediaControl['assignment'] : array();
    $assignmentTransport = is_array($assignmentContract['transport'] ?? null) ? $assignmentContract['transport'] : array();

    if (true !== ($mediaControl['inventory'] ?? null)) {
        throw new RuntimeException('Media capability unavailable during closeout: inventory');
    }
    if (true !== ($mediaControl['inspection'] ?? null)) {
        throw new RuntimeException('Media capability unavailable during closeout: inspection');
    }
    if ('media.read' !== (string) ($mediaControl['read_scope'] ?? '')) {
        throw new RuntimeException('Media capability contract mismatch during closeout: read_scope');
    }
    if (empty($mediaControl['write_available']) || 'media.write' !== (string) ($mediaControl['write_scope'] ?? '')) {
        throw new RuntimeException('Media capability unavailable during closeout: metadata write transport');
    }
    if (! in_array('media-metadata-update', (array) ($mediaControl['operations'] ?? array()), true)) {
        throw new RuntimeException('Media capability contract mismatch during closeout: metadata operation');
    }
    if ('/research-manager/v1/media/metadata' !== (string) ($metadataTransport['base'] ?? '')
        || $normaliseSet((array) ($metadataTransport['lifecycle'] ?? array())) !== $normaliseSet(array('plan','apply','status','verify','rollback'))
        || empty($metadataTransport['stale_attachment_revision_protection'])
        || empty($metadataTransport['idempotency'])) {
        throw new RuntimeException('Media capability unavailable during closeout: metadata');
    }
    if (empty($assignmentContract['available'])
        || 'media-assignment' !== (string) ($assignmentContract['operation'] ?? '')
        || 'theme' !== (string) ($assignmentContract['layout_authority'] ?? '')
        || empty($assignmentTransport['rendered_verification'])
        || empty($assignmentTransport['dual_resource_stale_protection'])
        || empty($assignmentTransport['idempotency'])) {
        throw new RuntimeException('Media capability unavailable during closeout: assignment');
    }
    if (empty($diagnosticsContract['available'])
        || 'media.read' !== (string) ($diagnosticsContract['read_scope'] ?? '')
        || empty($diagnosticsContract['aggregate_readiness'])) {
        throw new RuntimeException('Media capability unavailable during closeout: diagnostics');
    }
    if (! empty($mediaControl['filesystem_paths_exposed'])
        || ! empty($mediaControl['arbitrary_upload_proxy'])
        || ! empty($mediaControl['destructive_delete'])
        || ! empty($mediaControl['arbitrary_wordpress_proxy'])
        || ! empty($assignmentContract['arbitrary_post_meta'])) {
        throw new RuntimeException('Media capability contract exposes a forbidden production control surface.');
    }

    $evidence['checks']['exact_8_scope_profile'] = true;
    $evidence['checks']['media_inventory_available'] = true;
    $evidence['checks']['media_metadata_transport_available'] = true;
    $evidence['checks']['media_assignment_available'] = true;
    $evidence['checks']['media_diagnostics_available'] = true;
    $evidence['checks']['media_contracts_available'] = true;

    $diagnostics = $request('/media/diagnostics', 'media-diagnostics-final');
    if (! array_key_exists('summary', $diagnostics) && ! array_key_exists('findings', $diagnostics)) {
        throw new RuntimeException('Media diagnostics response does not expose the expected diagnostics shape.');
    }
    $evidence['checks']['final_media_diagnostics_succeeded'] = true;
    $evidence['checks']['no_unsafe_response_fields'] = true;
    $evidence['requests'] = $requests;
    $evidence['result'] = 'pass-awaiting-local-revocation';

    fwrite(STDOUT, "Phase 4 Media production closeout audit: PASS\n");
    fwrite(STDOUT, "Next required action: revoke the temporary Media credential locally, then prove the old token is rejected.\n");
} catch (Throwable $error) {
    $evidence['result'] = 'fail';
    $evidence['failure'] = array('class' => get_class($error), 'message' => $error->getMessage());
    $evidence['requests'] = $requests;
    if ('' !== $outputPath) {
        $dir = dirname($outputPath);
        if ('.' !== $dir && ! is_dir($dir)) { @mkdir($dir, 0700, true); }
        @file_put_contents($outputPath, (string) json_encode($sanitize($evidence), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, LOCK_EX);
        @chmod($outputPath, 0600);
    }
    fwrite(STDERR, "Phase 4 Media production closeout audit: FAIL — {$error->getMessage()}\n");
    exit(1);
}

if ('' !== $outputPath) {
    $dir = dirname($outputPath);
    if ('.' !== $dir && ! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
        fwrite(STDERR, "Unable to create evidence directory: {$dir}\n");
        exit(1);
    }
    $encoded = json_encode($sanitize($evidence), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (false === $encoded || false === file_put_contents($outputPath, $encoded . PHP_EOL, LOCK_EX)) {
        fwrite(STDERR, "Unable to write evidence file: {$outputPath}\n");
        exit(1);
    }
    @chmod($outputPath, 0600);
    fwrite(STDOUT, "Evidence: {$outputPath}\n");
}

exit(0);
