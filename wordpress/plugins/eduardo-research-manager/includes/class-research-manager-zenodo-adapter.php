<?php
/** Verified-ORCID Zenodo read adapter. Published-record reads only; no deposit actions. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Zenodo_Adapter {
    private const CONNECTIONS_OPTION = 'eduardo_research_connections';

    public function capabilities(): array {
        return array(
            'exact_orcid_record_search'=>true,
            'record_read'=>true,
            'doi_reconciliation_preview'=>true,
            'name_matching'=>false,
            'deposit_read'=>false,
            'deposit_write'=>false,
            'publish'=>false,
            'external_write'=>false,
        );
    }

    public function configuration(): array {
        $token = $this->token();
        return array(
            'configured'=>'' !== $token,
            'environment'=>$this->environment(),
            'token_required'=>true,
            'missing'=>'' === $token ? array('EDUARDO_RESEARCH_ZENODO_TOKEN') : array(),
            'secrets_exposed'=>false,
            'external_write'=>false,
        );
    }

    public function normalize_orcid(string $orcid): string {
        return Eduardo_Research_Manager::openalex()->normalize_orcid($orcid);
    }

    public function normalize_record_id(string|int $record_id): int {
        if (is_int($record_id)) { return $record_id > 0 ? $record_id : 0; }
        $record_id = trim(rawurldecode($record_id));
        $record_id = preg_replace('#^https?://(?:sandbox\.)?zenodo\.org/records/#i', '', $record_id) ?? $record_id;
        $record_id = preg_replace('/[^0-9].*$/', '', $record_id) ?? $record_id;
        return ctype_digit($record_id) && (int) $record_id > 0 ? (int) $record_id : 0;
    }

    public function record(int|string $record_id): array|WP_Error {
        $configured = $this->require_configuration();
        if (is_wp_error($configured)) { return $configured; }
        $record_id = $this->normalize_record_id($record_id);
        if ($record_id <= 0) {
            return new WP_Error('research_manager_zenodo_invalid_record_id', 'A valid exact Zenodo record ID is required.');
        }
        $payload = $this->api_get('/api/records/' . $record_id);
        if (is_wp_error($payload)) { return $payload; }
        $record = $this->normalize_record($payload);
        if ($record_id !== (int) ($record['record_id'] ?? 0)) {
            return $this->connection_error('research_manager_zenodo_record_mismatch', 'Zenodo returned a record that did not match the requested record ID.');
        }
        $this->connection_success();
        return array('provider'=>'zenodo','status'=>'read','record'=>$record,'fetched_at'=>gmdate(DATE_W3C),'external_write'=>false);
    }

    public function records_by_verified_orcid(int $size = 100): array|WP_Error {
        $configured = $this->require_configuration();
        if (is_wp_error($configured)) { return $configured; }
        $orcid = $this->verified_orcid();
        if (is_wp_error($orcid)) { return $orcid; }
        $size = max(1, min(100, $size));

        // Zenodo supports Elasticsearch-style phrase search. We deliberately query the
        // exact ORCID phrase and then independently verify the creator identifier in every
        // returned record; search ranking/name matching is never sufficient for identity.
        $payload = $this->api_get('/api/records', array(
            'q'=>'"' . $orcid . '"',
            'size'=>$size,
            'all_versions'=>'false',
            'sort'=>'-mostrecent',
        ));
        if (is_wp_error($payload)) { return $payload; }

        $raw_records = $this->search_records($payload);
        $records = array();
        foreach ($raw_records as $raw) {
            if (! is_array($raw)) { continue; }
            $record = $this->normalize_record($raw);
            if (! $this->record_contains_orcid($record, $orcid)) { continue; }
            if ((int) ($record['record_id'] ?? 0) <= 0) { continue; }
            $records[] = $record;
        }
        $this->connection_success();

        $total = $this->search_total($payload, count($raw_records));
        return array(
            'provider'=>'zenodo',
            'status'=>'read',
            'identity_basis'=>'exact_verified_orcid_in_creator_metadata',
            'orcid'=>$orcid,
            'records'=>$records,
            'record_count'=>count($records),
            'search_result_count'=>count($raw_records),
            'search_total'=>$total,
            'bounded_size'=>$size,
            'truncated'=>$total > count($raw_records),
            'name_matching'=>false,
            'external_write'=>false,
            'fetched_at'=>gmdate(DATE_W3C),
        );
    }

    public function reconciliation_preview(int $size = 100): array|WP_Error {
        $remote = $this->records_by_verified_orcid($size);
        if (is_wp_error($remote)) { return $remote; }
        $local = $this->local_doi_index();
        if (is_wp_error($local)) { return $local; }

        $matches = array();
        $remote_without_local = array();
        foreach ((array) ($remote['records'] ?? array()) as $record) {
            if (! is_array($record)) { continue; }
            $candidates = array(
                'doi'=>Eduardo_Research_Manager::crossref()->normalize_doi((string) ($record['doi'] ?? '')),
                'concept_doi'=>Eduardo_Research_Manager::crossref()->normalize_doi((string) ($record['concept_doi'] ?? '')),
            );
            $matched = false;
            foreach ($candidates as $match_kind => $doi) {
                if ('' === $doi || empty($local[$doi])) { continue; }
                foreach ((array) $local[$doi] as $local_record) {
                    $matches[] = array(
                        'match_kind'=>$match_kind,
                        'doi'=>$doi,
                        'remote'=>$record,
                        'local'=>$local_record,
                        'title_matches'=>$this->normalized_text((string) ($record['title'] ?? '')) === $this->normalized_text((string) ($local_record['title'] ?? '')),
                    );
                }
                $matched = true;
                break;
            }
            if (! $matched) { $remote_without_local[] = $record; }
        }

        return array(
            'provider'=>'zenodo',
            'status'=>'preview',
            'identity_basis'=>(string) ($remote['identity_basis'] ?? ''),
            'orcid'=>(string) ($remote['orcid'] ?? ''),
            'remote_records'=>(array) ($remote['records'] ?? array()),
            'remote_record_count'=>(int) ($remote['record_count'] ?? 0),
            'search_total'=>(int) ($remote['search_total'] ?? 0),
            'truncated'=>! empty($remote['truncated']),
            'local_doi_matches'=>$matches,
            'local_doi_match_count'=>count($matches),
            'remote_without_local'=>$remote_without_local,
            'requires_reconciliation'=>true,
            'automatic_local_apply'=>false,
            'name_matching'=>false,
            'deposit_write'=>false,
            'publish'=>false,
            'external_write'=>false,
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    private function api_get(string $path, array $query = array()): array|WP_Error {
        $url = untrailingslashit($this->api_base()) . '/' . ltrim($path, '/');
        if ($query) { $url = add_query_arg($query, $url); }
        $this->update_connection(array('last_sync_at'=>gmdate(DATE_W3C)));
        $response = wp_remote_get($url, array(
            'timeout'=>15,
            'headers'=>array(
                'Accept'=>'application/json',
                'Authorization'=>'Bearer ' . $this->token(),
                'User-Agent'=>'EduardoResearchManager/' . EDUARDO_RESEARCH_MANAGER_VERSION . ' (' . home_url('/') . ')',
            ),
        ));
        if (is_wp_error($response)) {
            return $this->connection_error('research_manager_zenodo_request_failed', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || ! is_array($payload)) {
            return $this->connection_error('research_manager_zenodo_response_invalid', 'Zenodo returned an invalid API response.');
        }
        return $payload;
    }

    private function normalize_record(array $raw): array {
        $metadata = is_array($raw['metadata'] ?? null) ? $raw['metadata'] : array();
        $pids = is_array($raw['pids'] ?? null) ? $raw['pids'] : array();
        $parent = is_array($raw['parent'] ?? null) ? $raw['parent'] : array();
        $parent_pids = is_array($parent['pids'] ?? null) ? $parent['pids'] : array();
        $links = is_array($raw['links'] ?? null) ? $raw['links'] : array();

        $record_id = $this->normalize_record_id($this->scalar($raw['id'] ?? $raw['record_id'] ?? ''));
        $doi = Eduardo_Research_Manager::crossref()->normalize_doi($this->first_scalar(array(
            $raw['doi'] ?? '',
            $metadata['doi'] ?? '',
            is_array($pids['doi'] ?? null) ? ($pids['doi']['identifier'] ?? '') : '',
        )));
        $concept_doi = Eduardo_Research_Manager::crossref()->normalize_doi($this->first_scalar(array(
            $raw['conceptdoi'] ?? '',
            $metadata['conceptdoi'] ?? '',
            is_array($parent_pids['doi'] ?? null) ? ($parent_pids['doi']['identifier'] ?? '') : '',
        )));

        $creators = array();
        foreach ((array) ($metadata['creators'] ?? array()) as $creator) {
            if (! is_array($creator)) { continue; }
            $person = is_array($creator['person_or_org'] ?? null) ? $creator['person_or_org'] : array();
            $name = sanitize_text_field($this->first_scalar(array($creator['name'] ?? '', $person['name'] ?? '')));
            $orcid = $this->normalize_orcid($this->scalar($creator['orcid'] ?? ''));
            $identifiers = array_merge(
                is_array($creator['identifiers'] ?? null) ? $creator['identifiers'] : array(),
                is_array($person['identifiers'] ?? null) ? $person['identifiers'] : array()
            );
            if ('' === $orcid) {
                foreach ($identifiers as $identifier) {
                    if (! is_array($identifier)) { continue; }
                    $scheme = strtolower($this->scalar($identifier['scheme'] ?? $identifier['name_identifier_scheme'] ?? ''));
                    $candidate = $this->scalar($identifier['identifier'] ?? $identifier['name_identifier'] ?? '');
                    if ('orcid' === $scheme || str_contains(strtolower($candidate), 'orcid.org/')) {
                        $orcid = $this->normalize_orcid($candidate);
                        if ('' !== $orcid) { break; }
                    }
                }
            }
            $affiliations = array();
            foreach ((array) ($creator['affiliations'] ?? array()) as $affiliation) {
                if (is_array($affiliation)) {
                    $value = sanitize_text_field($this->scalar($affiliation['name'] ?? ''));
                } else {
                    $value = sanitize_text_field($this->scalar($affiliation));
                }
                if ('' !== $value) { $affiliations[] = $value; }
            }
            $legacy_affiliation = sanitize_text_field($this->scalar($creator['affiliation'] ?? ''));
            if ('' !== $legacy_affiliation) { $affiliations[] = $legacy_affiliation; }
            $creators[] = array(
                'name'=>$name,
                'orcid'=>$orcid,
                'affiliations'=>array_values(array_unique($affiliations)),
            );
        }

        $resource_type = '';
        $resource = $metadata['resource_type'] ?? '';
        if (is_array($resource)) {
            $resource_type = $this->first_scalar(array($resource['type'] ?? '', $resource['title'] ?? '', $resource['id'] ?? ''));
        } else {
            $resource_type = $this->scalar($resource);
        }
        if ('' === $resource_type) { $resource_type = $this->scalar($metadata['upload_type'] ?? ''); }

        $license = '';
        if (is_array($metadata['license'] ?? null)) {
            $license = $this->first_scalar(array($metadata['license']['id'] ?? '', $metadata['license']['title'] ?? ''));
        } else {
            $license = $this->scalar($metadata['license'] ?? '');
        }
        $access = $metadata['access_right'] ?? '';
        if (is_array($raw['access'] ?? null)) { $access = $raw['access']['status'] ?? $access; }

        $record_url = esc_url_raw($this->first_scalar(array($links['html'] ?? '', $links['self_html'] ?? '')));
        if ('' === $record_url && $record_id > 0) { $record_url = $this->site_base() . '/records/' . $record_id; }

        return array(
            'record_id'=>$record_id,
            'record_url'=>$record_url,
            'doi'=>$doi,
            'concept_doi'=>$concept_doi,
            'title'=>sanitize_text_field($this->scalar($metadata['title'] ?? $raw['title'] ?? '')),
            'publication_date'=>sanitize_text_field($this->scalar($metadata['publication_date'] ?? $raw['created'] ?? '')),
            'version'=>sanitize_text_field($this->scalar($metadata['version'] ?? '')),
            'resource_type'=>sanitize_key(str_replace(array('_',' '), '-', strtolower($resource_type))),
            'access_right'=>sanitize_key(strtolower($this->scalar($access))),
            'license'=>sanitize_text_field($license),
            'creators'=>$creators,
            'keywords'=>array_values(array_filter(array_map('sanitize_text_field', array_map('strval', (array) ($metadata['keywords'] ?? array()))))),
        );
    }

    private function search_records(array $payload): array {
        if (isset($payload['hits']['hits']) && is_array($payload['hits']['hits'])) { return $payload['hits']['hits']; }
        if (array_is_list($payload)) { return $payload; }
        return array();
    }

    private function search_total(array $payload, int $fallback): int {
        $total = $payload['hits']['total'] ?? $fallback;
        if (is_array($total)) { $total = $total['value'] ?? $fallback; }
        return is_numeric($total) ? max(0, (int) $total) : $fallback;
    }

    private function record_contains_orcid(array $record, string $orcid): bool {
        foreach ((array) ($record['creators'] ?? array()) as $creator) {
            if (is_array($creator) && $orcid === (string) ($creator['orcid'] ?? '')) { return true; }
        }
        return false;
    }

    private function local_doi_index(): array|WP_Error {
        $index = array();
        foreach (array('output','software','dataset') as $kind) {
            foreach (Eduardo_Research_Manager::contract()->languages() as $language) {
                $rows = Eduardo_Research_Manager::object_editor()->list($kind, (string) $language);
                if (is_wp_error($rows)) { return $rows; }
                foreach ($rows as $row) {
                    if (! is_array($row)) { continue; }
                    $doi = Eduardo_Research_Manager::crossref()->normalize_doi((string) ($row['doi'] ?? ''));
                    if ('' === $doi) { continue; }
                    $row['kind'] = $kind;
                    if (! isset($index[$doi])) { $index[$doi] = array(); }
                    $index[$doi][] = $row;
                }
            }
        }
        return $index;
    }

    private function verified_orcid(): string|WP_Error {
        $status = Eduardo_Research_Manager::connections()->status('orcid');
        if (is_wp_error($status)) { return $status; }
        $orcid = $this->normalize_orcid((string) ($status['identifier'] ?? ''));
        if ('' === $orcid) {
            return new WP_Error('research_manager_zenodo_verified_orcid_required', 'A verified or authenticated ORCID iD is required before Zenodo record reconciliation.');
        }
        return $orcid;
    }

    private function require_configuration(): bool|WP_Error {
        if ('' === $this->token()) {
            return new WP_Error('research_manager_zenodo_token_required', 'Zenodo read access requires EDUARDO_RESEARCH_ZENODO_TOKEN in secure server configuration.');
        }
        return true;
    }

    private function environment(): string {
        if (! defined('EDUARDO_RESEARCH_ZENODO_ENVIRONMENT')) { return 'production'; }
        $value = sanitize_key($this->scalar(constant('EDUARDO_RESEARCH_ZENODO_ENVIRONMENT')));
        return 'sandbox' === $value ? 'sandbox' : 'production';
    }

    private function site_base(): string {
        return 'sandbox' === $this->environment() ? 'https://sandbox.zenodo.org' : 'https://zenodo.org';
    }

    private function api_base(): string { return $this->site_base(); }

    private function token(): string {
        if (! defined('EDUARDO_RESEARCH_ZENODO_TOKEN') || ! is_scalar(constant('EDUARDO_RESEARCH_ZENODO_TOKEN'))) { return ''; }
        return trim((string) constant('EDUARDO_RESEARCH_ZENODO_TOKEN'));
    }

    private function connection_success(): void {
        $this->update_connection(array('status'=>'configured','last_success_at'=>gmdate(DATE_W3C),'last_error'=>''));
    }

    private function connection_error(string $code, string $message): WP_Error {
        $this->update_connection(array('status'=>'error','last_error'=>sanitize_text_field($message)));
        return new WP_Error($code, $message);
    }

    private function update_connection(array $changes): void {
        $store = get_option(self::CONNECTIONS_OPTION, array());
        $store = is_array($store) ? $store : array();
        $current = is_array($store['zenodo'] ?? null) ? $store['zenodo'] : array();
        $store['zenodo'] = array_merge($current, $changes);
        update_option(self::CONNECTIONS_OPTION, $store, false);
    }

    private function normalized_text(string $value): string {
        $value = remove_accents(wp_strip_all_tags($value));
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private function first_scalar(array $values): string {
        foreach ($values as $value) {
            $value = $this->scalar($value);
            if ('' !== $value) { return $value; }
        }
        return '';
    }

    private function scalar(mixed $value): string {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
