#!/usr/bin/env php
<?php
/**
 * Phase 4.3 controlled production acceptance for Remote Media metadata.
 *
 * Safety model:
 * - bearer token is read only from ERM_TOKEN;
 * - HTTPS only, redirects disabled, TLS verification enabled;
 * - exact temporary 8-scope Media acceptance profile is required;
 * - only one explicitly selected existing image attachment can be targeted;
 * - only bounded metadata fields are accepted;
 * - Preview is always executed first;
 * - Apply requires --apply and explicit confirmation in the REST call;
 * - successful Apply is always verified and then rolled back;
 * - rollback must restore the exact source revision;
 * - evidence never stores the bearer token.
 *
 * Usage:
 *   ERM_BASE_URL='https://eduardoyauriluna.com' ERM_TOKEN='...' \
 *     php scripts/p4-media-metadata-production-acceptance.php \
 *     --attachment-id=123 --field=alt_text --value='Temporary Phase 4 acceptance text'
 *
 * To execute the reversible mutation lifecycle:
 *   ... --apply --evidence=tmp/p4-media-metadata-production-acceptance.json
 */
declare(strict_types=1);

const P4_MEDIA_API_PREFIX = '/wp-json/research-manager/v1';
const P4_MEDIA_FIELDS = array('title', 'alt_text', 'caption', 'description');

$options = getopt('', array(
    'attachment-id:',
    'field:',
    'value:',
    'apply',
    'evidence::',
    'help',
));

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php scripts/p4-media-metadata-production-acceptance.php --attachment-id=ID --field=alt_text --value='Temporary text' [--apply] [--evidence=path]\n");
    exit(0);
}

$baseUrl = rtrim(trim((string) getenv('ERM_BASE_URL')), '/');
$token = trim((string) getenv('ERM_TOKEN'));
$attachmentId = isset($options['attachment-id']) ? (int) $options['attachment-id'] : 0;
$field = trim((string) ($options['field'] ?? ''));
$value = (string) ($options['value'] ?? '');
$apply = isset($options['apply']);
$evidencePath = trim((string) ($options['evidence'] ?? ''));

if ('' === $baseUrl || '' === $token) {
    fwrite(STDERR, "ERM_BASE_URL and ERM_TOKEN must be set in the local environment.\n");
    exit(2);
}
if (! preg_match('#^https://#i', $baseUrl)) {
    fwrite(STDERR, "ERM_BASE_URL must use HTTPS.\n");
    exit(2);
}
if ($attachmentId <= 0) {
    fwrite(STDERR, "--attachment-id must identify one existing Media attachment.\n");
    exit(2);
}
if (! in_array($field, P4_MEDIA_FIELDS, true)) {
    fwrite(STDERR, "--field must be one of: " . implode(', ', P4_MEDIA_FIELDS) . "\n");
    exit(2);
}
if ('title' === $field && '' === trim($value)) {
    fwrite(STDERR, "Media title cannot be empty.\n");
    exit(2);
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

$requests = array();
$request = static function (string $method, string $path, ?array $body, string $label) use ($baseUrl, $token, &$requests, $sanitize): array {
    $method = strtoupper($method);
    $allowed = array(
        'GET' => array(
            '#^/capabilities$#',
            '#^/media/\d+$#',
            '#^/media/metadata/plans/[A-Za-z0-9._:-]+$#',
            '#^/media/metadata/operations/[A-Za-z0-9._:-]+$#',
        ),
        'POST' => array(
            '#^/media/metadata/plan$#',
            '#^/media/metadata/plans/[A-Za-z0-9._:-]+/apply$#',
            '#^/media/metadata/operations/[A-Za-z0-9._:-]+/verify$#',
            '#^/media/metadata/operations/[A-Za-z0-9._:-]+/rollback$#',
        ),
    );
    $route = (string) (parse_url($path, PHP_URL_PATH) ?: '');
    $allowedRoute = false;
    foreach ($allowed[$method] ?? array() as $pattern) {
        if (1 === preg_match($pattern, $route)) { $allowedRoute = true; break; }
    }
    if (! $allowedRoute) {
        throw new RuntimeException(sprintf('Route outside Phase 4 Media metadata allowlist: %s %s', $method, $route));
    }

    $requestId = 'p4-media-metadata-' . preg_replace('/[^A-Za-z0-9._:-]/', '-', $label) . '-' . time() . '-' . bin2hex(random_bytes(4));
    $nonce = bin2hex(random_bytes(16));
    $payload = null === $body ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $url = $baseUrl . P4_MEDIA_API_PREFIX . $path;

    $ch = curl_init($url);
    if (false === $ch) { throw new RuntimeException('Unable to initialise cURL.'); }
    $headers = array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
        'X-Research-Manager-Request-Id: ' . $requestId,
        'X-Research-Manager-Nonce: ' . $nonce,
        'X-Research-Manager-Timestamp: ' . time(),
    );
    if (null !== $body) { $headers[] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Eduardo-Research-P4-Media-Metadata-Acceptance/1.0',
    ));
    if (null !== $body) { curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); }

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
    if (! is_array($decoded)) { throw new RuntimeException($label . ' returned an invalid payload.'); }

    $requests[] = array(
        'label'=>$label,
        'method'=>$method,
        'route'=>P4_MEDIA_API_PREFIX . $path,
        'request_id'=>$requestId,
        'http_status'=>$status,
        'response'=>$sanitize($decoded),
    );

    if ($status < 200 || $status >= 300 || empty($decoded['ok']) || ! is_array($decoded['data'] ?? null)) {
        $code = (string) ($decoded['code'] ?? 'request_failed');
        $message = (string) ($decoded['message'] ?? 'Request did not succeed.');
        throw new RuntimeException(sprintf('%s failed: HTTP %d %s — %s', $label, $status, $code, $message));
    }
    return $decoded;
};

$data = static fn(array $response): array => is_array($response['data'] ?? null) ? $response['data'] : array();
$evidence = array(
    'schema'=>'p4-media-metadata-production-acceptance-v1',
    'generated_at'=>gmdate(DATE_W3C),
    'base_url'=>$baseUrl,
    'attachment_id'=>$attachmentId,
    'field'=>$field,
    'apply_requested'=>$apply,
    'requests'=>array(),
    'checks'=>array(),
    'result'=>'running',
);

try {
    $capabilities = $data($request('GET', '/capabilities', null, 'capabilities'));
    $grantedScopes = $normaliseSet((array) ($capabilities['granted_scopes'] ?? array()));
    if ($grantedScopes !== $expectedScopes) {
        throw new RuntimeException('Temporary credential does not match the exact Phase 4 8-scope profile.');
    }
    $evidence['checks']['exact_8_scope_profile'] = true;

    $media = $data($request('GET', '/media/' . $attachmentId, null, 'media-before'));
    if ((int) ($media['id'] ?? 0) !== $attachmentId) {
        throw new RuntimeException('Media inspection did not return the selected attachment.');
    }
    if ('alt_text' === $field && 'image' !== (string) ($media['media_type'] ?? '')) {
        throw new RuntimeException('alt_text acceptance requires an image attachment.');
    }
    if (! array_key_exists($field, $media)) {
        throw new RuntimeException('Selected metadata field is not exposed by Media inspection.');
    }
    $sourceValue = (string) ($media[$field] ?? '');
    if ($sourceValue === $value) {
        throw new RuntimeException('Acceptance value already matches production. Choose a temporary value that creates a real reversible change.');
    }
    $evidence['source'] = array(
        'attachment_id'=>$attachmentId,
        'field'=>$field,
        'value'=>$sourceValue,
        'media_type'=>(string) ($media['media_type'] ?? ''),
        'source_url'=>(string) ($media['source_url'] ?? ''),
    );

    $plan = $data($request('POST', '/media/metadata/plan', array(
        'operation'=>'media-metadata-update',
        'payload'=>array(
            'attachment_id'=>$attachmentId,
            'changes'=>array($field=>$value),
        ),
    ), 'plan'));
    $planId = (string) ($plan['plan_id'] ?? '');
    if ('' === $planId || empty($plan['apply_allowed']) || empty($plan['confirmation_required'])) {
        throw new RuntimeException('Media metadata Preview did not produce an explicitly confirmable plan.');
    }
    if ('editorial-review' !== (string) ($plan['risk'] ?? '')) {
        throw new RuntimeException('Unexpected Media metadata risk classification.');
    }
    $evidence['plan'] = $sanitize($plan);
    $evidence['checks']['preview_created'] = true;
    $evidence['checks']['explicit_confirmation_required'] = true;

    if (! $apply) {
        $evidence['result'] = 'preview-pass';
        $evidence['requests'] = $requests;
        fwrite(STDOUT, "Phase 4 Media metadata acceptance PREVIEW: PASS\n");
        fwrite(STDOUT, "No production mutation executed. Re-run with --apply only after reviewing the plan.\n");
    } else {
        $operation = $data($request('POST', '/media/metadata/plans/' . rawurlencode($planId) . '/apply', array('confirm'=>true), 'apply'));
        $operationId = (string) ($operation['operation_id'] ?? '');
        if ('' === $operationId || ! in_array((string) ($operation['status'] ?? ''), array('applied','verified'), true)) {
            throw new RuntimeException('Media metadata Apply did not return a valid operation.');
        }
        $evidence['operation_id'] = $operationId;
        $evidence['checks']['apply_completed'] = true;

        $verified = $data($request('POST', '/media/metadata/operations/' . rawurlencode($operationId) . '/verify', array('rendered'=>false), 'verify'));
        if ('verified' !== (string) ($verified['status'] ?? '') || empty($verified['stored_verification']['verified'])) {
            throw new RuntimeException('Media metadata stored-state verification failed.');
        }
        $evidence['checks']['stored_verification'] = true;

        $rolledBack = $data($request('POST', '/media/metadata/operations/' . rawurlencode($operationId) . '/rollback', array('confirm'=>true), 'rollback'));
        if ('rolled-back' !== (string) ($rolledBack['status'] ?? '') || empty($rolledBack['rollback_restored_source_revision'])) {
            throw new RuntimeException('Media metadata rollback did not restore the exact source revision.');
        }
        $evidence['checks']['rollback_restored_source_revision'] = true;

        $after = $data($request('GET', '/media/' . $attachmentId, null, 'media-after'));
        $restoredValue = (string) ($after[$field] ?? '');
        if ($restoredValue !== $sourceValue) {
            throw new RuntimeException('Post-rollback Media inspection does not match the original metadata value.');
        }
        $evidence['checks']['post_rollback_value_restored'] = true;
        $evidence['result'] = 'pass';
        $evidence['requests'] = $requests;
        fwrite(STDOUT, "Phase 4 Media metadata production acceptance: PASS\n");
        fwrite(STDOUT, "Apply → Verify → Rollback completed and original value restored.\n");
    }
} catch (Throwable $error) {
    $evidence['result'] = 'fail';
    $evidence['failure'] = array('class'=>get_class($error), 'message'=>$error->getMessage());
    $evidence['requests'] = $requests;
    if ('' !== $evidencePath) {
        $dir = dirname($evidencePath);
        if ('.' !== $dir && ! is_dir($dir)) { @mkdir($dir, 0700, true); }
        @file_put_contents($evidencePath, (string) json_encode($sanitize($evidence), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, LOCK_EX);
        @chmod($evidencePath, 0600);
    }
    fwrite(STDERR, "Phase 4 Media metadata production acceptance: FAIL — {$error->getMessage()}\n");
    exit(1);
}

if ('' !== $evidencePath) {
    $dir = dirname($evidencePath);
    if ('.' !== $dir && ! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
        fwrite(STDERR, "Unable to create evidence directory: {$dir}\n");
        exit(1);
    }
    $encoded = json_encode($sanitize($evidence), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (false === $encoded || false === file_put_contents($evidencePath, $encoded . PHP_EOL, LOCK_EX)) {
        fwrite(STDERR, "Unable to write evidence file: {$evidencePath}\n");
        exit(1);
    }
    @chmod($evidencePath, 0600);
    fwrite(STDOUT, "Evidence: {$evidencePath}\n");
}

exit(0);
