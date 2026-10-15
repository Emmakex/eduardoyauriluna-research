#!/usr/bin/env php
<?php
declare(strict_types=1);

final class M8DesignBreadthProductionAcceptance
{
    private const API_PREFIX = '/wp-json/research-manager/v1';
    private const EXPECTED_SCOPES = [
        'site.read',
        'design.read',
        'design.write',
        'operations.apply',
        'operations.rollback',
    ];
    private const DIMENSIONS = ['typography', 'density', 'variant'];

    private string $baseUrl;
    private string $token;
    private string $dimension;
    private string $evidencePath;
    private bool $apply;
    private array $evidence = [];
    private ?string $appliedOperationId = null;
    private ?string $operationBase = null;
    private bool $rolledBack = false;

    public function __construct(array $argv)
    {
        $this->baseUrl = rtrim((string) getenv('ERM_BASE_URL'), '/');
        $this->token = trim((string) getenv('ERM_TOKEN'));
        $this->apply = in_array('--apply', $argv, true);
        $this->dimension = $this->argument($argv, '--dimension=');
        $this->evidencePath = $this->argument($argv, '--evidence=')
            ?: 'tmp/final-v1-design-' . ($this->dimension ?: 'unknown') . '-' . gmdate('Ymd\\THis\\Z') . '-' . getmypid() . '.json';
    }

    public function run(): int
    {
        try {
            $this->validateRuntime();
            $this->evidence = [
                'schema_version' => 1,
                'milestone' => 'M8',
                'slice' => 'final-v1-design-breadth-production-acceptance',
                'site' => $this->baseUrl,
                'dimension' => $this->dimension,
                'apply_enabled' => $this->apply,
                'temporary_credential_required' => true,
                'expected_scopes' => self::EXPECTED_SCOPES,
                'started_at' => gmdate(DATE_ATOM),
                'requests' => [],
                'checks' => [],
                'result' => 'running',
            ];

            $this->runAcceptance();
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
            fwrite(STDERR, 'M8 Design breadth production acceptance: FAIL — ' . $error->getMessage() . PHP_EOL);
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

        $beforeDesign = $this->data($this->request('GET', '/design', null, [200], 'design-before'));
        $beforeVariants = $this->data($this->request('GET', '/design/variants', null, [200], 'variants-before'));
        $this->assertReadiness('baseline', $this->request('GET', '/design/readiness?rendered=1', null, [200], 'readiness-before'));

        $isVariant = 'variant' === $this->dimension;
        $surfaceBefore = $isVariant ? $beforeVariants : $beforeDesign;
        $changes = $isVariant
            ? $this->chooseVariantChange($surfaceBefore)
            : $this->chooseDesignChange($surfaceBefore, $this->dimension);
        if (!$changes) {
            throw new RuntimeException('No safe alternate Theme preset is available for requested dimension: ' . $this->dimension);
        }

        $planBase = $isVariant ? '/design/variants' : '/design';
        $this->operationBase = $planBase;
        $this->evidence['selected_change'] = $changes;
        $this->evidence['baseline_design_state'] = $beforeDesign['state'] ?? [];
        $this->evidence['baseline_variant_state'] = $beforeVariants['state'] ?? [];

        $plan = $this->data($this->request('POST', $planBase . '/plan', [
            'changes' => $changes,
            'intent' => 'Final v1 production acceptance for Design breadth: ' . $this->dimension,
        ], [200], 'plan'));
        $planId = (string) ($plan['plan_id'] ?? '');
        $this->assertIdentifier('plan_id', $planId);
        $this->evidence['plan_id'] = $planId;

        if (!$this->apply) {
            $this->evidence['apply_skipped'] = true;
            fwrite(STDOUT, 'M8 Design ' . $this->dimension . " Preview: PASS\n");
            fwrite(STDOUT, 'Selected bounded change: ' . json_encode($changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
            fwrite(STDOUT, "No production mutation was executed.\n");
            fwrite(STDOUT, 'Evidence: ' . $this->evidencePath . PHP_EOL);
            return;
        }

        $apply = $this->data($this->request('POST', $planBase . '/plans/' . rawurlencode($planId) . '/apply', ['confirm' => true], [200], 'apply'));
        $operationId = (string) ($apply['operation_id'] ?? '');
        $this->assertIdentifier('operation_id', $operationId);
        $this->appliedOperationId = $operationId;
        $this->evidence['operation_id'] = $operationId;

        $verify = $this->data($this->request('POST', $planBase . '/operations/' . rawurlencode($operationId) . '/verify', [], [200], 'verify'));
        $this->assertCheck('operation-verified', 'verified' === (string) ($verify['status'] ?? ''), ['status' => $verify['status'] ?? null]);
        if (isset($verify['verification'])) {
            $this->assertCheck('semantic-verification', !empty($verify['verification']['verified']), ['verification' => $verify['verification']]);
        }

        $afterDesign = $this->data($this->request('GET', '/design', null, [200], 'design-after'));
        $afterVariants = $this->data($this->request('GET', '/design/variants', null, [200], 'variants-after'));
        $this->assertCheck('requested-surface-changed',
            ($surfaceBefore['state'] ?? null) !== (($isVariant ? $afterVariants : $afterDesign)['state'] ?? null),
            ['before' => $surfaceBefore['state'] ?? null, 'after' => ($isVariant ? $afterVariants : $afterDesign)['state'] ?? null]
        );
        $this->assertCheck('non-target-surface-unchanged',
            $isVariant
                ? (($beforeDesign['state'] ?? null) === ($afterDesign['state'] ?? null))
                : (($beforeVariants['state'] ?? null) === ($afterVariants['state'] ?? null)),
            ['design_before' => $beforeDesign['state'] ?? null, 'design_after' => $afterDesign['state'] ?? null,
             'variants_before' => $beforeVariants['state'] ?? null, 'variants_after' => $afterVariants['state'] ?? null]
        );

        $this->assertReadiness('after-apply', $this->request('GET', '/design/readiness?rendered=1', null, [200], 'readiness-after'));

        $rollback = $this->data($this->request('POST', $planBase . '/operations/' . rawurlencode($operationId) . '/rollback', ['confirm' => true], [200], 'rollback'));
        $this->assertCheck('rollback-status', 'rolled-back' === (string) ($rollback['status'] ?? ''), ['status' => $rollback['status'] ?? null]);
        $this->rolledBack = true;

        $finalDesign = $this->data($this->request('GET', '/design', null, [200], 'design-final'));
        $finalVariants = $this->data($this->request('GET', '/design/variants', null, [200], 'variants-final'));
        $this->assertCheck('exact-design-baseline-restored', ($beforeDesign['state'] ?? null) === ($finalDesign['state'] ?? null), [
            'before' => $beforeDesign['state'] ?? null,
            'final' => $finalDesign['state'] ?? null,
        ]);
        $this->assertCheck('exact-variant-baseline-restored', ($beforeVariants['state'] ?? null) === ($finalVariants['state'] ?? null), [
            'before' => $beforeVariants['state'] ?? null,
            'final' => $finalVariants['state'] ?? null,
        ]);
        $this->assertReadiness('final', $this->request('GET', '/design/readiness?rendered=1', null, [200], 'readiness-final'));

        fwrite(STDOUT, 'M8 Design ' . $this->dimension . " reversible production acceptance: PASS\n");
        fwrite(STDOUT, "Preview → Apply → Verify → rendered readiness → Rollback completed; exact Design + Variant baselines restored.\n");
        fwrite(STDOUT, 'Evidence: ' . $this->evidencePath . PHP_EOL);
    }

    private function validateRuntime(): void
    {
        if (!str_starts_with($this->baseUrl, 'https://')) {
            throw new RuntimeException('ERM_BASE_URL must be an HTTPS site URL.');
        }
        if (!str_starts_with($this->token, 'ermt1_')) {
            throw new RuntimeException('ERM_TOKEN must be an independent temporary Research Manager credential (ermt1_).');
        }
        if (!in_array($this->dimension, self::DIMENSIONS, true)) {
            throw new RuntimeException('--dimension must be one of: typography, density, variant.');
        }
    }

    private function chooseDesignChange(array $design, string $dimension): array
    {
        $state = is_array($design['state'] ?? null) ? $design['state'] : [];
        $presets = is_array($design['presets'] ?? null) ? $design['presets'] : [];
        $current = (string) ($state[$dimension] ?? '');
        foreach ((array) ($presets[$dimension] ?? []) as $key => $definition) {
            if ((string) $key !== $current) {
                return [$dimension => (string) $key];
            }
        }
        return [];
    }

    private function chooseVariantChange(array $variants): array
    {
        $state = is_array($variants['state'] ?? null) ? $variants['state'] : [];
        $presets = is_array($variants['presets'] ?? null) ? $variants['presets'] : [];
        foreach (['hero', 'sections', 'cards', 'collections'] as $dimension) {
            $current = (string) ($state[$dimension] ?? '');
            foreach ((array) ($presets[$dimension] ?? []) as $key => $definition) {
                if ((string) $key !== $current) {
                    return [$dimension => (string) $key];
                }
            }
        }
        return [];
    }

    private function assertReadiness(string $label, array $response): void
    {
        $data = $this->data($response);
        $responsive = is_array($data['responsive_accessibility'] ?? null) ? $data['responsive_accessibility'] : [];
        $this->assertCheck('design-readiness-' . $label, !empty($data['ready']), [
            'ready' => $data['ready'] ?? null,
            'blocking_count' => $data['blocking_count'] ?? null,
            'advisory_count' => $data['advisory_count'] ?? null,
        ]);
        $this->assertCheck('responsive-accessibility-' . $label, !empty($responsive['ready']), [
            'responsive_accessibility' => $responsive,
        ]);
        foreach (['fluid_spacing', 'mobile_variant_guard', 'mobile_hover_lift_disabled', 'reduced_motion', 'reduced_motion_contract'] as $check) {
            $this->assertCheck('responsive-' . $label . '-' . $check, !empty($responsive[$check]), [$check => $responsive[$check] ?? null]);
        }
        if (isset($data['rendered'])) {
            $this->assertCheck('rendered-readiness-' . $label, !empty($data['rendered']['ready']), ['rendered' => $data['rendered']]);
        }
    }

    private function assertIdentifier(string $name, string $value): void
    {
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
            throw new RuntimeException('Invalid ' . $name . ' returned by Manager.');
        }
    }

    private function request(string $method, string $relative, ?array $body, array $expected, string $label): array
    {
        $requestId = 'm8-design-breadth-' . preg_replace('/[^A-Za-z0-9._:-]/', '-', $label) . '-' . time() . '-' . bin2hex(random_bytes(4));
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
            CURLOPT_USERAGENT => 'Eduardo-Research-M8-Design-Breadth-Acceptance/1.0',
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
        if (!$this->apply || null === $this->appliedOperationId || null === $this->operationBase || $this->rolledBack || !$this->evidence) {
            return;
        }
        try {
            $this->request('POST', $this->operationBase . '/operations/' . rawurlencode($this->appliedOperationId) . '/rollback', ['confirm' => true], [200], 'emergency-rollback');
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

$runner = new M8DesignBreadthProductionAcceptance($argv);
exit($runner->run());
