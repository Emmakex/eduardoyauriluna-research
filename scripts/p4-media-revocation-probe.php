#!/usr/bin/env php
<?php
/**
 * Phase 4 Media post-revocation proof.
 *
 * Run only AFTER revoking the temporary Media acceptance credential/session locally.
 * ERM_TOKEN must contain the just-revoked token for this one negative-authentication probe.
 * PASS requires GET /capabilities to return HTTP 401 with `authentication_failed`.
 * The token is never written to evidence.
 */
declare(strict_types=1);

const P4_REVOKE_API_PREFIX = '/wp-json/research-manager/v1';

$options = getopt('', array('evidence::', 'help'));
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: ERM_BASE_URL=https://eduardoyauriluna.com ERM_TOKEN='<revoked-token>' php scripts/p4-media-revocation-probe.php [--evidence=FILE]\n");
    exit(0);
}

$baseUrl = rtrim(trim((string) getenv('ERM_BASE_URL')), '/');
$token = trim((string) getenv('ERM_TOKEN'));
$evidencePath = trim((string) ($options['evidence'] ?? ''));

if ('' === $baseUrl || '' === $token) {
    fwrite(STDERR, "ERM_BASE_URL and the revoked ERM_TOKEN must be set locally.\n");
    exit(2);
}
if (! preg_match('#^https://#i', $baseUrl)) {
    fwrite(STDERR, "ERM_BASE_URL must use HTTPS.\n");
    exit(2);
}

$requestId = 'p4-media-revoked-' . time() . '-' . bin2hex(random_bytes(5));
$ch = curl_init($baseUrl . P4_REVOKE_API_PREFIX . '/capabilities');
if (false === $ch) {
    fwrite(STDERR, "Unable to initialise cURL.\n");
    exit(1);
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
    CURLOPT_USERAGENT => 'Eduardo-Research-P4-Media-Revocation-Probe/1.0',
));
$raw = curl_exec($ch);
$curlError = curl_error($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

$evidence = array(
    'schema' => 'p4-media-revocation-probe-v1',
    'generated_at' => gmdate(DATE_W3C),
    'base_url' => $baseUrl,
    'request' => array(
        'method' => 'GET',
        'route' => P4_REVOKE_API_PREFIX . '/capabilities',
        'request_id' => $requestId,
        'http_status' => $status,
    ),
    'checks' => array(),
    'result' => 'running',
);

$writeEvidence = static function (string $path, array $payload): void {
    if ('' === $path) { return; }
    $dir = dirname($path);
    if ('.' !== $dir && ! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
        throw new RuntimeException('Unable to create evidence directory: ' . $dir);
    }
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (false === file_put_contents($path, $encoded . PHP_EOL, LOCK_EX)) {
        throw new RuntimeException('Unable to write evidence file: ' . $path);
    }
    @chmod($path, 0600);
};

try {
    if (false === $raw || '' !== $curlError) {
        throw new RuntimeException('Revocation probe request failed at transport level: ' . $curlError);
    }
    try {
        $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        throw new RuntimeException('Revocation probe returned non-JSON response.');
    }
    if (! is_array($decoded)) {
        throw new RuntimeException('Revocation probe returned an invalid response object.');
    }
    $code = (string) ($decoded['code'] ?? $decoded['data']['code'] ?? '');
    $evidence['response'] = array(
        'code' => $code,
        'message' => (string) ($decoded['message'] ?? ''),
    );
    if (401 !== $status) {
        throw new RuntimeException(sprintf('Revoked credential returned HTTP %d instead of 401.', $status));
    }
    if ('authentication_failed' !== $code) {
        throw new RuntimeException('Revoked credential was not rejected with authentication_failed.');
    }
    if (! empty($decoded['ok']) || isset($decoded['data']['granted_scopes'])) {
        throw new RuntimeException('Revoked credential unexpectedly received authenticated capability data.');
    }

    $evidence['checks']['http_401'] = true;
    $evidence['checks']['authentication_failed'] = true;
    $evidence['checks']['no_authenticated_data'] = true;
    $evidence['checks']['credential_material_recorded'] = false;
    $evidence['result'] = 'pass';
    $writeEvidence($evidencePath, $evidence);
    fwrite(STDOUT, "Phase 4 Media credential revocation probe: PASS\n");
    fwrite(STDOUT, "The revoked temporary credential is rejected with 401 authentication_failed.\n");
    if ('' !== $evidencePath) { fwrite(STDOUT, "Evidence: {$evidencePath}\n"); }
    exit(0);
} catch (Throwable $error) {
    $evidence['result'] = 'fail';
    $evidence['failure'] = array('class' => get_class($error), 'message' => $error->getMessage());
    try { $writeEvidence($evidencePath, $evidence); } catch (Throwable) {}
    fwrite(STDERR, "Phase 4 Media credential revocation probe: FAIL — {$error->getMessage()}\n");
    exit(1);
}
