<?php
/** Explicitly-selected GitHub repository read adapter. Never scans an account or writes externally. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_GitHub_Adapter {
    private const API_BASE = 'https://api.github.com/repos/';
    private const CONNECTIONS_OPTION = 'eduardo_research_connections';

    public function capabilities(): array {
        return array(
            'explicit_repository_selection'=>true,
            'exact_repository_read'=>true,
            'repository_metadata_preview'=>true,
            'latest_release_read'=>true,
            'citation_cff_detection'=>true,
            'research_software_reconciliation'=>true,
            'account_repository_listing'=>false,
            'account_scan'=>false,
            'automatic_local_apply'=>false,
            'external_write'=>false,
        );
    }

    public function configuration(): array {
        $token = $this->token();
        return array(
            'configured'=>true,
            'public_api'=>true,
            'optional_token_set'=>'' !== $token,
            'secrets_exposed'=>false,
            'account_repository_listing'=>false,
            'external_write'=>false,
        );
    }

    /** @return array{owner:string,repo:string,full_name:string,html_url:string}|array{} */
    public function normalize_repository(string $selected): array {
        $selected = trim($selected);
        if ('' === $selected || strlen($selected) > 300) { return array(); }

        $owner = '';
        $repo = '';
        if (preg_match('#^https?://github\.com/([^/]+)/([^/?#]+?)(?:\.git)?/?$#i', $selected, $match)) {
            $owner = rawurldecode((string) $match[1]);
            $repo = rawurldecode((string) $match[2]);
        } elseif (preg_match('#^([^/\s]+)/([^/\s]+)$#', $selected, $match)) {
            $owner = (string) $match[1];
            $repo = (string) $match[2];
            if (str_ends_with(strtolower($repo), '.git')) { $repo = substr($repo, 0, -4); }
        }

        $owner = trim($owner);
        $repo = trim($repo);
        if ('' === $owner || '' === $repo) { return array(); }
        if (1 !== preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/', $owner)) { return array(); }
        if (1 !== preg_match('/^[A-Za-z0-9._-]{1,100}$/', $repo) || '.' === $repo || '..' === $repo) { return array(); }

        return array(
            'owner'=>$owner,
            'repo'=>$repo,
            'full_name'=>$owner . '/' . $repo,
            'html_url'=>'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($repo),
        );
    }

    public function repository(string $selected): array|WP_Error {
        $identity = $this->normalize_repository($selected);
        if (! $identity) {
            return new WP_Error('research_manager_github_repository_invalid', 'Select one exact GitHub repository URL or owner/repository slug.');
        }

        $this->update_connection(array('last_sync_at'=>gmdate(DATE_W3C)));
        $response = $this->get(self::API_BASE . rawurlencode($identity['owner']) . '/' . rawurlencode($identity['repo']));
        if (is_wp_error($response)) { return $response; }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (404 === $status) {
            return $this->connection_error('research_manager_github_repository_not_found', 'GitHub did not find the explicitly selected repository.');
        }
        if ($status < 200 || $status >= 300 || ! is_array($body)) {
            return $this->connection_error('research_manager_github_repository_response_invalid', 'GitHub returned an invalid repository response.');
        }

        $remote_full_name = $this->scalar($body['full_name'] ?? '');
        if ('' === $remote_full_name || strtolower($remote_full_name) !== strtolower($identity['full_name'])) {
            return $this->connection_error('research_manager_github_repository_mismatch', 'GitHub response did not match the explicitly selected repository.');
        }

        $repository = $this->normalize_repository_response($body);
        $release = $this->latest_release($repository['full_name']);
        if (is_wp_error($release)) { return $release; }
        $citation = $this->citation_cff($repository['full_name'], (string) $repository['default_branch']);
        if (is_wp_error($citation)) { return $citation; }

        $this->update_connection(array('last_success_at'=>gmdate(DATE_W3C),'last_error'=>''));
        return array(
            'provider'=>'github',
            'status'=>'read',
            'selection_basis'=>'explicit_exact_repository',
            'selected_repository'=>$identity,
            'repository'=>$repository,
            'latest_release'=>$release,
            'citation_cff'=>$citation,
            'account_repository_listing'=>false,
            'automatic_local_apply'=>false,
            'external_write'=>false,
            'fetched_at'=>gmdate(DATE_W3C),
        );
    }

    public function selected_repository_preview(string $selected): array|WP_Error {
        $remote = $this->repository($selected);
        if (is_wp_error($remote)) { return $remote; }
        $repository = is_array($remote['repository'] ?? null) ? $remote['repository'] : array();
        $release = is_array($remote['latest_release'] ?? null) ? $remote['latest_release'] : array();
        $citation = is_array($remote['citation_cff'] ?? null) ? $remote['citation_cff'] : array();
        $local = $this->local_software_match((string) ($repository['full_name'] ?? ''));

        $candidate = array(
            'title'=>(string) ($repository['name'] ?? ''),
            'excerpt'=>(string) ($repository['description'] ?? ''),
            'repository_url'=>(string) ($repository['html_url'] ?? ''),
            'version'=>(string) ($release['tag_name'] ?? ''),
            'release_date'=>$this->date_only((string) ($release['published_at'] ?? '')),
            'license'=>(string) ($repository['license_spdx'] ?? ''),
            'programming_languages'=>'' !== (string) ($repository['language'] ?? '') ? array((string) $repository['language']) : array(),
        );

        $comparison = array();
        if ($local) {
            foreach ($candidate as $field => $remote_value) {
                $local_value = $local[$field] ?? (is_array($remote_value) ? array() : '');
                $comparison[$field] = array(
                    'local'=>$local_value,
                    'remote'=>$remote_value,
                    'matches'=>maybe_serialize($local_value) === maybe_serialize($remote_value),
                    'candidate'=>! empty($remote_value),
                );
            }
        }

        return array(
            'provider'=>'github',
            'status'=>'preview',
            'selection_basis'=>'explicit_exact_repository',
            'selected_repository'=>(string) ($repository['full_name'] ?? ''),
            'remote'=>$repository,
            'latest_release'=>$release,
            'citation_cff'=>$citation,
            'research_software_candidate'=>$candidate,
            'local_software'=>$local,
            'comparison'=>$comparison,
            'has_local_match'=>! empty($local),
            'requires_reconciliation'=>! empty($local),
            'account_repository_listing'=>false,
            'non_selected_repository_discovery'=>false,
            'automatic_publication'=>false,
            'automatic_local_apply'=>false,
            'external_write'=>false,
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    private function latest_release(string $full_name): array|WP_Error {
        $response = $this->get(self::API_BASE . $this->api_repository_path($full_name) . '/releases/latest');
        if (is_wp_error($response)) { return $response; }
        $status = (int) wp_remote_retrieve_response_code($response);
        if (404 === $status) { return array(); }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || ! is_array($body)) {
            return $this->connection_error('research_manager_github_release_response_invalid', 'GitHub returned an invalid latest-release response for the selected repository.');
        }
        return array(
            'tag_name'=>sanitize_text_field($this->scalar($body['tag_name'] ?? '')),
            'name'=>sanitize_text_field($this->scalar($body['name'] ?? '')),
            'published_at'=>sanitize_text_field($this->scalar($body['published_at'] ?? '')),
            'html_url'=>esc_url_raw($this->scalar($body['html_url'] ?? '')),
            'prerelease'=>! empty($body['prerelease']),
            'draft'=>! empty($body['draft']),
        );
    }

    private function citation_cff(string $full_name, string $default_branch): array|WP_Error {
        if ('' === $default_branch) { return array('present'=>false); }
        $url = self::API_BASE . $this->api_repository_path($full_name) . '/contents/CITATION.cff?ref=' . rawurlencode($default_branch);
        $response = $this->get($url);
        if (is_wp_error($response)) { return $response; }
        $status = (int) wp_remote_retrieve_response_code($response);
        if (404 === $status) { return array('present'=>false); }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || ! is_array($body) || 'file' !== $this->scalar($body['type'] ?? '')) {
            return $this->connection_error('research_manager_github_citation_response_invalid', 'GitHub returned an invalid CITATION.cff response for the selected repository.');
        }
        return array(
            'present'=>true,
            'path'=>'CITATION.cff',
            'sha'=>sanitize_text_field($this->scalar($body['sha'] ?? '')),
            'size'=>absint($body['size'] ?? 0),
            'html_url'=>esc_url_raw($this->scalar($body['html_url'] ?? '')),
            'download_url'=>esc_url_raw($this->scalar($body['download_url'] ?? '')),
        );
    }

    private function normalize_repository_response(array $body): array {
        $license = is_array($body['license'] ?? null) ? $body['license'] : array();
        $topics = array_values(array_filter(array_map(
            static fn($topic): string => is_scalar($topic) ? sanitize_key((string) $topic) : '',
            is_array($body['topics'] ?? null) ? $body['topics'] : array()
        )));
        return array(
            'id'=>absint($body['id'] ?? 0),
            'full_name'=>sanitize_text_field($this->scalar($body['full_name'] ?? '')),
            'name'=>sanitize_text_field($this->scalar($body['name'] ?? '')),
            'owner'=>sanitize_text_field($this->scalar($body['owner']['login'] ?? '')),
            'description'=>sanitize_text_field($this->scalar($body['description'] ?? '')),
            'html_url'=>esc_url_raw($this->scalar($body['html_url'] ?? '')),
            'homepage'=>esc_url_raw($this->scalar($body['homepage'] ?? '')),
            'default_branch'=>sanitize_text_field($this->scalar($body['default_branch'] ?? '')),
            'language'=>sanitize_text_field($this->scalar($body['language'] ?? '')),
            'topics'=>$topics,
            'license_spdx'=>sanitize_text_field($this->scalar($license['spdx_id'] ?? '')),
            'license_name'=>sanitize_text_field($this->scalar($license['name'] ?? '')),
            'archived'=>! empty($body['archived']),
            'fork'=>! empty($body['fork']),
            'visibility'=>sanitize_key($this->scalar($body['visibility'] ?? '')),
            'stargazers_count'=>absint($body['stargazers_count'] ?? 0),
            'forks_count'=>absint($body['forks_count'] ?? 0),
            'open_issues_count'=>absint($body['open_issues_count'] ?? 0),
            'created_at'=>sanitize_text_field($this->scalar($body['created_at'] ?? '')),
            'updated_at'=>sanitize_text_field($this->scalar($body['updated_at'] ?? '')),
            'pushed_at'=>sanitize_text_field($this->scalar($body['pushed_at'] ?? '')),
        );
    }

    private function local_software_match(string $full_name): array {
        $identity = $this->normalize_repository($full_name);
        if (! $identity) { return array(); }
        $target = strtolower($identity['full_name']);
        $posts = get_posts(array(
            'post_type'=>'research_software','post_status'=>'any','posts_per_page'=>250,
            'orderby'=>'ID','order'=>'ASC','suppress_filters'=>true,
        ));
        foreach ($posts as $post) {
            if (! $post instanceof WP_Post) { continue; }
            $record = Eduardo_Research_Manager::software()->inspect((int) $post->ID);
            if (is_wp_error($record)) { continue; }
            $local_identity = $this->normalize_repository((string) ($record['repository_url'] ?? ''));
            if ($local_identity && strtolower($local_identity['full_name']) === $target) { return $record; }
        }
        return array();
    }

    private function get(string $url): array|WP_Error {
        $headers = array(
            'Accept'=>'application/vnd.github+json',
            'X-GitHub-Api-Version'=>'2022-11-28',
            'User-Agent'=>'EduardoResearchManager/' . EDUARDO_RESEARCH_MANAGER_VERSION,
        );
        $token = $this->token();
        if ('' !== $token) { $headers['Authorization'] = 'Bearer ' . $token; }
        $response = wp_remote_get($url, array('timeout'=>15,'headers'=>$headers));
        if (is_wp_error($response)) {
            return $this->connection_error('research_manager_github_request_failed', $response->get_error_message());
        }
        return $response;
    }

    private function token(): string {
        return defined('EDUARDO_RESEARCH_GITHUB_TOKEN') && is_scalar(constant('EDUARDO_RESEARCH_GITHUB_TOKEN'))
            ? trim((string) constant('EDUARDO_RESEARCH_GITHUB_TOKEN'))
            : '';
    }

    private function api_repository_path(string $full_name): string {
        $identity = $this->normalize_repository($full_name);
        return $identity ? rawurlencode($identity['owner']) . '/' . rawurlencode($identity['repo']) : '';
    }

    private function date_only(string $value): string {
        $value = trim($value);
        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : '';
    }

    private function update_connection(array $changes): void {
        $store = get_option(self::CONNECTIONS_OPTION, array());
        $store = is_array($store) ? $store : array();
        $current = is_array($store['github'] ?? null) ? $store['github'] : array();
        $store['github'] = array_merge($current, $changes);
        update_option(self::CONNECTIONS_OPTION, $store, false);
    }

    private function connection_error(string $code, string $message): WP_Error {
        $this->update_connection(array('last_error'=>sanitize_text_field($message)));
        return new WP_Error($code, $message);
    }

    private function scalar(mixed $value): string {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
