#!/usr/bin/env php
<?php
declare(strict_types=1);

final class M8M5ObjectProductionAcceptance
{
    private const API = '/wp-json/research-manager/v1';
    private const KIND = 'software';
    private const SCOPES = [
        'site.read','site.diagnostics','research.read','research.write',
        'operations.apply','operations.rollback',
    ];

    private string $base;
    private string $token;
    private string $evidencePath;
    private bool $apply;
    private array $evidence = [];
    private array $rollbacks = [];

    public function __construct(array $argv)
    {
        $this->base = rtrim((string) getenv('ERM_BASE_URL'), '/');
        $this->token = trim((string) getenv('ERM_TOKEN'));
        $this->apply = in_array('--apply', $argv, true);
        $this->evidencePath = $this->arg($argv, '--evidence=')
            ?: 'tmp/final-v1-m5-object-' . gmdate('Ymd\\THis\\Z') . '-' . getmypid() . '.json';
    }

    public function run(): int
    {
        try {
            $this->validate();
            $this->evidence = [
                'schema_version'=>1,
                'milestone'=>'M8',
                'slice'=>'final-v1-m5-representative-object',
                'representative_kind'=>self::KIND,
                'site'=>$this->base,
                'apply_enabled'=>$this->apply,
                'expected_scopes'=>self::SCOPES,
                'requests'=>[],
                'checks'=>[],
                'started_at'=>gmdate(DATE_ATOM),
                'result'=>'running',
            ];

            $this->preflight();
            if (!$this->apply) {
                $this->evidence['apply_skipped'] = true;
                return $this->finish(true, "M8 M5 representative Research Object preflight: PASS\nNo production mutation was executed.");
            }

            $this->exercise();
            $this->check(
                'no-pending-rollbacks',
                !array_filter($this->rollbacks, fn(array $r): bool => !$r['done']),
                ['rollbacks'=>$this->rollbacks]
            );
            return $this->finish(
                true,
                "M8 M5 representative Research Object reversible production acceptance: PASS\nTemporary Research Software fixture removed; production baseline restored."
            );
        } catch (Throwable $e) {
            $this->emergency();
            $this->evidence['failure'] = ['class'=>get_class($e),'message'=>$e->getMessage()];
            return $this->finish(false, 'M8 M5 representative Research Object acceptance: FAIL — ' . $e->getMessage());
        }
    }

    private function preflight(): void
    {
        $cap = $this->data($this->req('GET','/capabilities',null,[200],'capabilities'));
        $got = array_values((array) ($cap['granted_scopes'] ?? []));
        $expected = self::SCOPES;
        sort($got); sort($expected);
        $this->check('exact-scope-profile', $got === $expected, ['got'=>$got,'expected'=>$expected]);

        $control = (array) ($cap['research_object_control'] ?? []);
        $ops = (array) ($control['operations'] ?? []);
        $kinds = (array) ($control['kinds'] ?? []);
        $this->check(
            'm5-object-capability',
            ($control['milestone'] ?? '') === 'M5'
                && !empty($control['foundation'])
                && !empty($control['evidence_gate']['required_for_mutation'])
                && in_array('object-create',$ops,true)
                && in_array('object-update',$ops,true)
                && isset($kinds[self::KIND]),
            ['research_object_control'=>$control]
        );
    }

    private function exercise(): void
    {
        $baselineInventory = $this->inventory('inventory-baseline');
        $baselineCount = (int) ($baselineInventory['count'] ?? 0);

        $slug = 'm8-v1-software-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        $title = 'Temporary M5 Research Software — reversible acceptance fixture';
        $data = [
            'title'=>$title,
            'slug'=>$slug,
            'excerpt'=>'Temporary structural acceptance fixture. Not research content and not an academic claim.',
            'content'=>'<p>Temporary structural acceptance fixture used only to verify bounded Research Manager behavior. Not research content and not an academic claim.</p>',
            'language'=>'en',
            'status'=>'draft',
            'software_status'=>'',
            'version'=>'',
            'release_date'=>'',
            'repository_url'=>'',
            'archive_url'=>'',
            'license'=>'',
            'documentation_url'=>'',
            'doi'=>'',
            'programming_languages'=>[],
            'line_ids'=>[],
        ];

        $createPlan = $this->plan('object-create', [
            'kind'=>self::KIND,
            'data'=>$data,
            'evidence_confirmed'=>true,
            'evidence_reference'=>'acceptance:final-v1:m5-object:software:create',
        ], 'object-create-plan');

        $afterPreview = $this->inventory('inventory-after-create-preview');
        $this->check(
            'create-preview-no-mutation',
            (int) ($afterPreview['count'] ?? -1) === $baselineCount,
            ['baseline_count'=>$baselineCount,'preview_count'=>$afterPreview['count'] ?? null]
        );

        $createOp = $this->applyPlan($createPlan, 'object-create-apply');
        $this->verify($createOp, true, 'object-create-verify');
        $apply = $this->last('object-create-apply');
        $postId = (int) ($apply['target']['post_id'] ?? $apply['result']['post_id'] ?? 0);
        if ($postId <= 0) throw new RuntimeException('Research Object creation Apply missing post_id.');

        $created = $this->inspect($postId, 'object-created-inspect');
        $this->check(
            'object-created-baseline',
            ($created['slug'] ?? '') === $slug
                && ($created['title'] ?? '') === $title
                && ($created['language'] ?? '') === 'en'
                && ($created['status'] ?? '') === 'draft'
                && [] === array_values((array) ($created['line_ids'] ?? [])),
            ['object'=>$created]
        );
        $baselineState = $this->state($created);

        $updatedTitle = $title . ' — updated';
        $updatePlan = $this->plan('object-update', [
            'kind'=>self::KIND,
            'post_id'=>$postId,
            'changes'=>['title'=>$updatedTitle],
            'evidence_confirmed'=>true,
            'evidence_reference'=>'acceptance:final-v1:m5-object:software:update',
        ], 'object-update-plan');

        $previewState = $this->inspect($postId, 'object-update-preview-inspect');
        $this->check(
            'update-preview-no-mutation',
            $this->state($previewState) === $baselineState,
            ['expected'=>$baselineState,'actual'=>$this->state($previewState)]
        );

        $updateOp = $this->applyPlan($updatePlan, 'object-update-apply');
        $this->verify($updateOp, false, 'object-update-verify');
        $updated = $this->inspect($postId, 'object-updated-inspect');
        $this->check(
            'object-update-applied',
            ($updated['title'] ?? '') === $updatedTitle && ($updated['status'] ?? '') === 'draft',
            ['object'=>$updated]
        );

        $this->rollback($updateOp, 'object-update-rollback');
        $restored = $this->inspect($postId, 'object-update-restored');
        $this->check(
            'object-update-baseline-restored',
            $this->state($restored) === $baselineState,
            ['expected'=>$baselineState,'actual'=>$this->state($restored)]
        );

        $this->rollback($createOp, 'object-create-rollback');
        $finalInventory = $this->inventory('inventory-final');
        $this->check(
            'inventory-baseline-restored',
            (int) ($finalInventory['count'] ?? -1) === $baselineCount,
            ['baseline_count'=>$baselineCount,'final_count'=>$finalInventory['count'] ?? null]
        );
        $missing = $this->req('GET','/research-objects/' . self::KIND . '/' . $postId,null,[404],'object-final-absence');
        $this->check('object-fixture-absent', (int) ($missing['_http_status'] ?? 0) === 404, ['post_id'=>$postId]);
    }

    private function inventory(string $label): array
    {
        return $this->data($this->req('GET','/research-objects',null,[200],$label));
    }

    private function inspect(int $postId, string $label): array
    {
        return $this->data($this->req('GET','/research-objects/' . self::KIND . '/' . $postId,null,[200],$label));
    }

    private function plan(string $operation, array $payload, string $label): string
    {
        $data = $this->data($this->req('POST','/research-objects/plan',[
            'operation'=>$operation,
            'payload'=>$payload,
        ],[200],$label));
        $planId = (string) ($data['plan_id'] ?? '');
        $this->id($planId, $label . ' plan_id');
        $this->check($label . '-apply-allowed', !empty($data['apply_allowed']), ['plan'=>$data]);
        $this->check($label . '-evidence-risk', ($data['risk'] ?? '') === 'evidence-required', ['plan'=>$data]);
        return $planId;
    }

    private function applyPlan(string $planId, string $label): string
    {
        $data = $this->data($this->req('POST','/research-objects/plans/' . rawurlencode($planId) . '/apply',[
            'confirm'=>true,
        ],[200],$label));
        $operationId = (string) ($data['operation_id'] ?? '');
        $this->id($operationId, $label . ' operation_id');
        $this->check($label . '-verified-result', !empty($data['result']['verified']), ['apply'=>$data]);
        $this->track($operationId, '/research-objects/operations/' . rawurlencode($operationId) . '/rollback');
        return $operationId;
    }

    private function verify(string $operationId, bool $rendered, string $label): void
    {
        $data = $this->data($this->req('POST','/research-objects/operations/' . rawurlencode($operationId) . '/verify',[
            'rendered'=>$rendered,
        ],[200],$label));
        $this->check($label . '-stored', !empty($data['stored_verification']['verified']), ['verify'=>$data]);
        if ($rendered) {
            $this->check($label . '-rendered', !empty($data['rendered_verification']['verified']), ['verify'=>$data]);
            $resource = (array) (($data['rendered_verification']['resources'][0] ?? []));
            $this->check(
                $label . '-draft-render-skip',
                !empty($resource['skipped']) && ($resource['reason'] ?? '') === 'draft-or-non-public',
                ['resource'=>$resource]
            );
        }
    }

    private function rollback(string $operationId, string $label): void
    {
        foreach ($this->rollbacks as &$rollback) {
            if ($rollback['op'] !== $operationId || $rollback['done']) continue;
            $data = $this->data($this->req('POST',$rollback['route'],['confirm'=>true],[200],$label));
            $status = (string) ($data['status'] ?? '');
            $this->check(
                $label . '-status',
                $status === '' || in_array($status,['rolled-back','rolled_back'],true),
                ['response'=>$data]
            );
            $rollback['done'] = true;
            unset($rollback);
            return;
        }
        unset($rollback);
        throw new RuntimeException('Untracked rollback ' . $operationId);
    }

    private function track(string $operationId, string $route): void
    {
        $this->rollbacks[] = ['op'=>$operationId,'route'=>$route,'done'=>false];
    }

    private function state(array $record): array
    {
        $keys = [
            'post_id','status','slug','title','excerpt','content','language','software_status','version',
            'release_date','repository_url','archive_url','license','documentation_url','doi','doi_verified',
            'programming_languages','line_ids',
        ];
        $state = [];
        foreach ($keys as $key) $state[$key] = $record[$key] ?? null;
        return $state;
    }

    private function req(string $method, string $relative, ?array $body, array $expected, string $label): array
    {
        $requestId = 'm8-m5-object-' . preg_replace('/[^A-Za-z0-9._:-]/','-',$label) . '-' . time() . '-' . bin2hex(random_bytes(4));
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
            'X-Research-Manager-Request-Id: ' . $requestId,
            'X-Research-Manager-Nonce: ' . bin2hex(random_bytes(16)),
            'X-Research-Manager-Timestamp: ' . time(),
        ];
        $payload = '';
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = (string) json_encode($body, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        }

        $curl = curl_init($this->base . self::API . $relative);
        if ($curl === false) throw new RuntimeException('Unable to initialize cURL.');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CUSTOMREQUEST=>strtoupper($method),
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>45,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'Eduardo-Research-M8-M5-Object/1.0',
        ]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);

        $raw = curl_exec($curl);
        if ($raw === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('Request failed: ' . $error);
        }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        try { $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { $decoded = ['raw_non_json'=>substr((string) $raw,0,1000)]; }
        if (!is_array($decoded)) $decoded = ['value'=>$decoded];
        $decoded['_http_status'] = $status;

        $this->evidence['requests'][] = [
            'label'=>$label,
            'method'=>strtoupper($method),
            'route'=>self::API . $relative,
            'request_id'=>$requestId,
            'http_status'=>$status,
            'response'=>$this->clean($decoded),
        ];

        if (!in_array($status,$expected,true)) {
            throw new RuntimeException(
                $label . ' returned HTTP ' . $status . ' (expected ' . implode('/',$expected) . ')'
                . (!empty($decoded['code']) ? ': ' . $decoded['code'] : '')
            );
        }
        return $decoded;
    }

    private function emergency(): void
    {
        for ($i=count($this->rollbacks)-1; $i>=0; --$i) {
            if ($this->rollbacks[$i]['done']) continue;
            try {
                $this->req('POST',$this->rollbacks[$i]['route'],['confirm'=>true],[200],'emergency-rollback-' . $i);
                $this->rollbacks[$i]['done'] = true;
                $this->evidence['emergency_rollbacks'][] = ['op'=>$this->rollbacks[$i]['op'],'success'=>true];
            } catch (Throwable $e) {
                $this->evidence['emergency_rollbacks'][] = [
                    'op'=>$this->rollbacks[$i]['op'],'success'=>false,'error'=>$e->getMessage(),
                ];
            }
        }
    }

    private function finish(bool $pass, string $message): int
    {
        $this->evidence['result'] = $pass ? 'pass' : 'fail';
        $this->evidence['finished_at'] = gmdate(DATE_ATOM);
        $this->write();
        fwrite($pass ? STDOUT : STDERR, $message . "\nEvidence: " . $this->evidencePath . "\n");
        return $pass ? 0 : 1;
    }

    private function validate(): void
    {
        if (!str_starts_with($this->base,'https://')) throw new RuntimeException('ERM_BASE_URL must be HTTPS.');
        if (!str_starts_with($this->token,'ermt1_')) throw new RuntimeException('ERM_TOKEN must be an independent temporary credential (ermt1_).');
    }

    private function check(string $id, bool $passes, array $details): void
    {
        $this->evidence['checks'][] = ['id'=>$id,'passes'=>$passes,'details'=>$this->clean($details)];
        if (!$passes) throw new RuntimeException('Acceptance check failed: ' . $id);
    }

    private function id(string $value, string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9._:-]+$/',$value)) throw new RuntimeException('Invalid ' . $name . ' returned by Manager.');
    }

    private function data(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    private function last(string $label): array
    {
        for ($i=count($this->evidence['requests'])-1; $i>=0; --$i) {
            $request = $this->evidence['requests'][$i];
            if (($request['label'] ?? '') === $label) {
                return is_array($request['response']['data'] ?? null) ? $request['response']['data'] : [];
            }
        }
        return [];
    }

    private function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key=>$item) {
                if (in_array(strtolower((string) $key),['token','token_hash','authorization','password','secret'],true)) {
                    $value[$key] = '[redacted]';
                } else {
                    $value[$key] = $this->clean($item);
                }
            }
            return $value;
        }
        if (is_string($value) && (str_contains($value,'ermt1_') || str_contains($value,'erm1_'))) return '[redacted]';
        return $value;
    }

    private function write(): void
    {
        $raw = (string) json_encode(
            $this->clean($this->evidence),
            JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (str_contains($raw,'ermt1_') || str_contains($raw,'erm1_')) throw new RuntimeException('Refusing to write bearer-like material.');
        $directory = dirname($this->evidencePath);
        if (!is_dir($directory)) mkdir($directory,0700,true);
        file_put_contents($this->evidencePath,$raw,LOCK_EX);
        @chmod($this->evidencePath,0600);
    }

    private function arg(array $argv, string $prefix): string
    {
        foreach ($argv as $arg) {
            if (str_starts_with((string) $arg,$prefix)) return trim(substr((string) $arg,strlen($prefix)));
        }
        return '';
    }
}

$args = $argv;
array_shift($args);
exit((new M8M5ObjectProductionAcceptance($args))->run());
