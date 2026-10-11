#!/usr/bin/env php
<?php
/**
 * Phase 4.4 controlled production acceptance for Theme-owned Insight Media assignment.
 *
 * The client targets one published Insight and one existing image attachment. Preview is
 * non-mutating by default. With --apply it must complete Apply -> stored+rendered Verify ->
 * Rollback, and it proves both the stored relationship and public primary-Media marker return
 * to the exact pre-Apply state. Once an Apply is known to have happened, rollback is mandatory.
 */
declare(strict_types=1);

const P4_ASSIGN_API_PREFIX = '/wp-json/research-manager/v1';

$options = getopt('', array(
    'insight-id:',
    'attachment-id:',
    'apply',
    'evidence::',
    'help',
));

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php scripts/p4-media-assignment-production-acceptance.php --insight-id=ID --attachment-id=ID [--apply] [--evidence=path]\n");
    exit(0);
}

$baseUrl = rtrim(trim((string) getenv('ERM_BASE_URL')), '/');
$token = trim((string) getenv('ERM_TOKEN'));
$insightId = isset($options['insight-id']) ? (int) $options['insight-id'] : 0;
$attachmentId = isset($options['attachment-id']) ? (int) $options['attachment-id'] : 0;
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
if ($insightId <= 0 || $attachmentId <= 0) {
    fwrite(STDERR, "--insight-id and --attachment-id must identify existing production resources.\n");
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
            '#^/insights/\d+$#',
            '#^/media/assignments$#',
            '#^/media/assignments/plans/[A-Za-z0-9._:-]+$#',
            '#^/media/assignments/operations/[A-Za-z0-9._:-]+$#',
        ),
        'POST' => array(
            '#^/media/assignments/plan$#',
            '#^/media/assignments/plans/[A-Za-z0-9._:-]+/apply$#',
            '#^/media/assignments/operations/[A-Za-z0-9._:-]+/verify$#',
            '#^/media/assignments/operations/[A-Za-z0-9._:-]+/rollback$#',
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
        throw new RuntimeException(sprintf('Route outside Phase 4 Media assignment allowlist: %s %s', $method, $route));
    }

    $requestId = 'p4-media-assignment-' . preg_replace('/[^A-Za-z0-9._:-]/', '-', $label) . '-' . time() . '-' . bin2hex(random_bytes(4));
    $payload = null === $body ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ch = curl_init($baseUrl . P4_ASSIGN_API_PREFIX . $path);
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
        CURLOPT_USERAGENT => 'Eduardo-Research-P4-Media-Assignment-Acceptance/1.0',
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
        'route' => P4_ASSIGN_API_PREFIX . $path,
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
    return $decoded;
};

$fetchRenderedMarkers = static function (string $url): array {
    if (! preg_match('#^https://#i', $url)) {
        throw new RuntimeException('Rendered verification URL must use HTTPS.');
    }
    $ch = curl_init($url);
    if (false === $ch) {
        throw new RuntimeException('Unable to initialise rendered verification request.');
    }
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array('Accept: text/html,application/xhtml+xml'),
        CURLOPT_USERAGENT => 'Eduardo-Research-P4-Media-Assignment-Public-Verify/1.0',
    ));
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (false === $body || '' !== $error || 200 !== $status) {
        throw new RuntimeException(sprintf('Rendered public verification failed (HTTP %d): %s', $status, $error));
    }
    $ids = array();
    if (preg_match_all('~<figure\b(?=[^>]*\bdata-research-media-slot=["\']primary["\'])(?=[^>]*\bdata-attachment-id=["\'](\d+)["\'])[^>]*>~is', (string) $body, $matches)) {
        $ids = array_values(array_unique(array_map('intval', (array) ($matches[1] ?? array()))));
        sort($ids);
    }
    return $ids;
};

$data = static fn(array $response): array => is_array($response['data'] ?? null) ? $response['data'] : array();
$assignmentPath = '/media/assignments?type=insight&post_id=' . $insightId . '&slot=primary';
$evidence = array(
    'schema' => 'p4-media-assignment-production-acceptance-v1',
    'generated_at' => gmdate(DATE_W3C),
    'base_url' => $baseUrl,
    'insight_id' => $insightId,
    'attachment_id' => $attachmentId,
    'apply_requested' => $apply,
    'requests' => array(),
    'checks' => array(),
    'result' => 'running',
);

try {
    $capabilities = $data($request('GET', '/capabilities', null, 'capabilities'));
    if ($normaliseSet((array) ($capabilities['granted_scopes'] ?? array())) !== $expectedScopes) {
        throw new RuntimeException('Temporary credential does not match the exact Phase 4 8-scope profile.');
    }
    $assignmentContract = (array) ($capabilities['media_control']['assignment'] ?? array());
    if (empty($assignmentContract['available']) || empty($assignmentContract['transport']['rendered_verification'])) {
        throw new RuntimeException('Remote capabilities do not expose the required rendered Media assignment contract.');
    }
    $evidence['checks']['exact_8_scope_profile'] = true;
    $evidence['checks']['assignment_contract_available'] = true;

    $insight = $data($request('GET', '/insights/' . $insightId, null, 'insight'));
    if ((int) ($insight['post_id'] ?? $insight['id'] ?? 0) !== $insightId || 'publish' !== (string) ($insight['status'] ?? '')) {
        throw new RuntimeException('Phase 4 assignment acceptance requires the explicitly selected published Insight.');
    }
    $targetUrl = (string) ($insight['url'] ?? '');
    if (! preg_match('#^https://#i', $targetUrl)) {
        throw new RuntimeException('Published Insight did not expose a valid HTTPS public URL.');
    }

    $media = $data($request('GET', '/media/' . $attachmentId, null, 'media'));
    if ((int) ($media['id'] ?? 0) !== $attachmentId || 'image' !== (string) ($media['media_type'] ?? '')) {
        throw new RuntimeException('Phase 4 assignment acceptance requires an existing image attachment.');
    }

    $before = $data($request('GET', $assignmentPath, null, 'assignment-before'));
    $previousAttachmentId = (int) ($before['attachment_id'] ?? 0);
    if ($previousAttachmentId === $attachmentId) {
        throw new RuntimeException('Candidate image is already assigned. Select a different safe image to prove a real reversible change.');
    }
    $beforeMarkers = $fetchRenderedMarkers($targetUrl);
    $evidence['source'] = array(
        'previous_attachment_id' => $previousAttachmentId,
        'rendered_primary_attachment_ids' => $beforeMarkers,
        'target_url' => $targetUrl,
    );

    $plan = $data($request('POST', '/media/assignments/plan', array(
        'payload' => array(
            'target' => array('type' => 'insight', 'post_id' => $insightId),
            'attachment_id' => $attachmentId,
            'slot' => 'primary',
        ),
    ), 'plan'));
    $planId = (string) ($plan['plan_id'] ?? '');
    if ('' === $planId || empty($plan['apply_allowed']) || empty($plan['confirmation_required']) || 'editorial-review' !== (string) ($plan['risk'] ?? '')) {
        throw new RuntimeException('Media assignment Preview did not produce the expected explicitly confirmable plan.');
    }
    if ((int) ($plan['preview']['actions'][0]['before'] ?? -1) !== $previousAttachmentId
        || (int) ($plan['preview']['actions'][0]['after'] ?? 0) !== $attachmentId
        || empty($plan['preview']['actions'][0]['changed'])) {
        throw new RuntimeException('Media assignment Preview does not describe the exact expected relationship change.');
    }
    $evidence['plan'] = $sanitize($plan);
    $evidence['checks']['preview_created'] = true;
    $evidence['checks']['explicit_confirmation_required'] = true;

    if (! $apply) {
        $evidence['result'] = 'preview-pass';
        $evidence['requests'] = $requests;
        fwrite(STDOUT, "Phase 4 Media assignment acceptance PREVIEW: PASS\n");
        fwrite(STDOUT, "No production mutation executed. Re-run with --apply only after reviewing the exact target and image.\n");
    } else {
        $operationId = '';
        $applyFailure = null;
        try {
            $operation = $data($request(
                'POST',
                '/media/assignments/plans/' . rawurlencode($planId) . '/apply',
                array('confirm' => true),
                'apply'
            ));
            $operationId = (string) ($operation['operation_id'] ?? '');
            if ('' === $operationId || ! in_array((string) ($operation['status'] ?? ''), array('applied', 'verified'), true)) {
                throw new RuntimeException('Media assignment Apply did not return a valid operation.');
            }
        } catch (Throwable $error) {
            $applyFailure = $error;
            try {
                $storedPlan = $data($request('GET', '/media/assignments/plans/' . rawurlencode($planId), null, 'apply-reconcile-plan'));
                if ('applied' === (string) ($storedPlan['status'] ?? '') && '' !== (string) ($storedPlan['operation_id'] ?? '')) {
                    $operationId = (string) $storedPlan['operation_id'];
                    $evidence['checks']['ambiguous_apply_reconciled'] = true;
                }
            } catch (Throwable $reconcileError) {
                $evidence['apply_reconciliation_failure'] = $reconcileError->getMessage();
            }
            if ('' === $operationId) {
                throw $error;
            }
        }

        $evidence['operation_id'] = $operationId;
        $evidence['checks']['apply_completed'] = true;
        $lifecycleFailure = $applyFailure;

        try {
            $verified = $data($request(
                'POST',
                '/media/assignments/operations/' . rawurlencode($operationId) . '/verify',
                array('rendered' => true),
                'verify'
            ));
            if ('verified' !== (string) ($verified['status'] ?? '')
                || empty($verified['stored_verification']['verified'])
                || empty($verified['rendered_verification']['verified'])) {
                throw new RuntimeException('Media assignment stored/rendered verification failed.');
            }
            $evidence['checks']['stored_verification'] = true;
            $evidence['checks']['rendered_verification'] = true;
        } catch (Throwable $verifyError) {
            $lifecycleFailure = $lifecycleFailure ?: $verifyError;
            $evidence['verification_failure'] = $verifyError->getMessage();
        } finally {
            try {
                $rolledBack = $data($request(
                    'POST',
                    '/media/assignments/operations/' . rawurlencode($operationId) . '/rollback',
                    array('confirm' => true),
                    'rollback'
                ));
                if ('rolled-back' !== (string) ($rolledBack['status'] ?? '') || empty($rolledBack['rollback_restored_relationship'])) {
                    throw new RuntimeException('Media assignment rollback did not restore the previous relationship.');
                }
                $after = $data($request('GET', $assignmentPath, null, 'assignment-after'));
                if ((int) ($after['attachment_id'] ?? 0) !== $previousAttachmentId) {
                    throw new RuntimeException('Post-rollback assignment inspection does not match the original relationship.');
                }
                $afterMarkers = $fetchRenderedMarkers($targetUrl);
                if ($afterMarkers !== $beforeMarkers) {
                    throw new RuntimeException('Post-rollback public primary-Media markers do not match the original rendered state.');
                }
                $evidence['checks']['rollback_restored_relationship'] = true;
                $evidence['checks']['post_rollback_rendered_state_restored'] = true;
                $evidence['restored'] = array(
                    'attachment_id' => (int) ($after['attachment_id'] ?? 0),
                    'rendered_primary_attachment_ids' => $afterMarkers,
                );
            } catch (Throwable $rollbackError) {
                $evidence['rollback_failure'] = $rollbackError->getMessage();
                $prior = $lifecycleFailure ? ' Prior lifecycle failure: ' . $lifecycleFailure->getMessage() : '';
                throw new RuntimeException('CRITICAL: Media assignment rollback failed after Apply. ' . $rollbackError->getMessage() . $prior);
            }
        }

        if ($lifecycleFailure instanceof Throwable) {
            throw new RuntimeException('Media assignment lifecycle failed but rollback restored the source state: ' . $lifecycleFailure->getMessage());
        }

        $evidence['result'] = 'pass';
        $evidence['requests'] = $requests;
        fwrite(STDOUT, "Phase 4 Media assignment production acceptance: PASS\n");
        fwrite(STDOUT, "Apply → rendered Verify → Rollback completed; stored and public state restored.\n");
    }
} catch (Throwable $error) {
    $evidence['result'] = 'fail';
    $evidence['failure'] = array('class' => get_class($error), 'message' => $error->getMessage());
    $evidence['requests'] = $requests;
    if ('' !== $evidencePath) {
        $dir = dirname($evidencePath);
        if ('.' !== $dir && ! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents(
            $evidencePath,
            (string) json_encode($sanitize($evidence), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            LOCK_EX
        );
        @chmod($evidencePath, 0600);
    }
    fwrite(STDERR, "Phase 4 Media assignment production acceptance: FAIL — {$error->getMessage()}\n");
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
