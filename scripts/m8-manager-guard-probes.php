#!/usr/bin/env php
<?php
declare(strict_types=1);

final class M8ManagerGuardProbes
{
    private const API_PREFIX = '/wp-json/research-manager/v1';

    private string $baseUrl;
    private string $token;
    private bool $apply;
    private string $evidencePath;
    private array $evidence = [];

    public function __construct(array $config, bool $apply, string $evidencePath)
    {
        $this->baseUrl = rtrim((string) (getenv('ERM_BASE_URL') ?: ($config['site'] ?? '')), '/');
        $this->token = trim((string) (getenv('ERM_TOKEN') ?: ''));
        $this->apply = $apply;
        $this->evidencePath = $evidencePath;
    }

    public function run(string $command): int
    {
        if ('contract' === $command) {
            return $this->contract();
        }

        try {
            $this->requireRuntime();
            $this->evidence = [
                'schema_version' => 1,
                'milestone' => 'M8',
                'probe' => $command,
                'site' => $this->baseUrl,
                'apply_enabled' => $this->apply,
                'started_at' => gmdate(DATE_ATOM),
                'requests' => [],
                'checks' => [],
                'result' => 'running',
            ];

            match ($command) {
                'idempotency' => $this->idempotencyProbe(),
                'stale-design' => $this->staleDesignProbe(),
                'all' => $this->all(),
                default => throw new RuntimeException('Unknown probe: ' . $command),
            };

            $this->evidence['result'] = 'pass';
            $this->evidence['finished_at'] = gmdate(DATE_ATOM);
            $this->writeEvidence();
            fwrite(STDOUT, "M8 guard probe {$command}: PASS\nEvidence: {$this->evidencePath}\n");
            return 0;
        } catch (Throwable $error) {
            $this->evidence['result'] = 'fail';
            $this->evidence['finished_at'] = gmdate(DATE_ATOM);
            $this->evidence['failure'] = ['class' => get_class($error), 'message' => $error->getMessage()];
            $this->writeEvidence();
            fwrite(STDERR, "M8 guard probe {$command}: FAIL — {$error->getMessage()}\nEvidence: {$this->evidencePath}\n");
            return 1;
        }
    }

    private function contract(): int
    {
        fwrite(STDOUT, "M8 guard probe contract: PASS\n");
        fwrite(STDOUT, "- token: ERM_TOKEN environment only\n");
        fwrite(STDOUT, "- transport: HTTPS, TLS verification, redirects disabled\n");
        fwrite(STDOUT, "- idempotency: repeated design Preview only; no mutation\n");
        fwrite(STDOUT, "- stale-plan: bounded Theme preset mutation only; --apply required; exact rollback required\n");
        return 0;
    }

    private function requireRuntime(): void
    {
        if (!str_starts_with($this->baseUrl, 'https://')) {
            throw new RuntimeException('ERM_BASE_URL (or config.site) must be HTTPS.');
        }
        if (!str_starts_with($this->token, 'erm1_')) {
            throw new RuntimeException('ERM_TOKEN is required in the environment and must be a Research Manager credential.');
        }
    }

    private function all(): void
    {
        $this->idempotencyProbe();
        $this->staleDesignProbe();
    }

    private function idempotencyProbe(): void
    {
        $design = $this->request('GET', '/design', null, [200], 'idempotency-design');
        $change = $this->chooseAlternates($this->data($design))[0] ?? null;
        if (!is_array($change)) {
            throw new RuntimeException('No safe alternate design preset is available for idempotency probe.');
        }

        $body = [
            'changes' => $change,
            'intent' => 'M8 live idempotency proof — Preview only',
        ];
        $fixedRequestId = 'm8-idempotency-' . time() . '-' . bin2hex(random_bytes(4));
        $first = $this->request('POST', '/design/plan', $body, [200], 'idempotency-first', $fixedRequestId);
        $second = $this->request('POST', '/design/plan', $body, [200], 'idempotency-replay', $fixedRequestId);

        $firstPlan = (string) ($this->data($first)['plan_id'] ?? '');
        $secondPlan = (string) ($this->data($second)['plan_id'] ?? '');
        $this->check('idempotency-plan-present', '' !== $firstPlan, ['plan_id' => $firstPlan]);
        $this->check('idempotency-same-plan', $firstPlan === $secondPlan, ['first' => $firstPlan, 'second' => $secondPlan]);
        $this->check('idempotency-replay-flag', !empty($second['idempotent_replay']), ['idempotent_replay' => $second['idempotent_replay'] ?? null]);

        $this->evidence['idempotency'] = [
            'request_id' => $fixedRequestId,
            'changes' => $change,
            'plan_id' => $firstPlan,
            'replayed' => !empty($second['idempotent_replay']),
            'mutation_performed' => false,
        ];
    }

    private function staleDesignProbe(): void
    {
        if (!$this->apply) {
            throw new RuntimeException('stale-design requires --apply because it performs one bounded design Apply followed by exact Rollback.');
        }

        $baselineResponse = $this->request('GET', '/design', null, [200], 'stale-baseline');
        $baseline = $this->data($baselineResponse);
        $baselineState = is_array($baseline['state'] ?? null) ? $baseline['state'] : [];
        $alternates = $this->chooseAlternates($baseline);
        if (count($alternates) < 2) {
            throw new RuntimeException('Stale-plan proof requires two safe alternate design presets from one Theme dimension.');
        }

        $planA = $this->request('POST', '/design/plan', [
            'changes' => $alternates[0],
            'intent' => 'M8 stale-plan proof A — must become stale',
        ], [200], 'stale-plan-a');
        $planB = $this->request('POST', '/design/plan', [
            'changes' => $alternates[1],
            'intent' => 'M8 stale-plan proof B — bounded temporary Apply',
        ], [200], 'stale-plan-b');

        $planAId = (string) ($this->data($planA)['plan_id'] ?? '');
        $planBId = (string) ($this->data($planB)['plan_id'] ?? '');
        if ('' === $planAId || '' === $planBId) {
            throw new RuntimeException('Design stale-plan setup did not return both plan IDs.');
        }

        $operationB = '';
        $rollbackDone = false;
        try {
            $applyB = $this->request('POST', "/design/plans/{$planBId}/apply", ['confirm' => true], [200], 'stale-apply-b');
            $operationB = (string) ($this->data($applyB)['operation_id'] ?? '');
            if ('' === $operationB) {
                throw new RuntimeException('Temporary design Apply did not return operation_id.');
            }

            $stale = $this->request('POST', "/design/plans/{$planAId}/apply", ['confirm' => true], [409], 'stale-apply-a');
            $staleCode = (string) ($stale['code'] ?? $stale['data']['code'] ?? '');
            $this->check('stale-plan-rejected', 'stale_revision' === $staleCode, ['error_code' => $staleCode]);

            $verified = $this->request('POST', "/design/operations/{$operationB}/verify", [], [200], 'stale-verify-b');
            $verifyData = $this->data($verified);
            $this->check('temporary-design-verified', 'verified' === (string) ($verifyData['status'] ?? '') && !empty($verifyData['verification']['verified']), [
                'status' => $verifyData['status'] ?? null,
                'verification' => $verifyData['verification'] ?? [],
            ]);

            $rollback = $this->request('POST', "/design/operations/{$operationB}/rollback", ['confirm' => true], [200], 'stale-rollback-b');
            $rollbackData = $this->data($rollback);
            $rollbackDone = true;
            $this->check('stale-probe-rollback-verified', 'rolled-back' === (string) ($rollbackData['status'] ?? '') && !empty($rollbackData['rollback_result']['verified']), [
                'status' => $rollbackData['status'] ?? null,
                'rollback_result' => $rollbackData['rollback_result'] ?? [],
            ]);

            $finalResponse = $this->request('GET', '/design', null, [200], 'stale-final');
            $finalState = is_array($this->data($finalResponse)['state'] ?? null) ? $this->data($finalResponse)['state'] : [];
            $this->check('stale-probe-baseline-restored', $baselineState === $finalState, ['baseline' => $baselineState, 'final' => $finalState]);

            $this->evidence['stale_design'] = [
                'baseline' => $baselineState,
                'plan_a_changes' => $alternates[0],
                'plan_b_changes' => $alternates[1],
                'plan_a' => $planAId,
                'plan_b' => $planBId,
                'operation_b' => $operationB,
                'stale_error' => $staleCode,
                'rollback_verified' => true,
                'baseline_restored' => true,
            ];
        } finally {
            if ('' !== $operationB && !$rollbackDone) {
                try {
                    $this->request('POST', "/design/operations/{$operationB}/rollback", ['confirm' => true], [200, 409], 'stale-emergency-rollback');
                } catch (Throwable $ignored) {
                    $this->evidence['emergency_rollback_error'] = $ignored->getMessage();
                }
            }
        }
    }

    /** @return list<array<string,string>> */
    private function chooseAlternates(array $design): array
    {
        $state = is_array($design['state'] ?? null) ? $design['state'] : [];
        $presets = is_array($design['presets'] ?? null) ? $design['presets'] : [];

        foreach (['palette', 'typography', 'density'] as $dimension) {
            $current = (string) ($state[$dimension] ?? '');
            $candidates = [];
            foreach ((array) ($presets[$dimension] ?? []) as $key => $definition) {
                $key = (string) $key;
                if ($key === $current) {
                    continue;
                }
                if ('palette' === $dimension && empty($definition['accessibility']['accessible'])) {
                    continue;
                }
                $candidates[] = [$dimension => $key];
            }
            if (count($candidates) >= 2) {
                return $candidates;
            }
        }
        return [];
    }

    private function request(string $method, string $relative, ?array $body, array $expectedStatuses, string $label, ?string $requestId = null): array
    {
        $method = strtoupper($method);
        $path = (string) (parse_url($relative, PHP_URL_PATH) ?: '');
        if (!$this->allowed($method, $path)) {
            throw new RuntimeException("Route outside M8 guard allowlist: {$method} {$path}");
        }

        $requestId = $requestId ?: 'm8-guard-' . time() . '-' . bin2hex(random_bytes(5));
        $nonce = bin2hex(random_bytes(16));
        $timestamp = (string) time();
        $url = $this->baseUrl . self::API_PREFIX . $relative;
        $payload = null === $body ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
            'X-Research-Manager-Request-Id: ' . $requestId,
            'X-Research-Manager-Nonce: ' . $nonce,
            'X-Research-Manager-Timestamp: ' . $timestamp,
        ];
        if (null !== $body) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        if (false === $ch) {
            throw new RuntimeException('Unable to initialize cURL.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Eduardo-Research-M8-Guard-Probes/1.0',
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

        try {
            $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decoded = ['raw_non_json' => substr((string) $raw, 0, 1000)];
        }
        if (!is_array($decoded)) {
            $decoded = ['value' => $decoded];
        }

        $this->evidence['requests'][] = [
            'label' => $label,
            'method' => $method,
            'route' => self::API_PREFIX . $relative,
            'request_id' => $requestId,
            'http_status' => $status,
            'response' => $this->sanitize($decoded),
        ];

        if (!in_array($status, $expectedStatuses, true)) {
            $code = (string) ($decoded['code'] ?? '');
            throw new RuntimeException(sprintf('%s returned HTTP %d, expected %s%s', $label, $status, implode('/', $expectedStatuses), $code ? ": {$code}" : ''));
        }
        return $decoded;
    }

    private function allowed(string $method, string $path): bool
    {
        $patterns = [
            'GET' => ['#^/design$#'],
            'POST' => [
                '#^/design/plan$#',
                '#^/design/plans/[A-Za-z0-9._:-]+/apply$#',
                '#^/design/operations/[A-Za-z0-9._:-]+/verify$#',
                '#^/design/operations/[A-Za-z0-9._:-]+/rollback$#',
            ],
        ];
        foreach ($patterns[$method] ?? [] as $pattern) {
            if (1 === preg_match($pattern, $path)) {
                return true;
            }
        }
        return false;
    }

    private function data(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    private function check(string $id, bool $passes, array $details): void
    {
        $this->evidence['checks'][] = ['id' => $id, 'passes' => $passes, 'details' => $this->sanitize($details)];
        if (!$passes) {
            throw new RuntimeException('M8 guard check failed: ' . $id);
        }
    }

    private function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $name = strtolower((string) $key);
                if (in_array($name, ['token', 'token_hash', 'authorization', 'password', 'secret'], true)) {
                    $out[$key] = '[redacted]';
                } else {
                    $out[$key] = $this->sanitize($item);
                }
            }
            return $out;
        }
        if (is_string($value) && str_contains($value, 'erm1_')) {
            return preg_replace('/erm1_[A-Fa-f0-9]{16,}/', 'erm1_[redacted]', $value);
        }
        return $value;
    }

    private function writeEvidence(): void
    {
        if (!$this->evidence) {
            return;
        }
        $dir = dirname($this->evidencePath);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create evidence directory.');
        }
        $json = json_encode($this->sanitize($this->evidence), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (false === file_put_contents($this->evidencePath, $json . PHP_EOL, LOCK_EX)) {
            throw new RuntimeException('Unable to write evidence file.');
        }
        @chmod($this->evidencePath, 0600);
    }
}

function m8_guard_usage(): void
{
    fwrite(STDOUT, <<<'TXT'
Usage:
  php scripts/m8-manager-guard-probes.php <contract|idempotency|stale-design|all> [--config=path] [--apply] [--evidence=path]

idempotency  Creates the same bounded design Preview twice with one request ID and proves replay. No mutation.
stale-design Creates two design Previews, applies B, proves A is rejected with stale_revision, verifies B, rolls B back, and proves the baseline is exact.
all          Runs idempotency then stale-design. Requires --apply.

Secrets are accepted only through ERM_TOKEN. ERM_BASE_URL can override config.site.
TXT
    );
}

$args = $argv;
array_shift($args);
$command = array_shift($args) ?? '';
if ('' === $command || in_array($command, ['help', '-h', '--help'], true)) {
    m8_guard_usage();
    exit('' === $command ? 1 : 0);
}

$configPath = 'config/m8-live-acceptance.example.json';
$apply = false;
$evidencePath = 'tmp/m8-live-guards-' . gmdate('Ymd-His') . '.json';
foreach ($args as $arg) {
    if ('--apply' === $arg) {
        $apply = true;
    } elseif (str_starts_with($arg, '--config=')) {
        $configPath = substr($arg, strlen('--config='));
    } elseif (str_starts_with($arg, '--evidence=')) {
        $evidencePath = substr($arg, strlen('--evidence='));
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}

if (!is_file($configPath)) {
    fwrite(STDERR, "Config file not found: {$configPath}\n");
    exit(1);
}
try {
    $config = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, "Invalid JSON config: {$error->getMessage()}\n");
    exit(1);
}
if (!is_array($config)) {
    fwrite(STDERR, "Config must be a JSON object.\n");
    exit(1);
}

$probe = new M8ManagerGuardProbes($config, $apply, $evidencePath);
exit($probe->run($command));
