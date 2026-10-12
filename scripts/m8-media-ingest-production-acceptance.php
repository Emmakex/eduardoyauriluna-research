#!/usr/bin/env php
<?php
declare(strict_types=1);

final class M8MediaIngestProductionAcceptance
{
    private const API_PREFIX = '/wp-json/research-manager/v1';

    private string $baseUrl;
    private string $token;
    private string $evidencePath;
    private bool $apply;
    private string $runId;
    private string $localFile = '';
    private string $planId = '';
    private string $operationId = '';
    private int $attachmentId = 0;
    private bool $remoteCleanupCompleted = false;
    private array $evidence = [];

    public function __construct(array $argv)
    {
        $this->baseUrl = rtrim((string) getenv('ERM_BASE_URL'), '/');
        $this->token = trim((string) getenv('ERM_TOKEN'));
        $this->apply = in_array('--apply', $argv, true);
        $this->runId = gmdate('YmdHis') . '-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $this->evidencePath = $this->argument($argv, '--evidence=')
            ?: 'tmp/m8-media-ingest-production-acceptance-' . $this->runId . '.json';
    }

    public function run(): int
    {
        try {
            $this->validateRuntime();
            $this->localFile = $this->createUniquePng();
            $this->evidence = [
                'schema_version' => 1,
                'milestone' => 'M8',
                'slice' => 'media-ingest-production-acceptance',
                'site' => $this->baseUrl,
                'apply_requested' => $this->apply,
                'fixture' => [
                    'kind' => 'generated-1x1-png',
                    'sha256' => hash_file('sha256', $this->localFile),
                    'bytes' => filesize($this->localFile),
                ],
                'started_at' => gmdate(DATE_ATOM),
                'requests' => [],
                'checks' => [],
                'result' => 'running',
            ];
            $this->execute();
            $this->evidence['result'] = 'pass';
            $this->evidence['finished_at'] = gmdate(DATE_ATOM);
            $this->writeEvidence();
            @unlink($this->localFile);
            return 0;
        } catch (Throwable $error) {
            $cleanupError = '';
            try {
                $this->cleanupRemoteAfterFailure();
            } catch (Throwable $cleanupFailure) {
                $cleanupError = $cleanupFailure->getMessage();
            }
            $this->evidence['result'] = 'fail';
            $this->evidence['failure'] = [
                'class' => get_class($error),
                'message' => $error->getMessage(),
                'remote_cleanup_error' => $cleanupError,
            ];
            $this->evidence['finished_at'] = gmdate(DATE_ATOM);
            $this->writeEvidence();
            if ('' !== $this->localFile) { @unlink($this->localFile); }
            fwrite(STDERR, 'M8 Media ingest production acceptance: FAIL — ' . $error->getMessage() . PHP_EOL);
            if ('' !== $cleanupError) { fwrite(STDERR, 'Emergency remote cleanup also failed: ' . $cleanupError . PHP_EOL); }
            fwrite(STDERR, 'Evidence: ' . $this->evidencePath . PHP_EOL);
            return 1;
        }
    }

    private function execute(): void
    {
        $title = 'M8 Media ingest acceptance ' . $this->runId;
        $filename = 'm8-media-ingest-' . $this->runId . '.png';
        $alt = 'Temporary one-pixel image used to verify the authenticated Research Manager Media ingest lifecycle.';
        $checksum = (string) hash_file('sha256', $this->localFile);
        $size = (int) filesize($this->localFile);

        $capabilities = $this->request('GET', '/capabilities', null, [200], 'capabilities');
        $control = (array) ($this->data($capabilities)['media_ingest_control'] ?? []);
        $this->check('media-ingest-capability-advertised',
            !empty($control['available'])
            && '/research-manager/v1/media/ingest' === (string) ($control['transport_base'] ?? '')
            && 'file' === (string) ($control['multipart_field'] ?? '')
            && 'sha256' === (string) ($control['checksum'] ?? '')
            && !empty($control['image_alt_required'])
            && 'private-temporary-ttl-15m' === (string) ($control['staging'] ?? '')
            && 900 === (int) ($control['staging_ttl_seconds'] ?? 0)
            && !empty($control['preview_cancel'])
            && !empty($control['rollback_requires_unused_owned_attachment'])
            && empty($control['arbitrary_url_ingest'])
            && empty($control['arbitrary_filesystem_path']),
            ['media_ingest_control' => $control]
        );

        $before = $this->request('GET', '/media?search=' . rawurlencode($title) . '&per_page=10', null, [200], 'inventory-before');
        $this->check('fixture-absent-before-preview', 0 === (int) ($this->data($before)['total'] ?? -1), [
            'total' => $this->data($before)['total'] ?? null,
        ]);

        $planResponse = $this->requestMultipart('/media/ingest/plan', [
            'file' => new CURLFile($this->localFile, 'image/png', $filename),
            'title' => $title,
            'alt_text' => $alt,
            'caption' => 'Temporary M8 Media ingest acceptance fixture.',
            'description' => 'Created only for reversible production acceptance and removed immediately after verification.',
        ], [200], 'media-ingest-plan');
        $plan = $this->data($planResponse);
        $this->planId = (string) ($plan['plan_id'] ?? '');
        $this->check('preview-plan-id-present', '' !== $this->planId, ['plan_id' => $this->planId]);
        $this->check('preview-binary-contract',
            'planned' === (string) ($plan['status'] ?? '')
            && !empty($plan['apply_allowed'])
            && 'image/png' === (string) ($plan['file']['mime_type'] ?? '')
            && $checksum === (string) ($plan['file']['sha256'] ?? '')
            && $size === (int) ($plan['file']['size'] ?? 0)
            && $title === (string) ($plan['metadata']['title'] ?? '')
            && $alt === (string) ($plan['metadata']['alt_text'] ?? '')
            && !array_key_exists('staged_path', $plan)
            && 'scheduled-or-explicit-cancel' === (string) ($plan['cleanup']['policy'] ?? '')
            && 900 === (int) ($plan['cleanup']['ttl_seconds'] ?? 0),
            ['plan' => $plan]
        );

        $afterPreview = $this->request('GET', '/media?search=' . rawurlencode($title) . '&per_page=10', null, [200], 'inventory-after-preview');
        $this->check('preview-created-no-attachment', 0 === (int) ($this->data($afterPreview)['total'] ?? -1), [
            'total' => $this->data($afterPreview)['total'] ?? null,
        ]);

        if (!$this->apply) {
            $cancel = $this->request('POST', '/media/ingest/plans/' . rawurlencode($this->planId) . '/cancel', [], [200], 'media-ingest-preview-cancel');
            $cancelled = $this->data($cancel);
            $this->check('preview-cancelled-cleanly', 'cancelled' === (string) ($cancelled['status'] ?? ''), ['status' => $cancelled['status'] ?? null]);
            $planAfterCancel = $this->request('GET', '/media/ingest/plans/' . rawurlencode($this->planId), null, [200], 'media-ingest-plan-after-cancel');
            $this->check('cancel-state-readable', 'cancelled' === (string) ($this->data($planAfterCancel)['status'] ?? ''), ['plan' => $this->data($planAfterCancel)]);
            $this->remoteCleanupCompleted = true;
            $this->evidence['apply_skipped'] = true;
            $this->evidence['attachment_created'] = false;
            $this->evidence['preview_staging_cancelled'] = true;
            $this->writeEvidence();
            fwrite(STDOUT, "M8 Media ingest production Preview: PASS\n");
            fwrite(STDOUT, "Private staging was checksum-verified and explicitly cancelled; no Media attachment was created.\n");
            fwrite(STDOUT, 'Evidence: ' . $this->evidencePath . PHP_EOL);
            return;
        }

        $applyResponse = $this->request('POST', '/media/ingest/plans/' . rawurlencode($this->planId) . '/apply', ['confirm' => true], [200], 'media-ingest-apply');
        $operation = $this->data($applyResponse);
        $this->operationId = (string) ($operation['operation_id'] ?? '');
        $this->attachmentId = (int) ($operation['target']['attachment_id'] ?? 0);
        $this->check('apply-created-owned-attachment',
            '' !== $this->operationId && $this->attachmentId > 0 && 'applied' === (string) ($operation['status'] ?? ''),
            ['operation_id' => $this->operationId, 'attachment_id' => $this->attachmentId, 'status' => $operation['status'] ?? null]
        );

        $inspectResponse = $this->request('GET', '/media/' . $this->attachmentId, null, [200], 'media-inspect-after-apply');
        $media = $this->data($inspectResponse);
        $this->check('attachment-safe-readback',
            $this->attachmentId === (int) ($media['id'] ?? 0)
            && $title === (string) ($media['title'] ?? '')
            && $alt === (string) ($media['alt_text'] ?? '')
            && 'image/png' === (string) ($media['mime_type'] ?? '')
            && 0 === (int) ($media['parent_id'] ?? -1)
            && 'inherit' === (string) ($media['status'] ?? ''),
            ['media' => $media]
        );

        $verifyResponse = $this->request('POST', '/media/ingest/operations/' . rawurlencode($this->operationId) . '/verify', [], [200], 'media-ingest-verify');
        $verified = $this->data($verifyResponse);
        $verification = (array) ($verified['verification'] ?? []);
        $this->check('ingest-verification-complete',
            'verified' === (string) ($verified['status'] ?? '')
            && !empty($verification['verified'])
            && !empty($verification['attachment_exists'])
            && !empty($verification['checksum_matches'])
            && !empty($verification['provenance_matches'])
            && !empty($verification['mime_matches'])
            && !empty($verification['alt_matches']),
            ['verification' => $verification]
        );

        $rollbackResponse = $this->request('POST', '/media/ingest/operations/' . rawurlencode($this->operationId) . '/rollback', ['confirm' => true], [200], 'media-ingest-rollback');
        $rolledBack = $this->data($rollbackResponse);
        $this->check('owned-unused-rollback-complete',
            'rolled-back' === (string) ($rolledBack['status'] ?? '')
            && !empty($rolledBack['rollback_result']['verified'])
            && !empty($rolledBack['rollback_result']['deleted'])
            && $this->attachmentId === (int) ($rolledBack['rollback_result']['attachment_id'] ?? 0),
            ['rollback_result' => $rolledBack['rollback_result'] ?? null]
        );
        $this->remoteCleanupCompleted = true;

        $missing = $this->request('GET', '/media/' . $this->attachmentId, null, [404], 'media-inspect-after-rollback');
        $missingCode = (string) ($missing['code'] ?? ($missing['data']['code'] ?? ''));
        $this->check('attachment-absent-after-rollback', 'media_not_found' === $missingCode, ['error_code' => $missingCode]);

        $finalInventory = $this->request('GET', '/media?search=' . rawurlencode($title) . '&per_page=10', null, [200], 'inventory-final');
        $this->check('final-inventory-clean', 0 === (int) ($this->data($finalInventory)['total'] ?? -1), [
            'total' => $this->data($finalInventory)['total'] ?? null,
        ]);

        $this->evidence['attachment_created'] = true;
        $this->evidence['attachment_id'] = $this->attachmentId;
        $this->evidence['rollback_completed'] = true;
        $this->writeEvidence();
        fwrite(STDOUT, "M8 Media ingest reversible production acceptance: PASS\n");
        fwrite(STDOUT, "Private Preview → Apply → safe readback → checksum/provenance Verify → owned-unused Rollback completed.\n");
        fwrite(STDOUT, "Final Media inventory is clean.\n");
        fwrite(STDOUT, 'Evidence: ' . $this->evidencePath . PHP_EOL);
    }

    private function cleanupRemoteAfterFailure(): void
    {
        if ($this->remoteCleanupCompleted) { return; }
        if ('' !== $this->operationId) {
            $response = $this->request('POST', '/media/ingest/operations/' . rawurlencode($this->operationId) . '/rollback', ['confirm' => true], [200], 'media-ingest-emergency-rollback');
            $data = $this->data($response);
            if ('rolled-back' !== (string) ($data['status'] ?? '') || empty($data['rollback_result']['verified'])) {
                throw new RuntimeException('Emergency Media ingest rollback did not verify deletion.');
            }
            $this->remoteCleanupCompleted = true;
            $this->evidence['emergency_rollback_completed'] = true;
            return;
        }
        if ('' !== $this->planId) {
            $response = $this->request('POST', '/media/ingest/plans/' . rawurlencode($this->planId) . '/cancel', [], [200, 409], 'media-ingest-emergency-preview-cancel');
            $status = (int) ($response['_http_status'] ?? 200);
            if (200 === $status) {
                $data = $this->data($response);
                if ('cancelled' !== (string) ($data['status'] ?? '')) {
                    throw new RuntimeException('Emergency Media Preview cancel did not reach cancelled state.');
                }
                $this->remoteCleanupCompleted = true;
                $this->evidence['emergency_preview_cancel_completed'] = true;
            }
        }
    }

    private function createUniquePng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'erm-m8-media-');
        if (!is_string($path) || '' === $path) { throw new RuntimeException('Unable to allocate local PNG fixture.'); }
        $rgb = substr(hash('sha256', $this->runId, true), 0, 3);
        $raw = "\x00" . $rgb;
        $png = "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0))
            . $this->pngChunk('IDAT', gzcompress($raw, 9))
            . $this->pngChunk('IEND', '');
        if (false === file_put_contents($path, $png) || filesize($path) <= 0) {
            @unlink($path);
            throw new RuntimeException('Unable to write local PNG fixture.');
        }
        return $path;
    }

    private function pngChunk(string $type, string $data): string
    {
        $crc = crc32($type . $data);
        return pack('N', strlen($data)) . $type . $data . pack('N', $crc);
    }

    private function validateRuntime(): void
    {
        if (!str_starts_with($this->baseUrl, 'https://')) { throw new RuntimeException('ERM_BASE_URL must be an HTTPS site URL.'); }
        if ('' === $this->token || (!str_starts_with($this->token, 'erm1_') && !str_starts_with($this->token, 'ermt1_'))) {
            throw new RuntimeException('ERM_TOKEN must be a Research Manager credential.');
        }
        if (!extension_loaded('curl')) { throw new RuntimeException('PHP cURL extension is required.'); }
        if (!function_exists('gzcompress')) { throw new RuntimeException('PHP zlib support is required.'); }
    }

    private function request(string $method, string $relative, ?array $body, array $expectedStatuses, string $label): array
    {
        $requestId = $this->requestId($label);
        $headers = $this->headers($requestId);
        $payload = '';
        if (null !== $body) {
            $headers[] = 'Content-Type: application/json';
            $payload = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $ch = curl_init($this->baseUrl . self::API_PREFIX . $relative);
        if (false === $ch) { throw new RuntimeException('Unable to initialize cURL.'); }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Eduardo-Research-M8-Media-Ingest-Acceptance/1.0',
        ]);
        if (null !== $body) { curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); }
        return $this->finishRequest($ch, $method, $relative, $requestId, $expectedStatuses, $label);
    }

    private function requestMultipart(string $relative, array $fields, array $expectedStatuses, string $label): array
    {
        $requestId = $this->requestId($label);
        $ch = curl_init($this->baseUrl . self::API_PREFIX . $relative);
        if (false === $ch) { throw new RuntimeException('Unable to initialize cURL.'); }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $this->headers($requestId),
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Eduardo-Research-M8-Media-Ingest-Acceptance/1.0',
        ]);
        return $this->finishRequest($ch, 'POST', $relative, $requestId, $expectedStatuses, $label);
    }

    private function finishRequest(CurlHandle $ch, string $method, string $relative, string $requestId, array $expectedStatuses, string $label): array
    {
        $raw = curl_exec($ch);
        if (false === $raw) {
            $message = curl_error($ch); curl_close($ch); throw new RuntimeException('Research Manager request failed: ' . $message);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode((string) $raw, true);
        $decoded = is_array($decoded) ? $decoded : ['raw_non_json' => substr((string) $raw, 0, 1000)];
        $this->evidence['requests'][] = [
            'label' => $label,
            'method' => strtoupper($method),
            'route' => self::API_PREFIX . $relative,
            'request_id' => $requestId,
            'http_status' => $status,
            'response' => $this->sanitize($decoded),
        ];
        if (!in_array($status, $expectedStatuses, true)) {
            $code = (string) ($decoded['code'] ?? '');
            throw new RuntimeException(sprintf('%s returned HTTP %d (expected %s)%s', $label, $status, implode('/', $expectedStatuses), $code ? ': ' . $code : ''));
        }
        $decoded['_http_status'] = $status;
        return $decoded;
    }

    private function headers(string $requestId): array
    {
        return [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
            'X-Research-Manager-Request-Id: ' . $requestId,
            'X-Research-Manager-Nonce: ' . bin2hex(random_bytes(16)),
            'X-Research-Manager-Timestamp: ' . time(),
        ];
    }

    private function requestId(string $label): string
    {
        return 'm8-media-ingest-' . preg_replace('/[^A-Za-z0-9._:-]/', '-', $label) . '-' . time() . '-' . bin2hex(random_bytes(4));
    }

    private function data(array $response): array { return is_array($response['data'] ?? null) ? $response['data'] : []; }

    private function check(string $id, bool $passes, array $details = []): void
    {
        $this->evidence['checks'][] = ['id' => $id, 'passes' => $passes, 'details' => $this->sanitize($details)];
        if (!$passes) { throw new RuntimeException('Acceptance check failed: ' . $id); }
    }

    private function sanitize(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        $clean = [];
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), ['token', 'token_hash', 'authorization', 'password', 'secret', 'cookie', 'staged_path'], true)) { continue; }
            $clean[$key] = $this->sanitize($item);
        }
        return $clean;
    }

    private function writeEvidence(): void
    {
        $dir = dirname($this->evidencePath);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException('Unable to create evidence directory.'); }
        $json = json_encode($this->sanitize($this->evidence), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (preg_match('/ermt?1_[A-Za-z0-9._:-]+/', $json)) { throw new RuntimeException('Evidence unexpectedly contains bearer-like material.'); }
        file_put_contents($this->evidencePath, $json);
    }

    private function argument(array $argv, string $prefix): string
    {
        foreach ($argv as $arg) { if (str_starts_with((string) $arg, $prefix)) { return substr((string) $arg, strlen($prefix)); } }
        return '';
    }
}

exit((new M8MediaIngestProductionAcceptance($argv))->run());