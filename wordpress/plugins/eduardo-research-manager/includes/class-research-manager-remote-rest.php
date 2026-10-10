<?php
/** Versioned authenticated REST bridge for the Research Manager. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_REST {
    public const NAMESPACE = 'research-manager/v1';

    private Eduardo_Research_Manager_Remote_Credentials $credentials;
    private Eduardo_Research_Manager_Remote_Request_Guard $guard;
    private Eduardo_Research_Manager_Remote_Audit $audit;
    private Eduardo_Research_Manager_Remote_Operations $operations;
    private array $auth_context = array();

    public function __construct(
        Eduardo_Research_Manager_Remote_Credentials $credentials,
        Eduardo_Research_Manager_Remote_Request_Guard $guard,
        Eduardo_Research_Manager_Remote_Audit $audit,
        ?Eduardo_Research_Manager_Remote_Operations $operations = null
    ) {
        $this->credentials = $credentials;
        $this->guard = $guard;
        $this->audit = $audit;
        $this->operations = $operations ?: new Eduardo_Research_Manager_Remote_Operations(null, $audit);
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes(): void {
        $this->register_read_route('/status', 'site.read', 'status');
        $this->register_read_route('/versions', 'site.read', 'versions');
        $this->register_read_route('/capabilities', 'site.read', 'capabilities');
        $this->register_read_route('/readiness', 'site.diagnostics', 'readiness');
        $this->register_read_route('/diagnostics', 'site.diagnostics', 'diagnostics');

        register_rest_route(self::NAMESPACE, '/operations/plan', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'create_plan'),
            'permission_callback' => fn(WP_REST_Request $request) => $this->permission($request, 'operations.apply'),
        ));
        register_rest_route(self::NAMESPACE, '/plans/(?P<plan_id>[A-Za-z0-9._:-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_plan'),
            'permission_callback' => fn(WP_REST_Request $request) => $this->permission($request, 'operations.apply'),
        ));
        register_rest_route(self::NAMESPACE, '/plans/(?P<plan_id>[A-Za-z0-9._:-]+)/apply', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'apply_plan'),
            'permission_callback' => fn(WP_REST_Request $request) => $this->permission($request, 'operations.apply'),
        ));
        register_rest_route(self::NAMESPACE, '/operations/(?P<operation_id>[A-Za-z0-9._:-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_operation'),
            'permission_callback' => fn(WP_REST_Request $request) => $this->permission($request, 'site.read'),
        ));
        register_rest_route(self::NAMESPACE, '/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/verify', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'verify_operation'),
            'permission_callback' => fn(WP_REST_Request $request) => $this->permission($request, 'site.diagnostics'),
        ));
        register_rest_route(self::NAMESPACE, '/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/rollback', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rollback_operation'),
            'permission_callback' => fn(WP_REST_Request $request) => $this->permission($request, 'operations.rollback'),
        ));
    }

    public function status(WP_REST_Request $request): WP_REST_Response {
        $diagnostics = Eduardo_Research_Manager::diagnostics()->run();
        $preset = Eduardo_Research_Manager::contract()->preset();
        $metadata = Eduardo_Research_Manager::blueprint_store()->metadata();
        $domain = is_wp_error($metadata) ? home_url('/') : (string) ($metadata['canonical_domain'] ?? home_url('/'));
        $connection = $this->credentials->state();

        return $this->response($request, array(
            'manager_version' => defined('EDUARDO_RESEARCH_MANAGER_VERSION') ? EDUARDO_RESEARCH_MANAGER_VERSION : '',
            'theme_version' => Eduardo_Research_Manager::contract()->theme_version(),
            'preset' => array(
                'id' => (string) ($preset['id'] ?? ''),
                'version' => (int) ($preset['version'] ?? 0),
                'compatible' => Eduardo_Research_Manager::contract()->compatible(),
            ),
            'mode' => Eduardo_Research_Manager::mode(),
            'canonical_domain' => $domain,
            'languages' => Eduardo_Research_Manager::contract()->languages(),
            'readiness' => array(
                'ready' => (bool) ($diagnostics['ready'] ?? false),
                'summary' => is_array($diagnostics['summary'] ?? null) ? $diagnostics['summary'] : array(),
                'generated_at' => (string) ($diagnostics['generated_at'] ?? ''),
            ),
            'remote' => array(
                'connection_id' => (string) ($connection['connection_id'] ?? ''),
                'enabled' => ! empty($connection['enabled']),
                'writes_enabled' => ! empty($connection['writes_enabled']),
                'generation' => (int) ($connection['generation'] ?? 0),
                'last_used_at' => (string) ($connection['last_used_at'] ?? ''),
            ),
        ));
    }

    public function versions(WP_REST_Request $request): WP_REST_Response {
        $preset = Eduardo_Research_Manager::contract()->preset();
        return $this->response($request, array(
            'manager' => defined('EDUARDO_RESEARCH_MANAGER_VERSION') ? EDUARDO_RESEARCH_MANAGER_VERSION : '',
            'theme' => Eduardo_Research_Manager::contract()->theme_version(),
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION,
            'preset' => array(
                'id' => (string) ($preset['id'] ?? ''),
                'version' => (int) ($preset['version'] ?? 0),
                'minimum_supported_version' => Eduardo_Research_Manager_Contract::MIN_PRESET_VERSION,
                'compatible' => Eduardo_Research_Manager::contract()->compatible(),
            ),
            'api' => 'v1',
        ));
    }

    public function capabilities(WP_REST_Request $request): WP_REST_Response {
        $connection = $this->connection_for($request);
        return $this->response($request, array(
            'namespace' => self::NAMESPACE,
            'connection_id' => (string) ($connection['connection_id'] ?? ''),
            'granted_scopes' => is_array($connection['scopes'] ?? null) ? $connection['scopes'] : array(),
            'supported_scopes' => Eduardo_Research_Manager_Remote_Credentials::supported_scopes(),
            'writes_enabled' => ! empty($connection['writes_enabled']),
            'read_endpoints' => array(
                'status' => 'site.read',
                'versions' => 'site.read',
                'capabilities' => 'site.read',
                'readiness' => 'site.diagnostics',
                'diagnostics' => 'site.diagnostics',
            ),
            'mutation_transport' => array(
                'available' => true,
                'milestone' => 'M2',
                'supported_operations' => array('greenfield-canonical'),
                'lifecycle' => array('plan', 'apply', 'status', 'verify', 'rollback'),
                'exact_plan_required' => true,
                'stale_revision_protection' => true,
                'idempotency' => true,
            ),
        ));
    }

    public function readiness(WP_REST_Request $request): WP_REST_Response {
        $diagnostics = Eduardo_Research_Manager::diagnostics()->run();
        return $this->response($request, array(
            'ready' => (bool) ($diagnostics['ready'] ?? false),
            'summary' => is_array($diagnostics['summary'] ?? null) ? $diagnostics['summary'] : array(),
            'next_actions' => is_array($diagnostics['next_actions'] ?? null) ? $diagnostics['next_actions'] : array(),
            'generated_at' => (string) ($diagnostics['generated_at'] ?? ''),
        ));
    }

    public function diagnostics(WP_REST_Request $request): WP_REST_Response {
        return $this->response($request, Eduardo_Research_Manager::diagnostics()->run());
    }

    public function create_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json_params($request);
        $operation = strtolower(trim((string) ($params['operation'] ?? '')));
        $operation = sanitize_key(str_replace('.', '-', $operation));

        return $this->idempotent_mutation($request, function () use ($params, $request, $operation) {
            return $this->operations->create_plan(
                $operation,
                is_array($params['payload'] ?? null) ? $params['payload'] : array(),
                $this->actor_for($request)
            );
        });
    }

    public function get_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->operations->get_plan((string) $request['plan_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function apply_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json_params($request);
        return $this->idempotent_mutation($request, function () use ($params, $request) {
            return $this->operations->apply(
                (string) $request['plan_id'],
                ! empty($params['confirm']),
                $this->actor_for($request)
            );
        });
    }

    public function get_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->operations->get_operation((string) $request['operation_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function verify_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json_params($request);
        return $this->idempotent_mutation($request, function () use ($params, $request) {
            return $this->operations->verify(
                (string) $request['operation_id'],
                ! empty($params['rendered']),
                $this->actor_for($request)
            );
        });
    }

    public function rollback_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json_params($request);
        return $this->idempotent_mutation($request, function () use ($params, $request) {
            return $this->operations->rollback(
                (string) $request['operation_id'],
                ! empty($params['confirm']),
                $this->actor_for($request)
            );
        });
    }

    public function permission(WP_REST_Request $request, string $required_scope): bool|WP_Error {
        $metadata = $this->guard->validate_metadata($request);
        if (is_wp_error($metadata)) {
            $this->audit_failure($request, $required_scope, $metadata, array());
            return $metadata;
        }

        if ($this->requires_ssl() && ! is_ssl()) {
            $error = new WP_Error('authentication_failed', 'Remote Manager API requires HTTPS.', array('status' => 403));
            $this->audit_failure($request, $required_scope, $error, $metadata);
            return $error;
        }

        $token = $this->bearer_token($request);
        if ('' === $token) {
            $error = new WP_Error('authentication_failed', 'Bearer credential is required.', array('status' => 401));
            $this->audit_failure($request, $required_scope, $error, $metadata);
            return $error;
        }

        $connection = $this->credentials->authenticate($token);
        if (is_wp_error($connection)) {
            $this->audit_failure($request, $required_scope, $connection, $metadata);
            return $connection;
        }

        if (! $this->credentials->has_scope($connection, $required_scope)) {
            $error = new WP_Error(
                'scope_denied',
                sprintf('Remote Manager connection does not grant %s.', $required_scope),
                array('status' => 403)
            );
            $this->audit_failure($request, $required_scope, $error, $metadata, $connection);
            return $error;
        }

        $rate = $this->guard->enforce_rate_limit((string) $connection['connection_id']);
        if (is_wp_error($rate)) {
            $this->audit_failure($request, $required_scope, $rate, $metadata, $connection);
            return $rate;
        }

        $nonce = $this->guard->consume_nonce(
            (string) $connection['connection_id'],
            (string) $metadata['nonce']
        );
        if (is_wp_error($nonce)) {
            $this->audit_failure($request, $required_scope, $nonce, $metadata, $connection);
            return $nonce;
        }

        $user_id = (int) ($connection['wordpress_user_id'] ?? 0);
        wp_set_current_user($user_id);
        if (! current_user_can('manage_options')) {
            $error = new WP_Error(
                'capability_unavailable',
                'The local WordPress execution identity is not authorised.',
                array('status' => 403)
            );
            $this->audit_failure($request, $required_scope, $error, $metadata, $connection);
            return $error;
        }

        $this->auth_context[$this->request_key($request)] = array(
            'connection' => $connection,
            'metadata' => $metadata,
            'scope' => $required_scope,
        );
        $this->credentials->note_used((string) $metadata['request_id']);
        return true;
    }

    private function register_read_route(string $route, string $scope, string $method): void {
        register_rest_route(self::NAMESPACE, $route, array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, $method),
            'permission_callback' => fn(WP_REST_Request $request) => $this->permission($request, $scope),
        ));
    }

    private function idempotent_mutation(WP_REST_Request $request, callable $callback): WP_REST_Response|WP_Error {
        $context = $this->context_for($request);
        $connection = is_array($context['connection'] ?? null) ? $context['connection'] : array();
        $metadata = is_array($context['metadata'] ?? null) ? $context['metadata'] : array();
        $connection_id = (string) ($connection['connection_id'] ?? '');
        $request_id = (string) ($metadata['request_id'] ?? '');
        $fingerprint = $this->guard->request_fingerprint($request);

        $idempotency = $this->guard->validate_idempotency($connection_id, $request_id, $fingerprint);
        if (is_wp_error($idempotency)) { return $idempotency; }

        if (is_array($idempotency)) {
            $stored = is_array($idempotency['result'] ?? null) ? $idempotency['result'] : array();
            return new WP_REST_Response(array(
                'ok' => true,
                'request_id' => $request_id,
                'idempotent_replay' => true,
                'data' => $stored,
            ), 200);
        }

        $result = $callback();
        if (is_wp_error($result)) { return $result; }
        $result = is_array($result) ? $result : array('result' => $result);
        $this->guard->remember_request($connection_id, $request_id, $fingerprint, $result);

        return new WP_REST_Response(array(
            'ok' => true,
            'request_id' => $request_id,
            'idempotent_replay' => false,
            'data' => $result,
        ), 200);
    }

    private function response(WP_REST_Request $request, array $data): WP_REST_Response {
        $context = $this->context_for($request);
        $metadata = is_array($context['metadata'] ?? null) ? $context['metadata'] : array();
        $connection = is_array($context['connection'] ?? null) ? $context['connection'] : array();
        $scope = (string) ($context['scope'] ?? '');

        $payload = array(
            'ok' => true,
            'request_id' => (string) ($metadata['request_id'] ?? ''),
            'data' => $data,
        );
        $this->audit->record(array(
            'request_id' => (string) ($metadata['request_id'] ?? ''),
            'connection_id' => (string) ($connection['connection_id'] ?? ''),
            'wordpress_user_id' => (int) ($connection['wordpress_user_id'] ?? 0),
            'method' => $request->get_method(),
            'route' => $request->get_route(),
            'scope' => $scope,
            'event' => 'remote-read',
            'outcome' => 'success',
        ));
        return new WP_REST_Response($payload, 200);
    }

    private function actor_for(WP_REST_Request $request): array {
        $context = $this->context_for($request);
        $connection = is_array($context['connection'] ?? null) ? $context['connection'] : array();
        $metadata = is_array($context['metadata'] ?? null) ? $context['metadata'] : array();
        return array(
            'connection_id' => (string) ($connection['connection_id'] ?? ''),
            'wordpress_user_id' => (int) ($connection['wordpress_user_id'] ?? 0),
            'request_id' => (string) ($metadata['request_id'] ?? ''),
        );
    }

    private function json_params(WP_REST_Request $request): array {
        $params = $request->get_json_params();
        return is_array($params) ? $params : array();
    }

    private function context_for(WP_REST_Request $request): array {
        return $this->auth_context[$this->request_key($request)] ?? array();
    }

    private function connection_for(WP_REST_Request $request): array {
        $context = $this->context_for($request);
        return is_array($context['connection'] ?? null) ? $context['connection'] : array();
    }

    private function request_key(WP_REST_Request $request): string {
        return (string) spl_object_id($request);
    }

    private function bearer_token(WP_REST_Request $request): string {
        $header = trim((string) $request->get_header('authorization'));
        if (! preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) { return ''; }
        return trim((string) $matches[1]);
    }

    private function requires_ssl(): bool {
        $environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        return (bool) apply_filters(
            'eduardo_research_manager_remote_require_ssl',
            ! in_array($environment, array('local', 'development'), true)
        );
    }

    private function audit_failure(
        WP_REST_Request $request,
        string $scope,
        WP_Error $error,
        array $metadata,
        array $connection = array()
    ): void {
        $this->audit->record(array(
            'request_id' => (string) ($metadata['request_id'] ?? ''),
            'connection_id' => (string) ($connection['connection_id'] ?? ''),
            'wordpress_user_id' => (int) ($connection['wordpress_user_id'] ?? 0),
            'method' => $request->get_method(),
            'route' => $request->get_route(),
            'scope' => $scope,
            'event' => 'remote-request-rejected',
            'outcome' => 'blocked',
            'error_code' => $error->get_error_code(),
        ));
    }
}
