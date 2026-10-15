#!/usr/bin/env php
<?php
declare(strict_types=1);

final class M8M5LineRelationProductionAcceptance
{
    private const API = '/wp-json/research-manager/v1';
    private const SCOPES = [
        'site.read','site.diagnostics','research.read','research.write',
        'insights.write','operations.apply','operations.rollback',
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
            ?: 'tmp/final-v1-m5-line-relation-' . gmdate('Ymd\THis\Z') . '-' . getmypid() . '.json';
    }

    public function run(): int
    {
        try {
            $this->validate();
            $this->evidence = [
                'schema_version'=>2,
                'milestone'=>'M8',
                'slice'=>'final-v1-m5-line-plus-m4-relation',
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
                return $this->finish(true, "M8 M5 Line + M4 relation preflight: PASS\nNo production mutation was executed.");
            }

            $this->exercise();
            $this->check('no-pending-rollbacks', !array_filter($this->rollbacks, fn(array $r): bool => !$r['done']), ['rollbacks'=>$this->rollbacks]);
            return $this->finish(true, "M8 M5 Line + M4 relation reversible production acceptance: PASS\nTemporary public verified Line and Insight fixtures removed; production baseline restored.");
        } catch (Throwable $e) {
            $this->emergency();
            $this->evidence['failure'] = ['class'=>get_class($e),'message'=>$e->getMessage()];
            return $this->finish(false, 'M8 M5 Line + M4 relation acceptance: FAIL — ' . $e->getMessage());
        }
    }

    private function preflight(): void
    {
        $cap = $this->data($this->req('GET','/capabilities',null,[200],'capabilities'));
        $got = array_values((array) ($cap['granted_scopes'] ?? []));
        $expected = self::SCOPES;
        sort($got); sort($expected);
        $this->check('exact-scope-profile', $got === $expected, ['got'=>$got,'expected'=>$expected]);

        $line = (array) ($cap['research_line_control'] ?? []);
        $ops = (array) ($line['operations'] ?? []);
        $this->check('m5-line-capability',
            ($line['milestone'] ?? '') === 'M5'
            && !empty($line['inventory'])
            && !empty($line['evidence_gate']['required_for_creation'])
            && in_array('line-create',$ops,true)
            && in_array('line-update',$ops,true),
            ['research_line_control'=>$line]
        );

        $transport = (array) (($cap['mutation_transport'] ?? [])['supported_operations'] ?? []);
        $this->check('insight-create-update-supported',
            in_array('insight-create',$transport,true) && in_array('insight-update',$transport,true),
            ['supported_operations'=>$transport]
        );

        $relations = (array) (($cap['insight_control'] ?? [])['research_relations'] ?? []);
        $this->check('m4-relation-capability',
            !empty($relations['available']) && ($relations['field'] ?? '') === 'line_ids',
            ['research_relations'=>$relations]
        );
    }

    private function exercise(): void
    {
        $line = $this->createLine();
        $this->publishVerifiedLine($line);
        $insight = $this->createInsight();
        $this->exerciseRelation($line, $insight);
        $this->removeInsight($insight);
        $this->restoreAndRemoveLine($line);
    }

    private function createLine(): array
    {
        $slug = 'm8-v1-line-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        $data = [
            'title'=>'Temporary M5 Research Line — reversible acceptance fixture',
            'slug'=>$slug,
            'excerpt'=>'Temporary structural acceptance fixture. Not research content and not an academic claim.',
            'content'=>'<p>Temporary structural acceptance fixture used only to verify bounded Research Manager behavior. Not research content and not an academic claim.</p>',
            'language'=>'en',
            'status'=>'draft',
            'evidence_status'=>'unverified',
            'research_status'=>'planned',
            'central_question'=>'Temporary acceptance fixture only; no academic research claim.',
            'order'=>'999',
            'topics'=>['acceptance-fixture'],
            'methods'=>['verification'],
        ];

        $plan = $this->linePlan('line-create', [
            'data'=>$data,
            'evidence_confirmed'=>true,
            'evidence_reference'=>'acceptance:final-v1:m5-line:create',
        ], 'line-create-plan');
        $operation = $this->lineApply($plan, 'line-create-apply');
        $this->lineVerify($operation, true, 'line-create-verify');
        $apply = $this->last('line-create-apply');
        $id = (int) ($apply['target']['post_id'] ?? $apply['result']['post_id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Line creation Apply missing post_id.');
        $record = $this->lineInspect($id, 'line-created-inspect');
        $this->check('line-created-baseline',
            ($record['slug'] ?? '') === $slug
            && ($record['language'] ?? '') === 'en'
            && ($record['status'] ?? '') === 'draft'
            && ($record['evidence_status'] ?? '') === 'unverified'
            && ($record['research_status'] ?? '') === 'planned',
            ['line'=>$record]
        );
        return [
            'id'=>$id,
            'slug'=>$slug,
            'create_operation'=>$operation,
            'update_operation'=>'',
            'baseline'=>$this->lineState($record),
        ];
    }

    private function publishVerifiedLine(array &$line): void
    {
        $plan = $this->linePlan('line-update', [
            'post_id'=>$line['id'],
            'changes'=>[
                'status'=>'publish',
                'evidence_status'=>'verified',
                'research_status'=>'active',
            ],
            'evidence_confirmed'=>true,
            'evidence_reference'=>'acceptance:final-v1:m5-line:publish-verified',
        ], 'line-publish-verified-plan');
        $operation = $this->lineApply($plan, 'line-publish-verified-apply');
        $line['update_operation'] = $operation;
        $this->lineVerify($operation, true, 'line-publish-verified-verify');
        $record = $this->lineInspect((int) $line['id'], 'line-public-inspect');
        $this->check('line-public-verified-active',
            ($record['language'] ?? '') === 'en'
            && ($record['status'] ?? '') === 'publish'
            && ($record['evidence_status'] ?? '') === 'verified'
            && ($record['research_status'] ?? '') === 'active',
            ['line'=>$record]
        );
    }

    private function createInsight(): array
    {
        $slug = 'm8-v1-relation-en-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        $plan = $this->insightPlan('insight-create', [
            'data'=>[
                'title'=>'Temporary M4 relation Insight — reversible acceptance fixture',
                'slug'=>$slug,
                'excerpt'=>'Temporary structural acceptance fixture. Not research content and not an academic claim.',
                'content'=>'<p>Temporary structural acceptance fixture used only to verify the bounded Insight-to-Research-Line relation. Not research content and not an academic claim.</p>',
                'language'=>'en',
                'insight_type'=>'research_note',
                'status'=>'draft',
            ],
            'reason'=>'Final v1 reversible M4 relation fixture',
        ], 'insight-fixture-plan');
        $operation = $this->insightApply($plan, 'insight-fixture-apply');
        $this->insightVerify($operation, true, 'insight-fixture-verify');
        $apply = $this->last('insight-fixture-apply');
        $id = (int) ($apply['target']['post_id'] ?? $apply['result']['post_id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Insight fixture Apply missing post_id.');
        $record = $this->insightInspect($id, 'insight-fixture-inspect');
        $this->check('insight-fixture-managed',
            ($record['slug'] ?? '') === $slug && ($record['language'] ?? '') === 'en',
            ['post_id'=>$id,'slug'=>$record['slug'] ?? null,'language'=>$record['language'] ?? null]
        );
        return ['id'=>$id,'slug'=>$slug,'create_operation'=>$operation];
    }

    private function exerciseRelation(array $line, array $insight): void
    {
        $before = $this->insightInspect((int) $insight['id'], 'relation-before');
        $this->check('relation-baseline-empty', [] === array_values(array_map('intval',(array) ($before['line_ids'] ?? []))), ['line_ids'=>$before['line_ids'] ?? []]);

        $plan = $this->insightPlan('insight-update', [
            'post_id'=>$insight['id'],
            'changes'=>['line_ids'=>[(int) $line['id']]],
            'reason'=>'Final v1 reversible M4 relation acceptance against temporary public verified M5 Line fixture',
        ], 'relation-plan');
        $preview = $this->insightInspect((int) $insight['id'], 'relation-preview');
        $this->check('relation-preview-no-mutation', [] === array_values(array_map('intval',(array) ($preview['line_ids'] ?? []))), ['line_ids'=>$preview['line_ids'] ?? []]);

        $operation = $this->insightApply($plan, 'relation-apply');
        $this->insightVerify($operation, true, 'relation-verify');
        $after = $this->insightInspect((int) $insight['id'], 'relation-after');
        $this->check('relation-applied', [(int) $line['id']] === array_values(array_map('intval',(array) ($after['line_ids'] ?? []))), ['line_ids'=>$after['line_ids'] ?? []]);

        $seo = $this->data($this->req('GET','/insights/' . (int) $insight['id'] . '/seo-geo',null,[200],'relation-seo-geo'));
        $diag = (array) ($seo['relations'] ?? []);
        $this->check('relation-diagnostics', (int) ($diag['count'] ?? 0) === 1 && !empty($diag['verified_same_language']), ['relations'=>$diag]);

        $this->rollback($operation, 'relation-rollback');
        $restored = $this->insightInspect((int) $insight['id'], 'relation-restored');
        $this->check('relation-baseline-restored', [] === array_values(array_map('intval',(array) ($restored['line_ids'] ?? []))), ['line_ids'=>$restored['line_ids'] ?? []]);
    }

    private function removeInsight(array $insight): void
    {
        $this->rollback((string) $insight['create_operation'], 'insight-fixture-cleanup');
        $inventory = $this->data($this->req('GET','/insights?language=en',null,[200],'insight-cleanup-inventory'));
        $found = false;
        foreach ((array) ($inventory['items'] ?? []) as $item) {
            if (is_array($item) && ((int) ($item['post_id'] ?? 0) === (int) $insight['id'] || ($item['slug'] ?? '') === $insight['slug'])) $found = true;
        }
        $this->check('insight-fixture-absent', !$found, ['post_id'=>$insight['id'],'slug'=>$insight['slug']]);
    }

    private function restoreAndRemoveLine(array $line): void
    {
        $this->rollback((string) $line['update_operation'], 'line-publish-verified-rollback');
        $restored = $this->lineInspect((int) $line['id'], 'line-baseline-restored');
        $this->check('line-update-baseline-restored', $this->lineState($restored) === $line['baseline'], ['expected'=>$line['baseline'],'actual'=>$this->lineState($restored)]);

        $this->rollback((string) $line['create_operation'], 'line-create-rollback');
        $inventory = $this->data($this->req('GET','/research-lines',null,[200],'line-cleanup-inventory'));
        $found = false;
        foreach ((array) ($inventory['items'] ?? []) as $item) {
            if (is_array($item) && ((int) ($item['post_id'] ?? 0) === (int) $line['id'] || ($item['slug'] ?? '') === $line['slug'])) $found = true;
        }
        $this->check('line-fixture-absent', !$found, ['post_id'=>$line['id'],'slug'=>$line['slug']]);
    }

    private function linePlan(string $operation, array $payload, string $label): string
    {
        $data = $this->data($this->req('POST','/research-lines/plan',['operation'=>$operation,'payload'=>$payload],[200],$label));
        $id = (string) ($data['plan_id'] ?? '');
        $this->id($id, $label . ' plan_id');
        $this->check($label . '-apply-allowed', !empty($data['apply_allowed']), ['plan'=>$data]);
        return $id;
    }

    private function lineApply(string $planId, string $label): string
    {
        $data = $this->data($this->req('POST','/research-lines/plans/' . rawurlencode($planId) . '/apply',['confirm'=>true],[200],$label));
        $id = (string) ($data['operation_id'] ?? '');
        $this->id($id, $label . ' operation_id');
        $this->check($label . '-verified-result', !empty($data['result']['verified']), ['apply'=>$data]);
        $this->track($id, '/research-lines/operations/' . rawurlencode($id) . '/rollback');
        return $id;
    }

    private function lineVerify(string $operationId, bool $rendered, string $label): void
    {
        $data = $this->data($this->req('POST','/research-lines/operations/' . rawurlencode($operationId) . '/verify',['rendered'=>$rendered],[200],$label));
        $this->check($label . '-stored', !empty($data['stored_verification']['verified']), ['verify'=>$data]);
        if ($rendered) $this->check($label . '-rendered', !empty($data['rendered_verification']['verified']), ['verify'=>$data]);
    }

    private function lineInspect(int $id, string $label): array
    {
        return $this->data($this->req('GET','/research-lines/' . $id,null,[200],$label));
    }

    private function insightPlan(string $operation, array $payload, string $label): string
    {
        $data = $this->data($this->req('POST','/operations/plan',['operation'=>$operation,'payload'=>$payload],[200],$label));
        $id = (string) ($data['plan_id'] ?? '');
        $this->id($id, $label . ' plan_id');
        $this->check($label . '-apply-allowed', !empty($data['apply_allowed']), ['plan'=>$data]);
        return $id;
    }

    private function insightApply(string $planId, string $label): string
    {
        $data = $this->data($this->req('POST','/plans/' . rawurlencode($planId) . '/apply',['confirm'=>true],[200],$label));
        $id = (string) ($data['operation_id'] ?? '');
        $this->id($id, $label . ' operation_id');
        $this->check($label . '-verified-result', !empty($data['result']['verified']), ['apply'=>$data]);
        $this->track($id, '/operations/' . rawurlencode($id) . '/rollback');
        return $id;
    }

    private function insightVerify(string $operationId, bool $rendered, string $label): void
    {
        $data = $this->data($this->req('POST','/operations/' . rawurlencode($operationId) . '/verify',['rendered'=>$rendered],[200],$label));
        $this->check($label . '-status', ($data['status'] ?? '') === 'verified', ['verify'=>$data]);
        if (isset($data['stored_verification'])) $this->check($label . '-stored', !empty($data['stored_verification']['verified']), ['verify'=>$data]);
        if ($rendered && isset($data['rendered_verification'])) {
            $this->check($label . '-rendered', !empty($data['rendered_verification']['verified']) || !empty($data['rendered_verification']['not_applicable']), ['verify'=>$data]);
        }
    }

    private function insightInspect(int $id, string $label): array
    {
        return $this->data($this->req('GET','/insights/' . $id,null,[200],$label));
    }

    private function rollback(string $operationId, string $label): void
    {
        foreach ($this->rollbacks as &$r) {
            if ($r['op'] !== $operationId || $r['done']) continue;
            $data = $this->data($this->req('POST',$r['route'],['confirm'=>true],[200],$label));
            $status = (string) ($data['status'] ?? '');
            $this->check($label . '-status', $status === '' || in_array($status,['rolled-back','rolled_back'],true), ['response'=>$data]);
            $r['done'] = true;
            unset($r);
            return;
        }
        unset($r);
        throw new RuntimeException('Untracked rollback ' . $operationId);
    }

    private function track(string $operationId, string $route): void
    {
        $this->rollbacks[] = ['op'=>$operationId,'route'=>$route,'done'=>false];
    }

    private function lineState(array $record): array
    {
        $out = [];
        foreach (['post_id','title','slug','excerpt','content','language','status','evidence_status','research_status','central_question','order','topics','methods'] as $key) $out[$key] = $record[$key] ?? null;
        return $out;
    }

    private function req(string $method, string $relative, ?array $body, array $expected, string $label): array
    {
        $rid = 'm8-m5-line-relation-' . preg_replace('/[^A-Za-z0-9._:-]/','-',$label) . '-' . time() . '-' . bin2hex(random_bytes(4));
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
            'X-Research-Manager-Request-Id: ' . $rid,
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
            CURLOPT_USERAGENT=>'Eduardo-Research-M8-M5-Line-Relation/1.1',
        ]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        $raw = curl_exec($curl);
        if ($raw === false) {
            $error = curl_error($curl); curl_close($curl);
            throw new RuntimeException('Request failed: ' . $error);
        }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        try { $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { $decoded = ['raw_non_json'=>substr((string) $raw,0,1000)]; }
        if (!is_array($decoded)) $decoded = ['value'=>$decoded];
        $this->evidence['requests'][] = [
            'label'=>$label,'method'=>strtoupper($method),'route'=>self::API . $relative,
            'request_id'=>$rid,'http_status'=>$status,'response'=>$this->clean($decoded),
        ];
        if (!in_array($status,$expected,true)) {
            throw new RuntimeException($label . ' returned HTTP ' . $status . ' (expected ' . implode('/',$expected) . ')' . (!empty($decoded['code']) ? ': ' . $decoded['code'] : ''));
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
                $this->evidence['emergency_rollbacks'][] = ['op'=>$this->rollbacks[$i]['op'],'success'=>false,'error'=>$e->getMessage()];
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
            $r = $this->evidence['requests'][$i];
            if (($r['label'] ?? '') === $label) return is_array($r['response']['data'] ?? null) ? $r['response']['data'] : [];
        }
        return [];
    }

    private function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key=>$item) {
                if (in_array(strtolower((string) $key),['token','token_hash','authorization','password','secret'],true)) $value[$key] = '[redacted]';
                else $value[$key] = $this->clean($item);
            }
            return $value;
        }
        if (is_string($value) && (str_contains($value,'ermt1_') || str_contains($value,'erm1_'))) return '[redacted]';
        return $value;
    }

    private function write(): void
    {
        $raw = (string) json_encode($this->clean($this->evidence), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) . PHP_EOL;
        if (str_contains($raw,'ermt1_') || str_contains($raw,'erm1_')) throw new RuntimeException('Refusing to write bearer-like material.');
        $dir = dirname($this->evidencePath);
        if (!is_dir($dir)) mkdir($dir,0700,true);
        file_put_contents($this->evidencePath,$raw,LOCK_EX);
        @chmod($this->evidencePath,0600);
    }

    private function arg(array $argv, string $prefix): string
    {
        foreach ($argv as $arg) if (str_starts_with((string) $arg,$prefix)) return trim(substr((string) $arg,strlen($prefix)));
        return '';
    }
}

$args = $argv;
array_shift($args);
exit((new M8M5LineRelationProductionAcceptance($args))->run());
