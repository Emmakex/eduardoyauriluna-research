#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Fail-safe launcher for the M8 live acceptance client.
 *
 * The underlying client remains authoritative for the acceptance lifecycle.
 * This launcher adds one production guard: if a command fails after a successful
 * Apply and no normal rollback was recorded, it immediately attempts the matching
 * bounded rollback route before returning the original non-zero exit code.
 */
final class M8SafeLauncher
{
    private const API_PREFIX = '/wp-json/research-manager/v1';

    /** @var list<string> */
    private array $args;
    private string $evidencePath;
    private bool $applyRequested;

    public function __construct(array $args)
    {
        $this->args = array_values($args);
        $this->applyRequested = in_array('--apply', $this->args, true);
        $this->evidencePath = $this->resolveEvidencePath();
    }

    public function run(): int
    {
        $command = $this->args[0] ?? '';
        if ('self-test' === $command) {
            return $this->selfTest();
        }

        if ('' === $command) {
            fwrite(STDERR, "Usage: php scripts/m8-manager-safe.php <command> [client args]\n");
            return 1;
        }

        $client = __DIR__ . '/m8-manager-client.php';
        if (!is_file($client)) {
            fwrite(STDERR, "M8 client not found: {$client}\n");
            return 1;
        }

        $args = $this->args;
        if (!$this->hasEvidenceArgument($args)) {
            $args[] = '--evidence=' . $this->evidencePath;
        }

        $parts = [escapeshellarg(PHP_BINARY), escapeshellarg($client)];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }
        passthru(implode(' ', $parts), $exitCode);

        if (0 === $exitCode || !$this->applyRequested) {
            return $exitCode;
        }

        $candidate = $this->rollbackCandidateFromEvidence($this->evidencePath);
        if (null === $candidate) {
            fwrite(STDERR, "Emergency rollback: no successfully applied, unrolled operation found.\n");
            return $exitCode;
        }

        try {
            $result = $this->rollback($candidate['route'], $candidate['operation_id']);
            $this->appendEmergencyEvidence($candidate, $result, null);
            fwrite(STDERR, "Emergency rollback: PASS — {$candidate['operation_id']}\n");
        } catch (Throwable $rollbackError) {
            $this->appendEmergencyEvidence($candidate, null, $rollbackError->getMessage());
            fwrite(STDERR, "Emergency rollback: FAIL — {$rollbackError->getMessage()}\n");
        }

        return $exitCode;
    }

    private function resolveEvidencePath(): string
    {
        foreach ($this->args as $arg) {
            if (str_starts_with($arg, '--evidence=')) {
                return substr($arg, strlen('--evidence='));
            }
        }
        return 'tmp/m8-live-safe-' . gmdate('Ymd-His') . '.json';
    }

    /** @param list<string> $args */
    private function hasEvidenceArgument(array $args): bool
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--evidence=')) {
                return true;
            }
        }
        return false;
    }

    /** @return array{operation_id:string,route:string,label:string}|null */
    private function rollbackCandidateFromEvidence(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        try {
            $evidence = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($evidence) || !is_array($evidence['requests'] ?? null)) {
            return null;
        }

        $rolled = [];
        foreach ($evidence['requests'] as $request) {
            if (!is_array($request) || 200 !== (int) ($request['http_status'] ?? 0)) {
                continue;
            }
            $label = (string) ($request['label'] ?? '');
            if (!str_contains($label, 'rollback')) {
                continue;
            }
            $operationId = $this->operationId($request);
            if ('' !== $operationId) {
                $rolled[$operationId] = true;
            }
        }

        for ($i = count($evidence['requests']) - 1; $i >= 0; --$i) {
            $request = $evidence['requests'][$i];
            if (!is_array($request) || 200 !== (int) ($request['http_status'] ?? 0)) {
                continue;
            }
            $label = (string) ($request['label'] ?? '');
            if (!str_ends_with($label, '-apply')) {
                continue;
            }
            $operationId = $this->operationId($request);
            if ('' === $operationId || isset($rolled[$operationId])) {
                continue;
            }
            $route = $this->rollbackRouteForLabel($label, $operationId);
            if ('' === $route) {
                continue;
            }
            return ['operation_id' => $operationId, 'route' => $route, 'label' => $label];
        }
        return null;
    }

    private function operationId(array $request): string
    {
        $response = is_array($request['response'] ?? null) ? $request['response'] : [];
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        return preg_match('/^[A-Za-z0-9._:-]+$/', (string) ($data['operation_id'] ?? ''))
            ? (string) $data['operation_id']
            : '';
    }

    private function rollbackRouteForLabel(string $label, string $operationId): string
    {
        if ('seo-apply' === $label) {
            return '/seo-geo/operations/' . $operationId . '/rollback';
        }
        if ('design-apply' === $label) {
            return '/design/operations/' . $operationId . '/rollback';
        }
        if (str_ends_with($label, '-apply')) {
            return '/operations/' . $operationId . '/rollback';
        }
        return '';
    }

    private function rollback(string $relative, string $operationId): array
    {
        $baseUrl = rtrim((string) getenv('ERM_BASE_URL'), '/');
        $token = trim((string) getenv('ERM_TOKEN'));
        if (!str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('ERM_BASE_URL must be HTTPS for emergency rollback.');
        }
        if (!str_starts_with($token, 'erm1_')) {
            throw new RuntimeException('ERM_TOKEN is unavailable for emergency rollback.');
        }
        if (!str_contains($relative, $operationId)) {
            throw new RuntimeException('Emergency rollback route does not match the applied operation.');
        }

        $requestId = 'm8-emergency-rollback-' . time() . '-' . bin2hex(random_bytes(4));
        $nonce = bin2hex(random_bytes(16));
        $payload = (string) json_encode(['confirm' => true], JSON_THROW_ON_ERROR);
        $ch = curl_init($baseUrl . self::API_PREFIX . $relative);
        if (false === $ch) {
            throw new RuntimeException('Unable to initialise cURL for emergency rollback.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
                'X-Research-Manager-Request-Id: ' . $requestId,
                'X-Research-Manager-Nonce: ' . $nonce,
                'X-Research-Manager-Timestamp: ' . time(),
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Eduardo-Research-M8-Safe-Launcher/1.0',
        ]);
        $raw = curl_exec($ch);
        if (false === $raw) {
            $message = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Emergency rollback request failed: ' . $message);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode((string) $raw, true);
        $decoded = is_array($decoded) ? $decoded : ['raw_non_json' => substr((string) $raw, 0, 1000)];
        if (200 !== $status) {
            $code = (string) ($decoded['code'] ?? '');
            throw new RuntimeException('Emergency rollback returned HTTP ' . $status . ($code ? ': ' . $code : ''));
        }
        return [
            'request_id' => $requestId,
            'http_status' => $status,
            'route' => self::API_PREFIX . $relative,
            'response' => $decoded,
        ];
    }

    private function appendEmergencyEvidence(array $candidate, ?array $result, ?string $error): void
    {
        if (!is_file($this->evidencePath)) {
            return;
        }
        try {
            $evidence = json_decode((string) file_get_contents($this->evidencePath), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($evidence)) {
                return;
            }
            if (!is_array($evidence['emergency_rollbacks'] ?? null)) {
                $evidence['emergency_rollbacks'] = [];
            }
            $evidence['emergency_rollbacks'][] = [
                'attempted_at' => gmdate(DATE_ATOM),
                'operation_id' => $candidate['operation_id'],
                'source_apply_label' => $candidate['label'],
                'route' => $candidate['route'],
                'success' => null === $error,
                'result' => $result,
                'error' => $error,
            ];
            file_put_contents(
                $this->evidencePath,
                json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL,
                LOCK_EX
            );
            @chmod($this->evidencePath, 0600);
        } catch (Throwable) {
            // Never mask the original M8 failure because evidence augmentation failed.
        }
    }

    private function selfTest(): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'm8-safe-');
        if (false === $tmp) {
            return 1;
        }
        $sample = [
            'requests' => [
                ['label' => 'page-slots-update-apply', 'http_status' => 200, 'response' => ['data' => ['operation_id' => 'erm-operation-page']]],
                ['label' => 'page-slots-update-verify', 'http_status' => 200, 'response' => ['data' => ['status' => 'verification-failed']]],
            ],
        ];
        file_put_contents($tmp, json_encode($sample, JSON_THROW_ON_ERROR));
        $candidate = $this->rollbackCandidateFromEvidence($tmp);
        @unlink($tmp);
        $passes = is_array($candidate)
            && 'erm-operation-page' === $candidate['operation_id']
            && '/operations/erm-operation-page/rollback' === $candidate['route'];
        if (!$passes) {
            fwrite(STDERR, "M8 safe launcher self-test: FAIL\n");
            return 1;
        }
        fwrite(STDOUT, "M8 safe launcher self-test: PASS\n");
        return 0;
    }
}

$args = $argv;
array_shift($args);
$launcher = new M8SafeLauncher($args);
exit($launcher->run());
