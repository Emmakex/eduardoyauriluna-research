<?php
/** Secure ORCID OAuth + read-only profile/works adapter. External writes are intentionally unsupported. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Orcid_Adapter {
    private const TOKEN_OPTION = 'eduardo_research_orcid_token';
    private const CONNECTIONS_OPTION = 'eduardo_research_connections';
    private const STATE_PREFIX = 'eduardo_research_orcid_state_';
    private const STATE_TTL = 600;

    public function capabilities(): array {
        return array(
            'oauth'=>true,
            'authenticated_identifier'=>true,
            'profile_read'=>true,
            'works_read'=>true,
            'reconcile_preview'=>true,
            'external_write'=>false,
        );
    }

    public function environment(): array {
        $environment = defined('EDUARDO_RESEARCH_ORCID_ENVIRONMENT')
            ? sanitize_key((string) constant('EDUARDO_RESEARCH_ORCID_ENVIRONMENT'))
            : 'sandbox';
        if (! in_array($environment, array('sandbox','production'), true)) { $environment = 'sandbox'; }

        if ('production' === $environment) {
            return array(
                'id'=>'production',
                'authorize'=>'https://orcid.org/oauth/authorize',
                'token'=>'https://orcid.org/oauth/token',
                'api'=>'https://pub.orcid.org/v3.0',
            );
        }
        return array(
            'id'=>'sandbox',
            'authorize'=>'https://sandbox.orcid.org/oauth/authorize',
            'token'=>'https://sandbox.orcid.org/oauth/token',
            'api'=>'https://pub.sandbox.orcid.org/v3.0',
        );
    }

    public function configuration(): array {
        $keys = array(
            'client_id'=>'EDUARDO_RESEARCH_ORCID_CLIENT_ID',
            'client_secret'=>'EDUARDO_RESEARCH_ORCID_CLIENT_SECRET',
            'redirect_uri'=>'EDUARDO_RESEARCH_ORCID_REDIRECT_URI',
        );
        $values = array();
        $missing = array();
        foreach ($keys as $field => $constant) {
            $value = defined($constant) && is_scalar(constant($constant)) ? trim((string) constant($constant)) : '';
            if ('' === $value) { $missing[] = $constant; }
            $values[$field] = $value;
        }
        return array(
            'configured'=>0 === count($missing),
            'missing'=>$missing,
            'environment'=>$this->environment()['id'],
            'redirect_uri'=>$values['redirect_uri'],
            'client_id'=>$values['client_id'],
            'client_secret_set'=>'' !== $values['client_secret'],
            'secrets_exposed'=>false,
        );
    }

    public function begin_authorization(): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_orcid_forbidden', 'You are not allowed to connect ORCID.');
        }
        $config = $this->configuration();
        if (empty($config['configured'])) {
            return new WP_Error('research_manager_orcid_configuration_missing', 'ORCID OAuth configuration is incomplete.', array('missing'=>$config['missing']));
        }

        try {
            $state = bin2hex(random_bytes(24));
        } catch (Throwable $error) {
            return new WP_Error('research_manager_orcid_state_generation_failed', 'Could not create a secure ORCID OAuth state.');
        }
        set_transient($this->state_key(), array(
            'state'=>$state,
            'redirect_uri'=>(string) $config['redirect_uri'],
            'created_at'=>gmdate(DATE_W3C),
        ), self::STATE_TTL);

        $environment = $this->environment();
        $url = add_query_arg(array(
            'client_id'=>(string) $config['client_id'],
            'response_type'=>'code',
            'scope'=>'/authenticate',
            'redirect_uri'=>(string) $config['redirect_uri'],
            'state'=>$state,
        ), (string) $environment['authorize']);

        return array(
            'provider'=>'orcid',
            'environment'=>$environment['id'],
            'authorization_url'=>$url,
            'expires_in'=>self::STATE_TTL,
            'external_write'=>false,
        );
    }

    public function exchange_code(string $code, string $state): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_orcid_forbidden', 'You are not allowed to complete an ORCID connection.');
        }
        $code = sanitize_text_field($code);
        $state = sanitize_text_field($state);
        if ('' === $code) {
            return new WP_Error('research_manager_orcid_code_missing', 'ORCID authorization code is required.');
        }
        $state_result = $this->consume_state($state);
        if (is_wp_error($state_result)) { return $state_result; }

        $config = $this->configuration();
        if (empty($config['configured'])) {
            return new WP_Error('research_manager_orcid_configuration_missing', 'ORCID OAuth configuration is incomplete.', array('missing'=>$config['missing']));
        }
        $client_secret = defined('EDUARDO_RESEARCH_ORCID_CLIENT_SECRET') ? (string) constant('EDUARDO_RESEARCH_ORCID_CLIENT_SECRET') : '';
        $environment = $this->environment();
        $response = wp_remote_post((string) $environment['token'], array(
            'timeout'=>15,
            'headers'=>array('Accept'=>'application/json'),
            'body'=>array(
                'client_id'=>(string) $config['client_id'],
                'client_secret'=>$client_secret,
                'grant_type'=>'authorization_code',
                'code'=>$code,
                'redirect_uri'=>(string) $config['redirect_uri'],
            ),
        ));
        if (is_wp_error($response)) {
            return $this->connection_error('research_manager_orcid_token_request_failed', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || ! is_array($payload)) {
            return $this->connection_error('research_manager_orcid_token_rejected', 'ORCID rejected the OAuth token exchange.');
        }

        $orcid = $this->normalize_orcid((string) ($payload['orcid'] ?? ''));
        $access_token = is_scalar($payload['access_token'] ?? null) ? trim((string) $payload['access_token']) : '';
        if ('' === $orcid || '' === $access_token) {
            return $this->connection_error('research_manager_orcid_token_invalid', 'ORCID OAuth response did not include a valid authenticated iD and access token.');
        }

        $token = array(
            'access_token'=>$access_token,
            'token_type'=>sanitize_text_field((string) ($payload['token_type'] ?? 'bearer')),
            'refresh_token'=>is_scalar($payload['refresh_token'] ?? null) ? (string) $payload['refresh_token'] : '',
            'scope'=>sanitize_text_field((string) ($payload['scope'] ?? '/authenticate')),
            'orcid'=>$orcid,
            'name'=>sanitize_text_field((string) ($payload['name'] ?? '')),
            'expires_in'=>absint($payload['expires_in'] ?? 0),
            'connected_at'=>gmdate(DATE_W3C),
            'environment'=>(string) $environment['id'],
        );
        update_option(self::TOKEN_OPTION, $token, false);
        $this->update_connection(array(
            'status'=>'connected',
            'mode'=>'oauth',
            'identifier'=>$orcid,
            'identifier_url'=>$this->identifier_url($orcid),
            'last_success_at'=>gmdate(DATE_W3C),
            'last_error'=>'',
        ));

        return array(
            'provider'=>'orcid',
            'status'=>'connected',
            'identifier'=>$orcid,
            'identifier_url'=>$this->identifier_url($orcid),
            'name'=>$token['name'],
            'environment'=>$environment['id'],
            'external_write'=>false,
        );
    }

    public function profile(): array|WP_Error {
        $connection = $this->connected_context();
        if (is_wp_error($connection)) { return $connection; }
        $response = $this->api_get('/' . rawurlencode($connection['orcid']) . '/person', $connection['access_token']);
        if (is_wp_error($response)) { return $response; }
        return array(
            'provider'=>'orcid',
            'identifier'=>$connection['orcid'],
            'fetched_at'=>gmdate(DATE_W3C),
            'profile'=>$this->normalize_profile($response),
            'raw'=>$response,
            'external_write'=>false,
        );
    }

    public function works(): array|WP_Error {
        $connection = $this->connected_context();
        if (is_wp_error($connection)) { return $connection; }
        $response = $this->api_get('/' . rawurlencode($connection['orcid']) . '/works', $connection['access_token']);
        if (is_wp_error($response)) { return $response; }
        return array(
            'provider'=>'orcid',
            'identifier'=>$connection['orcid'],
            'fetched_at'=>gmdate(DATE_W3C),
            'works'=>$this->normalize_works($response),
            'raw'=>$response,
            'external_write'=>false,
        );
    }

    public function sync_preview(): array|WP_Error {
        $profile = $this->profile();
        if (is_wp_error($profile)) { return $profile; }
        $works = $this->works();
        if (is_wp_error($works)) { return $works; }

        $local_identifier = Eduardo_Research_Manager::connections()->status('orcid');
        if (is_wp_error($local_identifier)) { return $local_identifier; }
        return array(
            'provider'=>'orcid',
            'status'=>'preview',
            'identifier'=>(string) ($profile['identifier'] ?? ''),
            'remote_profile'=>$profile['profile'],
            'remote_works'=>$works['works'],
            'remote_work_count'=>count((array) ($works['works'] ?? array())),
            'local_identifier'=>(string) ($local_identifier['identifier'] ?? ''),
            'identifier_matches'=>(string) ($profile['identifier'] ?? '') === (string) ($local_identifier['identifier'] ?? ''),
            'requires_reconciliation'=>true,
            'automatic_local_apply'=>false,
            'external_write'=>false,
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    public function disconnect(): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_orcid_forbidden', 'You are not allowed to disconnect ORCID.');
        }
        $token = get_option(self::TOKEN_OPTION, array());
        $orcid = is_array($token) ? $this->normalize_orcid((string) ($token['orcid'] ?? '')) : '';
        delete_option(self::TOKEN_OPTION);
        delete_transient($this->state_key());
        $this->update_connection(array(
            'status'=>'' !== $orcid ? 'linked' : 'disconnected',
            'mode'=>'oauth',
            'identifier'=>$orcid,
            'identifier_url'=>'' !== $orcid ? $this->identifier_url($orcid) : '',
            'last_sync_at'=>'',
            'last_error'=>'',
        ));
        return array('provider'=>'orcid','status'=>'' !== $orcid ? 'linked' : 'disconnected','identifier'=>$orcid,'token_removed'=>true,'external_write'=>false);
    }

    private function consume_state(string $state): array|WP_Error {
        $stored = get_transient($this->state_key());
        delete_transient($this->state_key());
        if (! is_array($stored) || '' === $state || ! isset($stored['state']) || ! hash_equals((string) $stored['state'], $state)) {
            return new WP_Error('research_manager_orcid_oauth_state_invalid', 'ORCID OAuth state is missing, expired or invalid. Start a new connection.');
        }
        $configured_redirect = (string) ($this->configuration()['redirect_uri'] ?? '');
        if ('' === $configured_redirect || ! hash_equals((string) ($stored['redirect_uri'] ?? ''), $configured_redirect)) {
            return new WP_Error('research_manager_orcid_oauth_redirect_changed', 'ORCID redirect configuration changed after authorization started. Start again.');
        }
        return $stored;
    }

    private function connected_context(): array|WP_Error {
        $token = get_option(self::TOKEN_OPTION, array());
        if (! is_array($token)) { $token = array(); }
        $orcid = $this->normalize_orcid((string) ($token['orcid'] ?? ''));
        $access_token = is_scalar($token['access_token'] ?? null) ? trim((string) $token['access_token']) : '';
        if ('' === $orcid || '' === $access_token) {
            return new WP_Error('research_manager_orcid_not_connected', 'ORCID is not authenticated. Connect ORCID before requesting profile or works.');
        }
        return array('orcid'=>$orcid,'access_token'=>$access_token);
    }

    private function api_get(string $path, string $access_token): array|WP_Error {
        $environment = $this->environment();
        $url = untrailingslashit((string) $environment['api']) . '/' . ltrim($path, '/');
        $this->update_connection(array('last_sync_at'=>gmdate(DATE_W3C)));
        $response = wp_remote_get($url, array(
            'timeout'=>15,
            'headers'=>array(
                'Accept'=>'application/json',
                'Authorization'=>'Bearer ' . $access_token,
            ),
        ));
        if (is_wp_error($response)) {
            return $this->connection_error('research_manager_orcid_read_failed', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || ! is_array($payload)) {
            return $this->connection_error('research_manager_orcid_read_rejected', 'ORCID read request failed with HTTP ' . $status . '.');
        }
        $this->update_connection(array('status'=>'connected','last_success_at'=>gmdate(DATE_W3C),'last_error'=>''));
        return $payload;
    }

    private function normalize_profile(array $payload): array {
        $name = is_array($payload['name'] ?? null) ? $payload['name'] : array();
        $given = $this->nested_value($name, array('given-names','value'));
        $family = $this->nested_value($name, array('family-name','value'));
        $credit = $this->nested_value($name, array('credit-name','value'));
        $biography = is_array($payload['biography'] ?? null) ? $this->nested_value($payload, array('biography','content')) : '';
        return array(
            'display_name'=>'' !== $credit ? $credit : trim($given . ' ' . $family),
            'given_names'=>$given,
            'family_name'=>$family,
            'credit_name'=>$credit,
            'biography'=>$biography,
        );
    }

    private function normalize_works(array $payload): array {
        $groups = is_array($payload['group'] ?? null) ? $payload['group'] : array();
        $result = array();
        foreach ($groups as $group) {
            if (! is_array($group)) { continue; }
            $summaries = is_array($group['work-summary'] ?? null) ? $group['work-summary'] : array();
            foreach ($summaries as $summary) {
                if (! is_array($summary)) { continue; }
                $title = $this->nested_value($summary, array('title','title','value'));
                $result[] = array(
                    'put_code'=>absint($summary['put-code'] ?? 0),
                    'title'=>$title,
                    'type'=>sanitize_key(str_replace('_', '-', strtolower((string) ($summary['type'] ?? '')))),
                    'publication_date'=>$this->publication_date($summary),
                    'source'=>$this->nested_value($summary, array('source','source-name','value')),
                );
            }
        }
        return $result;
    }

    private function publication_date(array $summary): string {
        $date = is_array($summary['publication-date'] ?? null) ? $summary['publication-date'] : array();
        $year = $this->nested_value($date, array('year','value'));
        $month = $this->nested_value($date, array('month','value'));
        $day = $this->nested_value($date, array('day','value'));
        if ('' === $year) { return ''; }
        return $year . ('' !== $month ? '-' . str_pad($month, 2, '0', STR_PAD_LEFT) : '') . ('' !== $day ? '-' . str_pad($day, 2, '0', STR_PAD_LEFT) : '');
    }

    private function nested_value(array $data, array $path): string {
        $value = $data;
        foreach ($path as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) { return ''; }
            $value = $value[$key];
        }
        return is_scalar($value) ? sanitize_text_field((string) $value) : '';
    }

    private function normalize_orcid(string $orcid): string {
        $orcid = strtoupper(trim($orcid));
        return preg_match('/^\d{4}-\d{4}-\d{4}-[\dX]{4}$/', $orcid) ? $orcid : '';
    }

    private function identifier_url(string $orcid): string {
        $host = 'production' === (string) $this->environment()['id'] ? 'https://orcid.org/' : 'https://sandbox.orcid.org/';
        return $host . rawurlencode($orcid);
    }

    private function update_connection(array $changes): void {
        $store = get_option(self::CONNECTIONS_OPTION, array());
        $store = is_array($store) ? $store : array();
        $current = is_array($store['orcid'] ?? null) ? $store['orcid'] : array();
        $store['orcid'] = array_merge($current, $changes);
        update_option(self::CONNECTIONS_OPTION, $store, false);
    }

    private function connection_error(string $code, string $message): WP_Error {
        $this->update_connection(array('status'=>'error','last_error'=>sanitize_text_field($message)));
        return new WP_Error($code, $message);
    }

    private function state_key(): string {
        return self::STATE_PREFIX . get_current_user_id();
    }
}
