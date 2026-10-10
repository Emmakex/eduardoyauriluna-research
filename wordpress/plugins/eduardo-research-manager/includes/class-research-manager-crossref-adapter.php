<?php
/** Exact-DOI Crossref read adapter. No name matching and no external writes. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Crossref_Adapter {
    private const API_BASE = 'https://api.crossref.org/works/';
    private const CONNECTIONS_OPTION = 'eduardo_research_connections';

    public function capabilities(): array {
        return array(
            'exact_doi_lookup'=>true,
            'metadata_read'=>true,
            'output_reconciliation_preview'=>true,
            'name_matching'=>false,
            'external_write'=>false,
        );
    }

    public function configuration(): array {
        $mailto = defined('EDUARDO_RESEARCH_CROSSREF_MAILTO') && is_scalar(constant('EDUARDO_RESEARCH_CROSSREF_MAILTO'))
            ? sanitize_email((string) constant('EDUARDO_RESEARCH_CROSSREF_MAILTO'))
            : '';
        return array(
            'configured'=>true,
            'public_api'=>true,
            'mailto_set'=>'' !== $mailto,
            'secrets_required'=>false,
            'external_write'=>false,
        );
    }

    public function normalize_doi(string $doi): string {
        $doi = trim(rawurldecode($doi));
        $doi = preg_replace('#^https?://(?:dx\.)?doi\.org/#i', '', $doi) ?? $doi;
        $doi = preg_replace('/^doi:\s*/i', '', $doi) ?? $doi;
        $doi = trim($doi);
        if ('' === $doi || strlen($doi) > 255 || preg_match('/\s/', $doi)) { return ''; }
        if (1 !== preg_match('/^10\.\d{4,9}\/\S+$/i', $doi)) { return ''; }
        return strtolower($doi);
    }

    public function lookup(string $doi): array|WP_Error {
        $doi = $this->normalize_doi($doi);
        if ('' === $doi) {
            return new WP_Error('research_manager_crossref_invalid_doi', 'A valid DOI is required for Crossref lookup.');
        }
        $url = self::API_BASE . rawurlencode($doi);
        $headers = array('Accept'=>'application/json');
        $mailto = defined('EDUARDO_RESEARCH_CROSSREF_MAILTO') && is_scalar(constant('EDUARDO_RESEARCH_CROSSREF_MAILTO'))
            ? sanitize_email((string) constant('EDUARDO_RESEARCH_CROSSREF_MAILTO'))
            : '';
        $user_agent = 'EduardoResearchManager/' . EDUARDO_RESEARCH_MANAGER_VERSION . ' (' . home_url('/') . ')';
        if ('' !== $mailto) { $user_agent .= ' mailto:' . $mailto; }
        $headers['User-Agent'] = $user_agent;

        $this->update_connection(array('last_sync_at'=>gmdate(DATE_W3C)));
        $response = wp_remote_get($url, array('timeout'=>15,'headers'=>$headers));
        if (is_wp_error($response)) {
            return $this->connection_error('research_manager_crossref_request_failed', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (404 === $status) {
            return $this->connection_error('research_manager_crossref_not_found', 'Crossref did not find metadata for this DOI.');
        }
        if ($status < 200 || $status >= 300 || ! is_array($body) || ! is_array($body['message'] ?? null)) {
            return $this->connection_error('research_manager_crossref_response_invalid', 'Crossref returned an invalid metadata response.');
        }

        $metadata = $this->normalize_message($body['message']);
        if ($doi !== $metadata['doi']) {
            return $this->connection_error('research_manager_crossref_doi_mismatch', 'Crossref response DOI did not match the requested DOI.');
        }
        $this->update_connection(array('status'=>'available','last_success_at'=>gmdate(DATE_W3C),'last_error'=>''));
        return array(
            'provider'=>'crossref',
            'status'=>'read',
            'doi'=>$doi,
            'metadata'=>$metadata,
            'raw'=>$body['message'],
            'fetched_at'=>gmdate(DATE_W3C),
            'external_write'=>false,
        );
    }

    public function preview_for_output(int $post_id, string $doi = ''): array|WP_Error {
        $local = Eduardo_Research_Manager::outputs()->inspect($post_id);
        if (is_wp_error($local)) { return $local; }
        $requested = '' !== trim($doi) ? $this->normalize_doi($doi) : $this->normalize_doi((string) ($local['doi'] ?? ''));
        if ('' === $requested) {
            return new WP_Error('research_manager_crossref_output_doi_missing', 'The Research Output needs an exact DOI before Crossref reconciliation can be previewed.');
        }
        $remote = $this->lookup($requested);
        if (is_wp_error($remote)) { return $remote; }
        $metadata = is_array($remote['metadata'] ?? null) ? $remote['metadata'] : array();

        $fields = array(
            'title'=>(string) ($metadata['title'] ?? ''),
            'doi'=>(string) ($metadata['doi'] ?? ''),
            'authors'=>is_array($metadata['authors'] ?? null) ? $metadata['authors'] : array(),
            'publication_date'=>(string) ($metadata['publication_date'] ?? ''),
            'venue'=>(string) ($metadata['venue'] ?? ''),
        );
        $comparison = array();
        foreach ($fields as $field => $remote_value) {
            $local_value = $local[$field] ?? (is_array($remote_value) ? array() : '');
            $comparison[$field] = array(
                'local'=>$local_value,
                'remote'=>$remote_value,
                'matches'=>maybe_serialize($local_value) === maybe_serialize($remote_value),
                'candidate'=>! empty($remote_value),
            );
        }

        return array(
            'provider'=>'crossref',
            'status'=>'preview',
            'post_id'=>$post_id,
            'doi'=>$requested,
            'local'=>$local,
            'remote'=>$metadata,
            'comparison'=>$comparison,
            'has_differences'=>! $this->comparison_matches($comparison),
            'requires_reconciliation'=>true,
            'automatic_local_apply'=>false,
            'external_write'=>false,
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    private function normalize_message(array $message): array {
        $doi = $this->normalize_doi($this->scalar($message['DOI'] ?? ''));
        $title = $this->first_string($message['title'] ?? array());
        $subtitle = $this->first_string($message['subtitle'] ?? array());
        $venue = $this->first_string($message['container-title'] ?? array());
        $authors = array();
        foreach ((array) ($message['author'] ?? array()) as $author) {
            if (! is_array($author)) { continue; }
            $name = trim($this->scalar($author['given'] ?? '') . ' ' . $this->scalar($author['family'] ?? ''));
            if ('' !== $name) { $authors[] = $name; }
        }
        $authors = array_values(array_unique($authors));
        return array(
            'doi'=>$doi,
            'title'=>sanitize_text_field($title),
            'subtitle'=>sanitize_text_field($subtitle),
            'authors'=>$authors,
            'publication_date'=>$this->publication_date($message),
            'venue'=>sanitize_text_field($venue),
            'publisher'=>sanitize_text_field($this->scalar($message['publisher'] ?? '')),
            'crossref_type'=>sanitize_key(str_replace('_', '-', strtolower($this->scalar($message['type'] ?? '')))),
            'url'=>esc_url_raw($this->scalar($message['URL'] ?? '')),
            'reference_count'=>absint($message['reference-count'] ?? 0),
            'is_referenced_by_count'=>absint($message['is-referenced-by-count'] ?? 0),
        );
    }

    private function publication_date(array $message): string {
        foreach (array('published-print','published-online','published','issued','created') as $field) {
            $container = is_array($message[$field] ?? null) ? $message[$field] : array();
            $parts = is_array($container['date-parts'][0] ?? null) ? $container['date-parts'][0] : array();
            if (! $parts) { continue; }
            $year = absint($parts[0] ?? 0);
            if ($year <= 0) { continue; }
            $date = (string) $year;
            $month = absint($parts[1] ?? 0);
            $day = absint($parts[2] ?? 0);
            if ($month >= 1 && $month <= 12) { $date .= '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT); }
            if ($day >= 1 && $day <= 31 && $month >= 1 && $month <= 12) { $date .= '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT); }
            return $date;
        }
        return '';
    }

    private function first_string(mixed $value): string {
        if (is_array($value)) {
            foreach ($value as $item) {
                $item = $this->scalar($item);
                if ('' !== $item) { return $item; }
            }
            return '';
        }
        return $this->scalar($value);
    }

    private function comparison_matches(array $comparison): bool {
        foreach ($comparison as $field) {
            if (is_array($field) && empty($field['matches']) && ! empty($field['candidate'])) { return false; }
        }
        return true;
    }

    private function update_connection(array $changes): void {
        $store = get_option(self::CONNECTIONS_OPTION, array());
        $store = is_array($store) ? $store : array();
        $current = is_array($store['crossref'] ?? null) ? $store['crossref'] : array();
        $store['crossref'] = array_merge($current, $changes);
        update_option(self::CONNECTIONS_OPTION, $store, false);
    }

    private function connection_error(string $code, string $message): WP_Error {
        $this->update_connection(array('status'=>'error','last_error'=>sanitize_text_field($message)));
        return new WP_Error($code, $message);
    }

    private function scalar(mixed $value): string {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
