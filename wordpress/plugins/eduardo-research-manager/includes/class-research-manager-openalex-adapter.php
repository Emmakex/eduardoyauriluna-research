<?php
/** Exact-ORCID OpenAlex read adapter. No name matching and no external writes. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_OpenAlex_Adapter {
    private const API_BASE = 'https://api.openalex.org';
    private const CONNECTIONS_OPTION = 'eduardo_research_connections';

    public function capabilities(): array {
        return array(
            'exact_orcid_author_lookup'=>true,
            'author_read'=>true,
            'works_read'=>true,
            'metrics_read'=>true,
            'doi_reconciliation_preview'=>true,
            'name_matching'=>false,
            'external_write'=>false,
        );
    }

    public function configuration(): array {
        $key = $this->api_key();
        return array(
            'configured'=>true,
            'public_api'=>true,
            'api_key_required'=>false,
            'api_key_set'=>'' !== $key,
            'secrets_exposed'=>false,
            'external_write'=>false,
        );
    }

    public function normalize_orcid(string $orcid): string {
        $orcid = trim(rawurldecode($orcid));
        $orcid = preg_replace('#^https?://(?:www\.)?orcid\.org/#i', '', $orcid) ?? $orcid;
        $orcid = preg_replace('/^orcid:\s*/i', '', $orcid) ?? $orcid;
        $orcid = strtoupper(trim($orcid));
        if (1 !== preg_match('/^\d{4}-\d{4}-\d{4}-[\dX]{4}$/', $orcid)) { return ''; }
        return $this->valid_orcid_checksum($orcid) ? $orcid : '';
    }

    public function normalize_author_id(string $author_id): string {
        $author_id = trim(rawurldecode($author_id));
        $author_id = preg_replace('#^https?://(?:www\.)?openalex\.org/#i', '', $author_id) ?? $author_id;
        $author_id = strtoupper(trim($author_id));
        return 1 === preg_match('/^A\d{4,20}$/', $author_id) ? $author_id : '';
    }

    public function resolve_author_by_orcid(string $orcid): array|WP_Error {
        $orcid = $this->normalize_orcid($orcid);
        if ('' === $orcid) {
            return new WP_Error('research_manager_openalex_invalid_orcid', 'A valid exact ORCID iD is required for OpenAlex author resolution.');
        }

        $response = $this->api_get('/authors', array(
            'filter'=>'orcid:' . $orcid,
            'per_page'=>2,
        ));
        if (is_wp_error($response)) { return $response; }
        $results = is_array($response['results'] ?? null) ? $response['results'] : array();
        if (0 === count($results)) {
            return $this->connection_error('research_manager_openalex_author_not_found', 'OpenAlex did not return an author for the exact ORCID iD.');
        }
        if (1 !== count($results) || ! is_array($results[0])) {
            return $this->connection_error('research_manager_openalex_author_ambiguous', 'OpenAlex returned multiple author records for the exact ORCID iD; automatic identity selection is blocked.');
        }

        $author = $this->normalize_author($results[0]);
        if ('' === (string) ($author['openalex_id'] ?? '') || ! $this->author_contains_orcid($author, $orcid)) {
            return $this->connection_error('research_manager_openalex_orcid_mismatch', 'OpenAlex author response did not contain the requested ORCID iD.');
        }
        $this->update_connection(array(
            'status'=>'available',
            'identifier'=>(string) $author['openalex_id'],
            'identifier_url'=>(string) $author['openalex_url'],
            'last_success_at'=>gmdate(DATE_W3C),
            'last_error'=>'',
        ));

        return array(
            'provider'=>'openalex',
            'status'=>'read',
            'orcid'=>$orcid,
            'author'=>$author,
            'fetched_at'=>gmdate(DATE_W3C),
            'external_write'=>false,
        );
    }

    public function works_by_author(string $author_id, int $per_page = 100): array|WP_Error {
        $author_id = $this->normalize_author_id($author_id);
        if ('' === $author_id) {
            return new WP_Error('research_manager_openalex_invalid_author_id', 'A valid exact OpenAlex author ID is required.');
        }
        $per_page = max(1, min(100, $per_page));
        $response = $this->api_get('/works', array(
            'filter'=>'author.id:' . $author_id,
            'sort'=>'-publication_date',
            'per_page'=>$per_page,
        ));
        if (is_wp_error($response)) { return $response; }
        $results = is_array($response['results'] ?? null) ? $response['results'] : array();
        $works = array();
        foreach ($results as $work) {
            if (! is_array($work)) { continue; }
            $normalized = $this->normalize_work($work);
            if ('' !== (string) ($normalized['openalex_id'] ?? '')) { $works[] = $normalized; }
        }
        $this->update_connection(array('status'=>'available','last_success_at'=>gmdate(DATE_W3C),'last_error'=>''));
        return array(
            'provider'=>'openalex',
            'status'=>'read',
            'author_id'=>$author_id,
            'works'=>$works,
            'work_count'=>count($works),
            'fetched_at'=>gmdate(DATE_W3C),
            'external_write'=>false,
        );
    }

    public function preview_from_verified_orcid(int $per_page = 100): array|WP_Error {
        $orcid_status = Eduardo_Research_Manager::connections()->status('orcid');
        if (is_wp_error($orcid_status)) { return $orcid_status; }
        $orcid = $this->normalize_orcid((string) ($orcid_status['identifier'] ?? ''));
        if ('' === $orcid) {
            return new WP_Error('research_manager_openalex_verified_orcid_required', 'A verified or authenticated ORCID iD is required before OpenAlex identity reconciliation.');
        }

        $author_result = $this->resolve_author_by_orcid($orcid);
        if (is_wp_error($author_result)) { return $author_result; }
        $author = is_array($author_result['author'] ?? null) ? $author_result['author'] : array();
        $works_result = $this->works_by_author((string) ($author['openalex_id'] ?? ''), $per_page);
        if (is_wp_error($works_result)) { return $works_result; }
        $works = is_array($works_result['works'] ?? null) ? $works_result['works'] : array();

        $local = $this->local_outputs_by_doi();
        if (is_wp_error($local)) { return $local; }
        $matches = array();
        $remote_without_local = array();
        foreach ($works as $work) {
            $doi = Eduardo_Research_Manager::crossref()->normalize_doi((string) ($work['doi'] ?? ''));
            if ('' !== $doi && isset($local[$doi])) {
                $matches[] = array(
                    'doi'=>$doi,
                    'remote'=>$work,
                    'local'=>$local[$doi],
                    'title_matches'=>$this->normalized_text((string) ($work['title'] ?? '')) === $this->normalized_text((string) ($local[$doi]['title'] ?? '')),
                );
            } else {
                $remote_without_local[] = $work;
            }
        }

        return array(
            'provider'=>'openalex',
            'status'=>'preview',
            'identity_basis'=>'exact_verified_orcid',
            'orcid'=>$orcid,
            'author'=>$author,
            'remote_works'=>$works,
            'remote_work_count'=>count($works),
            'local_doi_matches'=>$matches,
            'local_doi_match_count'=>count($matches),
            'remote_without_local'=>$remote_without_local,
            'requires_reconciliation'=>true,
            'automatic_local_apply'=>false,
            'name_matching'=>false,
            'external_write'=>false,
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    private function api_get(string $path, array $query = array()): array|WP_Error {
        $url = untrailingslashit(self::API_BASE) . '/' . ltrim($path, '/');
        if ($query) { $url = add_query_arg($query, $url); }
        $headers = array(
            'Accept'=>'application/json',
            'User-Agent'=>'EduardoResearchManager/' . EDUARDO_RESEARCH_MANAGER_VERSION . ' (' . home_url('/') . ')',
        );
        $key = $this->api_key();
        if ('' !== $key) { $headers['Authorization'] = 'Bearer ' . $key; }

        $this->update_connection(array('last_sync_at'=>gmdate(DATE_W3C)));
        $response = wp_remote_get($url, array('timeout'=>15,'headers'=>$headers));
        if (is_wp_error($response)) {
            return $this->connection_error('research_manager_openalex_request_failed', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || ! is_array($payload)) {
            return $this->connection_error('research_manager_openalex_response_invalid', 'OpenAlex returned an invalid API response.');
        }
        return $payload;
    }

    private function normalize_author(array $author): array {
        $id = $this->normalize_author_id($this->scalar($author['id'] ?? ''));
        $primary_orcid = $this->normalize_orcid($this->scalar($author['orcid'] ?? ''));
        $observed = array();
        foreach ((array) ($author['observed_orcids'] ?? array()) as $candidate) {
            $candidate = $this->normalize_orcid($this->scalar($candidate));
            if ('' !== $candidate) { $observed[] = $candidate; }
        }
        if ('' !== $primary_orcid) { array_unshift($observed, $primary_orcid); }
        $observed = array_values(array_unique($observed));

        $institutions = array();
        foreach ((array) ($author['last_known_institutions'] ?? array()) as $institution) {
            if (! is_array($institution)) { continue; }
            $name = sanitize_text_field($this->scalar($institution['display_name'] ?? ''));
            if ('' !== $name) {
                $institutions[] = array(
                    'name'=>$name,
                    'ror'=>esc_url_raw($this->scalar($institution['ror'] ?? '')),
                    'country_code'=>sanitize_key($this->scalar($institution['country_code'] ?? '')),
                    'type'=>sanitize_key($this->scalar($institution['type'] ?? '')),
                );
            }
        }
        $stats = is_array($author['summary_stats'] ?? null) ? $author['summary_stats'] : array();
        return array(
            'openalex_id'=>$id,
            'openalex_url'=>'' !== $id ? 'https://openalex.org/' . rawurlencode($id) : '',
            'display_name'=>sanitize_text_field($this->scalar($author['display_name'] ?? $author['full_name'] ?? '')),
            'orcid'=>$primary_orcid,
            'observed_orcids'=>$observed,
            'works_count'=>absint($author['works_count'] ?? 0),
            'cited_by_count'=>absint($author['cited_by_count'] ?? 0),
            'h_index'=>absint($stats['h_index'] ?? 0),
            'i10_index'=>absint($stats['i10_index'] ?? 0),
            'two_year_mean_citedness'=>is_numeric($stats['2yr_mean_citedness'] ?? null) ? (float) $stats['2yr_mean_citedness'] : 0.0,
            'last_known_institutions'=>$institutions,
            'updated_date'=>sanitize_text_field($this->scalar($author['updated_date'] ?? '')),
        );
    }

    private function normalize_work(array $work): array {
        $id = $this->normalize_work_id($this->scalar($work['id'] ?? ''));
        $doi = Eduardo_Research_Manager::crossref()->normalize_doi($this->scalar($work['doi'] ?? ''));
        $source = is_array($work['primary_location']['source'] ?? null) ? $work['primary_location']['source'] : array();
        $oa = is_array($work['open_access'] ?? null) ? $work['open_access'] : array();
        $authors = array();
        foreach ((array) ($work['authorships'] ?? array()) as $authorship) {
            if (! is_array($authorship)) { continue; }
            $author = is_array($authorship['author'] ?? null) ? $authorship['author'] : array();
            $name = sanitize_text_field($this->scalar($author['display_name'] ?? ''));
            if ('' !== $name) { $authors[] = $name; }
        }
        return array(
            'openalex_id'=>$id,
            'openalex_url'=>'' !== $id ? 'https://openalex.org/' . rawurlencode($id) : '',
            'doi'=>$doi,
            'title'=>sanitize_text_field($this->scalar($work['title'] ?? $work['display_name'] ?? '')),
            'publication_date'=>sanitize_text_field($this->scalar($work['publication_date'] ?? '')),
            'publication_year'=>absint($work['publication_year'] ?? 0),
            'type'=>sanitize_key(str_replace('_', '-', strtolower($this->scalar($work['type'] ?? '')))),
            'venue'=>sanitize_text_field($this->scalar($source['display_name'] ?? '')),
            'authors'=>array_values(array_unique($authors)),
            'cited_by_count'=>absint($work['cited_by_count'] ?? 0),
            'is_oa'=>! empty($oa['is_oa']),
            'oa_status'=>sanitize_key($this->scalar($oa['oa_status'] ?? '')),
        );
    }

    private function normalize_work_id(string $work_id): string {
        $work_id = trim(rawurldecode($work_id));
        $work_id = preg_replace('#^https?://(?:www\.)?openalex\.org/#i', '', $work_id) ?? $work_id;
        $work_id = strtoupper(trim($work_id));
        return 1 === preg_match('/^W\d{4,20}$/', $work_id) ? $work_id : '';
    }

    private function local_outputs_by_doi(): array|WP_Error {
        $map = array();
        foreach (Eduardo_Research_Manager::contract()->languages() as $language) {
            $rows = Eduardo_Research_Manager::object_editor()->list('output', (string) $language);
            if (is_wp_error($rows)) { return $rows; }
            foreach ($rows as $row) {
                if (! is_array($row)) { continue; }
                $doi = Eduardo_Research_Manager::crossref()->normalize_doi((string) ($row['doi'] ?? ''));
                if ('' !== $doi && ! isset($map[$doi])) { $map[$doi] = $row; }
            }
        }
        return $map;
    }

    private function author_contains_orcid(array $author, string $orcid): bool {
        if ($orcid === (string) ($author['orcid'] ?? '')) { return true; }
        return in_array($orcid, (array) ($author['observed_orcids'] ?? array()), true);
    }

    private function valid_orcid_checksum(string $orcid): bool {
        $digits = str_replace('-', '', $orcid);
        if (16 !== strlen($digits)) { return false; }
        $total = 0;
        for ($i = 0; $i < 15; $i++) {
            if (! ctype_digit($digits[$i])) { return false; }
            $total = ($total + (int) $digits[$i]) * 2;
        }
        $remainder = $total % 11;
        $result = (12 - $remainder) % 11;
        $check = 10 === $result ? 'X' : (string) $result;
        return $check === $digits[15];
    }

    private function api_key(): string {
        if (! defined('EDUARDO_RESEARCH_OPENALEX_API_KEY') || ! is_scalar(constant('EDUARDO_RESEARCH_OPENALEX_API_KEY'))) { return ''; }
        return trim((string) constant('EDUARDO_RESEARCH_OPENALEX_API_KEY'));
    }

    private function update_connection(array $changes): void {
        $store = get_option(self::CONNECTIONS_OPTION, array());
        $store = is_array($store) ? $store : array();
        $current = is_array($store['openalex'] ?? null) ? $store['openalex'] : array();
        $store['openalex'] = array_merge($current, $changes);
        update_option(self::CONNECTIONS_OPTION, $store, false);
    }

    private function connection_error(string $code, string $message): WP_Error {
        $this->update_connection(array('status'=>'error','last_error'=>sanitize_text_field($message)));
        return new WP_Error($code, $message);
    }

    private function normalized_text(string $value): string {
        $value = remove_accents(wp_strip_all_tags($value));
        $value = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
        return $value;
    }

    private function scalar(mixed $value): string {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
