#!/usr/bin/env php
<?php
/**
 * Phase 4 Media metadata production guard probes.
 *
 * Proves, against one explicitly selected existing image attachment:
 * - request-id idempotency using Preview only (no mutation);
 * - stale-revision rejection using two plans from the same baseline;
 * - exact rollback of the one temporary mutation used to make plan A stale.
 *
 * The stale probe requires --apply. Once the temporary Apply is known to have happened,
 * rollback is mandatory in a finally block. The source metadata value and full attachment
 * revision must be restored before PASS.
 */
declare(strict_types=1);

const P4_MEDIA_GUARD_API_PREFIX = '/wp-json/research-manager/v1';
const P4_MEDIA_GUARD_FIELDS = array('title', 'alt_text', 'caption', 'description');

$options = getopt('', array(
    'attachment-id:',
    'field::',
    'apply',
    'evidence::',
    'help',
));

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php scripts/p4-media-metadata-guard-probes.php --attachment-id=ID [--field=alt_text] [--apply] [--evidence=FILE]\n");
    exit(0);
}

$baseUrl = rtrim(trim((string) getenv('ERM_BASE_URL')), '/');
$token = trim((string) getenv('ERM_TOKEN'));
$attachmentId = isset($options['attachment-id']) ? (int) $options['attachment-id'] : 0;
$field = trim((string) ($options['field'] ?? 'alt_text'));
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
if (! in_array($field, P4_MEDIA_GUARD_FIELDS, true)) {
    fwrite(STDERR, "--field must be one of: " . implode(', ', P4_MEDIA_GUARD_FIELDS) . "\n");
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
$request = static function (
    string $method,
    string $path,
    ?array $body,
    array $expectedStatuses,
    string $label,
    ?string $fixedRequestId = null
) use ($baseUrl, $token, &$requests, $sanitize): array {
    $method = strtoupper($method);
    $allowed = array(
        'GET' => array('#^/capabilities$#', '#^/media/\d+$#'),
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
        if (1 === preg_match($pattern, $route)) {
            $allowedRoute = true;
            break;
        }
    }
    if (! $allowedRoute) {
        throw new RuntimeException(sprintf('Route outside Phase 4 Media guard allowlist: %s %s', $method, $route));
    }

    $requestId = $fixedRequestId ?: 'p4-media-guard-' . preg_replace('/[^A-Za-z0-9._:-]/', '-', $label) . '-' . time() . '-' . bin2hex(random_bytes(4));
    $payload = null === $body ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ch = curl_init($baseUrl . P4_MEDIA_GUARD_API_PREFIX . $path);
    if (false === $ch) {
        throw new RuntimeException('Unable to initialise cURL.');
    }
    $headers = array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
        'X-Research-Manager-Request-Id: ' . $requestId,
        'X-Research-Manager-Nonce: ' . bin2hex(random_bytes(16)),
        'X-Research-Manager-Timestamp: ' . time(),
    );
    if (null !== $body) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Eduardo-Research-P4-Media-Guard-Probes/1.0',
    ));
    if (null !== $body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
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
    $requests[] = array(
        'label' => $label,
        'method' => $method,
        'route' => P4_MEDIA_GUARD_API_PREFIX . $path,
        'request_id' => $requestId,
        'http_status' => $status,
        'response' => $sanitize($decoded),
    );
    if (! in_array($status, $expectedStatuses, true)) {
        throw new RuntimeException(sprintf(
            '%s returned HTTP %d, expected %s (%s).',
            $label,
            $status,
            implode('/', $expectedStatuses),
            (string) ($decoded['code'] ?? 'no-code')
        ));
    }
    return $decoded;
};

$data = static fn(array $response): array => is_array($response['data'] ?? null) ? $response['data'] : array();
$temporaryValue = static function (string $field, string $suffix): string {
    return match ($field) {
        'title' => 'Phase 4 temporary Media guard ' . $suffix,
        'alt_text' => 'Phase 4 temporary Media guard ' . $suffix,
        'caption' => 'Phase 4 temporary Media guard ' . $suffix,
        'description' => 'Phase 4 temporary Media guard ' . $suffix,
        default => throw new RuntimeException('Unsupported Media guard field.'),
    };
};

$evidence = array(
    'schema' => 'p4-media-metadata-guard-probes-v1',
    'generated_at' => gmdate(DATE_W3C),
    'base_url' => $baseUrl,
    'attachment_id' => $attachmentId,
    'field' => $field,
    'apply_requested' => $apply,
    'checks' => array(),
    'requests' => array(),
    'result' => 'running',
);

try {
    $capabilities = $data($request('GET', '/capabilities', null, array(200), 'capabilities'));
    if ($normaliseSet((array) ($capabilities['granted_scopes'] ?? array())) !== $expectedScopes) {
        throw new RuntimeException('Temporary credential does not match the exact Phase 4 8-scope profile.');
    }
    $metadataTransport = (array) ($capabilities['media_control']['metadata_transport'] ?? array());
    if (empty($metadataTransport['idempotency']) || empty($metadataTransport['stale_attachment_revision_protection'])) {
        throw new RuntimeException('Capabilities do not declare Media metadata idempotency and stale-revision protection.');
    }
    $evidence['checks']['exact_8_scope_profile'] = true;
    $evidence['checks']['guard_contract_declared'] = true;

    $before = $data($request('GET', '/media/' . $attachmentId, null, array(200), 'media-before'));
    if ((int) ($before['id'] ?? 0) !== $attachmentId) {
        throw new RuntimeException('Media inspection did not return the selected attachment.');
    }
    if ('alt_text' === $field && 'image' !== (string) ($before['media_type'] ?? '')) {
        throw new RuntimeException('alt_text guard probe requires an image attachment.');
    }
    if (! array_key_exists($field, $before)) {
        throw new RuntimeException('Selected metadata field is not exposed by Media inspection.');
    }
    $sourceValue = (string) ($before[$field] ?? '');
    $sourceFingerprint = hash('sha256', (string) json_encode(array(
        'id' => (int) ($before['id'] ?? 0),
        'title' => (string) ($before['title'] ?? ''),
        'alt_text' => (string) ($before['alt_text'] ?? ''),
        'caption' => (string) ($before['caption'] ?? ''),
        'description' => (string) ($before['description'] ?? ''),
        'mime_type' => (string) ($before['mime_type'] ?? ''),
        'media_type' => (string) ($before['media_type'] ?? ''),
        'parent_id' => (int) ($before['parent_id'] ?? 0),
        'status' => (string) ($before['status'] ?? ''),
        'source_url' => (string) ($before['source_url'] ?? ''),
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $evidence['source'] = array('field_value' => $sourceValue, 'fingerprint' => $sourceFingerprint);

    // Idempotency proof is Preview-only and therefore safe even without --apply.
    $idemValue = $temporaryValue($field, 'idempotency-' . gmdate('YmdHis'));
    if ($idemValue === $sourceValue) {
        $idemValue .= '-alternate';
    }
    $idemBody = array(
        'operation' => 'media-metadata-update',
        'payload' => array('attachment_id' => $attachmentId, 'changes' => array($field => $idemValue)),
    );
    $fixedRequestId = 'p4-media-idempotency-' . time() . '-' . bin2hex(random_bytes(4));
    $idemFirst = $request('POST', '/media/metadata/plan', $idemBody, array(200), 'idempotency-first', $fixedRequestId);
    $idemReplay = $request('POST', '/media/metadata/plan', $idemBody, array(200), 'idempotency-replay', $fixedRequestId);
    $firstPlanId = (string) ($data($idemFirst)['plan_id'] ?? '');
    $replayPlanId = (string) ($data($idemReplay)['plan_id'] ?? '');
    if ('' === $firstPlanId || $firstPlanId !== $replayPlanId || empty($idemReplay['idempotent_replay'])) {
        throw new RuntimeException('Media metadata request-id idempotency proof failed.');
    }
    $evidence['checks']['idempotency_same_plan'] = true;
    $evidence['checks']['idempotency_replay_flag'] = true;
    $evidence['idempotency'] = array(
        'request_id' => $fixedRequestId,
        'plan_id' => $firstPlanId,
        'mutation_performed' => false,
    );

    if (! $apply) {
        $evidence['result'] = 'idempotency-pass-stale-not-run';
        $evidence['requests'] = $requests;
        fwrite(STDOUT, "Phase 4 Media metadata idempotency probe: PASS\n");
        fwrite(STDOUT, "Stale-revision probe not run because --apply was not supplied. No production mutation executed.\n");
    } else {
        $valueA = $temporaryValue($field, 'stale-A-' . gmdate('YmdHis'));
        $valueB = $temporaryValue($field, 'stale-B-' . gmdate('YmdHis'));
        if ($valueA === $sourceValue) { $valueA .= '-1'; }
        if ($valueB === $sourceValue || $valueB === $valueA) { $valueB .= '-2'; }

        $planA = $data($request('POST', '/media/metadata/plan', array(
            'operation' => 'media-metadata-update',
            'payload' => array('attachment_id' => $attachmentId, 'changes' => array($field => $valueA)),
        ), array(200), 'stale-plan-a'));
        $planB = $data($request('POST', '/media/metadata/plan', array(
            'operation' => 'media-metadata-update',
            'payload' => array('attachment_id' => $attachmentId, 'changes' => array($field => $valueB)),
        ), array(200), 'stale-plan-b'));
        $planAId = (string) ($planA['plan_id'] ?? '');
        $planBId = (string) ($planB['plan_id'] ?? '');
        if ('' === $planAId || '' === $planBId) {
            throw new RuntimeException('Stale-revision setup did not return both Media metadata plan IDs.');
        }
        if ((string) ($planA['source_revision'] ?? '') !== (string) ($planB['source_revision'] ?? '')) {
            throw new RuntimeException('Stale-revision setup plans do not share the same source revision.');
        }

        $operationB = '';
        $rollbackDone = false;
        $lifecycleFailure = null;
        try {
            $applyB = $request(
                'POST',
                '/media/metadata/plans/' . rawurlencode($planBId) . '/apply',
                array('confirm' => true),
                array(200),
                'stale-apply-b'
            );
            $operationB = (string) ($data($applyB)['operation_id'] ?? '');
            if ('' === $operationB) {
                throw new RuntimeException('Temporary Media metadata Apply did not return operation_id.');
            }

            $stale = $request(
                'POST',
                '/media/metadata/plans/' . rawurlencode($planAId) . '/apply',
                array('confirm' => true),
                array(409),
                'stale-apply-a'
            );
            $staleCode = (string) ($stale['code'] ?? $stale['data']['code'] ?? '');
            if ('stale_revision' !== $staleCode) {
                throw new RuntimeException('Stale Media metadata plan was not rejected with stale_revision.');
            }
            $evidence['checks']['stale_plan_rejected'] = true;

            $verified = $data($request(
                'POST',
                '/media/metadata/operations/' . rawurlencode($operationB) . '/verify',
                array('rendered' => false),
                array(200),
                'stale-verify-b'
            ));
            if ('verified' !== (string) ($verified['status'] ?? '') || empty($verified['stored_verification']['verified'])) {
                throw new RuntimeException('Temporary Media metadata operation did not verify before rollback.');
            }
            $evidence['checks']['temporary_operation_verified'] = true;
        } catch (Throwable $error) {
            $lifecycleFailure = $error;
        } finally {
            if ('' !== $operationB) {
                try {
                    $rolledBack = $data($request(
                        'POST',
                        '/media/metadata/operations/' . rawurlencode($operationB) . '/rollback',
                        array('confirm' => true),
                        array(200),
                        'stale-rollback-b'
                    ));
                    if ('rolled-back' !== (string) ($rolledBack['status'] ?? '') || empty($rolledBack['rollback_restored_source_revision'])) {
                        throw new RuntimeException('Media metadata stale-probe rollback did not restore the source revision.');
                    }
                    $rollbackDone = true;
                    $evidence['checks']['stale_probe_rollback_restored_revision'] = true;
                } catch (Throwable $rollbackError) {
                    $prior = $lifecycleFailure ? ' Prior lifecycle failure: ' . $lifecycleFailure->getMessage() : '';
                    throw new RuntimeException('CRITICAL: Media metadata stale-probe rollback failed after Apply. ' . $rollbackError->getMessage() . $prior);
                }
            }
        }

        if ($lifecycleFailure instanceof Throwable) {
            throw new RuntimeException('Media metadata stale guard failed but rollback completed: ' . $lifecycleFailure->getMessage());
        }
        if (! $rollbackDone) {
            throw new RuntimeException('Media metadata stale guard did not complete its required rollback.');
        }

        $after = $data($request('GET', '/media/' . $attachmentId, null, array(200), 'media-after'));
        $afterFingerprint = hash('sha256', (string) json_encode(array(
            'id' => (int) ($after['id'] ?? 0),
            'title' => (string) ($after['title'] ?? ''),
            'alt_text' => (string) ($after['alt_text'] ?? ''),
            'caption' => (string) ($after['caption'] ?? ''),
            'description' => (string) ($after['description'] ?? ''),
            'mime_type' => (string) ($after['mime_type'] ?? ''),
            'media_type' => (string) ($after['media_type'] ?? ''),
            'parent_id' => (int) ($after['parent_id'] ?? 0),
            'status' => (string) ($after['status'] ?? ''),
            'source_url' => (string) ($after['source_url'] ?? ''),
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ((string) ($after[$field] ?? '') !== $sourceValue || $afterFingerprint !== $sourceFingerprint) {
            throw new RuntimeException('Post-rollback Media attachment does not exactly match the original source state.');
        }
        $evidence['checks']['exact_source_state_restored'] = true;
        $evidence['stale_revision'] = array(
            'plan_a' => $planAId,
            'plan_b' => $planBId,
            'operation_b' => $operationB,
            'error_code' => 'stale_revision',
            'rollback_verified' => true,
            'source_fingerprint_restored' => true,
        );
        $evidence['result'] = 'pass';
        $evidence['requests'] = $requests;
        fwrite(STDOUT, "Phase 4 Media metadata guard probes: PASS\n");
        fwrite(STDOUT, "Idempotency replay + stale_revision rejection + exact rollback all verified.\n");
    }
} catch (Throwable $error) {
    $evidence['result'] = 'fail';
    $evidence['failure'] = array('class' => get_class($error), 'message' => $error->getMessage());
    $evidence['requests'] = $requests;
    if ('' !== $evidencePath) {
        $dir = dirname($evidencePath);
        if ('.' !== $dir && ! is_dir($dir)) { @mkdir($dir, 0700, true); }
        @file_put_contents($evidencePath, (string) json_encode($sanitize($evidence), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, LOCK_EX);
        @chmod($evidencePath, 0600);
    }
    fwrite(STDERR, "Phase 4 Media metadata guard probes: FAIL — {$error->getMessage()}\n");
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
