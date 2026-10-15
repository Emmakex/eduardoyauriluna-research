#!/usr/bin/env php
<?php
declare(strict_types=1);

final class M8M5TranslationProductionAcceptance
{
    private const API = '/wp-json/research-manager/v1';
    private const KIND = 'software';
    private const POST_TYPE = 'research_software';
    private const SCOPES = [
        'site.read','site.diagnostics','research.read','research.write',
        'translations.read','translations.write','operations.apply','operations.rollback',
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
            ?: 'tmp/final-v1-m5-translation-' . gmdate('Ymd\\THis\\Z') . '-' . getmypid() . '.json';
    }

    public function run(): int
    {
        try {
            $this->validate();
            $this->evidence = [
                'schema_version'=>1,
                'milestone'=>'M8',
                'slice'=>'final-v1-m5-structured-translation',
                'representative_kind'=>self::KIND,
                'languages'=>['en','es'],
                'fixture_policy'=>'temporary-published-non-research-non-academic-reversible',
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
                return $this->finish(true, "M8 M5 structured Translation preflight: PASS\nNo production mutation was executed.");
            }

            $this->exercise();
            $this->check(
                'no-pending-rollbacks',
                !array_filter($this->rollbacks, fn(array $r): bool => !$r['done']),
                ['rollbacks'=>$this->rollbacks]
            );

            return $this->finish(
                true,
                "M8 M5 structured Translation reversible production acceptance: PASS\nTemporary EN/ES Research Software fixtures removed; production baseline restored."
            );
        } catch (Throwable $e) {
            $this->emergency();
            $this->evidence['failure'] = ['class'=>get_class($e),'message'=>$e->getMessage()];
            return $this->finish(false, 'M8 M5 structured Translation acceptance: FAIL — ' . $e->getMessage());
        }
    }

    private function preflight(): void
    {
        $cap = $this->data($this->req('GET','/capabilities',null,[200],'capabilities'));
        $got = array_values((array) ($cap['granted_scopes'] ?? []));
        $expected = self::SCOPES;
        sort($got); sort($expected);
        $this->check('exact-scope-profile', $got === $expected, ['got'=>$got,'expected'=>$expected]);

        $objects = (array) ($cap['research_object_control'] ?? []);
        $objectOps = (array) ($objects['operations'] ?? []);
        $objectKinds = (array) ($objects['kinds'] ?? []);
        $this->check(
            'm5-object-fixture-capability',
            ($objects['milestone'] ?? '') === 'M5'
                && !empty($objects['foundation'])
                && !empty($objects['evidence_gate']['required_for_mutation'])
                && in_array('object-create',$objectOps,true)
                && isset($objectKinds[self::KIND]),
            ['research_object_control'=>$objects]
        );

        $translations = (array) ($cap['research_translation_control'] ?? []);
        $translationOps = (array) ($translations['operations'] ?? []);
        $types = (array) ($translations['post_types'] ?? []);
        $requirements = (array) ($translations['pair_requirements'] ?? []);
        $this->check(
            'm5-translation-capability',
            ($translations['milestone'] ?? '') === 'M5'
                && !empty($translations['available'])
                && in_array(self::POST_TYPE,$types,true)
                && in_array('translation-pair',$translationOps,true)
                && in_array('translation-unpair',$translationOps,true)
                && !empty($requirements['same_supported_type'])
                && !empty($requirements['published_only'])
                && array_values((array) ($requirements['languages'] ?? [])) === ['en','es']
                && !empty($translations['evidence_reference']['supported'])
                && !empty($translations['collision_protection'])
                && !empty($translations['stale_preview_protection'])
                && !empty($translations['rendered_verification'])
                && !empty($translations['insights_use_dedicated_m4_gateway'])
                && empty($translations['arbitrary_wordpress_proxy']),
            ['research_translation_control'=>$translations]
        );
    }

    private function exercise(): void
    {
        $baselineInventory = $this->objectInventory('object-inventory-baseline');
        $baselineCount = (int) ($baselineInventory['count'] ?? 0);
        $suffix = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));

        $enCreatePlan = $this->objectCreatePlan('en', $suffix, 'object-en-create-plan');
        $afterEnPreview = $this->objectInventory('object-inventory-after-en-preview');
        $this->check(
            'en-create-preview-no-mutation',
            (int) ($afterEnPreview['count'] ?? -1) === $baselineCount,
            ['baseline_count'=>$baselineCount,'preview_count'=>$afterEnPreview['count'] ?? null]
        );
        $enCreateOp = $this->objectApply($enCreatePlan, 'object-en-create-apply');
        $this->objectVerify($enCreateOp, 'object-en-create-verify');
        $enId = $this->createdPostId('object-en-create-apply');
        $en = $this->objectInspect($enId, 'object-en-created-inspect');
        $this->check(
            'en-fixture-public-structural',
            ($en['status'] ?? '') === 'publish'
                && ($en['language'] ?? '') === 'en'
                && str_starts_with((string) ($en['title'] ?? ''), 'Temporary M5 Translation Software EN')
                && empty($en['doi'])
                && [] === array_values((array) ($en['line_ids'] ?? [])),
            ['object'=>$en]
        );

        $esCreatePlan = $this->objectCreatePlan('es', $suffix, 'object-es-create-plan');
        $afterEsPreview = $this->objectInventory('object-inventory-after-es-preview');
        $this->check(
            'es-create-preview-no-mutation',
            (int) ($afterEsPreview['count'] ?? -1) === $baselineCount + 1,
            ['expected_count'=>$baselineCount + 1,'preview_count'=>$afterEsPreview['count'] ?? null]
        );
        $esCreateOp = $this->objectApply($esCreatePlan, 'object-es-create-apply');
        $this->objectVerify($esCreateOp, 'object-es-create-verify');
        $esId = $this->createdPostId('object-es-create-apply');
        $es = $this->objectInspect($esId, 'object-es-created-inspect');
        $this->check(
            'es-fixture-public-structural',
            ($es['status'] ?? '') === 'publish'
                && ($es['language'] ?? '') === 'es'
                && str_starts_with((string) ($es['title'] ?? ''), 'Temporary M5 Translation Software ES')
                && empty($es['doi'])
                && [] === array_values((array) ($es['line_ids'] ?? [])),
            ['object'=>$es]
        );

        $initialEnTranslation = $this->translationInspect($enId, 'translation-en-initial');
        $initialEsTranslation = $this->translationInspect($esId, 'translation-es-initial');
        $this->check(
            'fixtures-start-unpaired',
            (int) ($initialEnTranslation['counterpart_id'] ?? -1) === 0
                && (int) ($initialEsTranslation['counterpart_id'] ?? -1) === 0,
            ['en'=>$initialEnTranslation,'es'=>$initialEsTranslation]
        );

        $pairReference = 'acceptance:final-v1:m5-translation:software:pair';
        $pairPlan = $this->translationPlan('translation-pair', [
            'first_id'=>$enId,
            'second_id'=>$esId,
            'evidence_confirmed'=>true,
            'evidence_reference'=>$pairReference,
        ], 'translation-pair-plan');

        $afterPairPreviewEn = $this->translationInspect($enId, 'translation-en-after-pair-preview');
        $afterPairPreviewEs = $this->translationInspect($esId, 'translation-es-after-pair-preview');
        $this->check(
            'pair-preview-no-mutation',
            (int) ($afterPairPreviewEn['counterpart_id'] ?? -1) === 0
                && (int) ($afterPairPreviewEs['counterpart_id'] ?? -1) === 0,
            ['en'=>$afterPairPreviewEn,'es'=>$afterPairPreviewEs]
        );

        $pairOp = $this->translationApply($pairPlan, 'translation-pair-apply');
        $this->translationVerify($pairOp, true, 'translation-pair-verify');
        $pairedEn = $this->translationInspect($enId, 'translation-en-paired');
        $pairedEs = $this->translationInspect($esId, 'translation-es-paired');
        $this->check(
            'bilateral-pair-and-evidence',
            (int) ($pairedEn['counterpart_id'] ?? 0) === $esId
                && (int) ($pairedEs['counterpart_id'] ?? 0) === $enId
                && !empty($pairedEn['counterpart_exists'])
                && !empty($pairedEs['counterpart_exists'])
                && ($pairedEn['evidence_reference'] ?? '') === $pairReference
                && ($pairedEs['evidence_reference'] ?? '') === $pairReference,
            ['en'=>$pairedEn,'es'=>$pairedEs]
        );

        $enCandidates = $this->translationCandidates('en', 'translation-en-candidates');
        $esCandidates = $this->translationCandidates('es', 'translation-es-candidates');
        $this->check(
            'paired-fixtures-discoverable',
            $this->candidatePresent($enCandidates, $enId, $esId)
                && $this->candidatePresent($esCandidates, $esId, $enId),
            ['en_count'=>$enCandidates['count'] ?? null,'es_count'=>$esCandidates['count'] ?? null]
        );

        $unpairReference = 'acceptance:final-v1:m5-translation:software:unpair';
        $unpairPlan = $this->translationPlan('translation-unpair', [
            'post_id'=>$enId,
            'evidence_confirmed'=>true,
            'evidence_reference'=>$unpairReference,
        ], 'translation-unpair-plan');
        $afterUnpairPreviewEn = $this->translationInspect($enId, 'translation-en-after-unpair-preview');
        $afterUnpairPreviewEs = $this->translationInspect($esId, 'translation-es-after-unpair-preview');
        $this->check(
            'unpair-preview-no-mutation',
            (int) ($afterUnpairPreviewEn['counterpart_id'] ?? 0) === $esId
                && (int) ($afterUnpairPreviewEs['counterpart_id'] ?? 0) === $enId
                && ($afterUnpairPreviewEn['evidence_reference'] ?? '') === $pairReference
                && ($afterUnpairPreviewEs['evidence_reference'] ?? '') === $pairReference,
            ['en'=>$afterUnpairPreviewEn,'es'=>$afterUnpairPreviewEs]
        );

        $unpairOp = $this->translationApply($unpairPlan, 'translation-unpair-apply');
        $this->translationVerify($unpairOp, true, 'translation-unpair-verify');
        $unpairedEn = $this->translationInspect($enId, 'translation-en-unpaired');
        $unpairedEs = $this->translationInspect($esId, 'translation-es-unpaired');
        $this->check(
            'unpair-applied-bilaterally',
            (int) ($unpairedEn['counterpart_id'] ?? -1) === 0
                && (int) ($unpairedEs['counterpart_id'] ?? -1) === 0
                && ($unpairedEn['evidence_reference'] ?? '') === $unpairReference
                && ($unpairedEs['evidence_reference'] ?? '') === $unpairReference,
            ['en'=>$unpairedEn,'es'=>$unpairedEs]
        );

        $this->rollbackTranslation($unpairOp, 'translation-unpair-rollback');
        $restoredPairEn = $this->translationInspect($enId, 'translation-en-after-unpair-rollback');
        $restoredPairEs = $this->translationInspect($esId, 'translation-es-after-unpair-rollback');
        $this->check(
            'unpair-rollback-restored-pair',
            (int) ($restoredPairEn['counterpart_id'] ?? 0) === $esId
                && (int) ($restoredPairEs['counterpart_id'] ?? 0) === $enId
                && ($restoredPairEn['evidence_reference'] ?? '') === $pairReference
                && ($restoredPairEs['evidence_reference'] ?? '') === $pairReference,
            ['en'=>$restoredPairEn,'es'=>$restoredPairEs]
        );

        $this->rollbackTranslation($pairOp, 'translation-pair-rollback');
        $restoredUnpairedEn = $this->translationInspect($enId, 'translation-en-after-pair-rollback');
        $restoredUnpairedEs = $this->translationInspect($esId, 'translation-es-after-pair-rollback');
        $this->check(
            'pair-rollback-restored-unpaired-baseline',
            (int) ($restoredUnpairedEn['counterpart_id'] ?? -1) === 0
                && (int) ($restoredUnpairedEs['counterpart_id'] ?? -1) === 0
                && ($restoredUnpairedEn['evidence_reference'] ?? '') === (string) ($initialEnTranslation['evidence_reference'] ?? '')
                && ($restoredUnpairedEs['evidence_reference'] ?? '') === (string) ($initialEsTranslation['evidence_reference'] ?? ''),
            ['en'=>$restoredUnpairedEn,'es'=>$restoredUnpairedEs]
        );

        $this->rollbackObject($esCreateOp, 'object-es-create-rollback');
        $this->rollbackObject($enCreateOp, 'object-en-create-rollback');

        $finalInventory = $this->objectInventory('object-inventory-final');
        $this->check(
            'object-inventory-baseline-restored',
            (int) ($finalInventory['count'] ?? -1) === $baselineCount,
            ['baseline_count'=>$baselineCount,'final_count'=>$finalInventory['count'] ?? null]
        );

        $enMissing = $this->req('GET','/research-objects/' . self::KIND . '/' . $enId,null,[404],'object-en-final-absence');
        $esMissing = $this->req('GET','/research-objects/' . self::KIND . '/' . $esId,null,[404],'object-es-final-absence');
        $this->check(
            'fixtures-absent',
            (int) ($enMissing['_http_status'] ?? 0) === 404 && (int) ($esMissing['_http_status'] ?? 0) === 404,
            ['en_id'=>$enId,'es_id'=>$esId]
        );
    }

    private function objectCreatePlan(string $language, string $suffix, string $label): string
    {
        $title = 'Temporary M5 Translation Software ' . strtoupper($language) . ' — reversible acceptance fixture';
        $slug = 'm8-v1-translation-software-' . $language . '-' . $suffix;
        $data = [
            'title'=>$title,
            'slug'=>$slug,
            'excerpt'=>'Temporary structural translation acceptance fixture. Not research content and not an academic claim.',
            'content'=>'<p>Temporary structural translation acceptance fixture used only to verify bounded EN/ES Manager behavior. Not research content and not an academic claim.</p>',
            'language'=>$language,
            'status'=>'publish',
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
        $response = $this->data($this->req('POST','/research-objects/plan',[
            'operation'=>'object-create',
            'payload'=>[
                'kind'=>self::KIND,
                'data'=>$data,
                'evidence_confirmed'=>true,
                'evidence_reference'=>'acceptance:final-v1:m5-translation:software:' . $language . ':create',
            ],
        ],[200],$label));
        $planId = (string) ($response['plan_id'] ?? '');
        $this->id($planId, $label . ' plan_id');
        $this->check($label . '-apply-allowed', !empty($response['apply_allowed']), ['plan'=>$response]);
        return $planId;
    }

    private function objectApply(string $planId, string $label): string
    {
        $data = $this->data($this->req('POST','/research-objects/plans/' . rawurlencode($planId) . '/apply',[
            'confirm'=>true,
        ],[200],$label));
        $operationId = (string) ($data['operation_id'] ?? '');
        $this->id($operationId, $label . ' operation_id');
        $this->check($label . '-verified-result', !empty($data['result']['verified']), ['apply'=>$data]);
        $this->track($operationId, '/research-objects/operations/' . rawurlencode($operationId) . '/rollback', 'object');
        return $operationId;
    }

    private function objectVerify(string $operationId, string $label): void
    {
        $data = $this->data($this->req('POST','/research-objects/operations/' . rawurlencode($operationId) . '/verify',[
            'rendered'=>false,
        ],[200],$label));
        $this->check($label . '-stored', !empty($data['stored_verification']['verified']), ['verify'=>$data]);
    }

    private function createdPostId(string $applyLabel): int
    {
        $apply = $this->last($applyLabel);
        $postId = (int) ($apply['target']['post_id'] ?? $apply['result']['post_id'] ?? 0);
        if ($postId <= 0) throw new RuntimeException($applyLabel . ' missing post_id.');
        return $postId;
    }

    private function objectInventory(string $label): array
    {
        return $this->data($this->req('GET','/research-objects',null,[200],$label));
    }

    private function objectInspect(int $postId, string $label): array
    {
        return $this->data($this->req('GET','/research-objects/' . self::KIND . '/' . $postId,null,[200],$label));
    }

    private function translationPlan(string $operation, array $payload, string $label): string
    {
        $data = $this->data($this->req('POST','/research-translations/plan',[
            'operation'=>$operation,
            'payload'=>$payload,
        ],[200],$label));
        $planId = (string) ($data['plan_id'] ?? '');
        $this->id($planId, $label . ' plan_id');
        $this->check($label . '-apply-allowed', !empty($data['apply_allowed']), ['plan'=>$data]);
        return $planId;
    }

    private function translationApply(string $planId, string $label): string
    {
        $data = $this->data($this->req('POST','/research-translations/plans/' . rawurlencode($planId) . '/apply',[
            'confirm'=>true,
        ],[200],$label));
        $operationId = (string) ($data['operation_id'] ?? '');
        $this->id($operationId, $label . ' operation_id');
        $this->check($label . '-verified-result', !empty($data['result']['verified']), ['apply'=>$data]);
        $this->track($operationId, '/research-translations/operations/' . rawurlencode($operationId) . '/rollback', 'translation');
        return $operationId;
    }

    private function translationVerify(string $operationId, bool $rendered, string $label): void
    {
        $data = $this->data($this->req('POST','/research-translations/operations/' . rawurlencode($operationId) . '/verify',[
            'rendered'=>$rendered,
        ],[200],$label));
        $this->check($label . '-stored', !empty($data['stored_verification']['verified']), ['verify'=>$data]);
        if ($rendered) {
            $this->check($label . '-rendered', !empty($data['rendered_verification']['verified']), ['verify'=>$data]);
            $resources = (array) ($data['rendered_verification']['resources'] ?? []);
            $this->check(
                $label . '-two-rendered-resources',
                count($resources) === 2 && !empty($resources[0]['verified']) && !empty($resources[1]['verified']),
                ['resources'=>$resources]
            );
        }
    }

    private function translationInspect(int $postId, string $label): array
    {
        return $this->data($this->req('GET','/research-translations/' . $postId,null,[200],$label));
    }

    private function translationCandidates(string $language, string $label): array
    {
        return $this->data($this->req(
            'GET',
            '/research-translations?post_type=' . rawurlencode(self::POST_TYPE) . '&language=' . rawurlencode($language),
            null,
            [200],
            $label
        ));
    }

    private function candidatePresent(array $response, int $postId, int $counterpartId): bool
    {
        foreach ((array) ($response['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            if ((int) ($item['post_id'] ?? 0) === $postId && (int) ($item['counterpart_id'] ?? 0) === $counterpartId) return true;
        }
        return false;
    }

    private function rollbackTranslation(string $operationId, string $label): void
    {
        $entry =& $this->rollbackEntry($operationId, 'translation');
        $data = $this->data($this->req('POST',$entry['route'],['confirm'=>true],[200],$label));
        $this->check(
            $label . '-status',
            ($data['status'] ?? '') === 'rolled-back' && !empty($data['rollback_restored_baseline']),
            ['response'=>$data]
        );
        $entry['done'] = true;
        unset($entry);
    }

    private function rollbackObject(string $operationId, string $label): void
    {
        $entry =& $this->rollbackEntry($operationId, 'object');
        $data = $this->data($this->req('POST',$entry['route'],['confirm'=>true],[200],$label));
        $status = (string) ($data['status'] ?? '');
        $this->check(
            $label . '-status',
            $status === '' || in_array($status,['rolled-back','rolled_back'],true),
            ['response'=>$data]
        );
        $entry['done'] = true;
        unset($entry);
    }

    private function &rollbackEntry(string $operationId, string $family): array
    {
        foreach ($this->rollbacks as &$entry) {
            if ($entry['op'] === $operationId && $entry['family'] === $family && !$entry['done']) return $entry;
        }
        unset($entry);
        throw new RuntimeException('Untracked ' . $family . ' rollback ' . $operationId);
    }

    private function track(string $operationId, string $route, string $family): void
    {
        $this->rollbacks[] = ['op'=>$operationId,'route'=>$route,'family'=>$family,'done'=>false];
    }

    private function req(string $method, string $relative, ?array $body, array $expected, string $label): array
    {
        $requestId = 'm8-m5-translation-' . preg_replace('/[^A-Za-z0-9._:-]/','-',$label) . '-' . time() . '-' . bin2hex(random_bytes(4));
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
            CURLOPT_USERAGENT=>'Eduardo-Research-M8-M5-Translation/1.0',
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
                $response = $this->data($this->req(
                    'POST',
                    $this->rollbacks[$i]['route'],
                    ['confirm'=>true],
                    [200],
                    'emergency-' . $this->rollbacks[$i]['family'] . '-rollback-' . $i
                ));
                $this->rollbacks[$i]['done'] = true;
                $this->evidence['emergency_rollbacks'][] = [
                    'op'=>$this->rollbacks[$i]['op'],
                    'family'=>$this->rollbacks[$i]['family'],
                    'success'=>true,
                    'status'=>$response['status'] ?? '',
                ];
            } catch (Throwable $e) {
                $this->evidence['emergency_rollbacks'][] = [
                    'op'=>$this->rollbacks[$i]['op'],
                    'family'=>$this->rollbacks[$i]['family'],
                    'success'=>false,
                    'error'=>$e->getMessage(),
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
exit((new M8M5TranslationProductionAcceptance($args))->run());
