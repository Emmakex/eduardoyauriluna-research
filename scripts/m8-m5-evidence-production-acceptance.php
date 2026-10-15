#!/usr/bin/env php
<?php
declare(strict_types=1);

final class M8M5EvidenceProductionAcceptance
{
    private const API = '/wp-json/research-manager/v1';
    private const GROUP = 'profile';
    private const SCOPES = [
        'site.read','site.diagnostics','evidence.read','evidence.write',
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
            ?: 'tmp/final-v1-m5-evidence-' . gmdate('Ymd\\THis\\Z') . '-' . getmypid() . '.json';
    }

    public function run(): int
    {
        try {
            $this->validate();
            $this->evidence = [
                'schema_version'=>1,
                'milestone'=>'M8',
                'slice'=>'final-v1-m5-evidence',
                'representative_group'=>self::GROUP,
                'fixture_policy'=>'temporary-unverified-non-academic-non-public',
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
                return $this->finish(true, "M8 M5 Evidence preflight: PASS\nNo production mutation was executed.");
            }

            $this->exercise();
            $this->check(
                'no-pending-rollbacks',
                !array_filter($this->rollbacks, fn(array $r): bool => !$r['done']),
                ['rollbacks'=>$this->rollbacks]
            );
            return $this->finish(
                true,
                "M8 M5 Evidence reversible production acceptance: PASS\nTemporary unverified Evidence fixture removed; production baseline restored."
            );
        } catch (Throwable $e) {
            $this->emergency();
            $this->evidence['failure'] = ['class'=>get_class($e),'message'=>$e->getMessage()];
            return $this->finish(false, 'M8 M5 Evidence acceptance: FAIL — ' . $e->getMessage());
        }
    }

    private function preflight(): void
    {
        $cap = $this->data($this->req('GET','/capabilities',null,[200],'capabilities'));
        $got = array_values((array) ($cap['granted_scopes'] ?? []));
        $expected = self::SCOPES;
        sort($got); sort($expected);
        $this->check('exact-scope-profile', $got === $expected, ['got'=>$got,'expected'=>$expected]);

        $control = (array) ($cap['research_evidence_control'] ?? []);
        $ops = (array) ($control['operations'] ?? []);
        $groups = (array) ($control['groups'] ?? []);
        $this->check(
            'm5-evidence-capability',
            ($control['milestone'] ?? '') === 'M5'
                && !empty($control['available'])
                && !empty($control['evidence_gate']['required_for_mutation'])
                && ($control['identity_extra_scope'] ?? '') === 'identity.write'
                && !empty($control['provenance_readback'])
                && empty($control['arbitrary_wordpress_proxy'])
                && in_array('evidence-create',$ops,true)
                && in_array('evidence-update',$ops,true)
                && in_array('evidence-delete',$ops,true)
                && isset($groups[self::GROUP]),
            ['research_evidence_control'=>$control]
        );
    }

    private function exercise(): void
    {
        $baselineInventory = $this->inventory('evidence-inventory-baseline');
        $baselineTotal = (int) ($baselineInventory['record_count'] ?? 0);
        $baselineGroup = (int) (($baselineInventory['groups'][self::GROUP]['count'] ?? 0));

        $label = 'Temporary M5 Evidence acceptance fixture';
        $summary = 'Temporary structural acceptance fixture. Not research evidence and not an academic claim.';
        $referenceCreate = 'acceptance:final-v1:m5-evidence:temporary-unverified:create';

        $createPlan = $this->plan('evidence-create', [
            'group'=>self::GROUP,
            'data'=>[
                'status'=>'unverified',
                'label'=>$label,
                'summary'=>$summary,
            ],
            'evidence_confirmed'=>true,
            'evidence_reference'=>$referenceCreate,
        ], 'evidence-create-plan');
        $recordId = (string) ($createPlan['target']['record_id'] ?? '');
        $this->id($recordId, 'evidence-create record_id');

        $afterPreview = $this->inventory('evidence-inventory-after-create-preview');
        $this->check(
            'create-preview-no-mutation',
            (int) ($afterPreview['record_count'] ?? -1) === $baselineTotal
                && (int) (($afterPreview['groups'][self::GROUP]['count'] ?? -1)) === $baselineGroup,
            [
                'baseline_total'=>$baselineTotal,
                'preview_total'=>$afterPreview['record_count'] ?? null,
                'baseline_group'=>$baselineGroup,
                'preview_group'=>$afterPreview['groups'][self::GROUP]['count'] ?? null,
            ]
        );

        $createOp = $this->applyPlan((string) $createPlan['plan_id'], 'evidence-create-apply');
        $createVerify = $this->verify($createOp, 'evidence-create-verify');
        $createProv = (array) ($createVerify['provenance'] ?? []);
        $this->check(
            'create-provenance',
            ($createProv['resource'] ?? '') === 'evidence'
                && ($createProv['group'] ?? '') === self::GROUP
                && ($createProv['record_id'] ?? '') === $recordId
                && ($createProv['status'] ?? '') === 'unverified'
                && ($createProv['evidence_reference'] ?? '') === $referenceCreate
                && in_array('about',(array) ($createProv['surfaces'] ?? []),true),
            ['provenance'=>$createProv]
        );

        $created = $this->inspect($recordId, 'evidence-created-inspect');
        $this->check(
            'created-fixture-state',
            ($created['status'] ?? '') === 'unverified'
                && ($created['label'] ?? '') === $label
                && ($created['summary'] ?? '') === $summary
                && ($created['evidence_reference'] ?? '') === $referenceCreate
                && !empty($created['verified_at']),
            ['record'=>$created]
        );
        $createdState = $this->state($created);

        $updatedSummary = 'Temporary structural acceptance fixture — updated. Still not research evidence and not an academic claim.';
        $referenceUpdate = 'acceptance:final-v1:m5-evidence:temporary-unverified:update';
        $updatePlan = $this->plan('evidence-update', [
            'group'=>self::GROUP,
            'record_id'=>$recordId,
            'data'=>[
                'status'=>'unverified',
                'summary'=>$updatedSummary,
            ],
            'evidence_confirmed'=>true,
            'evidence_reference'=>$referenceUpdate,
        ], 'evidence-update-plan');

        $previewState = $this->state($this->inspect($recordId, 'evidence-update-preview-inspect'));
        $this->check('update-preview-no-mutation', $previewState === $createdState, ['expected'=>$createdState,'actual'=>$previewState]);

        $updateOp = $this->applyPlan((string) $updatePlan['plan_id'], 'evidence-update-apply');
        $updateVerify = $this->verify($updateOp, 'evidence-update-verify');
        $updateProv = (array) ($updateVerify['provenance'] ?? []);
        $this->check(
            'update-provenance',
            ($updateProv['status'] ?? '') === 'unverified'
                && ($updateProv['evidence_reference'] ?? '') === $referenceUpdate,
            ['provenance'=>$updateProv]
        );
        $updated = $this->inspect($recordId, 'evidence-updated-inspect');
        $this->check(
            'update-applied',
            ($updated['summary'] ?? '') === $updatedSummary
                && ($updated['status'] ?? '') === 'unverified'
                && ($updated['evidence_reference'] ?? '') === $referenceUpdate,
            ['record'=>$updated]
        );
        $updatedState = $this->state($updated);

        $referenceDelete = 'acceptance:final-v1:m5-evidence:temporary-unverified:delete';
        $deletePlan = $this->plan('evidence-delete', [
            'group'=>self::GROUP,
            'record_id'=>$recordId,
            'evidence_confirmed'=>true,
            'evidence_reference'=>$referenceDelete,
        ], 'evidence-delete-plan');
        $deletePreviewState = $this->state($this->inspect($recordId, 'evidence-delete-preview-inspect'));
        $this->check('delete-preview-no-mutation', $deletePreviewState === $updatedState, ['expected'=>$updatedState,'actual'=>$deletePreviewState]);

        $deleteOp = $this->applyPlan((string) $deletePlan['plan_id'], 'evidence-delete-apply');
        $deleteVerify = $this->verify($deleteOp, 'evidence-delete-verify');
        $deleteProv = (array) ($deleteVerify['provenance'] ?? []);
        $this->check(
            'delete-provenance-absent',
            ($deleteProv['status'] ?? '') === 'absent'
                && ($deleteProv['evidence_reference'] ?? '') === $referenceDelete,
            ['provenance'=>$deleteProv]
        );

        $afterDelete = $this->inventory('evidence-inventory-after-delete');
        $this->check(
            'delete-applied',
            (int) ($afterDelete['record_count'] ?? -1) === $baselineTotal
                && (int) (($afterDelete['groups'][self::GROUP]['count'] ?? -1)) === $baselineGroup,
            ['inventory'=>$afterDelete]
        );

        $this->rollback($deleteOp, 'evidence-delete-rollback');
        $restoredUpdated = $this->state($this->inspect($recordId, 'evidence-delete-restored'));
        $this->check('delete-rollback-restored-updated-state', $restoredUpdated === $updatedState, ['expected'=>$updatedState,'actual'=>$restoredUpdated]);

        $this->rollback($updateOp, 'evidence-update-rollback');
        $restoredCreated = $this->state($this->inspect($recordId, 'evidence-update-restored'));
        $this->check('update-rollback-restored-created-state', $restoredCreated === $createdState, ['expected'=>$createdState,'actual'=>$restoredCreated]);

        $this->rollback($createOp, 'evidence-create-rollback');
        $finalInventory = $this->inventory('evidence-inventory-final');
        $this->check(
            'inventory-baseline-restored',
            (int) ($finalInventory['record_count'] ?? -1) === $baselineTotal
                && (int) (($finalInventory['groups'][self::GROUP]['count'] ?? -1)) === $baselineGroup,
            [
                'baseline_total'=>$baselineTotal,
                'final_total'=>$finalInventory['record_count'] ?? null,
                'baseline_group'=>$baselineGroup,
                'final_group'=>$finalInventory['groups'][self::GROUP]['count'] ?? null,
            ]
        );

        $records = $this->records('evidence-final-group-records');
        $ids = array_map(fn(array $r): string => (string) ($r['record_id'] ?? ''), (array) ($records['items'] ?? []));
        $this->check('evidence-fixture-absent', !in_array($recordId,$ids,true), ['record_id'=>$recordId,'remaining_ids'=>$ids]);
    }

    private function inventory(string $label): array
    {
        return $this->data($this->req('GET','/research-evidence',null,[200],$label));
    }

    private function records(string $label): array
    {
        return $this->data($this->req('GET','/research-evidence/' . self::GROUP,null,[200],$label));
    }

    private function inspect(string $recordId, string $label): array
    {
        return $this->data($this->req('GET','/research-evidence/' . self::GROUP . '/' . rawurlencode($recordId),null,[200],$label));
    }

    private function plan(string $operation, array $payload, string $label): array
    {
        $data = $this->data($this->req('POST','/research-evidence/plan',[
            'operation'=>$operation,
            'payload'=>$payload,
        ],[200],$label));
        $planId = (string) ($data['plan_id'] ?? '');
        $this->id($planId, $label . ' plan_id');
        $this->check($label . '-apply-allowed', !empty($data['apply_allowed']), ['plan'=>$data]);
        $this->check($label . '-evidence-risk', ($data['risk'] ?? '') === 'evidence-required', ['plan'=>$data]);
        $this->check($label . '-explicit-confirmation', ($data['confirmation_class'] ?? '') === 'evidence-explicit', ['plan'=>$data]);
        return $data;
    }

    private function applyPlan(string $planId, string $label): string
    {
        $data = $this->data($this->req('POST','/research-evidence/plans/' . rawurlencode($planId) . '/apply',[
            'confirm'=>true,
        ],[200],$label));
        $operationId = (string) ($data['operation_id'] ?? '');
        $this->id($operationId, $label . ' operation_id');
        $this->check($label . '-verified-result', !empty($data['result']['verified']), ['apply'=>$data]);
        $this->track($operationId, '/research-evidence/operations/' . rawurlencode($operationId) . '/rollback');
        return $operationId;
    }

    private function verify(string $operationId, string $label): array
    {
        $data = $this->data($this->req('POST','/research-evidence/operations/' . rawurlencode($operationId) . '/verify',[],[200],$label));
        $this->check($label . '-stored', !empty($data['stored_verification']['verified']), ['verify'=>$data]);
        return $data;
    }

    private function rollback(string $operationId, string $label): void
    {
        foreach ($this->rollbacks as &$rollback) {
            if ($rollback['op'] !== $operationId || $rollback['done']) continue;
            $data = $this->data($this->req('POST',$rollback['route'],['confirm'=>true],[200],$label));
            $this->check(
                $label . '-status',
                ($data['status'] ?? '') === 'rolled-back' && !empty($data['rollback_restored_baseline']),
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
        $keys = ['id','status','title','label','value','period','date','dates','start_date','end_date','organization','institution','company','affiliation','summary','url','translations','evidence_reference','verified_at'];
        $state = [];
        foreach ($keys as $key) $state[$key] = $record[$key] ?? null;
        return $state;
    }

    private function req(string $method, string $relative, ?array $body, array $expected, string $label): array
    {
        $requestId = 'm8-m5-evidence-' . preg_replace('/[^A-Za-z0-9._:-]/','-',$label) . '-' . time() . '-' . bin2hex(random_bytes(4));
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
            CURLOPT_USERAGENT=>'Eduardo-Research-M8-M5-Evidence/1.0',
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
exit((new M8M5EvidenceProductionAcceptance($args))->run());
