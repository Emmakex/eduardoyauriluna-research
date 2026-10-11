#!/usr/bin/env php
<?php
declare(strict_types=1);

final class M8ManagerClient
{
    private const API_PREFIX = '/wp-json/research-manager/v1';

    private string $baseUrl;
    private string $token;
    private array $config;
    private bool $apply;
    private array $evidence = [];
    private string $evidencePath;

    /** @var array<string,list<string>> */
    private array $allowlist = [
        'GET' => [
            '#^/status$#', '#^/versions$#', '#^/capabilities$#', '#^/readiness$#', '#^/diagnostics$#',
            '#^/pages$#', '#^/pages/[a-z0-9-]+$#', '#^/pages/[a-z0-9-]+/seo-geo$#',
            '#^/insights$#', '#^/insights/\d+$#', '#^/insights/\d+/seo-geo$#',
            '#^/plans/[A-Za-z0-9._:-]+$#', '#^/operations/[A-Za-z0-9._:-]+$#',
            '#^/seo-geo/site$#', '#^/seo-geo/resource$#', '#^/seo-geo/resource/actions$#',
            '#^/seo-geo/indexability$#', '#^/seo-geo/indexability/resource$#', '#^/seo-geo/internal-links$#',
            '#^/seo-geo/readiness$#', '#^/seo-geo/plans/[A-Za-z0-9._:-]+$#', '#^/seo-geo/operations/[A-Za-z0-9._:-]+$#',
            '#^/design$#', '#^/design/readiness$#', '#^/design/plans/[A-Za-z0-9._:-]+$#', '#^/design/operations/[A-Za-z0-9._:-]+$#',
            '#^/design/variants$#', '#^/design/variants/plans/[A-Za-z0-9._:-]+$#', '#^/design/variants/operations/[A-Za-z0-9._:-]+$#',
        ],
        'POST' => [
            '#^/operations/plan$#', '#^/plans/[A-Za-z0-9._:-]+/apply$#',
            '#^/operations/[A-Za-z0-9._:-]+/verify$#', '#^/operations/[A-Za-z0-9._:-]+/rollback$#',
            '#^/seo-geo/remediation/plan$#', '#^/seo-geo/plans/[A-Za-z0-9._:-]+/apply$#',
            '#^/seo-geo/operations/[A-Za-z0-9._:-]+/verify$#', '#^/seo-geo/operations/[A-Za-z0-9._:-]+/rollback$#',
            '#^/design/plan$#', '#^/design/plans/[A-Za-z0-9._:-]+/apply$#',
            '#^/design/operations/[A-Za-z0-9._:-]+/verify$#', '#^/design/operations/[A-Za-z0-9._:-]+/rollback$#',
            '#^/design/variants/plan$#', '#^/design/variants/plans/[A-Za-z0-9._:-]+/apply$#',
            '#^/design/variants/operations/[A-Za-z0-9._:-]+/verify$#', '#^/design/variants/operations/[A-Za-z0-9._:-]+/rollback$#',
        ],
    ];

    public function __construct(array $config, bool $apply, string $evidencePath)
    {
        $this->config = $config;
        $this->apply = $apply;
        $this->baseUrl = rtrim((string) (getenv('ERM_BASE_URL') ?: ($config['site'] ?? '')), '/');
        $this->token = trim((string) (getenv('ERM_TOKEN') ?: ''));
        $this->evidencePath = $evidencePath;
    }

    public function run(string $command): int
    {
        if ('contract' === $command) {
            return $this->contractCheck();
        }

        $this->requireRuntimeConfiguration();
        $this->evidence = [
            'schema_version' => 1,
            'milestone' => 'M8',
            'site' => $this->baseUrl,
            'command' => $command,
            'apply_enabled' => $this->apply,
            'started_at' => gmdate(DATE_ATOM),
            'requests' => [],
            'checks' => [],
            'result' => 'running',
        ];

        try {
            match ($command) {
                'preflight' => $this->preflight(),
                'page' => $this->pageCycle(),
                'insight' => $this->insightCycle(),
                'seo' => $this->seoCycle(),
                'design' => $this->designCycle(),
                'readonly-probe' => $this->readOnlyProbe(),
                'revoked-probe' => $this->revokedProbe(),
                'full' => $this->fullCycle(),
                default => throw new RuntimeException('Unknown command: ' . $command),
            };
            $this->evidence['result'] = 'pass';
            $this->evidence['finished_at'] = gmdate(DATE_ATOM);
            $this->writeEvidence();
            fwrite(STDOUT, "M8 {$command}: PASS\nEvidence: {$this->evidencePath}\n");
            return 0;
        } catch (Throwable $error) {
            $this->evidence['result'] = 'fail';
            $this->evidence['finished_at'] = gmdate(DATE_ATOM);
            $this->evidence['failure'] = [
                'class' => get_class($error),
                'message' => $error->getMessage(),
            ];
            $this->writeEvidence();
            fwrite(STDERR, "M8 {$command}: FAIL — {$error->getMessage()}\nEvidence: {$this->evidencePath}\n");
            return 1;
        }
    }

    private function contractCheck(): int
    {
        $problems = [];
        if (isset($this->config['token']) || isset($this->config['authorization'])) {
            $problems[] = 'Config must never contain a bearer token or Authorization value.';
        }
        $site = (string) ($this->config['site'] ?? '');
        if ('' !== $site && !str_starts_with($site, 'https://')) {
            $problems[] = 'Configured production site must use HTTPS.';
        }
        foreach ($this->allowlist as $method => $patterns) {
            if (!$patterns) {
                $problems[] = "Allowlist for {$method} is empty.";
            }
        }
        if ($problems) {
            foreach ($problems as $problem) {
                fwrite(STDERR, "CONTRACT FAIL: {$problem}\n");
            }
            return 1;
        }
        fwrite(STDOUT, "M8 client contract: PASS\n");
        fwrite(STDOUT, "- secrets: ERM_TOKEN environment only\n");
        fwrite(STDOUT, "- transport: HTTPS, no redirects, TLS verification enabled\n");
        fwrite(STDOUT, "- remote surface: allowlisted Research Manager routes only\n");
        fwrite(STDOUT, "- writes: exact Plan → Apply → Verify → optional Rollback; --apply required\n");
        return 0;
    }

    private function requireRuntimeConfiguration(): void
    {
        if (!str_starts_with($this->baseUrl, 'https://')) {
            throw new RuntimeException('ERM_BASE_URL (or config.site) must be an HTTPS site URL.');
        }
        if ('' === $this->token) {
            throw new RuntimeException('ERM_TOKEN is required in the environment. Never place it in the config file.');
        }
        if (!str_starts_with($this->token, 'erm1_')) {
            throw new RuntimeException('ERM_TOKEN does not look like a Research Manager credential.');
        }
    }

    private function preflight(): array
    {
        $status = $this->request('GET', '/status', null, [200], 'status');
        $versions = $this->request('GET', '/versions', null, [200], 'versions');
        $capabilities = $this->request('GET', '/capabilities', null, [200], 'capabilities');
        $readiness = $this->request('GET', '/readiness', null, [200], 'readiness');
        $pages = $this->request('GET', '/pages', null, [200], 'pages');
        $insightsEn = $this->request('GET', '/insights?language=en', null, [200], 'insights-en');
        $insightsEs = $this->request('GET', '/insights?language=es', null, [200], 'insights-es');
        $seo = $this->request('GET', '/seo-geo/readiness?per_type_limit=1', null, [200], 'seo-geo-readiness');
        $design = $this->request('GET', '/design', null, [200], 'design');
        $variants = $this->request('GET', '/design/variants', null, [200], 'design-variants');
        $designReadiness = $this->request('GET', '/design/readiness?rendered=1', null, [200], 'design-readiness');

        $statusData = $this->data($status);
        $capabilityData = $this->data($capabilities);
        $canonical = rtrim((string) ($statusData['canonical_domain'] ?? ''), '/');
        $siteHost = parse_url($this->baseUrl, PHP_URL_HOST);
        $canonicalHost = parse_url($canonical, PHP_URL_HOST);
        $this->assertCheck('canonical-domain', is_string($siteHost) && $siteHost !== '' && $siteHost === $canonicalHost, [
            'configured_host' => $siteHost,
            'canonical_host' => $canonicalHost,
        ]);
        $this->assertCheck('remote-enabled', !empty($statusData['remote']['enabled']), [
            'remote' => $statusData['remote'] ?? [],
        ]);
        $this->assertCheck('languages-en-es', ['en', 'es'] === array_values((array) ($statusData['languages'] ?? [])), [
            'languages' => $statusData['languages'] ?? [],
        ]);
        $this->assertCheck('manager-capabilities-present', !empty($capabilityData['read_endpoints']) && !empty($capabilityData['mutation_transport']), []);

        $result = compact('status', 'versions', 'capabilities', 'readiness', 'pages', 'insightsEn', 'insightsEs', 'seo', 'design', 'variants', 'designReadiness');
        $this->evidence['preflight'] = $this->sanitizeForEvidence($result);
        return $result;
    }

    private function fullCycle(): void
    {
        $this->preflight();
        if (isset($this->config['page'])) {
            $this->pageCycle();
        }
        if (isset($this->config['insight'])) {
            $this->insightCycle();
        }
        if (isset($this->config['seo'])) {
            $this->seoCycle();
        }
        if (!isset($this->config['design']) || false !== ($this->config['design']['enabled'] ?? true)) {
            $this->designCycle();
        }
    }

    private function pageCycle(): void
    {
        $cfg = $this->section('page');
        $this->rejectPlaceholders($cfg, 'page');
        $key = $this->slug((string) ($cfg['key'] ?? ''));
        $language = $this->slug((string) ($cfg['language'] ?? 'en'));
        $slots = is_array($cfg['slots'] ?? null) ? $cfg['slots'] : [];
        if ('' === $key || !$slots) {
            throw new RuntimeException('page requires key and non-empty slots in config.');
        }

        $before = $this->request('GET', "/pages/{$key}?language=" . rawurlencode($language), null, [200], 'page-before');
        $payload = [
            'key' => $key,
            'language' => $language,
            'slots' => $slots,
            'reason' => (string) ($cfg['reason'] ?? 'M8 real-site Page acceptance'),
        ];
        $cycle = $this->genericOperationCycle('page-slots-update', $payload, !empty($cfg['rollback_after_verify'] ?? true), true);
        if ($this->apply) {
            $after = $this->request('GET', "/pages/{$key}?language=" . rawurlencode($language), null, [200], 'page-after');
            $cycle['inspection_after'] = $after;
        }
        $this->evidence['page_cycle'] = $this->sanitizeForEvidence(['before' => $before] + $cycle);
    }

    private function insightCycle(): void
    {
        $cfg = $this->section('insight');
        $this->rejectPlaceholders($cfg, 'insight');
        $data = is_array($cfg['data'] ?? null) ? $cfg['data'] : [];
        if (!$data) {
            throw new RuntimeException('insight requires a bounded data object in config.');
        }
        $payload = [
            'data' => $data,
            'reason' => (string) ($cfg['reason'] ?? 'M8 real-site Insight acceptance'),
        ];
        $rollback = !empty($cfg['rollback_after_verify'] ?? true);
        $cycle = $this->genericOperationCycle('insight-create', $payload, false, true);
        $postId = (int) ($cycle['apply']['data']['target']['post_id'] ?? $cycle['apply']['data']['result']['post_id'] ?? 0);
        if ($this->apply && $postId > 0) {
            $cycle['inspection'] = $this->request('GET', "/insights/{$postId}", null, [200], 'insight-inspect');
            $cycle['seo_geo'] = $this->request('GET', "/insights/{$postId}/seo-geo", null, [200], 'insight-seo-geo');
            if ($rollback) {
                $operationId = (string) ($cycle['apply']['data']['operation_id'] ?? '');
                if ('' === $operationId) {
                    throw new RuntimeException('Insight Apply response did not contain operation_id for rollback.');
                }
                $cycle['rollback'] = $this->request('POST', "/operations/{$operationId}/rollback", ['confirm' => true], [200], 'insight-create-rollback');
            }
        }
        $this->evidence['insight_cycle'] = $this->sanitizeForEvidence($cycle);
    }

    private function seoCycle(): void
    {
        $cfg = $this->section('seo');
        $this->rejectPlaceholders($cfg, 'seo');
        $checkId = $this->slug((string) ($cfg['check_id'] ?? ''));
        if ('' === $checkId) {
            throw new RuntimeException('seo requires check_id in config after inspecting live readiness.');
        }
        $intent = (string) ($cfg['intent'] ?? 'M8 real-site SEO/GEO acceptance');
        $plan = $this->request('POST', '/seo-geo/remediation/plan', ['check_id' => $checkId, 'intent' => $intent], [200], 'seo-plan');
        $planId = (string) ($this->data($plan)['plan_id'] ?? '');
        if ('' === $planId) {
            throw new RuntimeException('SEO/GEO plan response did not contain plan_id.');
        }
        $cycle = ['plan' => $plan];
        if (!$this->apply) {
            $cycle['apply_skipped'] = true;
            $this->evidence['seo_cycle'] = $this->sanitizeForEvidence($cycle);
            return;
        }
        $apply = $this->request('POST', "/seo-geo/plans/{$planId}/apply", ['confirm' => true], [200], 'seo-apply');
        $operationId = (string) ($this->data($apply)['operation_id'] ?? '');
        if ('' === $operationId) {
            throw new RuntimeException('SEO/GEO Apply response did not contain operation_id.');
        }
        $verify = $this->request('POST', "/seo-geo/operations/{$operationId}/verify", ['rendered' => true], [200], 'seo-verify');
        $this->assertOperationVerified('seo', $verify, true);
        $cycle += ['apply' => $apply, 'verify' => $verify, 'readiness_after' => $this->request('GET', '/seo-geo/readiness?per_type_limit=1', null, [200], 'seo-readiness-after')];
        if (!empty($cfg['rollback_after_verify'] ?? true)) {
            $cycle['rollback'] = $this->request('POST', "/seo-geo/operations/{$operationId}/rollback", ['confirm' => true], [200], 'seo-rollback');
            $cycle['readiness_final'] = $this->request('GET', '/seo-geo/readiness?per_type_limit=1', null, [200], 'seo-readiness-final');
        }
        $this->evidence['seo_cycle'] = $this->sanitizeForEvidence($cycle);
    }

    private function designCycle(): void
    {
        $cfg = is_array($this->config['design'] ?? null) ? $this->config['design'] : [];
        $before = $this->request('GET', '/design', null, [200], 'design-before');
        $beforeData = $this->data($before);
        $changes = is_array($cfg['changes'] ?? null) && $cfg['changes'] ? $cfg['changes'] : $this->chooseDesignChange($beforeData);
        if (!$changes) {
            throw new RuntimeException('No safe alternate Theme design preset is available for M8 acceptance.');
        }
        $plan = $this->request('POST', '/design/plan', [
            'changes' => $changes,
            'intent' => (string) ($cfg['intent'] ?? 'M8 real-site bounded design acceptance'),
        ], [200], 'design-plan');
        $planId = (string) ($this->data($plan)['plan_id'] ?? '');
        if ('' === $planId) {
            throw new RuntimeException('Design plan response did not contain plan_id.');
        }
        $cycle = ['before' => $before, 'changes' => $changes, 'plan' => $plan];
        if (!$this->apply) {
            $cycle['apply_skipped'] = true;
            $this->evidence['design_cycle'] = $this->sanitizeForEvidence($cycle);
            return;
        }
        $apply = $this->request('POST', "/design/plans/{$planId}/apply", ['confirm' => true], [200], 'design-apply');
        $operationId = (string) ($this->data($apply)['operation_id'] ?? '');
        if ('' === $operationId) {
            throw new RuntimeException('Design Apply response did not contain operation_id.');
        }
        $verify = $this->request('POST', "/design/operations/{$operationId}/verify", [], [200], 'design-verify');
        $this->assertOperationVerified('design', $verify, false);
        $cycle += [
            'apply' => $apply,
            'verify' => $verify,
            'readiness_after' => $this->request('GET', '/design/readiness?rendered=1', null, [200], 'design-readiness-after'),
        ];
        if (!empty($cfg['rollback_after_verify'] ?? true)) {
            $cycle['rollback'] = $this->request('POST', "/design/operations/{$operationId}/rollback", ['confirm' => true], [200], 'design-rollback');
            $final = $this->request('GET', '/design', null, [200], 'design-final');
            $cycle['final'] = $final;
            $cycle['readiness_final'] = $this->request('GET', '/design/readiness?rendered=1', null, [200], 'design-readiness-final');
            $this->assertCheck('design-rollback-restored-baseline', ($beforeData['state'] ?? null) === ($this->data($final)['state'] ?? null), [
                'before' => $beforeData['state'] ?? null,
                'final' => $this->data($final)['state'] ?? null,
            ]);
        }
        $this->evidence['design_cycle'] = $this->sanitizeForEvidence($cycle);
    }

    private function genericOperationCycle(string $operation, array $payload, bool $rollback, bool $rendered): array
    {
        $plan = $this->request('POST', '/operations/plan', ['operation' => $operation, 'payload' => $payload], [200], $operation . '-plan');
        $planId = (string) ($this->data($plan)['plan_id'] ?? '');
        if ('' === $planId) {
            throw new RuntimeException("{$operation} plan response did not contain plan_id.");
        }
        $cycle = ['plan' => $plan];
        if (!$this->apply) {
            $cycle['apply_skipped'] = true;
            return $cycle;
        }
        $apply = $this->request('POST', "/plans/{$planId}/apply", ['confirm' => true], [200], $operation . '-apply');
        $operationId = (string) ($this->data($apply)['operation_id'] ?? '');
        if ('' === $operationId) {
            throw new RuntimeException("{$operation} Apply response did not contain operation_id.");
        }
        $verify = $this->request('POST', "/operations/{$operationId}/verify", ['rendered' => $rendered], [200], $operation . '-verify');
        $this->assertOperationVerified($operation, $verify, $rendered);
        $cycle += [
            'apply' => $apply,
            'verify' => $verify,
            'status' => $this->request('GET', "/operations/{$operationId}", null, [200], $operation . '-status'),
        ];
        if ($rollback) {
            $cycle['rollback'] = $this->request('POST', "/operations/{$operationId}/rollback", ['confirm' => true], [200], $operation . '-rollback');
        }
        return $cycle;
    }

    private function assertOperationVerified(string $label, array $response, bool $rendered): void
    {
        $data = $this->data($response);
        $this->assertCheck($label . '-operation-status-verified', 'verified' === (string) ($data['status'] ?? ''), [
            'status' => $data['status'] ?? null,
        ]);
        if (isset($data['stored_verification'])) {
            $this->assertCheck($label . '-stored-verified', !empty($data['stored_verification']['verified']), [
                'stored_verification' => $data['stored_verification'],
            ]);
        }
        if ($rendered && isset($data['rendered_verification'])) {
            $renderedState = is_array($data['rendered_verification']) ? $data['rendered_verification'] : [];
            $passes = !empty($renderedState['verified']) || !empty($renderedState['not_applicable']);
            $this->assertCheck($label . '-rendered-verified', $passes, [
                'rendered_verification' => $renderedState,
            ]);
        }
        if ('design' === $label && isset($data['verification'])) {
            $this->assertCheck('design-semantic-verification', !empty($data['verification']['verified']), [
                'verification' => $data['verification'],
            ]);
        }
    }

    private function readOnlyProbe(): void
    {
        $capabilities = $this->request('GET', '/capabilities', null, [200], 'readonly-capabilities');
        $data = $this->data($capabilities);
        $this->assertCheck('readonly-writes-disabled', empty($data['writes_enabled']), ['writes_enabled' => $data['writes_enabled'] ?? null]);

        $probe = $this->request('POST', '/operations/plan', [
            'operation' => 'page-slots-update',
            'payload' => ['key' => 'home', 'language' => 'en', 'slots' => ['headline' => 'M8 read-only permission probe']],
        ], [403], 'readonly-write-probe');
        $code = (string) ($probe['code'] ?? $probe['data']['code'] ?? '');
        $this->assertCheck('readonly-write-scope-denied', 'scope_denied' === $code, ['error_code' => $code]);
        $this->evidence['readonly_probe'] = $this->sanitizeForEvidence(['capabilities' => $capabilities, 'blocked_write' => $probe]);
    }

    private function revokedProbe(): void
    {
        $response = $this->request('GET', '/status', null, [401], 'revoked-status');
        $code = (string) ($response['code'] ?? $response['data']['code'] ?? '');
        $this->assertCheck('revoked-token-rejected', 'authentication_failed' === $code, ['error_code' => $code]);
        $this->evidence['revoked_probe'] = $this->sanitizeForEvidence($response);
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

    private function request(string $method, string $relative, ?array $body, array $expectedStatus, string $label): array
    {
        $method = strtoupper($method);
        $route = (string) (parse_url($relative, PHP_URL_PATH) ?: '');
        $this->assertAllowed($method, $route);

        $requestId = 'm8-' . preg_replace('/[^A-Za-z0-9._:-]/', '-', $label) . '-' . time() . '-' . bin2hex(random_bytes(4));
        $nonce = bin2hex(random_bytes(16));
        $url = $this->baseUrl . self::API_PREFIX . $relative;
        $payload = null === $body ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if (false === $ch) {
            throw new RuntimeException('Unable to initialize cURL.');
        }
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
            'X-Research-Manager-Request-Id: ' . $requestId,
            'X-Research-Manager-Nonce: ' . $nonce,
            'X-Research-Manager-Timestamp: ' . time(),
        ];
        if (null !== $body) {
            $headers[] = 'Content-Type: application/json';
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
            CURLOPT_USERAGENT => 'Eduardo-Research-M8-Acceptance/1.0',
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
        } catch (Throwable $error) {
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
            'response' => $this->sanitizeForEvidence($decoded),
        ];

        if (!in_array($status, $expectedStatus, true)) {
            $errorCode = (string) ($decoded['code'] ?? '');
            throw new RuntimeException(sprintf('%s returned HTTP %d (expected %s)%s', $label, $status, implode('/', $expectedStatus), $errorCode ? ": {$errorCode}" : ''));
        }
        return $decoded;
    }

    private function assertAllowed(string $method, string $route): void
    {
        foreach ($this->allowlist[$method] ?? [] as $pattern) {
            if (1 === preg_match($pattern, $route)) {
                return;
            }
        }
        throw new RuntimeException("Route is outside M8 Research Manager allowlist: {$method} {$route}");
    }

    private function data(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    private function section(string $name): array
    {
        $value = $this->config[$name] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException("Missing {$name} section in M8 config.");
        }
        return $value;
    }

    private function slug(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9-]+/i', '-', trim($value)));
    }

    private function rejectPlaceholders(mixed $value, string $section): void
    {
        $stack = [$value];
        while ($stack) {
            $item = array_pop($stack);
            if (is_array($item)) {
                foreach ($item as $child) { $stack[] = $child; }
                continue;
            }
            if (is_string($item) && str_contains($item, 'REPLACE_')) {
                throw new RuntimeException("{$section} config still contains REPLACE_ placeholders. Run preflight and approve real acceptance input first.");
            }
        }
    }

    private function assertCheck(string $id, bool $passes, array $details): void
    {
        $this->evidence['checks'][] = ['id' => $id, 'passes' => $passes, 'details' => $this->sanitizeForEvidence($details)];
        if (!$passes) {
            throw new RuntimeException("M8 acceptance check failed: {$id}");
        }
    }

    private function sanitizeForEvidence(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $name = strtolower((string) $key);
                if (in_array($name, ['token', 'token_hash', 'authorization', 'password', 'secret'], true)) {
                    $clean[$key] = '[redacted]';
                    continue;
                }
                $clean[$key] = $this->sanitizeForEvidence($item);
            }
            return $clean;
        }
        if (is_string($value) && str_contains($value, 'erm1_')) {
            return preg_replace('/erm1_[A-Fa-f0-9]{16,}/', 'erm1_[redacted]', $value);
        }
        return $value;
    }

    private function writeEvidence(): void
    {
        $directory = dirname($this->evidencePath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create evidence directory: ' . $directory);
        }
        $json = json_encode($this->sanitizeForEvidence($this->evidence), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (false === file_put_contents($this->evidencePath, $json . PHP_EOL, LOCK_EX)) {
            throw new RuntimeException('Unable to write M8 evidence file.');
        }
        @chmod($this->evidencePath, 0600);
    }
}

function usage(): void
{
    $text = <<<'TXT'
Usage:
  php scripts/m8-manager-client.php <command> [--config=path] [--apply] [--evidence=path]

Commands:
  contract        Validate local M8 client/config safety contract. No network/token required.
  preflight       Read live status, versions, capabilities, inventories and M6/M7 readiness.
  page            Preview (and with --apply execute) one configured Page slot lifecycle.
  insight         Preview (and with --apply execute) one configured Insight create lifecycle.
  seo             Preview (and with --apply execute) one configured SEO/GEO remediation lifecycle.
  design          Preview (and with --apply execute) one bounded Theme design lifecycle.
  readonly-probe  Prove a read-only credential can read but receives scope_denied on a write plan.
  revoked-probe   Prove a locally revoked credential receives authentication_failed.
  full            Preflight plus configured Page/Insight/SEO/Design cycles.

Secrets:
  ERM_TOKEN       Required for network commands. Never store this value in Git/config/evidence.
  ERM_BASE_URL    Optional override for config.site, e.g. https://eduardoyauriluna.com

Writes are never executed unless --apply is supplied. Per-operation rollback_after_verify defaults to true.
TXT;
    fwrite(STDOUT, $text . PHP_EOL);
}

$args = $argv;
array_shift($args);
$command = $args[0] ?? '';
if ('' === $command || in_array($command, ['-h', '--help', 'help'], true)) {
    usage();
    exit('' === $command ? 1 : 0);
}
array_shift($args);
$configPath = 'config/m8-live-acceptance.example.json';
$apply = false;
$evidencePath = 'tmp/m8-live-acceptance-' . gmdate('Ymd-His') . '.json';
foreach ($args as $arg) {
    if ('--apply' === $arg) {
        $apply = true;
    } elseif (str_starts_with($arg, '--config=')) {
        $configPath = substr($arg, strlen('--config='));
    } elseif (str_starts_with($arg, '--evidence=')) {
        $evidencePath = substr($arg, strlen('--evidence='));
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        usage();
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
    fwrite(STDERR, "M8 config must be a JSON object.\n");
    exit(1);
}

$client = new M8ManagerClient($config, $apply, $evidencePath);
exit($client->run($command));
