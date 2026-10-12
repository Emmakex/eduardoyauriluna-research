#!/usr/bin/env php
<?php
declare(strict_types=1);

final class M8ReadonlyAuditProductionAcceptance
{
    private const API_PREFIX = '/wp-json/research-manager/v1';
    private const EXPECTED_SCOPES = ['site.read', 'site.diagnostics'];
    private const AUDIT_SOURCE = __DIR__ . '/../wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-audit.php';

    private string $baseUrl;
    private string $token;
    private string $evidencePath;
    private bool $revocationProof;
    private array $evidence = [];

    public function __construct(array $argv)
    {
        $this->baseUrl = rtrim((string) getenv('ERM_BASE_URL'), '/');
        $this->token = trim((string) getenv('ERM_TOKEN'));
        $this->evidencePath = $this->argument($argv, '--evidence=')
            ?: 'tmp/m8-readonly-audit-production-acceptance-' . gmdate('Ymd\\THis\\Z') . '-' . getmypid() . '.json';
        $this->revocationProof = in_array('--revocation-proof', $argv, true);
    }

    public function run(): int
    {
        try {
            $this->validateRuntime();
            $this->evidence = [
                'schema_version' => 2,
                'milestone' => 'M8',
                'slice' => 'readonly-audit-production-acceptance',
                'site' => $this->baseUrl,
                'temporary_credential_required' => true,
                'expected_scopes' => self::EXPECTED_SCOPES,
                'revocation_proof' => $this->revocationProof,
                'started_at' => gmdate(DATE_ATOM),
                'requests' => [],
                'checks' => [],
                'result' => 'running',
            ];

            if ($this->revocationProof) {
                $this->runRevocationProof();
            } else {
                $this->runAcceptance();
            }

            $this->evidence['result'] = 'pass';
            $this->evidence['finished_at'] = gmdate(DATE_ATOM);
            $this->writeEvidence();
            return 0;
        } catch (Throwable $error) {
            $this->evidence['result'] = 'fail';
            $this->evidence['failure'] = [
                'class' => get_class($error),
                'message' => $error->getMessage(),
            ];
            $this->evidence['finished_at'] = gmdate(DATE_ATOM);
            $this->writeEvidence();
            fwrite(STDERR, 'M8 read-only/audit production acceptance: FAIL — ' . $error->getMessage() . PHP_EOL);
            fwrite(STDERR, 'Evidence: ' . $this->evidencePath . PHP_EOL);
            return 1;
        }
    }

    private function runAcceptance(): void
    {
        $this->assertAuditSourceContract();

        $capabilities = $this->request('GET', '/capabilities', null, [200], 'capabilities');
        $capabilityData = $this->data($capabilities);
        $scopes = array_values((array) ($capabilityData['granted_scopes'] ?? []));
        sort($scopes);
        $expected = self::EXPECTED_SCOPES;
        sort($expected);
        $this->check('exact-readonly-scope-profile', $scopes === $expected, [
            'granted_scopes' => $scopes,
            'expected_scopes' => $expected,
        ]);
        $this->check('writes-disabled', empty($capabilityData['writes_enabled']), [
            'writes_enabled' => $capabilityData['writes_enabled'] ?? null,
        ]);
        $auditControl = is_array($capabilityData['audit_control'] ?? null) ? $capabilityData['audit_control'] : [];
        $this->check('remote-audit-capability-advertised',
            !empty($auditControl['available'])
            && !empty($auditControl['read_only'])
            && '/research-manager/v1/audit' === (string) ($auditControl['transport'] ?? '')
            && 'site.diagnostics' === (string) ($auditControl['scope'] ?? '')
            && empty($auditControl['remote_clear_delete']),
            ['audit_control' => $auditControl]
        );

        $status = $this->request('GET', '/status', null, [200], 'status');
        $this->check('status-readable', !empty($status['ok']), ['ok' => $status['ok'] ?? null]);

        $diagnostics = $this->request('GET', '/diagnostics', null, [200], 'diagnostics');
        $this->check('diagnostics-readable', !empty($diagnostics['ok']), ['ok' => $diagnostics['ok'] ?? null]);

        $deniedRequestId = 'm8-readonly-denied-' . time() . '-' . bin2hex(random_bytes(5));
        $denied = $this->request(
            'POST',
            '/operations/plan',
            [
                'operation' => 'greenfield-canonical',
                'payload' => [],
                'intent' => 'M8 read-only negative write proof — must be denied before callback',
            ],
            [403],
            'denied-write-plan',
            $deniedRequestId
        );
        $code = (string) ($denied['code'] ?? ($denied['data']['code'] ?? ''));
        $this->check('write-denied-by-scope', 'scope_denied' === $code, [
            'http_status' => 403,
            'error_code' => $code,
            'request_id' => $deniedRequestId,
        ]);

        $audit = $this->request(
            'GET',
            '/audit?request_id=' . rawurlencode($deniedRequestId) . '&per_page=10',
            null,
            [200],
            'remote-audit-correlation'
        );
        $auditData = $this->data($audit);
        $this->assertNoSecretKeys($auditData);
        $items = is_array($auditData['items'] ?? null) ? array_values($auditData['items']) : [];
        $this->check('remote-audit-filter-echo',
            $deniedRequestId === (string) ($auditData['filters']['request_id'] ?? ''),
            ['filters' => $auditData['filters'] ?? []]
        );
        $this->check('remote-audit-match-count', 1 === count($items), [
            'count' => count($items),
            'total' => $auditData['total'] ?? null,
        ]);
        $auditRow = is_array($items[0] ?? null) ? $items[0] : [];
        $this->check('remote-audit-correlates-denied-write',
            $deniedRequestId === (string) ($auditRow['request_id'] ?? '')
            && 'remote-request-rejected' === (string) ($auditRow['event'] ?? '')
            && 'blocked' === (string) ($auditRow['outcome'] ?? '')
            && 'operations.apply' === (string) ($auditRow['scope'] ?? '')
            && 'scope_denied' === (string) ($auditRow['error_code'] ?? '')
            && 'POST' === (string) ($auditRow['method'] ?? '')
            && '/research-manager/v1/operations/plan' === (string) ($auditRow['route'] ?? ''),
            ['audit_row' => $auditRow]
        );

        $statusAfter = $this->request('GET', '/status', null, [200], 'status-after-denied-write');
        $this->check('reads-still-available-after-denied-write', !empty($statusAfter['ok']), [
            'ok' => $statusAfter['ok'] ?? null,
        ]);

        $this->evidence['audit_correlation'] = [
            'request_id' => $deniedRequestId,
            'expected_event' => 'remote-request-rejected',
            'expected_outcome' => 'blocked',
            'expected_scope' => 'operations.apply',
            'expected_error_code' => 'scope_denied',
            'remote_surface' => 'GET /wp-json/research-manager/v1/audit?request_id=…',
            'verified_remotely' => true,
        ];
        $this->evidence['mutation_performed'] = false;

        $this->writeEvidence();
        fwrite(STDOUT, "M8 read-only/audit production acceptance: PASS\n");
        fwrite(STDOUT, "Exact temporary scopes: 2; writes_enabled=false.\n");
        fwrite(STDOUT, "Read endpoints: PASS; denied mutation: HTTP 403 scope_denied; no mutation executed.\n");
        fwrite(STDOUT, "Remote audit correlation: PASS through GET /audit; no WordPress Admin required.\n");
        fwrite(STDOUT, "Audit whitelist/no-secret contract: PASS.\n");
        fwrite(STDOUT, 'Audit correlation request ID: ' . $deniedRequestId . PHP_EOL);
        fwrite(STDOUT, 'Evidence: ' . $this->evidencePath . PHP_EOL);
    }

    private function runRevocationProof(): void
    {
        $response = $this->request('GET', '/capabilities', null, [401], 'revoked-capabilities');
        $code = (string) ($response['code'] ?? ($response['data']['code'] ?? ''));
        $this->check('revoked-readonly-token-rejected', 'authentication_failed' === $code, [
            'http_status' => 401,
            'error_code' => $code,
        ]);

        $this->writeEvidence();
        fwrite(STDOUT, "M8 read-only temporary credential revocation proof: PASS\n");
        fwrite(STDOUT, "The revoked temporary credential is rejected with 401 authentication_failed.\n");
        fwrite(STDOUT, 'Evidence: ' . $this->evidencePath . PHP_EOL);
    }

    private function assertAuditSourceContract(): void
    {
        $source = @file_get_contents(self::AUDIT_SOURCE);
        if (!is_string($source) || '' === $source) {
            throw new RuntimeException('Unable to read Remote Audit source contract.');
        }

        $fields = [
            'event_id', 'timestamp', 'request_id', 'connection_id', 'wordpress_user_id',
            'method', 'route', 'scope', 'event', 'outcome', 'error_code',
            'operation_id', 'plan_id', 'reason',
        ];
        foreach ($fields as $field) {
            $this->check('audit-field-' . str_replace('_', '-', $field),
                str_contains($source, "'{$field}' =>"),
                ['field' => $field]
            );
        }

        $forbiddenKeys = ['token', 'token_hash', 'authorization', 'password', 'secret', 'cookie'];
        foreach ($forbiddenKeys as $key) {
            $pattern = '/[\\'\\"]' . preg_quote($key, '/') . '[\\'\\"]\\s*=>/i';
            $this->check('audit-forbids-' . str_replace('_', '-', $key),
                1 !== preg_match($pattern, $source),
                ['forbidden_key' => $key]
            );
        }

        $this->evidence['audit_source'] = [
            'path' => 'wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-audit.php',
            'sha256' => hash('sha256', $source),
            'whitelist_field_count' => count($fields),
            'forbidden_keys_checked' => $forbiddenKeys,
        ];
    }

    private function assertNoSecretKeys(mixed $value, string $path = 'data'): void
    {
        if (!is_array($value)) {
            return;
        }
        $forbidden = ['token', 'token_hash', 'authorization', 'password', 'secret', 'cookie'];
        foreach ($value as $key => $item) {
            $name = strtolower((string) $key);
            $this->check('remote-audit-no-secret-key-' . substr(hash('sha256', $path . '.' . $name), 0, 12),
                !in_array($name, $forbidden, true),
                ['path' => $path, 'key' => $name]
            );
            $this->assertNoSecretKeys($item, $path . '.' . $name);
        }
    }

    private function validateRuntime(): void
    {
        if (!str_starts_with($this->baseUrl, 'https://')) {
            throw new RuntimeException('ERM_BASE_URL must be an HTTPS site URL.');
        }
        if (!str_starts_with($this->token, 'ermt1_')) {
            throw new RuntimeException('ERM_TOKEN must be an independent temporary Research Manager credential (ermt1_).');
        }
    }

    private function request(
        string $method,
        string $relative,
        ?array $body,
        array $expectedStatuses,
        string $label,
        ?string $requestId = null
    ): array {
        $requestId = $requestId ?: 'm8-readonly-' . preg_replace('/[^A-Za-z0-9._:-]/', '-', $label) . '-' . time() . '-' . bin2hex(random_bytes(4));
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
            'X-Research-Manager-Request-Id: ' . $requestId,
            'X-Research-Manager-Nonce: ' . bin2hex(random_bytes(16)),
            'X-Research-Manager-Timestamp: ' . time(),
        ];
        $payload = '';
        if (null !== $body) {
            $headers[] = 'Content-Type: application/json';
            $payload = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $ch = curl_init($this->baseUrl . self::API_PREFIX . $relative);
        if (false === $ch) {
            throw new RuntimeException('Unable to initialize cURL.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Eduardo-Research-M8-Readonly-Audit-Acceptance/2.0',
        ]);
        if (null !== $body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);
        if (false === $raw) {
            $message = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Research Manager request failed: ' . $message);
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
        return $decoded;
    }

    private function data(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    private function check(string $id, bool $passes, array $details = []): void
    {
        $this->evidence['checks'][] = [
            'id' => $id,
            'passes' => $passes,
            'details' => $this->sanitize($details),
        ];
        if (!$passes) {
            throw new RuntimeException('Acceptance check failed: ' . $id);
        }
    }

    private function sanitize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $clean = [];
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), ['token', 'token_hash', 'authorization', 'password', 'secret', 'cookie'], true)) {
                continue;
            }
            $clean[$key] = $this->sanitize($item);
        }
        return $clean;
    }

    private function writeEvidence(): void
    {
        $dir = dirname($this->evidencePath);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create evidence directory.');
        }
        $json = json_encode($this->sanitize($this->evidence), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (preg_match('/ermt?1_[A-Za-z0-9._:-]+/', $json)) {
            throw new RuntimeException('Evidence unexpectedly contains bearer-like material.');
        }
        file_put_contents($this->evidencePath, $json);
    }

    private function argument(array $argv, string $prefix): string
    {
        foreach ($argv as $arg) {
            if (str_starts_with((string) $arg, $prefix)) {
                return substr((string) $arg, strlen($prefix));
            }
        }
        return '';
    }
}

exit((new M8ReadonlyAuditProductionAcceptance($argv))->run());