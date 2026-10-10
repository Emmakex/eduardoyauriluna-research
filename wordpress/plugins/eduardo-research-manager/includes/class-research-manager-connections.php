<?php
/** Read-only registry and normalized status model for academic connections. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Connections {
    private Eduardo_Research_Manager_Evidence_Editor $evidence;

    public function __construct(?Eduardo_Research_Manager_Evidence_Editor $evidence = null) {
        $this->evidence = $evidence ?: Eduardo_Research_Manager::evidence_editor();
    }

    public function providers(): array {
        return array(
            'orcid'=>array(
                'label'=>'ORCID','mode'=>'oauth','priority'=>10,
                'capabilities'=>array('authenticated_identifier','profile_read','works_read','reconcile'),
                'write_enabled'=>false,'ready_without_configuration'=>false,
                'configuration'=>array('EDUARDO_RESEARCH_ORCID_CLIENT_ID','EDUARDO_RESEARCH_ORCID_CLIENT_SECRET','EDUARDO_RESEARCH_ORCID_REDIRECT_URI'),
            ),
            'google_scholar'=>array(
                'label'=>'Google Scholar','mode'=>'manual_link','priority'=>20,
                'capabilities'=>array('profile_link','bibtex_import','csv_import','academic_meta'),
                'write_enabled'=>false,'ready_without_configuration'=>false,'configuration'=>array(),
            ),
            'crossref'=>array(
                'label'=>'Crossref','mode'=>'public_api','priority'=>30,
                'capabilities'=>array('doi_lookup','metadata_read','reconcile'),
                'write_enabled'=>false,'ready_without_configuration'=>true,'configuration'=>array(),
            ),
            'zenodo'=>array(
                'label'=>'Zenodo','mode'=>'token_read','priority'=>40,
                'capabilities'=>array('record_read','doi_link','reconcile'),
                'write_enabled'=>false,'ready_without_configuration'=>false,
                'configuration'=>array('EDUARDO_RESEARCH_ZENODO_TOKEN'),
            ),
            'openalex'=>array(
                'label'=>'OpenAlex','mode'=>'public_api','priority'=>50,
                'capabilities'=>array('exact_orcid_author_lookup','author_read','works_read','metrics_read','doi_reconcile'),
                'write_enabled'=>false,'ready_without_configuration'=>true,'configuration'=>array(),
            ),
            'github'=>array(
                'label'=>'GitHub','mode'=>'manual_link','priority'=>60,
                'capabilities'=>array('profile_link','software_link','repository_metadata'),
                'write_enabled'=>false,'ready_without_configuration'=>false,'configuration'=>array(),
            ),
        );
    }

    public function provider(string $provider): array|WP_Error {
        $provider = sanitize_key($provider);
        $definition = $this->providers()[$provider] ?? null;
        if (! is_array($definition)) {
            return new WP_Error('research_manager_connection_provider_unknown', 'Unsupported academic connection provider.');
        }
        return array_merge(array('provider'=>$provider), $definition);
    }

    public function statuses(): array {
        $statuses = array();
        foreach (array_keys($this->providers()) as $provider) {
            $status = $this->status((string) $provider);
            if (! is_wp_error($status)) { $statuses[$provider] = $status; }
        }
        uasort($statuses, static function (array $left, array $right): int {
            return (int) ($left['priority'] ?? 999) <=> (int) ($right['priority'] ?? 999);
        });
        return $statuses;
    }

    public function status(string $provider): array|WP_Error {
        $definition = $this->provider($provider);
        if (is_wp_error($definition)) { return $definition; }
        $provider = (string) $definition['provider'];
        $store = get_option('eduardo_research_connections', array());
        $store = is_array($store) ? $store : array();
        $stored = is_array($store[$provider] ?? null) ? $store[$provider] : array();
        $configuration = $this->validate_configuration($provider);
        $identifier = $this->verified_identifier($provider);

        $allowed = array('disconnected','available','configured','linked','connected','error');
        $state = sanitize_key($this->scalar($stored['status'] ?? ''));
        if (! in_array($state, $allowed, true)) { $state = 'disconnected'; }
        $required = is_array($configuration['required'] ?? null) ? $configuration['required'] : array();

        if ('disconnected' === $state && ! is_wp_error($identifier) && $identifier) {
            $state = 'linked';
        } elseif ('disconnected' === $state && $required && ! empty($configuration['configured'])) {
            $state = 'configured';
        } elseif ('disconnected' === $state && ! empty($definition['ready_without_configuration'])) {
            $state = 'available';
        }

        $identifier_value = ! is_wp_error($identifier) && is_array($identifier) ? $this->scalar($identifier['value'] ?? '') : '';
        $identifier_url = ! is_wp_error($identifier) && is_array($identifier) ? $this->scalar($identifier['url'] ?? '') : '';

        return array(
            'provider'=>$provider,
            'label'=>(string) $definition['label'],
            'priority'=>(int) $definition['priority'],
            'status'=>$state,
            'mode'=>(string) ($stored['mode'] ?? $definition['mode']),
            'identifier'=>'' !== $this->scalar($stored['identifier'] ?? '') ? $this->scalar($stored['identifier']) : $identifier_value,
            'identifier_url'=>'' !== $this->scalar($stored['identifier_url'] ?? '') ? $this->scalar($stored['identifier_url']) : $identifier_url,
            'last_sync_at'=>$this->scalar($stored['last_sync_at'] ?? ''),
            'last_success_at'=>$this->scalar($stored['last_success_at'] ?? ''),
            'last_error'=>$this->scalar($stored['last_error'] ?? ''),
            'capabilities'=>array_values((array) $definition['capabilities']),
            'write_enabled'=>false,
            'configuration'=>$configuration,
            'identifier_evidence'=>! is_wp_error($identifier) && is_array($identifier) ? $identifier : array(),
        );
    }

    public function validate_configuration(string $provider): array|WP_Error {
        $definition = $this->provider($provider);
        if (is_wp_error($definition)) { return $definition; }
        $required = array_values((array) ($definition['configuration'] ?? array()));
        $missing = array();
        foreach ($required as $constant) {
            $constant = (string) $constant;
            if (! defined($constant) || '' === trim($this->scalar(constant($constant)))) { $missing[] = $constant; }
        }
        return array(
            'provider'=>(string) $definition['provider'],
            'configured'=>0 === count($missing),
            'required'=>$required,
            'missing'=>$missing,
            'secrets_exposed'=>false,
        );
    }

    public function overview(): array {
        $statuses = $this->statuses();
        $summary = array('connected'=>0,'linked'=>0,'configured'=>0,'available'=>0,'disconnected'=>0,'error'=>0);
        foreach ($statuses as $status) {
            $state = (string) ($status['status'] ?? 'disconnected');
            if (! isset($summary[$state])) { $state = 'disconnected'; }
            $summary[$state]++;
        }
        return array(
            'providers'=>$statuses,
            'summary'=>$summary,
            'provider_count'=>count($statuses),
            'writes_enabled'=>false,
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    private function verified_identifier(string $provider): array|WP_Error {
        $records = $this->evidence->records('identifiers');
        if (is_wp_error($records)) { return $records; }
        foreach ($records as $record) {
            if (! is_array($record) || 'verified' !== sanitize_key($this->scalar($record['status'] ?? ''))) { continue; }
            if ($provider !== $this->identify_provider($record)) { continue; }
            return array(
                'record_id'=>$this->scalar($record['record_id'] ?? $record['id'] ?? ''),
                'value'=>$this->scalar($record['value'] ?? ''),
                'url'=>$this->scalar($record['url'] ?? ''),
                'evidence_reference'=>$this->scalar($record['evidence_reference'] ?? ''),
                'verified_at'=>$this->scalar($record['verified_at'] ?? ''),
            );
        }
        return array();
    }

    private function identify_provider(array $record): string {
        $haystack = strtolower(implode(' ', array(
            $this->scalar($record['id'] ?? ''),
            $this->scalar($record['record_id'] ?? ''),
            $this->scalar($record['title'] ?? ''),
            $this->scalar($record['label'] ?? ''),
            $this->scalar($record['value'] ?? ''),
            $this->scalar($record['url'] ?? ''),
        )));
        $patterns = array(
            'orcid'=>array('orcid','orcid.org'),
            'google_scholar'=>array('google scholar','scholar.google'),
            'crossref'=>array('crossref'),
            'zenodo'=>array('zenodo','zenodo.org'),
            'openalex'=>array('openalex','openalex.org'),
            'github'=>array('github','github.com'),
        );
        foreach ($patterns as $candidate => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) { return (string) $candidate; }
            }
        }
        return '';
    }

    private function scalar(mixed $value): string {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
