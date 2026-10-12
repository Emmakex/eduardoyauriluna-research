#!/usr/bin/env php
<?php
declare(strict_types=1);

final class M8DesignProductionAcceptance
{
    private const API_PREFIX = '/wp-json/research-manager/v1';
    private const EXPECTED_SCOPES = [
        'site.read',
        'design.read',
        'design.write',
        'operations.apply',
        'operations.rollback',
    ];

    private string $baseUrl;
    private string $token;
    private string $evidencePath;
    private bool $apply;
    private bool $revocationProof;
    private array $evidence = [];
    private ?string $appliedOperationId = null;
    private bool $rolledBack = false;

    public function __construct(array $argv)
    {
        $this->apply = in_array('--apply', $argv, true);
        $this->revocationProof = in_array('--revocation-proof', $argv, true);
        $this->baseUrl = rtrim((string) getenv('ERM_BASE_URL'), '/');
        $this->token = trim((string) getenv('ERM_TOKEN'));
        $this->evidencePath = $this->argument($argv, '--evidence=')
            ?: 'tmp/m8-design-production-acceptance-' . gmdate('Ymd\THis\Z') . '-' . getmypid() . '.json';
    }

    public function run(): int
    {
        try {
            $this->validateRuntime();
            $this->evidence = [
                'schema_version' => 1,
                'milestone' => 'M8',
                'slice' => 'bounded-design-production-acceptance',
                'site' => $this->baseUrl,
                'temporary_credential_required' => true,
                'expected_scopes' => self::EXPECTED_SCOPES,
                'apply_enabled' => $this->apply,
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
            $this->attemptEmergencyRollback();
            $this->evidence['result'] = 'fail';
            $this->evidence['failure'] = [
                'class' => get_class($error),
                'message' => $error->getMessage(),
            ];
            $this->evidence['finished_at'] = gmdate(DATE_ATOM);
            $this->writeEvidence();
            fwrite(STDERR, 'M8 Design production acceptance: FAIL — ' . $error->getMessage() . PHP_EOL);
            fwrite(STDERR, 'Evidence: ' . $this->evidencePath . PHP_EOL);
            return 1;
        }
    }

    private function runAcceptance(): void
    {
        $capabilities = $this->request('GET', '/capabilities', null, [200], 'capabilities');
        $scopes = array_values((array) ($this->data($capabilities)['granted_scopes'] ?? []));
        sort($scopes);
        $expected = self::EXPECTED_SCOPES;
        sort($expected);
        $this->assertCheck('exact-temporary-design-scope-profile', $scopes === $expected, [
            'granted_scopes' => $scopes,
            'expected_scopes' => $expected,
        ]);

        $before = $this->request('GET', '/design', null, [200], 'design-before');
        $beforeData = $this->data($before);
        $readinessBefore = $this->request('GET', '/design/readiness?rendered=1', null, [200], 'design-readiness-before');
        $this->assertReadiness('baseline', $readinessBefore);

        $changes = $this->chooseDesignChange($beforeData);
        if (!$changes) {
            throw new RuntimeException('No safe alternate Theme design preset is available for production acceptance.');
        }
        $this->evidence['selected_change'] = $changes;
        $this->evidence['baseline_state'] = $beforeData['state'] ?? [];

        $plan = $this->request('POST', '/design/plan', [
            'changes' => $changes,
            'intent' => 'M8 temporary-scope bounded design production acceptance',
        ], [200], 'design-plan');
        $planId = (string) ($this->data($plan)['plan_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $planId)) {
            throw new RuntimeException('Design Preview did not return a valid plan_id.');
        }
        $this->evidence['plan_id'] = $planId;

        if (!$this->apply) {
            $this->evidence['apply_skipped'] = true;
            $this->writeEvidence();
            fwrite(STDOUT, "M8 Design production Preview: PASS\n");
            fwrite(STDOUT, 'Exact temporary scopes: ' . count($scopes) . "\n");
            fwrite(STDOUT, 'Selected bounded change: ' . json_encode($changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            fwrite(STDOUT, "No Theme design mutation was executed.\n");
            fwrite(STDOUT, 'Evidence: ' . $this->evidencePath . PHP_EOL);
            return;
        }

        $apply = $this->request('POST', '/design/plans/' . rawurlencode($planId) . '/apply', ['confirm' => true], [200], 'design-apply');
        $operationId = (string) ($this->data($apply)['operation_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $operationId)) {
            throw new RuntimeException('Design Apply did not return a valid operation_id.');
        }
        $this->appliedOperationId = $operationId;
        $this->evidence['operation_id'] = $operationId;

        $verify = $this->request('POST', '/design/operations/' . rawurlencode($operationId) . '/verify', [], [200], 'design-verify');
        $verifyData = $this->data($verify);
        $this->assertCheck('operation-verified', 'verified' === (string) ($verifyData['status'] ?? ''), [
            'status' => $verifyData['status'] ?? null,
        ]);
        if (isset($verifyData['verification'])) {
            $this->assertCheck('semantic-design-verification', !empty($verifyData['verification']['verified']), [
                'verification' => $verifyData['verification'],
            ]);
        }

        $readinessAfter = $this->request('GET', '/design/readiness?rendered=1', null, [200], 'design-readiness-after');
        $this->assertReadiness('after-apply', $readinessAfter);

        $this->request('POST', '/design/operations/' . rawurlencode($operationId) . '/rollback', ['confirm' => true], [200], 'design-rollback');
        $this->rolledBack = true;

        $final = $this->request('GET', '/design', null, [200], 'design-final');
        $finalData = $this->data($final);
        $this->assertCheck('exact-design-baseline-restored', ($beforeData['state'] ?? null) === ($finalData['state'] ?? null), [
            'before' => $beforeData['state'] ?? null,
            'final' => $finalData['state'] ?? null,
        ]);

        $readinessFinal = $this->request('GET', '/design/readiness?rendered=1', null, [200], 'design-readiness-final');
        $this->assertReadiness('final', $readinessFinal);

        $this->writeEvidence();
        fwrite(STDOUT, "M8 Design reversible production acceptance: PASS\n");
        fwrite(STDOUT, "Apply → Verify → rendered readiness → Rollback completed; exact Theme design baseline restored.\n");
        fwrite(STDOUT, 'Evidence: ' . $this->evidencePath . PHP_EOL);
    }

    private function runRevocationProof(): void
    {
        $response = $this->request('GET', '/capabilities', null, [401], 'revoked-capabilities');
        $code = (string) ($response['code'] ?? ($response['data']['code'] ?? ''));
        $this->assertCheck('revoked-temporary-design-token-rejected', 'authentication_failed' === $code, [
            'error_code' => $code,
        ]);
        $this->writeEvidence();
        fwrite(STDOUT, "M8 Design temporary credential revocation proof: PASS\n");
        fwrite(STDOUT, "The revoked temporary credential is rejected with 401 authentication_failed.\n");
        fwrite(STDOUT, 'Evidence: ' . $this->evidencePath . PHP_EOL);
    }

    private function validateRuntime(): void
    {
        if (!str_starts_with($this->baseUrl, 'https://')) {
            throw new RuntimeException('ERM_BASE_URL must be an HTTPS site URL.');
        }
        if (!str_starts_with($this->token, 'ermt1_')) {
            throw new RuntimeException('ERM_TOKEN must be an independent temporary Research Manager credential (ermt1_) for this acceptance.');
        }
    }

    private function chooseDesignChange(array $design): array
    {
        $state = is_array($design['state'] ?? null) ? $design['state'] : [];
        $presets = is_array($design['presets'] ?? null) ? $design['presets'] : [];
        foreach (['palette', 'typography', 'density'] as $dimension) {
            $current = (string) ($state[$dimension] ?? '');
            foreach ((array) ($presets[$dimension] ?? []) as $key => $definition) {
                if ((string) $key === $current) {
                    continue;
                }
                if ('palette' === $dimension && empty($definition['accessibility']['accessible'])) {
                    continue;
                }
                return [$dimension => (string) $key];
            }
        }
        return [];
    }

    private function assertReadiness(string $label, array $response): void
    {
        $data = $this->data($response);
        $this->assertCheck('design-readiness-' . $label, !empty($data['ready']), [
            'ready' => $data['ready'] ?? null,
            'blocking_count' => $data['blocking_count'] ?? null,
            'advisory_count' => $data['advisory_count'] ?? null,
        ]);
    }

    private function request(string $method, string $relative, ?array $body, array $expected, string $label): array
    {
        $requestId = 'm8-design-' . preg_replace('/[^A-Za-z0-9._:-]/', '-', $label) . '-' . time() . '-' . bin2hex(random_bytes(4));
        $nonce = bin2hex(random_bytes(16));
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
            'X-Research-Manager-Request-Id: ' . $requestId,
            'X-Research-Manager-Nonce: ' . $nonce,
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
            CURLOPT_USERAGENT => 'Eduardo-Research-M8-Design-Acceptance/1.0',
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

        if (!in_array($status, $expected, true)) {
            $code = (string) ($decoded['code'] ?? '');
            throw new RuntimeException(sprintf('%s returned HTTP %d (expected %s)%s', $label, $status, implode('/', $expected), $code ? ': ' . $code : ''));
        }
        return $decoded;
    }

    private function attemptEmergencyRollback(): void
    {
        if (!$this->apply || null === $this->appliedOperationId || $this->rolledBack || !$this->evidence) {
            return;
        }
        try {
            $this->request(
                'POST',
                '/design/operations/' . rawurlencode($this->appliedOperationId) . '/rollback',
                ['confirm' => true],
                [200],
                'design-emergency-rollback'
            );
            $this->rolledBack = true;
            $this->evidence['emergency_rollback'] = ['success' => true, 'operation_id' => $this->appliedOperationId];
        } catch (Throwable $rollbackError) {
            $this->evidence['emergency_rollback'] = [
                'success' => false,
                'operation_id' => $this->appliedOperationId,
                'error' => $rollbackError->getMessage(),
            ];
        }
    }

    private function assertCheck(string $id, bool $passes, array $details = []): void
    {
        $this->evidence['checks'][] = ['id' => $id, 'passes' => $passes, 'details' => $this->sanitize($details)];
        if (!$passes) {
            throw new RuntimeException('Acceptance check failed: ' . $id);
        }
    }

    private function data(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    private function sanitize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $clean = [];
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), ['token', 'token_hash', 'authorization', 'password', 'secret'], true)) {
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
        if (str_contains($json, 'ermt1_') || str_contains($json, 'erm1_')) {
            throw new RuntimeException('Evidence unexpectedly contains bearer-like material.');
        }
        file_put_contents($this->evidencePath, $json, LOCK_EX);
        @chmod($this->evidencePath, 0600);
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

$runner = new M8DesignProductionAcceptance($argv);
exit($runner->run());
