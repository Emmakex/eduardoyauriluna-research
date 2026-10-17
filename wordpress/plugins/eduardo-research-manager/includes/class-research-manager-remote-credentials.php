<?php
/** Site-specific credentials for the authenticated Research Manager bridge. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Credentials {
    /** Stable production connection. Kept unchanged for backward compatibility. */
    private const OPTION = 'eduardo_research_manager_remote_connection';

    /** Independent short-lived / task-scoped credentials. */
    private const TEMPORARY_OPTION = 'eduardo_research_manager_remote_temporary_connections';

    private const MAX_ACTIVE_TEMPORARY = 8;

    /** Raw state of the credential that authenticated the current request. */
    private ?array $authenticated_state = null;

    public static function supported_scopes(): array {
        return array(
            'site.read',
            'site.diagnostics',
            'pages.read',
            'pages.write',
            'insights.read',
            'insights.write',
            'research.read',
            'research.write',
            'evidence.read',
            'evidence.write',
            'translations.read',
            'translations.write',
            'seo.read',
            'seo.write',
            'media.read',
            'media.write',
            'connections.read',
            'connections.write',
            'design.read',
            'design.write',
            'operations.apply',
            'operations.rollback',
            'identity.write',
            'credentials.manage',
            'external_publish.execute',
            'software_update.execute',
            'destructive_delete.execute',
        );
    }

    public static function default_scopes(): array {
        return array('site.read', 'site.diagnostics');
    }

    public function ensure_identity(): array {
        $state = $this->raw_state();
        if ('' !== (string) ($state['connection_id'] ?? '')) {
            return $this->public_state($state, 'primary');
        }

        $state = array(
            'connection_id' => 'rmc_' . str_replace('-', '', wp_generate_uuid4()),
            'label' => 'ChatGPT Manager Bridge',
            'enabled' => false,
            'scopes' => self::default_scopes(),
            'token_hash' => '',
            'token_prefix' => '',
            'wordpress_user_id' => 0,
            'created_at' => gmdate(DATE_W3C),
            'rotated_at' => '',
            'revoked_at' => '',
            'last_used_at' => '',
            'last_request_id' => '',
            'generation' => 0,
            'kind' => 'primary',
        );
        $this->persist($state);
        return $this->public_state($state, 'primary');
    }

    /**
     * Issue/replace the stable production credential.
     *
     * This retains the original single-connection semantics deliberately so
     * existing production tokens are not migrated or silently replaced.
     */
    public function issue_token(array $scopes, int $wordpress_user_id, string $label = 'ChatGPT Manager Bridge'): array|WP_Error {
        $userError = $this->validate_admin_user($wordpress_user_id);
        if (is_wp_error($userError)) { return $userError; }

        $scopes = $this->sanitize_scopes($scopes);
        if (! $scopes) {
            return new WP_Error('validation_failed', 'At least one supported remote scope is required.', array('status'=>400));
        }

        $state = $this->raw_state();
        if ('' === (string) ($state['connection_id'] ?? '')) {
            $this->ensure_identity();
            $state = $this->raw_state();
        }

        $token = $this->generate_token('erm1_');
        if (is_wp_error($token)) { return $token; }

        $now = gmdate(DATE_W3C);
        $state['label'] = sanitize_text_field($label) ?: 'ChatGPT Manager Bridge';
        $state['enabled'] = true;
        $state['scopes'] = $scopes;
        $state['token_hash'] = wp_hash_password($token);
        $state['token_prefix'] = substr($token, 0, 13);
        $state['wordpress_user_id'] = $wordpress_user_id;
        $state['rotated_at'] = $now;
        $state['revoked_at'] = '';
        $state['last_used_at'] = '';
        $state['last_request_id'] = '';
        $state['generation'] = max(0, (int) ($state['generation'] ?? 0)) + 1;
        $state['kind'] = 'primary';
        $this->persist($state);
        $this->authenticated_state = $state;

        return array(
            'token' => $token,
            'connection' => $this->public_state($state, 'primary'),
        );
    }

    /**
     * Issue an independent temporary credential without modifying the stable
     * production credential or its scope profile.
     */
    public function issue_temporary_token(
        array $scopes,
        int $wordpress_user_id,
        string $label = 'Temporary Remote Session'
    ): array|WP_Error {
        $userError = $this->validate_admin_user($wordpress_user_id);
        if (is_wp_error($userError)) { return $userError; }

        $scopes = $this->sanitize_scopes($scopes);
        if (! $scopes) {
            return new WP_Error('validation_failed', 'At least one supported remote scope is required.', array('status'=>400));
        }

        $temporary = $this->raw_temporary_states();
        $active = array_filter($temporary, static fn($state): bool => is_array($state)
            && ! empty($state['enabled'])
            && '' !== (string) ($state['token_hash'] ?? ''));
        if (count($active) >= self::MAX_ACTIVE_TEMPORARY) {
            return new WP_Error(
                'capability_unavailable',
                'The maximum number of active temporary Remote Manager credentials has been reached.',
                array('status'=>409)
            );
        }

        $token = $this->generate_token('ermt1_');
        if (is_wp_error($token)) { return $token; }

        $now = gmdate(DATE_W3C);
        $connectionId = 'rmt_' . str_replace('-', '', wp_generate_uuid4());
        $state = array(
            'connection_id' => $connectionId,
            'label' => sanitize_text_field($label) ?: 'Temporary Remote Session',
            'enabled' => true,
            'scopes' => $scopes,
            'token_hash' => wp_hash_password($token),
            'token_prefix' => substr($token, 0, 14),
            'wordpress_user_id' => $wordpress_user_id,
            'created_at' => $now,
            'rotated_at' => $now,
            'revoked_at' => '',
            'last_used_at' => '',
            'last_request_id' => '',
            'generation' => 1,
            'kind' => 'temporary',
        );
        $temporary[$connectionId] = $state;
        $this->persist_temporary_states($temporary);

        return array(
            'token' => $token,
            'connection' => $this->public_state($state, 'temporary'),
        );
    }

    public function temporary_connections(): array {
        $connections = array();
        foreach ($this->raw_temporary_states() as $state) {
            if (! is_array($state)) { continue; }
            $connections[] = $this->public_state($state, 'temporary');
        }
        usort($connections, static function (array $left, array $right): int {
            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        });
        return $connections;
    }

    public function set_temporary_scopes(string $connection_id, array $scopes): array|WP_Error {
        $connection_id = trim($connection_id);
        $scopes = $this->sanitize_scopes($scopes);
        if (! $scopes) {
            return new WP_Error('validation_failed', 'At least one supported remote scope is required.', array('status'=>400));
        }

        $temporary = $this->raw_temporary_states();
        if ('' === $connection_id || ! isset($temporary[$connection_id]) || ! is_array($temporary[$connection_id])) {
            return new WP_Error('validation_failed', 'Temporary Remote Manager credential was not found.', array('status'=>404));
        }

        $state = $temporary[$connection_id];
        if (empty($state['enabled']) || '' === (string) ($state['token_hash'] ?? '')) {
            return new WP_Error('capability_unavailable', 'Revoked temporary Remote Manager credentials cannot change scopes.', array('status'=>409));
        }

        $state['scopes'] = $scopes;
        $state['kind'] = 'temporary';
        $temporary[$connection_id] = $state;
        $this->persist_temporary_states($temporary);

        if ((string) ($this->authenticated_state['connection_id'] ?? '') === $connection_id) {
            $this->authenticated_state = $state;
        }

        return $this->public_state($state, 'temporary');
    }

    public function revoke_temporary(string $connection_id): array|WP_Error {
        $connection_id = trim($connection_id);
        $temporary = $this->raw_temporary_states();
        if ('' === $connection_id || ! isset($temporary[$connection_id]) || ! is_array($temporary[$connection_id])) {
            return new WP_Error('validation_failed', 'Temporary Remote Manager credential was not found.', array('status'=>404));
        }

        $state = $temporary[$connection_id];
        $state['enabled'] = false;
        $state['token_hash'] = '';
        $state['token_prefix'] = '';
        $state['revoked_at'] = gmdate(DATE_W3C);
        $state['last_request_id'] = '';
        $state['kind'] = 'temporary';
        $temporary[$connection_id] = $state;
        $this->persist_temporary_states($temporary);

        if ((string) ($this->authenticated_state['connection_id'] ?? '') === $connection_id) {
            $this->authenticated_state = null;
        }

        return $this->public_state($state, 'temporary');
    }

    public function rotate(int $wordpress_user_id): array|WP_Error {
        $state = $this->raw_state();
        $scopes = is_array($state['scopes'] ?? null) ? $state['scopes'] : self::default_scopes();
        $label = (string) ($state['label'] ?? 'ChatGPT Manager Bridge');
        return $this->issue_token($scopes, $wordpress_user_id, $label);
    }

    public function set_enabled(bool $enabled): array|WP_Error {
        $state = $this->raw_state();
        if ($enabled && '' === (string) ($state['token_hash'] ?? '')) {
            return new WP_Error('capability_unavailable', 'Generate a connection token before enabling remote access.', array('status'=>409));
        }
        $state['enabled'] = $enabled;
        $state['kind'] = 'primary';
        $this->persist($state);
        if (! is_array($this->authenticated_state) || 'temporary' !== (string) ($this->authenticated_state['kind'] ?? '')) {
            $this->authenticated_state = $state;
        }
        return $this->public_state($state, 'primary');
    }

    public function set_scopes(array $scopes): array|WP_Error {
        $scopes = $this->sanitize_scopes($scopes);
        if (! $scopes) {
            return new WP_Error('validation_failed', 'At least one supported remote scope is required.', array('status'=>400));
        }
        $state = $this->raw_state();
        $state['scopes'] = $scopes;
        $state['kind'] = 'primary';
        $this->persist($state);
        if (! is_array($this->authenticated_state) || 'temporary' !== (string) ($this->authenticated_state['kind'] ?? '')) {
            $this->authenticated_state = $state;
        }
        return $this->public_state($state, 'primary');
    }

    public function revoke(): array {
        $state = $this->raw_state();
        $state['enabled'] = false;
        $state['token_hash'] = '';
        $state['token_prefix'] = '';
        $state['revoked_at'] = gmdate(DATE_W3C);
        $state['last_request_id'] = '';
        $state['kind'] = 'primary';
        $this->persist($state);
        if ('primary' === (string) ($this->authenticated_state['kind'] ?? '')) {
            $this->authenticated_state = null;
        }
        return $this->public_state($state, 'primary');
    }

    public function authenticate(string $token): array|WP_Error {
        $this->authenticated_state = null;
        if ('' === $token) {
            return new WP_Error('authentication_failed', 'Remote Manager credential is invalid.', array('status'=>401));
        }

        $primary = $this->raw_state();
        $authenticated = $this->authenticate_state($token, $primary, 'primary');
        if (is_array($authenticated)) {
            $primary['kind'] = 'primary';
            $this->authenticated_state = $primary;
            return $authenticated;
        }
        if (is_wp_error($authenticated) && 'capability_unavailable' === $authenticated->get_error_code()) {
            return $authenticated;
        }

        foreach ($this->raw_temporary_states() as $state) {
            if (! is_array($state)) { continue; }
            $authenticated = $this->authenticate_state($token, $state, 'temporary');
            if (is_array($authenticated)) {
                $state['kind'] = 'temporary';
                $this->authenticated_state = $state;
                return $authenticated;
            }
            if (is_wp_error($authenticated) && 'capability_unavailable' === $authenticated->get_error_code()) {
                return $authenticated;
            }
        }

        return new WP_Error('authentication_failed', 'Remote Manager credential is invalid.', array('status'=>401));
    }

    public function note_used(string $request_id): void {
        $request_id = sanitize_text_field($request_id);
        $connectionId = (string) ($this->authenticated_state['connection_id'] ?? '');
        $kind = (string) ($this->authenticated_state['kind'] ?? '');

        if ('temporary' === $kind && '' !== $connectionId) {
            $temporary = $this->raw_temporary_states();
            if (isset($temporary[$connectionId]) && is_array($temporary[$connectionId])) {
                $state = $temporary[$connectionId];
                $state['last_used_at'] = gmdate(DATE_W3C);
                $state['last_request_id'] = $request_id;
                $state['kind'] = 'temporary';
                $temporary[$connectionId] = $state;
                $this->persist_temporary_states($temporary);
                $this->authenticated_state = $state;
                return;
            }
        }

        $state = $this->raw_state();
        $state['last_used_at'] = gmdate(DATE_W3C);
        $state['last_request_id'] = $request_id;
        $state['kind'] = 'primary';
        $this->persist($state);
        if ('primary' === $kind || '' === $kind) {
            $this->authenticated_state = $state;
        }
    }

    /**
     * During a REST request this returns the authenticated credential state.
     * Outside authenticated execution it remains backward-compatible and
     * returns the stable production connection.
     */
    public function state(): array {
        if (is_array($this->authenticated_state)
            && '' !== (string) ($this->authenticated_state['connection_id'] ?? '')) {
            return $this->public_state(
                $this->authenticated_state,
                (string) ($this->authenticated_state['kind'] ?? 'primary')
            );
        }
        return $this->primary_state();
    }

    public function primary_state(): array {
        $state = $this->raw_state();
        if ('' === (string) ($state['connection_id'] ?? '')) {
            return $this->ensure_identity();
        }
        return $this->public_state($state, 'primary');
    }

    public function has_scope(array $connection, string $scope): bool {
        $scopes = is_array($connection['scopes'] ?? null) ? $connection['scopes'] : array();
        return in_array($scope, $scopes, true);
    }

    private function validate_admin_user(int $wordpress_user_id): bool|WP_Error {
        $user = get_user_by('id', $wordpress_user_id);
        if (! $user instanceof WP_User || ! user_can($user, 'manage_options')) {
            return new WP_Error('capability_unavailable', 'Remote Manager credentials must be bound to a WordPress administrator.', array('status'=>403));
        }
        return true;
    }

    private function authenticate_state(string $token, array $state, string $kind): array|WP_Error|null {
        if (empty($state['enabled']) || '' === (string) ($state['token_hash'] ?? '')) {
            return null;
        }
        if (! wp_check_password($token, (string) $state['token_hash'])) {
            return null;
        }

        $user_id = (int) ($state['wordpress_user_id'] ?? 0);
        $user = get_user_by('id', $user_id);
        if (! $user instanceof WP_User || ! user_can($user, 'manage_options')) {
            return new WP_Error('capability_unavailable', 'The WordPress administrator bound to this connection is no longer authorised.', array('status'=>403));
        }

        return $this->public_state($state, $kind);
    }

    private function generate_token(string $prefix): string|WP_Error {
        try {
            return $prefix . bin2hex(random_bytes(32));
        } catch (Throwable $error) {
            return new WP_Error('capability_unavailable', 'Unable to generate a cryptographically secure remote token.', array('status'=>500));
        }
    }

    private function raw_state(): array {
        $state = get_option(self::OPTION, array());
        return is_array($state) ? $state : array();
    }

    private function persist(array $state): void {
        if (false === get_option(self::OPTION, false)) {
            add_option(self::OPTION, $state, '', false);
            return;
        }
        update_option(self::OPTION, $state, false);
    }

    private function raw_temporary_states(): array {
        $states = get_option(self::TEMPORARY_OPTION, array());
        return is_array($states) ? $states : array();
    }

    private function persist_temporary_states(array $states): void {
        if (false === get_option(self::TEMPORARY_OPTION, false)) {
            add_option(self::TEMPORARY_OPTION, $states, '', false);
            return;
        }
        update_option(self::TEMPORARY_OPTION, $states, false);
    }

    private function sanitize_scopes(array $scopes): array {
        $supported = self::supported_scopes();
        $clean = array();
        foreach ($scopes as $scope) {
            $scope = strtolower(trim((string) $scope));
            if (in_array($scope, $supported, true)) {
                $clean[] = $scope;
            }
        }
        return array_values(array_unique($clean));
    }

    private function public_state(array $state, string $kind = 'primary'): array {
        $scopes = is_array($state['scopes'] ?? null) ? array_values($state['scopes']) : array();
        $writes_enabled = false;
        foreach ($scopes as $scope) {
            if (str_ends_with((string) $scope, '.write') || in_array($scope, array('operations.apply','operations.rollback','external_publish.execute','software_update.execute','destructive_delete.execute'), true)) {
                $writes_enabled = true;
                break;
            }
        }
        return array(
            'connection_id' => (string) ($state['connection_id'] ?? ''),
            'kind' => 'temporary' === $kind ? 'temporary' : 'primary',
            'label' => (string) ($state['label'] ?? ''),
            'enabled' => ! empty($state['enabled']),
            'credential_present' => '' !== (string) ($state['token_hash'] ?? ''),
            'token_prefix' => (string) ($state['token_prefix'] ?? ''),
            'scopes' => $scopes,
            'writes_enabled' => $writes_enabled,
            'wordpress_user_id' => (int) ($state['wordpress_user_id'] ?? 0),
            'created_at' => (string) ($state['created_at'] ?? ''),
            'rotated_at' => (string) ($state['rotated_at'] ?? ''),
            'revoked_at' => (string) ($state['revoked_at'] ?? ''),
            'last_used_at' => (string) ($state['last_used_at'] ?? ''),
            'last_request_id' => (string) ($state['last_request_id'] ?? ''),
            'generation' => (int) ($state['generation'] ?? 0),
        );
    }
}
