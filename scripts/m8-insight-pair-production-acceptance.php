#!/usr/bin/env php
<?php
declare(strict_types=1);

final class M8InsightPairProductionAcceptance
{
    private const API = '/wp-json/research-manager/v1';
    private const SCOPES = ['site.read','site.diagnostics','insights.write','translations.write','research.read','operations.apply','operations.rollback'];
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
        $this->evidencePath = $this->arg($argv, '--evidence=') ?: 'tmp/final-v1-insight-pair-' . gmdate('Ymd\\THis\\Z') . '-' . getmypid() . '.json';
    }

    public function run(): int
    {
        try {
            $this->validate();
            $this->evidence = ['schema_version'=>1,'milestone'=>'M8','slice'=>'final-v1-insight-pairing-breadth','site'=>$this->base,'apply_enabled'=>$this->apply,'expected_scopes'=>self::SCOPES,'requests'=>[],'checks'=>[],'started_at'=>gmdate(DATE_ATOM),'result'=>'running'];
            $this->preflight();
            if (!$this->apply) {
                $this->evidence['apply_skipped'] = true;
                return $this->finish(true, "M8 Insight pair preflight: PASS\nNo production mutation was executed.");
            }
            $this->pair();
            $this->check('no-pending-rollbacks', !array_filter($this->rollbacks, fn($r) => !$r['done']), ['rollbacks'=>$this->rollbacks]);
            return $this->finish(true, "M8 Insight pair reversible production acceptance: PASS\nPublished EN/ES fixtures paired, stored/rendered verified, unpaired by rollback, then removed.");
        } catch (Throwable $e) {
            $this->emergency();
            $this->evidence['failure'] = ['class'=>get_class($e),'message'=>$e->getMessage()];
            return $this->finish(false, 'M8 Insight pair acceptance: FAIL — ' . $e->getMessage());
        }
    }

    private function preflight(): void
    {
        $cap = $this->data($this->req('GET','/capabilities',null,[200],'capabilities'));
        $got = array_values((array)($cap['granted_scopes'] ?? []));
        sort($got);
        $exp = self::SCOPES;
        sort($exp);
        $this->check('exact-scope-profile', $got === $exp, ['got'=>$got,'expected'=>$exp]);
        $pairing = (array)(($cap['insight_control'] ?? [])['translation_pairing'] ?? []);
        $ops = (array)($pairing['operations'] ?? []);
        $this->check('pairing-capability', !empty($pairing['available']) && in_array('insight-pair',$ops,true) && in_array('insight-unpair',$ops,true), ['pairing'=>$pairing]);
    }

    private function pair(): void
    {
        $en = $this->fixture('en','Pair EN');
        $es = $this->fixture('es','Pair ES');
        $plan = $this->data($this->req('POST','/insights/pairing/plan',['operation'=>'insight-pair','payload'=>['first_id'=>$en['id'],'second_id'=>$es['id']]],[200],'pair-plan'));
        $planId = (string)($plan['plan_id'] ?? '');
        $this->id($planId,'pair plan_id');
        $this->check('pair-preview-allowed', !empty($plan['apply_allowed']), ['plan'=>$plan]);
        $apply = $this->data($this->req('POST','/insights/pairing/plans/'.rawurlencode($planId).'/apply',['confirm'=>true],[200],'pair-apply'));
        $operationId = (string)($apply['operation_id'] ?? '');
        $this->id($operationId,'pair operation_id');
        $this->track($operationId,'/insights/pairing/operations/'.rawurlencode($operationId).'/rollback');
        $verify = $this->data($this->req('POST','/insights/pairing/operations/'.rawurlencode($operationId).'/verify',['rendered'=>true],[200],'pair-verify'));
        $this->check('pair-stored-verified', !empty($verify['stored_verification']['verified']), ['verify'=>$verify]);
        $this->check('pair-rendered-verified', !empty($verify['rendered_verification']['verified']), ['verify'=>$verify]);
        $this->rollback($operationId,'pair-rollback');
        $replan = $this->data($this->req('POST','/insights/pairing/plan',['operation'=>'insight-pair','payload'=>['first_id'=>$en['id'],'second_id'=>$es['id']]],[200],'pair-restored-replan'));
        $this->check('pair-baseline-restored', !empty($replan['apply_allowed']) && !empty($replan['plan_id']), ['replan'=>$replan]);
        $this->cleanup($es,'pair-es-cleanup');
        $this->cleanup($en,'pair-en-cleanup');
    }

    private function fixture(string $lang, string $purpose): array
    {
        $slug = 'm8-v1-pair-'.$lang.'-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(3));
        $plan = $this->data($this->req('POST','/operations/plan',['operation'=>'insight-create','payload'=>['data'=>['title'=>'Temporary M8 '.$purpose.' Insight — reversible production fixture','slug'=>$slug,'excerpt'=>'Temporary public production acceptance fixture. Not research content and not an academic claim.','content'=>'<p>Temporary public production acceptance fixture used only to verify bounded Research Manager translation pairing. Not research content and not an academic claim.</p>','language'=>$lang,'insight_type'=>'research_note','status'=>'publish'],'reason'=>'Final v1 reversible public fixture for Insight pairing']],[200],'fixture-'.$lang.'-plan'));
        $planId = (string)($plan['plan_id'] ?? '');
        $this->id($planId,'fixture plan_id');
        $this->check('fixture-'.$lang.'-preview-allowed', !empty($plan['apply_allowed']), ['plan'=>$plan]);
        $apply = $this->data($this->req('POST','/plans/'.rawurlencode($planId).'/apply',['confirm'=>true],[200],'fixture-'.$lang.'-apply'));
        $operationId = (string)($apply['operation_id'] ?? '');
        $this->id($operationId,'fixture operation_id');
        $this->track($operationId,'/operations/'.rawurlencode($operationId).'/rollback');
        $postId = (int)($apply['target']['post_id'] ?? $apply['result']['post_id'] ?? 0);
        if ($postId <= 0) throw new RuntimeException('Fixture Apply missing post_id.');
        $verify = $this->data($this->req('POST','/operations/'.rawurlencode($operationId).'/verify',['rendered'=>true],[200],'fixture-'.$lang.'-verify'));
        $this->check('fixture-'.$lang.'-stored-verified', !empty($verify['stored_verification']['verified']), ['verify'=>$verify]);
        $this->check('fixture-'.$lang.'-rendered-verified', !empty($verify['rendered_verification']['verified']), ['verify'=>$verify]);
        $inspect = $this->data($this->req('GET','/insights/'.$postId,null,[200],'fixture-'.$lang.'-inspect'));
        $this->check('fixture-'.$lang.'-public-managed', ($inspect['slug'] ?? '') === $slug && ($inspect['language'] ?? '') === $lang && ($inspect['status'] ?? '') === 'publish', ['post_id'=>$postId]);
        return ['id'=>$postId,'op'=>$operationId,'lang'=>$lang,'slug'=>$slug];
    }

    private function cleanup(array $fixture, string $label): void
    {
        $this->rollback($fixture['op'],$label);
        $inventory = $this->data($this->req('GET','/insights?language='.rawurlencode($fixture['lang']),null,[200],$label.'-inventory'));
        $found = false;
        foreach ((array)($inventory['items'] ?? []) as $item) {
            if (is_array($item) && ((int)($item['post_id'] ?? 0) === $fixture['id'] || ($item['slug'] ?? '') === $fixture['slug'])) $found = true;
        }
        $this->check($label.'-absent', !$found, ['post_id'=>$fixture['id'],'slug'=>$fixture['slug']]);
    }

    private function rollback(string $operationId, string $label): void
    {
        foreach ($this->rollbacks as &$rollback) {
            if ($rollback['op'] === $operationId && !$rollback['done']) {
                $data = $this->data($this->req('POST',$rollback['route'],['confirm'=>true],[200],$label));
                $status = (string)($data['status'] ?? '');
                $this->check($label.'-status', $status === '' || in_array($status,['rolled-back','rolled_back'],true), ['response'=>$data]);
                $rollback['done'] = true;
                return;
            }
        }
        unset($rollback);
        throw new RuntimeException('Untracked rollback '.$operationId);
    }

    private function track(string $operationId, string $route): void { $this->rollbacks[] = ['op'=>$operationId,'route'=>$route,'done'=>false]; }

    private function req(string $method, string $relative, ?array $body, array $expected, string $label): array
    {
        $requestId = 'm8-insight-pair-'.preg_replace('/[^A-Za-z0-9._:-]/','-',$label).'-'.time().'-'.bin2hex(random_bytes(4));
        $headers = ['Accept: application/json','Authorization: Bearer '.$this->token,'X-Research-Manager-Request-Id: '.$requestId,'X-Research-Manager-Nonce: '.bin2hex(random_bytes(16)),'X-Research-Manager-Timestamp: '.time()];
        $payload = '';
        if ($body !== null) { $headers[]='Content-Type: application/json'; $payload=(string)json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
        $curl = curl_init($this->base.self::API.$relative);
        if ($curl === false) throw new RuntimeException('Unable to initialize cURL.');
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'Eduardo-Research-M8-Insight-Pair/1.0']);
        if ($body !== null) curl_setopt($curl,CURLOPT_POSTFIELDS,$payload);
        $raw = curl_exec($curl);
        if ($raw === false) { $error=curl_error($curl); curl_close($curl); throw new RuntimeException('Request failed: '.$error); }
        $status = (int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        try { $decoded=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR); } catch (Throwable) { $decoded=['raw_non_json'=>substr((string)$raw,0,1000)]; }
        if (!is_array($decoded)) $decoded=['value'=>$decoded];
        $this->evidence['requests'][]=['label'=>$label,'method'=>strtoupper($method),'route'=>self::API.$relative,'request_id'=>$requestId,'http_status'=>$status,'response'=>$this->clean($decoded)];
        if (!in_array($status,$expected,true)) throw new RuntimeException($label.' returned HTTP '.$status.' (expected '.implode('/',$expected).')'.(!empty($decoded['code'])?': '.$decoded['code']:''));
        return $decoded;
    }

    private function emergency(): void
    {
        for ($i=count($this->rollbacks)-1; $i>=0; --$i) {
            if ($this->rollbacks[$i]['done']) continue;
            try {
                $this->req('POST',$this->rollbacks[$i]['route'],['confirm'=>true],[200],'emergency-rollback-'.$i);
                $this->rollbacks[$i]['done']=true;
                $this->evidence['emergency_rollbacks'][]=['op'=>$this->rollbacks[$i]['op'],'success'=>true];
            } catch (Throwable $e) {
                $this->evidence['emergency_rollbacks'][]=['op'=>$this->rollbacks[$i]['op'],'success'=>false,'error'=>$e->getMessage()];
            }
        }
    }

    private function validate(): void
    {
        if (!str_starts_with($this->base,'https://')) throw new RuntimeException('ERM_BASE_URL must be HTTPS.');
        if (!str_starts_with($this->token,'ermt1_')) throw new RuntimeException('ERM_TOKEN must be an independent temporary credential (ermt1_).');
    }

    private function finish(bool $pass, string $message): int
    {
        $this->evidence['result']=$pass?'pass':'fail';
        $this->evidence['finished_at']=gmdate(DATE_ATOM);
        $this->write();
        fwrite($pass?STDOUT:STDERR,$message."\nEvidence: ".$this->evidencePath."\n");
        return $pass?0:1;
    }

    private function check(string $id, bool $ok, array $details): void
    {
        $this->evidence['checks'][]=['id'=>$id,'passes'=>$ok,'details'=>$this->clean($details)];
        if (!$ok) throw new RuntimeException('Acceptance check failed: '.$id);
    }

    private function id(string $value, string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9._:-]+$/',$value)) throw new RuntimeException('Invalid '.$name.' returned by Manager.');
    }

    private function data(array $response): array { return is_array($response['data'] ?? null) ? $response['data'] : []; }

    private function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key=>$item) {
                if (in_array(strtolower((string)$key),['token','token_hash','authorization','password','secret'],true)) $value[$key]='[redacted]';
                else $value[$key]=$this->clean($item);
            }
            return $value;
        }
        if (is_string($value) && (str_contains($value,'ermt1_') || str_contains($value,'erm1_'))) return '[redacted]';
        return $value;
    }

    private function write(): void
    {
        $raw=(string)json_encode($this->clean($this->evidence),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
        if (str_contains($raw,'ermt1_') || str_contains($raw,'erm1_')) throw new RuntimeException('Refusing to write bearer-like material.');
        $dir=dirname($this->evidencePath);
        if (!is_dir($dir)) mkdir($dir,0700,true);
        file_put_contents($this->evidencePath,$raw,LOCK_EX);
        @chmod($this->evidencePath,0600);
    }

    private function arg(array $argv, string $prefix): string
    {
        foreach ($argv as $arg) if (str_starts_with((string)$arg,$prefix)) return trim(substr((string)$arg,strlen($prefix)));
        return '';
    }
}

$args=$argv;
array_shift($args);
exit((new M8InsightPairProductionAcceptance($args))->run());
