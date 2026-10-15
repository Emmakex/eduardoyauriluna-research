#!/usr/bin/env php
<?php
declare(strict_types=1);

final class M8V1FinalCapabilitiesProductionAcceptance
{
    private const API = '/wp-json/research-manager/v1';
    private const EXPECTED_MANAGER = '0.10.4';
    private const EXPECTED_THEME = '1.0.0';
    private const EXPECTED_SCOPES = ['site.read'];
    private const SUPPORTED_SCOPES = [
        'site.read','site.diagnostics',
        'pages.read','pages.write',
        'insights.read','insights.write',
        'research.read','research.write',
        'evidence.read','evidence.write',
        'translations.read','translations.write',
        'seo.read','seo.write',
        'media.read','media.write',
        'connections.read','connections.write',
        'design.read','design.write',
        'operations.apply','operations.rollback',
        'identity.write','credentials.manage','external_publish.execute',
        'software_update.execute','destructive_delete.execute',
    ];
    private const SURFACES = [
        'identity','pages','navigation','insights','research-lines','research-objects',
        'translations','evidence','media','seo-geo','design','connections','audit','operations',
    ];

    private string $base;
    private string $token;
    private string $evidencePath;
    private array $evidence = [];

    public function __construct(array $argv)
    {
        $this->base = rtrim(trim((string) getenv('ERM_BASE_URL')), '/');
        $this->token = trim((string) getenv('ERM_TOKEN'));
        $this->evidencePath = $this->arg($argv, '--evidence=')
            ?: 'tmp/final-v1-capabilities-' . gmdate('Ymd\\THis\\Z') . '-' . getmypid() . '.json';
    }

    public function run(): int
    {
        try {
            $this->validateEnvironment();
            $this->evidence = [
                'schema_version'=>1,
                'milestone'=>'M8',
                'slice'=>'final-v1-capabilities-and-operating-boundary',
                'site'=>$this->base,
                'read_only'=>true,
                'expected_manager'=>self::EXPECTED_MANAGER,
                'expected_theme'=>self::EXPECTED_THEME,
                'expected_granted_scopes'=>self::EXPECTED_SCOPES,
                'requests'=>[],
                'checks'=>[],
                'started_at'=>gmdate(DATE_ATOM),
                'result'=>'running',
            ];

            $status = $this->data($this->request('/status', 'status'));
            $versions = $this->data($this->request('/versions', 'versions'));
            $caps = $this->data($this->request('/capabilities', 'capabilities'));

            $languages = $this->normalise((array) ($status['languages'] ?? []));
            $this->check('production-version-pair',
                ($status['manager_version'] ?? '') === self::EXPECTED_MANAGER
                && ($status['theme_version'] ?? '') === self::EXPECTED_THEME
                && ($versions['manager'] ?? '') === self::EXPECTED_MANAGER
                && ($versions['theme'] ?? '') === self::EXPECTED_THEME
                && ($versions['api'] ?? '') === 'v1',
                ['status_manager'=>$status['manager_version'] ?? null,'status_theme'=>$status['theme_version'] ?? null,'versions'=>$versions]
            );

            $mode = $status['mode'] ?? null;
            $greenfield = is_array($mode)
                ? (($mode['mode'] ?? '') === 'greenfield' && !empty($mode['greenfield']) && empty($mode['migration']))
                : ($mode === 'greenfield');

            $this->check('production-site-contract',
                $greenfield
                && $this->host((string) ($status['canonical_domain'] ?? '')) === 'eduardoyauriluna.com'
                && $languages === ['en','es']
                && !empty($status['preset']['compatible']),
                ['mode'=>$mode,'canonical_domain'=>$status['canonical_domain'] ?? null,'languages'=>$languages,'preset'=>$status['preset'] ?? null]
            );

            $granted = $this->normalise((array) ($caps['granted_scopes'] ?? []));
            $supported = $this->normalise((array) ($caps['supported_scopes'] ?? []));
            $expectedSupported = $this->normalise(self::SUPPORTED_SCOPES);
            $this->check('least-privilege-final-credential', $granted === self::EXPECTED_SCOPES, ['granted'=>$granted]);
            $this->check('supported-scope-registry', $supported === $expectedSupported, [
                'missing'=>array_values(array_diff($expectedSupported,$supported)),
                'unexpected'=>array_values(array_diff($supported,$expectedSupported)),
            ]);

            $read = (array) ($caps['read_endpoints'] ?? []);
            $transport = (array) ($caps['mutation_transport'] ?? []);
            $this->check('core-v1-contract',
                ($caps['namespace'] ?? '') === 'research-manager/v1'
                && ($read['status'] ?? '') === 'site.read'
                && ($read['versions'] ?? '') === 'site.read'
                && ($read['capabilities'] ?? '') === 'site.read'
                && !empty($transport['available'])
                && !empty($transport['exact_plan_required'])
                && !empty($transport['stale_revision_protection'])
                && !empty($transport['idempotency']),
                ['namespace'=>$caps['namespace'] ?? null,'read_endpoints'=>$read,'mutation_transport'=>$transport]
            );

            $v1 = (array) ($caps['manager_v1_development'] ?? []);
            $surfaces = $this->normalise((array) ($v1['surfaces'] ?? []));
            $expectedSurfaces = $this->normalise(self::SURFACES);
            $pair = (array) ($v1['production_pair'] ?? []);
            $this->check('v1-production-closeout-state',
                ($v1['status'] ?? '') === 'production-accepted'
                && ($v1['production_acceptance_status'] ?? '') === 'complete'
                && array_key_exists('production_acceptance_frozen',$v1)
                && $v1['production_acceptance_frozen'] === false
                && ($pair['theme'] ?? '') === self::EXPECTED_THEME
                && ($pair['manager'] ?? '') === self::EXPECTED_MANAGER
                && ($v1['site_scope'] ?? '') === 'single-site'
                && array_key_exists('future_multiclient_saas_in_v1',$v1)
                && $v1['future_multiclient_saas_in_v1'] === false,
                ['manager_v1_development'=>$v1]
            );
            $this->check('v1-surface-inventory', $surfaces === $expectedSurfaces, [
                'surfaces'=>$surfaces,
                'missing'=>array_values(array_diff($expectedSurfaces,$surfaces)),
                'unexpected'=>array_values(array_diff($surfaces,$expectedSurfaces)),
            ]);
            $this->check('no-normal-bypass',
                array_key_exists('normal_operation_ssh_required',$v1)
                && $v1['normal_operation_ssh_required'] === false
                && array_key_exists('normal_operation_wordpress_credentials_shared',$v1)
                && $v1['normal_operation_wordpress_credentials_shared'] === false
                && array_key_exists('arbitrary_wordpress_rest_proxy',$v1)
                && $v1['arbitrary_wordpress_rest_proxy'] === false
                && !empty($v1['local_admin_shared_service_parity']),
                ['normal_operation_ssh_required'=>$v1['normal_operation_ssh_required'] ?? null,'normal_operation_wordpress_credentials_shared'=>$v1['normal_operation_wordpress_credentials_shared'] ?? null,'arbitrary_wordpress_rest_proxy'=>$v1['arbitrary_wordpress_rest_proxy'] ?? null,'local_admin_shared_service_parity'=>$v1['local_admin_shared_service_parity'] ?? null]
            );

            $targets = (array) ($caps['page_target_control'] ?? []);
            $this->check('bounded-page-targets',
                !empty($targets['available'])
                && !empty($targets['registered_theme_targets_only'])
                && array_key_exists('arbitrary_urls',$targets)
                && $targets['arbitrary_urls'] === false
                && !empty($targets['language_aware_resolution']),
                ['page_target_control'=>$targets]
            );

            $this->evidence['result'] = 'pass';
            $this->evidence['finished_at'] = gmdate(DATE_ATOM);
            $this->writeEvidence();
            fwrite(STDOUT,"M8 final v1 capabilities/read-only production acceptance: PASS\n");
            fwrite(STDOUT,"Manager " . self::EXPECTED_MANAGER . " + Theme " . self::EXPECTED_THEME . "; single-site v1 boundary verified.\n");
            fwrite(STDOUT,"No production mutation was executed.\nEvidence: {$this->evidencePath}\n");
            return 0;
        } catch (Throwable $e) {
            $this->evidence['result'] = 'fail';
            $this->evidence['failure'] = ['class'=>get_class($e),'message'=>$e->getMessage()];
            $this->evidence['finished_at'] = gmdate(DATE_ATOM);
            $this->writeEvidence();
            fwrite(STDERR,"M8 final v1 capabilities/read-only production acceptance: FAIL — {$e->getMessage()}\nEvidence: {$this->evidencePath}\n");
            return 1;
        }
    }

    private function validateEnvironment(): void
    {
        if (!str_starts_with($this->base,'https://')) throw new RuntimeException('ERM_BASE_URL must use HTTPS.');
        if (!str_starts_with($this->token,'ermt1_')) throw new RuntimeException('ERM_TOKEN must be an independent temporary credential (ermt1_).');
    }

    private function request(string $relative, string $label): array
    {
        $requestId = 'm8-v1-closeout-' . $label . '-' . time() . '-' . bin2hex(random_bytes(4));
        $ch = curl_init($this->base . self::API . $relative);
        if ($ch === false) throw new RuntimeException('Unable to initialize cURL.');
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>30,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'Eduardo-Research-M8-V1-Closeout/1.0',
            CURLOPT_HTTPHEADER=>[
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
                'X-Research-Manager-Request-Id: ' . $requestId,
                'X-Research-Manager-Nonce: ' . bin2hex(random_bytes(16)),
                'X-Research-Manager-Timestamp: ' . time(),
            ],
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch); curl_close($ch);
            throw new RuntimeException($label . ' request failed: ' . $error);
        }
        $http = (int) curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
        try {$decoded=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);} catch(Throwable){$decoded=[];}
        if (!is_array($decoded)) $decoded=[];
        $this->evidence['requests'][]=[
            'label'=>$label,'method'=>'GET','route'=>self::API.$relative,'request_id'=>$requestId,
            'http_status'=>$http,'response'=>$this->clean($decoded),
        ];
        if ($http!==200 || empty($decoded['ok']) || !is_array($decoded['data']??null)) {
            throw new RuntimeException($label . ' returned HTTP ' . $http . ' (expected 200): ' . (string)($decoded['code']??'request_failed'));
        }
        return $decoded;
    }

    private function data(array $response): array {return is_array($response['data']??null)?$response['data']:[];}
    private function host(string $url): string
    {
        $url = trim($url);
        if ('' !== $url && !str_contains($url, '://')) $url = 'https://' . $url;
        return strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    }
    private function normalise(array $items): array {$items=array_values(array_unique(array_map('strval',$items)));sort($items);return $items;}

    private function check(string $id,bool $pass,array $details): void
    {
        $this->evidence['checks'][]=['id'=>$id,'passes'=>$pass,'details'=>$this->clean($details)];
        if (!$pass) throw new RuntimeException('Acceptance check failed: ' . $id);
    }

    private function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach($value as $key=>$item){
                if(in_array(strtolower((string)$key),['token','token_hash','authorization','password','secret'],true)){$value[$key]='[redacted]';}
                else{$value[$key]=$this->clean($item);}
            }
            return $value;
        }
        if(is_string($value)&&(str_contains($value,'ermt1_')||str_contains($value,'erm1_')))return '[redacted]';
        return $value;
    }

    private function writeEvidence(): void
    {
        if (!$this->evidence) return;
        $raw=(string)json_encode($this->clean($this->evidence),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
        if(str_contains($raw,'ermt1_')||str_contains($raw,'erm1_'))throw new RuntimeException('Refusing to write bearer-like material.');
        $dir=dirname($this->evidencePath);
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Unable to create evidence directory.');
        if(file_put_contents($this->evidencePath,$raw,LOCK_EX)===false)throw new RuntimeException('Unable to write evidence file.');
        @chmod($this->evidencePath,0600);
    }

    private function arg(array $argv,string $prefix): string
    {
        foreach($argv as $arg){if(str_starts_with((string)$arg,$prefix))return trim(substr((string)$arg,strlen($prefix)));}
        return '';
    }
}

$args=$argv;array_shift($args);
exit((new M8V1FinalCapabilitiesProductionAcceptance($args))->run());
