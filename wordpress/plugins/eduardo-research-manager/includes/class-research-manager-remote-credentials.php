<?php
/** Site-specific credentials for the authenticated Research Manager bridge. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Credentials {
    private const OPTION = 'eduardo_research_manager_remote_connection';

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
            return $this->public_state($state);
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
        );
        $this->persist($state);
        return $this->public_state($state);
    }

    public function issue_token(array $scopes, int $wordpress_user_id, string $label = 'ChatGPT Manager Bridge'): array|WP_Error {
        $user = get_user_by('id', $wordpress_user_id);
        if (! $user instanceof WP_User || ! user_can($user, 'manage_options')) {
            return new WP_Error('capability_unavailable', 'Remote Manager credentials must be bound to a WordPress administrator.', array('status'=>403));
        }

        $scopes = $this->sanitize_scopes($scopes);
        if (! $scopes) {
            return new WP_Error('validation_failed', 'At least one supported remote scope is required.', array('status'=>400));
        }

        $state = $this->raw_state();
        if ('' === (string) ($state['connection_id'] ?? '')) {
            $this->ensure_identity();
            $state = $this->raw_state();
        }

        try {
            $token = 'erm1_' . bin2hex(random_bytes(32));
        } catch (Throwable $error) {
            return new WP_Error('capability_unavailable', 'Unable to generate a cryptographically secure remote token.', array('status'=>500));
        }

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
        $this->persist($state);

        return array(
            'token' => $token,
            'connection' => $this->public_state($state),
        );
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
        $this->persist($state);
        return $this->public_state($state);
    }

    public function set_scopes(array $scopes): array|WP_Error {
        $scopes = $this->sanitize_scopes($scopes);
        if (! $scopes) {
            return new WP_Error('validation_failed', 'At least one supported remote scope is required.', array('status'=>400));
        }
        $state = $this->raw_state();
        $state['scopes'] = $scopes;
        $this->persist($state);
        return $this->public_state($state);
    }

    public function revoke(): array {
        $state = $this->raw_state();
        $state['enabled'] = false;
        $state['token_hash'] = '';
        $state['token_prefix'] = '';
        $state['revoked_at'] = gmdate(DATE_W3C);
        $state['last_request_id'] = '';
        $this->persist($state);
        return $this->public_state($state);
    }

    public function authenticate(string $token): array|WP_Error {
        $state = $this->raw_state();
        if (empty($state['enabled']) || '' === (string) ($state['token_hash'] ?? '')) {
            return new WP_Error('authentication_failed', 'Remote Manager access is disabled or has no active credential.', array('status'=>401));
        }
        if ('' === $token || ! wp_check_password($token, (string) $state['token_hash'])) {
            return new WP_Error('authentication_failed', 'Remote Manager credential is invalid.', array('status'=>401));
        }

        $user_id = (int) ($state['wordpress_user_id'] ?? 0);
        $user = get_user_by('id', $user_id);
        if (! $user instanceof WP_User || ! user_can($user, 'manage_options')) {
            return new WP_Error('capability_unavailable', 'The WordPress administrator bound to this connection is no longer authorised.', array('status'=>403));
        }

        return $this->public_state($state);
    }

    public function note_used(string $request_id): void {
        $state = $this->raw_state();
        $state['last_used_at'] = gmdate(DATE_W3C);
        $state['last_request_id'] = sanitize_text_field($request_id);
        $this->persist($state);
    }

    public function state(): array {
        $state = $this->raw_state();
        if ('' === (string) ($state['connection_id'] ?? '')) {
            return $this->ensure_identity();
        }
        return $this->public_state($state);
    }

    public function has_scope(array $connection, string $scope): bool {
        $scopes = is_array($connection['scopes'] ?? null) ? $connection['scopes'] : array();
        return in_array($scope, $scopes, true);
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

    private function public_state(array $state): array {
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
