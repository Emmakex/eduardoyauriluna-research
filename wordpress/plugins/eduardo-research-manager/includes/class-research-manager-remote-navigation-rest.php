<?php
/** Authenticated remote gateway for bounded Research Theme navigation. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Navigation_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_Navigation $navigation;

    public function __construct(
        ?Eduardo_Research_Manager_Remote_REST $auth = null,
        ?Eduardo_Research_Manager_Navigation $navigation = null
    ) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
        $this->navigation = $navigation ?: new Eduardo_Research_Manager_Navigation(Eduardo_Research_Manager::remote_audit());
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 10, 3);
    }

    public function register_routes(): void {
        $ns = Eduardo_Research_Manager_Remote_REST::NAMESPACE;
        register_rest_route($ns, '/navigation', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'inspect'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'navigation.read'),
        ));
        register_rest_route($ns, '/navigation/plan', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'create_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'operations.apply', 'navigation.write'),
        ));
        register_rest_route($ns, '/navigation/plans/(?P<plan_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'navigation.read'),
        ));
        register_rest_route($ns, '/navigation/plans/(?P<plan_id>[A-Za-z0-9._:-]+)/apply', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'apply_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'operations.apply', 'navigation.write'),
        ));
        register_rest_route($ns, '/navigation/operations/(?P<operation_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'navigation.read'),
        ));
        register_rest_route($ns, '/navigation/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/verify', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'verify_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'site.diagnostics', 'navigation.read'),
        ));
        register_rest_route($ns, '/navigation/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/rollback', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'rollback_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'operations.rollback', 'navigation.write'),
        ));
    }

    public function inspect(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->navigation->inspect();
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function create_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json($request);
        return $this->idem($request, fn() => $this->navigation->create_plan(
            (string) ($params['location'] ?? ''),
            is_array($params['items'] ?? null) ? $params['items'] : array(),
            (string) ($params['intent'] ?? ''),
            $this->actor($request)
        ));
    }

    public function get_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->navigation->get_plan((string) $request['plan_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function apply_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json($request);
        return $this->idem($request, fn() => $this->navigation->apply(
            (string) $request['plan_id'], ! empty($params['confirm']), $this->actor($request)
        ));
    }

    public function get_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->navigation->get_operation((string) $request['operation_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function verify_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json($request);
        return $this->idem($request, fn() => $this->navigation->verify(
            (string) $request['operation_id'], ! empty($params['rendered']), $this->actor($request)
        ));
    }

    public function rollback_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json($request);
        return $this->idem($request, fn() => $this->navigation->rollback(
            (string) $request['operation_id'], ! empty($params['confirm']), $this->actor($request)
        ));
    }

    public function augment_capabilities(mixed $response, mixed $handler, WP_REST_Request $request): mixed {
        if ('/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE . '/capabilities' !== $request->get_route()
            || is_wp_error($response) || ! $response instanceof WP_REST_Response) { return $response; }
        $payload = $response->get_data();
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) { return $response; }
        $data = $payload['data'];
        $read = is_array($data['read_endpoints'] ?? null) ? $data['read_endpoints'] : array();
        $read['navigation'] = 'navigation.read';
        $data['read_endpoints'] = $read;
        $data['navigation_control'] = array(
            'available'=>true,
            'transport_base'=>'/research-manager/v1/navigation',
            'locations'=>array('primary','footer'),
            'read_scope'=>'navigation.read',
            'write_scopes'=>array('navigation.write','operations.apply'),
            'rollback_scopes'=>array('navigation.write','operations.rollback'),
            'lifecycle'=>array('inspect','plan','apply','status','verify','rollback'),
            'registered_theme_targets_only'=>true,
            'language_aware_urls'=>true,
            'arbitrary_urls'=>false,
            'arbitrary_wordpress_menu_proxy'=>false,
            'rendered_verification'=>true,
            'stale_preview_protection'=>true,
            'idempotency'=>true,
        );
        $payload['data'] = $data;
        $response->set_data($payload);
        return $response;
    }

    private function permission(WP_REST_Request $request, string $primary, string $secondary = ''): bool|WP_Error {
        $ok = $this->auth->permission($request, $primary);
        if (is_wp_error($ok) || true !== $ok || '' === $secondary) { return $ok; }
        $credentials = Eduardo_Research_Manager::remote_credentials();
        $state = $credentials->state();
        if ($credentials->has_scope($state, $secondary)) { return true; }
        Eduardo_Research_Manager::remote_audit()->record(array(
            'request_id'=>(string) $request->get_header('x-research-manager-request-id'),
            'connection_id'=>(string) ($state['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($state['wordpress_user_id'] ?? 0),
            'method'=>$request->get_method(),'route'=>$request->get_route(),'scope'=>$secondary,
            'event'=>'remote-request-rejected','outcome'=>'blocked','error_code'=>'scope_denied',
        ));
        return new WP_Error('scope_denied', sprintf('Remote Manager connection does not grant %s.', $secondary), array('status'=>403));
    }

    private function idem(WP_REST_Request $request, callable $callback): WP_REST_Response|WP_Error {
        $credentials = Eduardo_Research_Manager::remote_credentials();
        $state = $credentials->state();
        $connection_id = (string) ($state['connection_id'] ?? '');
        $request_id = trim((string) $request->get_header('x-research-manager-request-id'));
        $guard = Eduardo_Research_Manager::remote_guard();
        $fingerprint = $guard->request_fingerprint($request);
        $previous = $guard->validate_idempotency($connection_id, $request_id, $fingerprint);
        if (is_wp_error($previous)) { return $previous; }
        if (is_array($previous)) {
            return new WP_REST_Response(array('ok'=>true,'request_id'=>$request_id,'idempotent_replay'=>true,'data'=>(array) ($previous['result'] ?? array())), 200);
        }
        $result = $callback();
        if (is_wp_error($result)) { return $result; }
        $result = is_array($result) ? $result : array('result'=>$result);
        $guard->remember_request($connection_id, $request_id, $fingerprint, $result);
        return new WP_REST_Response(array('ok'=>true,'request_id'=>$request_id,'idempotent_replay'=>false,'data'=>$result), 200);
    }

    private function actor(WP_REST_Request $request): array {
        $state = Eduardo_Research_Manager::remote_credentials()->state();
        return array(
            'connection_id'=>(string) ($state['connection_id'] ?? ''),
            'wordpress_user_id'=>get_current_user_id(),
            'request_id'=>(string) $request->get_header('x-research-manager-request-id'),
        );
    }

    private function json(WP_REST_Request $request): array {
        $params = $request->get_json_params();
        if (! is_array($params)) { $params = $request->get_body_params(); }
        return is_array($params) ? $params : array();
    }

    private function response(WP_REST_Request $request, array $data): WP_REST_Response {
        return new WP_REST_Response(array('ok'=>true,'request_id'=>(string) $request->get_header('x-research-manager-request-id'),'data'=>$data), 200);
    }
}
