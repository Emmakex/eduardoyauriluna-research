#!/usr/bin/env php
<?php
declare(strict_types=1);

final class M8NavigationProductionAcceptance
{
    private const API_PREFIX = '/wp-json/research-manager/v1';

    private string $baseUrl;
    private string $token;
    private string $evidencePath;
    private bool $apply;
    private array $evidence = [];
    private string $operationId = '';
    private bool $rollbackCompleted = false;

    public function __construct(array $argv)
    {
        $this->baseUrl = rtrim((string) getenv('ERM_BASE_URL'), '/');
        $this->token = trim((string) getenv('ERM_TOKEN'));
        $this->apply = in_array('--apply', $argv, true);
        $this->evidencePath = $this->argument($argv, '--evidence=')
            ?: 'tmp/m8-navigation-production-acceptance-' . gmdate('Ymd\\THis\\Z') . '-' . getmypid() . '.json';
    }

    public function run(): int
    {
        try {
            $this->validateRuntime();
            $this->evidence = [
                'schema_version' => 1,
                'milestone' => 'M8',
                'slice' => 'navigation-production-acceptance',
                'site' => $this->baseUrl,
                'apply_requested' => $this->apply,
                'mutation_strategy' => 'reorder-first-two-existing-targets-only',
                'started_at' => gmdate(DATE_ATOM),
                'requests' => [],
                'checks' => [],
                'result' => 'running',
            ];
            $this->execute();
            $this->evidence['result'] = 'pass';
            $this->evidence['finished_at'] = gmdate(DATE_ATOM);
            $this->writeEvidence();
            return 0;
        } catch (Throwable $error) {
            $rollbackError = '';
            if ($this->apply && '' !== $this->operationId && !$this->rollbackCompleted) {
                try {
                    $this->emergencyRollback();
                } catch (Throwable $rollbackFailure) {
                    $rollbackError = $rollbackFailure->getMessage();
                }
            }
            $this->evidence['result'] = 'fail';
            $this->evidence['failure'] = [
                'class' => get_class($error),
                'message' => $error->getMessage(),
                'emergency_rollback_error' => $rollbackError,
            ];
            $this->evidence['finished_at'] = gmdate(DATE_ATOM);
            $this->writeEvidence();
            fwrite(STDERR, 'M8 navigation production acceptance: FAIL — ' . $error->getMessage() . PHP_EOL);
            if ('' !== $rollbackError) {
                fwrite(STDERR, 'Emergency rollback also failed: ' . $rollbackError . PHP_EOL);
            }
            fwrite(STDERR, 'Evidence: ' . $this->evidencePath . PHP_EOL);
            return 1;
        }
    }

    private function execute(): void
    {
        $capabilities = $this->request('GET', '/capabilities', null, [200], 'capabilities');
        $capabilityData = $this->data($capabilities);
        $control = is_array($capabilityData['navigation_control'] ?? null) ? $capabilityData['navigation_control'] : [];
        $this->check('navigation-capability-advertised',
            !empty($control['available'])
            && '/research-manager/v1/navigation' === (string) ($control['transport_base'] ?? '')
            && !empty($control['registered_theme_targets_only'])
            && empty($control['arbitrary_urls'])
            && !empty($control['rendered_verification'])
            && !empty($control['stale_preview_protection'])
            && !empty($control['idempotency']),
            ['navigation_control' => $control]
        );

        $baselineResponse = $this->request('GET', '/navigation', null, [200], 'navigation-baseline');
        $baseline = $this->data($baselineResponse);
        $state = is_array($baseline['state'] ?? null) ? $baseline['state'] : [];
        $revision = (string) ($baseline['revision'] ?? '');
        $this->check('baseline-revision-present', '' !== $revision, ['revision' => $revision]);

        [$location, $beforeItems] = $this->selectLocation($state);
        $afterItems = $beforeItems;
        [$afterItems[0], $afterItems[1]] = [$afterItems[1], $afterItems[0]];
        $this->evidence['baseline'] = [
            'location' => $location,
            'items' => $beforeItems,
            'revision' => $revision,
            'full_state' => $state,
        ];
        $this->evidence['candidate'] = [
            'items' => $afterItems,
            'membership_changed' => false,
            'reorder_only' => true,
        ];

        $planResponse = $this->request('POST', '/navigation/plan', [
            'location' => $location,
            'items' => $afterItems,
            'intent' => 'M8 production acceptance — reversible Theme navigation reorder',
        ], [200], 'navigation-plan');
        $plan = $this->data($planResponse);
        $planId = (string) ($plan['plan_id'] ?? '');
        $preview = is_array($plan['preview'] ?? null) ? $plan['preview'] : [];
        $this->check('preview-plan-id-present', '' !== $planId, ['plan_id' => $planId]);
        $this->check('preview-is-reorder-only',
            $beforeItems === array_values((array) ($preview['before'] ?? []))
            && $afterItems === array_values((array) ($preview['after'] ?? []))
            && !empty($preview['reordered'])
            && [] === array_values((array) ($preview['added'] ?? []))
            && [] === array_values((array) ($preview['removed'] ?? []))
            && empty($preview['arbitrary_urls'])
            && !empty($plan['apply_allowed']),
            ['preview' => $preview]
        );

        if (!$this->apply) {
            $this->evidence['apply_skipped'] = true;
            $this->evidence['mutation_performed'] = false;
            $this->writeEvidence();
            fwrite(STDOUT, "M8 navigation production Preview: PASS\n");
            fwrite(STDOUT, "Reorder-only plan prepared; no production mutation was executed.\n");
            fwrite(STDOUT, 'Evidence: ' . $this->evidencePath . PHP_EOL);
            return;
        }

        $applyResponse = $this->request('POST', '/navigation/plans/' . rawurlencode($planId) . '/apply', [
            'confirm' => true,
        ], [200], 'navigation-apply');
        $operation = $this->data($applyResponse);
        $this->operationId = (string) ($operation['operation_id'] ?? '');
        $this->check('apply-operation-id-present', '' !== $this->operationId, ['operation_id' => $this->operationId]);
        $this->check('apply-status-applied', 'applied' === (string) ($operation['status'] ?? ''), ['status' => $operation['status'] ?? null]);

        $appliedInspection = $this->request('GET', '/navigation', null, [200], 'navigation-after-apply');
        $appliedState = (array) ($this->data($appliedInspection)['state'] ?? []);
        $this->check('applied-state-matches-candidate',
            $afterItems === array_values((array) ($appliedState[$location] ?? [])),
            ['location' => $location, 'actual' => $appliedState[$location] ?? null]
        );

        $verifyResponse = $this->request('POST', '/navigation/operations/' . rawurlencode($this->operationId) . '/verify', [
            'rendered' => true,
        ], [200], 'navigation-verify');
        $verifiedOperation = $this->data($verifyResponse);
        $verification = is_array($verifiedOperation['verification'] ?? null) ? $verifiedOperation['verification'] : [];
        $rendered = is_array($verification['rendered'] ?? null) ? $verification['rendered'] : [];
        $this->check('stored-navigation-verified',
            'verified' === (string) ($verifiedOperation['status'] ?? '')
            && !empty($verification['verified'])
            && !empty($verification['state_matches'])
            && !empty($verification['revision_matches']),
            ['verification' => $verification]
        );
        $this->check('rendered-navigation-verified', !empty($rendered['verified']), ['rendered' => $rendered]);

        $rollbackResponse = $this->request('POST', '/navigation/operations/' . rawurlencode($this->operationId) . '/rollback', [
            'confirm' => true,
        ], [200], 'navigation-rollback');
        $rolledBack = $this->data($rollbackResponse);
        $this->check('rollback-status',
            'rolled-back' === (string) ($rolledBack['status'] ?? '')
            && !empty($rolledBack['rollback_result']['verified']),
            ['rollback' => $rolledBack['rollback_result'] ?? null]
        );
        $this->rollbackCompleted = true;

        $finalResponse = $this->request('GET', '/navigation', null, [200], 'navigation-final');
        $final = $this->data($finalResponse);
        $finalState = is_array($final['state'] ?? null) ? $final['state'] : [];
        $finalRevision = (string) ($final['revision'] ?? '');
        $this->check('exact-baseline-restored', $state === $finalState, [
            'baseline_state' => $state,
            'final_state' => $finalState,
        ]);
        $this->check('baseline-revision-restored', hash_equals($revision, $finalRevision), [
            'baseline_revision' => $revision,
            'final_revision' => $finalRevision,
        ]);

        $this->evidence['mutation_performed'] = true;
        $this->evidence['rollback_completed'] = true;
        $this->writeEvidence();
        fwrite(STDOUT, "M8 navigation reversible production acceptance: PASS\n");
        fwrite(STDOUT, "Preview → Apply → stored/rendered Verify → Rollback completed.\n");
        fwrite(STDOUT, "Exact navigation baseline and revision restored.\n");
        fwrite(STDOUT, 'Evidence: ' . $this->evidencePath . PHP_EOL);
    }

    private function selectLocation(array $state): array
    {
        foreach (['primary', 'footer'] as $location) {
            $items = array_values(array_filter((array) ($state[$location] ?? []), static fn($item): bool => is_string($item) && '' !== $item));
            if (count($items) >= 2) {
                return [$location, $items];
            }
        }
        throw new RuntimeException('Production navigation has fewer than two existing Theme targets in both primary and footer; reorder-only acceptance cannot run safely.');
    }

    private function emergencyRollback(): void
    {
        $response = $this->request(
            'POST',
            '/navigation/operations/' . rawurlencode($this->operationId) . '/rollback',
            ['confirm' => true],
            [200],
            'navigation-emergency-rollback'
        );
        $data = $this->data($response);
        if ('rolled-back' !== (string) ($data['status'] ?? '') || empty($data['rollback_result']['verified'])) {
            throw new RuntimeException('Emergency navigation rollback did not verify baseline restoration.');
        }
        $this->rollbackCompleted = true;
        $this->evidence['emergency_rollback_completed'] = true;
    }

    private function validateRuntime(): void
    {
        if (!str_starts_with($this->baseUrl, 'https://')) {
            throw new RuntimeException('ERM_BASE_URL must be an HTTPS site URL.');
        }
        if ('' === $this->token || (!str_starts_with($this->token, 'erm1_') && !str_starts_with($this->token, 'ermt1_'))) {
            throw new RuntimeException('ERM_TOKEN must be a Research Manager credential.');
        }
    }

    private function request(string $method, string $relative, ?array $body, array $expectedStatuses, string $label): array
    {
        $requestId = 'm8-navigation-' . preg_replace('/[^A-Za-z0-9._:-]/', '-', $label) . '-' . time() . '-' . bin2hex(random_bytes(4));
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
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Eduardo-Research-M8-Navigation-Acceptance/1.0',
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
        $this->evidence['checks'][] = ['id' => $id, 'passes' => $passes, 'details' => $this->sanitize($details)];
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

exit((new M8NavigationProductionAcceptance($argv))->run());